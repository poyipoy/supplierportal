<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\Filters\QuotationExportFilters;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use App\Support\StatusHelper;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QuotationListController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * List all incoming quotations for Purchasing.
     */
    public function index(Request $request)
    {
        $filters = QuotationExportFilters::validated($request);
        $query = Quotation::with(['supplier', 'purchaseRequisition.period', 'items'])
            ->whereIn('status', ['submitted', 'revision_requested', 'accepted', 'rejected', 'all_unavailable']);
        QuotationExportFilters::apply($query, $filters);

        $quotations = $query->orderByDesc('submitted_at')
            ->paginate(20)
            ->appends($request->except(PurchasingNavigation::RETURN_URL_KEY));

        $suppliers = User::importEligible()
            ->orWhereIn('id', DB::table('quotations')->distinct()->pluck('supplier_id'))
            ->orderBy('name')->get();

        return view('purchasing.quotations.index', compact('quotations', 'suppliers'));
    }

    private function resolveSupplierFilter(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }

        abort_unless(is_string($value) && ! ctype_digit($value), 404);

        $supplier = (new User)->resolveRouteBinding($value);
        abort_unless($supplier instanceof User && $supplier->role === 'supplier', 404);

        return $supplier;
    }

    /**
     * Show quotation details.
     */
    public function show($id)
    {
        $quotation = Quotation::with([
            'supplier.supplier',
            'purchaseRequisition.awards',
            'purchaseRequisition.period',
            'items.prItem',
            'items.attachments',
            'exchange_rate',
            'attachments',
            'purchaseOrders',
            'reviewer',
        ])->findOrFail($id);

        // Use the quotation exchange-rate snapshot for consistent history conversion.
        $quotationRate = $quotation->exchange_rate;
        $latestRate = ExchangeRate::latestRate($quotation->currency);

        // Check whether a PO can be created.
        $hasAvailableItems = $quotation->hasAvailableItems();
        $awardsByPrItem = $quotation->purchaseRequisition->awards->keyBy('pr_item_id');
        $directPoItems = $quotation->items->map(function (QuotationItem $item) use ($awardsByPrItem): array {
            $award = $awardsByPrItem->get($item->pr_item_id);
            $eligible = $item->is_available && ($award === null || $award->purchase_order_id === null);
            $skipReason = match (true) {
                ! $item->is_available => 'Unavailable - skipped',
                $award?->purchase_order_id !== null => __('purchasing.copy.already_assigned_to_po_skipped'),
                default => null,
            };

            return [
                'item' => $item,
                'eligible' => $eligible,
                'skip_reason' => $skipReason,
                'amount' => $eligible ? (float) $item->resolved_amount : 0.0,
            ];
        });
        $directPoTotalAmount = round((float) $directPoItems->sum('amount'), 4, PHP_ROUND_HALF_UP);
        $directPoTotalIdr = $quotationRate
            ? round($directPoTotalAmount * (float) $quotationRate->rate_to_idr, 4, PHP_ROUND_HALF_UP)
            : null;

        $canCreatePo = in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)
            && $quotation->purchaseOrders->isEmpty()
            && $quotation->purchaseRequisition->status !== 'completed'
            && ! $quotation->isExpired()
            && $directPoItems->contains('eligible', true);

        $canRequestRevision = $quotation->canRequestRevision()
            && $quotation->purchaseRequisition->status !== 'completed';
        $chatAvailable = in_array($quotation->status, ['submitted', 'revision_requested', 'accepted', 'all_unavailable'], true);
        $supplierDisplayName = $quotation->supplier->supplier->company_name
            ?? $quotation->supplier->name
            ?? __('purchasing.copy.supplier');

        return view('purchasing.quotations.show', compact(
            'quotation',
            'quotationRate',
            'latestRate',
            'canCreatePo',
            'canRequestRevision',
            'chatAvailable',
            'supplierDisplayName',
            'hasAvailableItems',
            'directPoItems',
            'directPoTotalAmount',
            'directPoTotalIdr'
        ));
    }

    /**
     * Atomically award eligible items from one quotation and generate its PO.
     */
    public function generatePo(
        Request $request,
        $id,
        PrItemAwardService $awardService,
        PurchaseOrderGenerationService $poService
    ) {
        $validated = $request->validate([
            'estimated_arrival' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $prId = Quotation::query()->whereKey($id)->value('pr_id');
        abort_if($prId === null, 404);

        try {
            $po = DB::transaction(function () use ($id, $prId, $request, $validated, $awardService, $poService) {
                $lockedPr = PurchaseRequisition::query()
                    ->whereKey($prId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedPrItems = PrItem::query()
                    ->where('pr_id', $lockedPr->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $lockedQuotation = Quotation::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedQuotation->pr_id !== (int) $lockedPr->id) {
                    throw new InvalidArgumentException(__('purchasing.copy.the_quotation_no_longer_belongs_to_this_purchase_requisition'));
                }

                if ($lockedPr->status === 'completed') {
                    throw new InvalidArgumentException(__('purchasing.copy.a_purchase_order_cannot_be_generated_because_this_purchase_requisition_is_already_completed'));
                }

                if (! in_array($lockedQuotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                    throw new InvalidArgumentException(__('purchasing.errors.ineligible_quotation', ['status' => StatusHelper::quotationLabel($lockedQuotation->status)]));
                }

                if ($lockedQuotation->isExpired()) {
                    throw new InvalidArgumentException(__('purchasing.copy.this_quotation_has_expired_ask_the_supplier_to_submit_a_revision_before_generating_a_purchase_order'));
                }

                if ($lockedQuotation->purchaseOrders()->exists()) {
                    throw new InvalidArgumentException(__('purchasing.copy.this_quotation_is_already_assigned_to_a_purchase_order'));
                }

                $lockedQuotationItems = QuotationItem::query()
                    ->where('quotation_id', $lockedQuotation->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $lockedAwards = PrItemAward::query()
                    ->whereIn('pr_item_id', $lockedPrItems->keys())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('pr_item_id');

                $selections = [];
                foreach ($lockedQuotationItems as $quotationItem) {
                    if (! $lockedPrItems->has($quotationItem->pr_item_id)) {
                        throw new InvalidArgumentException(__('purchasing.copy.a_quotation_item_no_longer_belongs_to_this_purchase_requisition'));
                    }

                    $existingAward = $lockedAwards->get($quotationItem->pr_item_id);
                    if (! $quotationItem->is_available || $existingAward?->purchase_order_id !== null) {
                        continue;
                    }

                    $selections[(int) $quotationItem->pr_item_id] = (int) $quotationItem->id;
                }

                if ($selections === []) {
                    throw new InvalidArgumentException(__('purchasing.copy.no_available_quotation_items_remain_eligible_for_purchase_order_generation'));
                }

                $awards = $awardService->awardBatch($lockedPr, $selections, $request->user());
                $purchaseOrders = $poService->generateFromAwards($awards, $request->user(), [
                    'estimated_arrival' => $validated['estimated_arrival'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                if ($purchaseOrders->count() !== 1) {
                    throw new InvalidArgumentException(__('purchasing.copy.direct_quotation_generation_must_produce_exactly_one_purchase_order'));
                }

                return $purchaseOrders->firstOrFail();
            });
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (UniqueConstraintViolationException $exception) {
            report($exception);

            return back()->withInput()->with(
                'error',
                __('purchasing.copy.the_quotation_selection_or_purchase_order_state_changed_while_it_was_being_finalized_please_refresh')
            );
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', __('purchasing.copy.failed_to_generate_the_purchase_order_from_this_quotation'));
        }

        return redirect()->route('purchasing.purchase-orders.show', $po)
            ->with('success', __('purchasing.feedback.po_created', ['po' => $po->po_number]));
    }

    public function accept(Request $request, $id)
    {
        try {
            $quotation = DB::transaction(function () use ($id, $request) {
                $quotation = Quotation::whereKey($id)->lockForUpdate()->firstOrFail();
                $quotation->load(['supplier', 'purchaseRequisition', 'purchaseOrders', 'items']);

                if (! $quotation->canApproveBy(auth()->user())) {
                    throw new InvalidArgumentException(__('purchasing.copy.this_quotation_cannot_be_accepted'));
                }
                if (! $quotation->hasAvailableItems()) {
                    throw new InvalidArgumentException(__('purchasing.copy.this_quotation_cannot_be_accepted_because_all_items_are_marked_as_not_available_by_the_supplier'));
                }
                if ($quotation->isExpired()) {
                    throw new InvalidArgumentException(__('purchasing.copy.this_quotation_has_expired_ask_the_supplier_to_submit_a_revision_before_accepting_it'));
                }

                $quotation->update([
                    'status' => Quotation::STATUS_ACCEPTED,
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                    'reviewer_notes' => $request->input('reviewer_notes'),
                ]);

                return $quotation;
            });
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->notifySupplierOfReview($quotation, 'accepted', 'purchasing.notify.accepted_title', 'purchasing.notify.accepted_body');

        return back()->with('success', __('purchasing.copy.quotation_successfully_accepted'));
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'reviewer_notes' => 'required|string|max:1000',
        ], [
            'reviewer_notes.required' => __('purchasing.copy.rejection_notes_are_required'),
        ]);

        try {
            $quotation = DB::transaction(function () use ($id, $request) {
                $quotation = Quotation::whereKey($id)->lockForUpdate()->firstOrFail();
                $quotation->load(['supplier', 'purchaseRequisition', 'purchaseOrders', 'items']);

                if (! $quotation->canApproveBy(auth()->user())) {
                    throw new InvalidArgumentException(__('purchasing.copy.this_quotation_cannot_be_rejected'));
                }
                if (! $quotation->hasAvailableItems()) {
                    throw new InvalidArgumentException(__('purchasing.copy.cannot_reject_a_quotation_that_has_no_available_items'));
                }

                $quotation->update([
                    'status' => Quotation::STATUS_REJECTED,
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                    'reviewer_notes' => $request->reviewer_notes,
                ]);

                return $quotation;
            });
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->notifySupplierOfReview($quotation, 'rejected', 'purchasing.notify.rejected_title', 'purchasing.notify.rejected_body');

        return back()->with('success', __('purchasing.copy.quotation_successfully_rejected'));
    }

    /**
     * Ask the supplier to revise an expired quotation.
     */
    public function requestRevision(Request $request, $id)
    {
        $request->validate([
            'revision_note' => 'required|string|max:1000',
        ], [
            'revision_note.required' => __('purchasing.copy.revision_notes_are_required'),
        ]);

        $revisionNote = trim((string) $request->input('revision_note', ''));
        try {
            $quotation = DB::transaction(function () use ($id, $revisionNote) {
                $quotation = Quotation::whereKey($id)->lockForUpdate()->firstOrFail();
                $quotation->load(['supplier.supplier', 'purchaseRequisition', 'purchaseOrders', 'items']);

                if ($quotation->purchaseRequisition->status === 'completed') {
                    throw new InvalidArgumentException(__('purchasing.copy.the_pr_is_completed_a_quotation_revision_cannot_be_requested'));
                }
                if (! $quotation->canRequestRevision()) {
                    throw new InvalidArgumentException(__('purchasing.copy.a_revision_can_only_be_requested_for_submitted_or_unavailable_quotations_that_have_not_been_used_to'));
                }

                $quotation->update([
                    'status' => Quotation::STATUS_REVISION_REQUESTED,
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                    'reviewer_notes' => $revisionNote !== '' ? $revisionNote : null,
                ]);

                $conversation = Conversation::firstOrCreate([
                    'conversable_type' => PurchaseRequisition::class,
                    'conversable_id' => $quotation->pr_id,
                    'purchasing_user_id' => auth()->id(),
                    'supplier_user_id' => $quotation->supplier_id,
                ]);

                $message = __(! $quotation->hasAvailableItems()
                    ? 'notifications.conversation.revise_unavailable'
                    : 'notifications.conversation.revise_expired', [
                        'pr_number' => $quotation->purchaseRequisition->pr_number ?? '#'.$quotation->pr_id,
                    ]);

                if ($revisionNote !== '') {
                    $message = __('notifications.conversation.with_revision_note', ['message' => $message, 'note' => $revisionNote]);
                }

                $conversation->messages()->create([
                    'sender_id' => auth()->id(),
                    'body' => $message,
                ]);

                return $quotation;
            });
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->notifySupplierOfReview(
            $quotation,
            'revision_requested',
            'purchasing.copy.quotation_revision_requested',
            'purchasing.notify.revision_body',
        );

        $showParameters = [$quotation];
        if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
            $showParameters['return_url'] = $request->input('return_url');
        }

        return redirect()->route('purchasing.quotations.show', $showParameters)
            ->with('success', __('purchasing.copy.quotation_revision_request_has_been_sent_to_the_supplier'));
    }

    private function notifySupplierOfReview(Quotation $quotation, string $eventSuffix, string $title, string $message): void
    {
        $quotation->loadMissing(['supplier', 'purchaseRequisition']);
        $reviewKey = $quotation->reviewed_at?->format('YmdHis.u') ?? $quotation->updated_at?->format('YmdHis.u');

        $this->notifications->send(
            $quotation->supplier,
            "quotation.{$eventSuffix}",
            "quotation.{$eventSuffix}:{$quotation->id}:{$reviewKey}",
            $title,
            $message,
            route('supplier.quotations.show', $quotation, absolute: false),
            $eventSuffix === 'revision_requested' ? 'refresh-cw text-warning' : 'tags text-primary',
            [
                'category' => NotificationCategory::QUOTATION,
                'quotation_id' => $quotation->id,
                'pr_id' => $quotation->pr_id,
                'pr_number' => $quotation->purchaseRequisition->pr_number,
            ],
            ['pr_number' => $quotation->purchaseRequisition->pr_number ?? '-'],
        );
    }
}
