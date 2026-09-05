<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class QuotationListController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * List all incoming quotations for Purchasing.
     */
    public function index(Request $request)
    {
        $supplierFilter = $this->resolveSupplierFilter($request->query('supplier_id'));

        $request->validate([
            'date_from' => 'nullable|date_format:Y-m',
            'date_to' => 'nullable|date_format:Y-m',
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
            'supplier_id' => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->filled('date_from') && $request->filled('date_to') && $request->date_to < $request->date_from) {
            return back()
                ->withInput()
                ->withErrors(['date_to' => 'End date cannot be before start date']);
        }

        $query = Quotation::with(['supplier', 'purchaseRequisition.period', 'items'])
            ->whereIn('status', ['submitted', 'revision_requested', 'accepted', 'rejected']);

        // Filter: Number PR
        if ($request->filled('pr_number')) {
            $query->whereHas('purchaseRequisition', function ($q) use ($request) {
                $q->where('pr_number', 'like', '%'.trim($request->pr_number).'%');
            });
        }

        // Filter: quotation submitted date range.
        if ($request->filled('date_from')) {
            $from = Carbon::createFromFormat('Y-m', $request->date_from)->startOfMonth();
            $query->where('submitted_at', '>=', $from);
        }

        if ($request->filled('date_to')) {
            $to = Carbon::createFromFormat('Y-m', $request->date_to)->endOfMonth();
            $query->where('submitted_at', '<=', $to);
        }

        // Filter: Supplier
        if ($supplierFilter) {
            $query->where('supplier_id', $supplierFilter->getKey());
        }

        // Filter: Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter: currency.
        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }

        $quotations = $query->orderByDesc('submitted_at')
            ->paginate(20)
            ->appends($request->except(PurchasingNavigation::RETURN_URL_KEY));

        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

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

        $canCreatePo = in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ACCEPTED], true)
            && $quotation->purchaseOrders->isEmpty()
            && $quotation->purchaseRequisition->status !== 'completed'
            && ! $quotation->isExpired()
            && $hasAvailableItems;

        $canRequestRevision = $quotation->canRequestRevision()
            && $quotation->purchaseRequisition->status !== 'completed';
        $chatAvailable = in_array($quotation->status, ['submitted', 'revision_requested', 'accepted', 'all_unavailable'], true);
        $supplierDisplayName = $quotation->supplier->supplier->company_name
            ?? $quotation->supplier->name
            ?? 'Supplier';

        return view('purchasing.quotations.show', compact(
            'quotation',
            'quotationRate',
            'latestRate',
            'canCreatePo',
            'canRequestRevision',
            'chatAvailable',
            'supplierDisplayName',
            'hasAvailableItems'
        ));
    }

    public function accept(Request $request, $id)
    {
        try {
            $quotation = DB::transaction(function () use ($id, $request) {
                $quotation = Quotation::whereKey($id)->lockForUpdate()->firstOrFail();
                $quotation->load(['supplier', 'purchaseRequisition', 'purchaseOrders', 'items']);

                if (! $quotation->canApproveBy(auth()->user())) {
                    throw new InvalidArgumentException('This quotation cannot be accepted.');
                }
                if (! $quotation->hasAvailableItems()) {
                    throw new InvalidArgumentException('This quotation cannot be accepted because all items are marked as not available by the supplier.');
                }
                if ($quotation->isExpired()) {
                    throw new InvalidArgumentException('This quotation has expired. Ask the supplier to submit a revision before accepting it.');
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

        $this->notifySupplierOfReview($quotation, 'accepted', 'Quotation Accepted', 'Quotation for PR :pr_number has been accepted by Purchasing.');

        return back()->with('success', 'Quotation successfully accepted.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'reviewer_notes' => 'required|string|max:1000',
        ], [
            'reviewer_notes.required' => 'Rejection notes are required.',
        ]);

        try {
            $quotation = DB::transaction(function () use ($id, $request) {
                $quotation = Quotation::whereKey($id)->lockForUpdate()->firstOrFail();
                $quotation->load(['supplier', 'purchaseRequisition', 'purchaseOrders', 'items']);

                if (! $quotation->canApproveBy(auth()->user())) {
                    throw new InvalidArgumentException('This quotation cannot be rejected.');
                }
                if (! $quotation->hasAvailableItems()) {
                    throw new InvalidArgumentException('Cannot reject a quotation that has no available items.');
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

        $this->notifySupplierOfReview($quotation, 'rejected', 'Quotation Rejected', 'Quotation for PR :pr_number was rejected by Purchasing.');

        return back()->with('success', 'Quotation successfully rejected.');
    }

    /**
     * Ask the supplier to revise an expired quotation.
     */
    public function requestRevision(Request $request, $id)
    {
        $request->validate([
            'revision_note' => 'required|string|max:1000',
        ], [
            'revision_note.required' => 'Revision notes are required.',
        ]);

        $revisionNote = trim((string) $request->input('revision_note', ''));
        try {
            $quotation = DB::transaction(function () use ($id, $revisionNote) {
                $quotation = Quotation::whereKey($id)->lockForUpdate()->firstOrFail();
                $quotation->load(['supplier.supplier', 'purchaseRequisition', 'purchaseOrders', 'items']);

                if ($quotation->purchaseRequisition->status === 'completed') {
                    throw new InvalidArgumentException('The PR is completed. A quotation revision cannot be requested.');
                }
                if (! $quotation->canRequestRevision()) {
                    throw new InvalidArgumentException('A revision can only be requested for submitted or unavailable quotations that have not been used to create a PO.');
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

                $reason = ! $quotation->hasAvailableItems()
                    ? 'because all items were marked as not available.'
                    : 'because the quotation validity has expired.';

                $message = 'Please revise the quotation for PR '
                    .($quotation->purchaseRequisition->pr_number ?? '#'.$quotation->pr_id)
                    .' '.$reason;

                if ($revisionNote !== '') {
                    $message .= "\n\nRevision notes: ".$revisionNote;
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
            'Quotation Revision Requested',
            'Purchasing requested a quotation revision for PR :pr_number.',
        );

        $showParameters = [$quotation];
        if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
            $showParameters['return_url'] = $request->input('return_url');
        }

        return redirect()->route('purchasing.quotations.show', $showParameters)
            ->with('success', 'Quotation revision request has been sent to the supplier.');
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
