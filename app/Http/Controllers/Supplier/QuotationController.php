<?php

namespace App\Http\Controllers\Supplier;

use App\Exceptions\ProcurementAvailabilityException;
use App\Exports\QuotationFormExport;
use App\Exports\QuotationImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\Filters\QuotationExportFilters;
use App\Http\Requests\ExportQuotationFormRequest;
use App\Http\Requests\Supplier\StoreQuotationRequest;
use App\Imports\QuotationItemsImport;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Services\Materials\MaterialWeightCalculator;
use App\Services\NotificationService;
use App\Services\Procurement\QuotationSubmissionService;
use App\Support\BusinessTime;
use App\Support\Procurement\ProcurementFeatures;
use App\Support\SpreadsheetImportReader;
use App\Support\Ui\RowAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Throwable;
use Yajra\DataTables\Facades\DataTables;

class QuotationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MaterialWeightCalculator $weightCalculator,
        private readonly QuotationSubmissionService $submissionService,
    ) {}

    /**
     * Display open quotation periods.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Quotation::class);
        $supplierId = (int) auth()->id();

        $periods = Period::where('status', 'open')
            ->where(function ($periodQuery) use ($supplierId) {
                $periodQuery->whereHas('purchaseRequisitions', function ($query) use ($supplierId) {
                    $query->accessibleBySupplier($supplierId);
                })
                    ->orWhereHas('purchaseRequisitions.quotations', function ($query) use ($supplierId) {
                        $query->where('supplier_id', $supplierId);
                    });
            })
            ->orderByDesc('year')
            ->orderByRaw('month IS NULL DESC')
            ->orderByDesc('month')
            ->get();

        if ($periods->isEmpty()) {
            return view('supplier.quotations.index', compact('periods'));
        }

        $periodIds = $periods->pluck('id');

        // Prefetch visible active PRs for all periods in 1 query (excludes cancelled PRs/invitations)
        $activePrs = PurchaseRequisition::whereIn('period_id', $periodIds)
            ->whereIn('status', ['submitted', 'bidding'])
            ->visibleToSupplier($supplierId)
            ->get(['id', 'period_id']);

        $activePrsByPeriod = $activePrs->groupBy('period_id');

        // Prefetch all accessible PRs (including cancelled) for accurate total PR counts per period
        $accessiblePrs = PurchaseRequisition::whereIn('period_id', $periodIds)
            ->accessibleBySupplier($supplierId)
            ->get(['id', 'period_id']);

        $accessiblePrsByPeriod = $accessiblePrs->groupBy('period_id');

        // Prefetch quotations of this supplier for all periods in 1 query
        $quotations = Quotation::where('supplier_id', $supplierId)
            ->whereHas('purchaseRequisition', function ($query) use ($periodIds) {
                $query->whereIn('period_id', $periodIds);
            })
            ->with('purchaseRequisition:id,period_id')
            ->get(['id', 'pr_id', 'supplier_id', 'status']);

        $quotationsByPeriod = $quotations->groupBy(fn ($q) => $q->purchaseRequisition?->period_id);

        // Derive metrics in-memory per period with zero extra database queries
        foreach ($periods as $period) {
            $periodActivePrs = $activePrsByPeriod->get($period->id, collect());
            $periodAccessiblePrs = $accessiblePrsByPeriod->get($period->id, collect());
            $periodQuotations = $quotationsByPeriod->get($period->id, collect());

            $accessiblePrIds = $periodAccessiblePrs->pluck('id');
            $quotedPrIds = $periodQuotations->pluck('pr_id');

            $period->total_prs = $accessiblePrIds
                ->merge($quotedPrIds)
                ->unique()
                ->count();

            $period->responded_prs = $periodQuotations->count();
            $period->rejected_prs = $periodQuotations->where('status', 'rejected')->count();

            $quotedPrIdSet = $quotedPrIds->unique()->flip();
            $period->unresponded_prs = $periodActivePrs->reject(fn ($pr) => isset($quotedPrIdSet[$pr->id]))->count();
        }

        return view('supplier.quotations.index', compact('periods'));
    }

    /**
     * Display PRs for a selected period.
     */
    public function period(Request $request, $period_id)
    {
        Gate::authorize('viewAny', Quotation::class);
        $filters = QuotationExportFilters::validated($request, supplier: true);
        $period = Period::findOrFail($period_id);
        $supplierId = (int) auth()->id();

        $query = PurchaseRequisition::query()
            ->select([
                'purchase_requisitions.id',
                'purchase_requisitions.period_id',
                'purchase_requisitions.pr_number',
                'purchase_requisitions.status',
                'purchase_requisitions.updated_at',
            ])
            ->with([
                'quotations' => fn ($query) => $query
                    ->select(['id', 'pr_id', 'supplier_id', 'status', 'submitted_at'])
                    ->where('supplier_id', $supplierId),
            ])
            ->withCount('items')
            ->withExists([
                'cancelledInvitedSuppliers as is_cancelled_invitation' => fn ($query) => $query->where('users.id', $supplierId),
            ])
            ->where('period_id', $period_id)
            ->accessibleBySupplier($supplierId);

        if ($request->filled('pr_number')) {
            $query->where('purchase_requisitions.pr_number', 'like', '%'.$request->pr_number.'%');
        }

        if ($request->filled('status')) {
            if ($request->status === 'cancelled') {
                $query->cancelledForSupplier($supplierId);
            } elseif ($request->status === 'unresponded') {
                $query->whereIn('status', ['submitted', 'bidding'])
                    ->whereDoesntHave('cancelledInvitedSuppliers', fn ($s) => $s->where('users.id', $supplierId))
                    ->whereDoesntHave('quotations', function ($q) use ($supplierId) {
                        $q->where('supplier_id', $supplierId);
                    });
            } else {
                $query->whereHas('quotations', function ($q) use ($request, $supplierId) {
                    $q->where('supplier_id', $supplierId)
                        ->where('status', $request->status);
                });
            }
        }

        if ($request->ajax()) {
            if ($search = trim((string) ($filters['search'] ?? ''))) {
                $query->where(fn ($q) => $q
                    ->where('purchase_requisitions.pr_number', 'like', '%'.$search.'%')
                    ->orWhere('purchase_requisitions.updated_at', 'like', '%'.$search.'%'));
            }

            $cancelledBadgeHtml = Blade::render(
                '<x-ui.status-chip tone="error" icon="x-circle">{{ $label }}</x-ui.status-chip>',
                ['label' => __('status.pr.cancelled')]
            );

            return DataTables::eloquent($query->orderByDesc('purchase_requisitions.updated_at'))
                ->filter(fn ($query) => null)
                ->addIndexColumn()
                ->addColumn('pr_number_display', fn ($pr) => $pr->pr_number ?? '-')
                ->addColumn('updated_date', fn ($pr) => BusinessTime::format($pr->updated_at, 'd M Y, H:i'))
                ->addColumn('item_count', fn ($pr) => $pr->isCancelledForSupplier($supplierId) ? '-' : ((int) $pr->items_count).' Item')
                ->addColumn('status_badge', function ($pr) use ($supplierId, $cancelledBadgeHtml) {
                    if ($pr->isCancelledForSupplier($supplierId)) {
                        return $cancelledBadgeHtml;
                    }

                    $quotation = $pr->quotations->first();
                    $status = $quotation ? $quotation->status : 'unresponded';

                    return match ($status) {
                        'unresponded' => '<span class="ui-status-chip ui-status-chip--error">'.e(__('supplier.copy.not_responded')).'</span>',
                        'draft' => '<span class="ui-status-chip ui-status-chip--neutral">'.e(__('supplier.copy.draft')).'</span>',
                        'revision_requested' => '<span class="ui-status-chip ui-status-chip--warning">'.e(__('supplier.copy.revision_requested')).'</span>',
                        'submitted' => '<span class="ui-status-chip ui-status-chip--success">Submitted ('.($quotation->submitted_at ? BusinessTime::format($quotation->submitted_at, 'd M Y H:i') : '-').')</span>',
                        'all_unavailable' => '<span class="ui-status-chip ui-status-chip--neutral">'.e(__('supplier.copy.all_unavailable')).'</span>',
                        'accepted' => '<span class="ui-status-chip ui-status-chip--info">'.e(__('supplier.copy.accepted')).'</span>',
                        'rejected' => '<span class="ui-status-chip ui-status-chip--error">'.e(__('supplier.copy.rejected')).'</span>',
                        default => '<span class="ui-status-chip ui-status-chip--neutral">'.e(ucwords($status)).'</span>',
                    };
                })
                ->addColumn('action', function ($pr) use ($supplierId) {
                    if ($pr->isCancelledForSupplier($supplierId)) {
                        $action = RowAction::detail(route('supplier.requests.show', $pr), $pr->pr_number);

                        return '<div class="tw:inline-flex tw:gap-1 tw:justify-end tw:flex-wrap">'.$action.'</div>';
                    }

                    $quotation = $pr->quotations->first();
                    $status = $quotation ? $quotation->status : 'unresponded';

                    $action = match ($status) {
                        'unresponded' => RowAction::link(route('supplier.quotations.create', $pr), __('supplier.copy.create_quotation'), 'primary', $pr->pr_number),
                        'draft' => RowAction::link(route('supplier.quotations.create', $pr), __('supplier.copy.continue'), 'primary', $pr->pr_number),
                        'revision_requested' => RowAction::link(route('supplier.quotations.create', $pr), __('supplier.copy.revise_quotation'), 'warning', $pr->pr_number),
                        default => $quotation ? RowAction::detail(route('supplier.quotations.show', $quotation), $pr->pr_number) : '-',
                    };

                    return '<div class="tw:inline-flex tw:gap-1 tw:justify-end tw:flex-wrap">'.$action.'</div>';
                })
                ->rawColumns(['status_badge', 'action'])
                ->ignoreSelectsInCountQuery()
                ->only([
                    'DT_RowIndex',
                    'pr_number_display',
                    'updated_date',
                    'item_count',
                    'status_badge',
                    'action',
                ])
                ->make(true);
        }

        return view('supplier.quotations.period', compact('period'));
    }

    /**
     * Passive availability check polled by the quotation form to detect mid-form cancellation.
     */
    public function availability(Request $request, $pr_id): JsonResponse
    {
        Gate::authorize('viewAny', Quotation::class);
        $supplierId = (int) auth()->id();

        $pr = PurchaseRequisition::query()
            ->select(['id', 'pr_number', 'status', 'cancelled_at'])
            ->withExists([
                'cancelledInvitedSuppliers as is_cancelled_invitation' => fn ($query) => $query->where('users.id', $supplierId),
            ])
            ->findOrFail($pr_id);

        if (! $pr->isAccessibleBySupplier($supplierId)) {
            abort(403, __('supplier.copy.you_are_not_invited_to_submit_a_quotation_for_this_requisition'));
        }

        $isCancelledForSupplier = $pr->isCancelledForSupplier($supplierId);
        $available = ! $isCancelledForSupplier && in_array($pr->status, ['submitted', 'bidding'], true);

        $code = match (true) {
            $pr->isCancelled() => ProcurementAvailabilityException::CODE_PR_CANCELLED,
            $isCancelledForSupplier => ProcurementAvailabilityException::CODE_INVITATION_CANCELLED,
            ! $available => ProcurementAvailabilityException::CODE_PR_UNAVAILABLE,
            default => null,
        };

        $message = match ($code) {
            ProcurementAvailabilityException::CODE_PR_CANCELLED => __('supplier_requests.availability.pr_cancelled_banner'),
            ProcurementAvailabilityException::CODE_INVITATION_CANCELLED => __('supplier_requests.availability.invitation_cancelled_banner'),
            ProcurementAvailabilityException::CODE_PR_UNAVAILABLE => __('supplier.copy.this_requisition_is_no_longer_available_for_quotation'),
            default => null,
        };

        return response()->json([
            'available' => $available,
            'status' => $isCancelledForSupplier ? 'cancelled' : ($available ? 'open' : 'closed'),
            'code' => $code,
            'message' => $message,
            'redirect_url' => route('supplier.requests.show', $pr),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /**
     * Display the quotation create/edit form for a selected PR.
     */
    public function create($pr_id)
    {
        Gate::authorize('create', Quotation::class);
        $supplierId = (int) auth()->id();
        $pr = PurchaseRequisition::with([
            'allInvitedSuppliers',
            'quotations' => fn ($q) => $q->where('supplier_id', $supplierId),
        ])->findOrFail($pr_id);

        if (! $pr->isAccessibleBySupplier($supplierId)) {
            abort(403, __('supplier.copy.you_are_not_invited_to_submit_a_quotation_for_this_requisition'));
        }

        if ($pr->isCancelledForSupplier($supplierId)) {
            $warning = $pr->isCancelled()
                ? __('supplier_requests.availability.pr_cancelled_redirect')
                : __('supplier_requests.availability.invitation_cancelled_redirect');

            return redirect()->route('supplier.requests.show', $pr)->with('warning', $warning);
        }

        if (! in_array($pr->status, ['submitted', 'bidding'], true)) {
            return redirect()->route('supplier.quotations.index')->with('error', __('supplier.copy.this_requisition_is_not_available_for_quotation'));
        }

        $pr->load('items');

        // Find an existing quotation.
        $quotation = Quotation::with('items.attachments')
            ->where('pr_id', $pr_id)
            ->where('supplier_id', $supplierId)
            ->first();

        // Final quotations are read-only; drafts and revision_requested quotations can be edited.
        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            return redirect()->route('supplier.quotations.show', $quotation)
                ->with('info', __('supplier.copy.you_have_already_submitted_a_quotation_for_this_requisition'));
        }
        // store() refuses to revise a quotation an award or PO refers to; do not
        // offer a form that can only be refused.
        if ($quotation && $quotation->isReferencedByAwardOrPurchaseOrder()) {
            return redirect()->route('supplier.quotations.show', $quotation)
                ->with('error', __('supplier.copy.this_quotation_is_locked_or_used_by_an_award_or_purchase_order_and_cannot_be_changed'));
        }
        if ($quotation) {
            Gate::authorize('update', $quotation);
        }

        $currencyOptions = ExchangeRate::CURRENCIES;
        $supplierCurrency = old('currency', $quotation?->currency);
        if (! in_array($supplierCurrency, $currencyOptions, true)) {
            $supplierCurrency = '';
        }

        $supplierRate = $supplierCurrency ? ExchangeRate::latestRate($supplierCurrency) : null;
        $currencyRates = ExchangeRate::query()
            ->whereIn('currency', $currencyOptions)
            ->orderByDesc('valid_from')
            ->get()
            ->unique('currency')
            ->mapWithKeys(fn ($rate) => [$rate->currency => (float) $rate->rate_to_idr])
            ->all();

        $surplusOffersAllowed = ProcurementFeatures::surplusOffersAllowed();
        $shipmentMtcEnabled = ProcurementFeatures::shipmentMtcEnabled();

        return view('supplier.quotations.create', compact('pr', 'quotation', 'supplierCurrency', 'supplierRate', 'currencyOptions', 'currencyRates', 'surplusOffersAllowed', 'shipmentMtcEnabled'));
    }

    public function importTemplate(Request $request, $pr_id)
    {
        try {
            $pr = $this->importableRequisition($pr_id);
        } catch (ProcurementAvailabilityException $exception) {
            $pr = PurchaseRequisition::findOrFail($pr_id);

            return $this->cancelledAvailabilityResponse($request, $pr, $exception->errorCode, $exception->getMessage());
        }

        $safeNumber = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $pr->pr_number ?? ''), '_');
        $safeNumber = $safeNumber !== '' ? $safeNumber : 'PR_'.$pr->id;

        return Excel::download(
            new QuotationImportTemplateExport($pr->id),
            'template_import_quotation_'.$safeNumber.'.xlsx'
        );
    }

    /**
     * GEN-03 Mode 1: download the entered, unsaved form as an import-compatible
     * XLSX. Authorised like import-preview; validated before any output; no
     * database write and no ExportJob.
     */
    public function exportForm(Request $request, $pr_id)
    {
        try {
            $pr = $this->importableRequisition($pr_id);
        } catch (ProcurementAvailabilityException $exception) {
            $pr = PurchaseRequisition::findOrFail($pr_id);

            return $this->cancelledAvailabilityResponse($request, $pr, $exception->errorCode, $exception->getMessage());
        }

        $validated = ExportQuotationFormRequest::validateFor($request, $pr);
        $safeNumber = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $pr->pr_number ?? ''), '_');
        $safeNumber = $safeNumber !== '' ? $safeNumber : 'PR_'.$pr->id;

        $response = Excel::download(
            new QuotationFormExport($pr, $validated['items']),
            'template_import_quotation_'.$safeNumber.'.xlsx'
        );
        $response->headers->set('Cache-Control', 'no-store, private');
        // The file is written; do not leave the export's value binder registered globally.
        Cell::setValueBinder(new DefaultValueBinder);

        return $response;
    }

    public function importPreview(Request $request, $pr_id)
    {
        try {
            $pr = $this->importableRequisition($pr_id);
        } catch (ProcurementAvailabilityException $exception) {
            $pr = PurchaseRequisition::findOrFail($pr_id);

            return $this->cancelledAvailabilityResponse($request, $pr, $exception->errorCode, $exception->getMessage(), forceJson: true);
        }

        $validator = Validator::make($request->all(), [
            'import_file' => [
                'required',
                File::types(['xlsx', 'xls', 'csv'])->max(10240),
                'extensions:xlsx,xls,csv',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(
                $this->importFailurePayload($validator->errors()->get('import_file')),
                422
            );
        }

        $import = new QuotationItemsImport($pr->items);

        try {
            SpreadsheetImportReader::import($import, $request->file('import_file'));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(
                $this->importFailurePayload([__('supplier.copy.the_spreadsheet_could_not_be_read_verify_the_file_and_try_again')]),
                422
            );
        }

        return response()->json(
            $import->preview(),
            $import->hasBlockingErrors() ? 422 : 200
        );
    }

    /**
     * Save quotation as draft or submitted.
     */
    public function store(Request $request, $pr_id)
    {
        Gate::authorize('create', Quotation::class);
        $supplierId = (int) auth()->id();
        $pr = PurchaseRequisition::with([
            'allInvitedSuppliers',
            'items.materialMaster',
        ])->findOrFail($pr_id);

        if (! $pr->isAccessibleBySupplier($supplierId)) {
            abort(403, __('supplier.copy.you_are_not_invited_to_submit_a_quotation_for_this_requisition'));
        }

        if ($pr->isCancelledForSupplier($supplierId)) {
            $code = $pr->isCancelled()
                ? ProcurementAvailabilityException::CODE_PR_CANCELLED
                : ProcurementAvailabilityException::CODE_INVITATION_CANCELLED;

            return $this->cancelledAvailabilityResponse($request, $pr, $code);
        }

        if (! in_array($pr->status, ['submitted', 'bidding'], true)) {
            if ($request->expectsJson()) {
                return $this->cancelledAvailabilityResponse(
                    $request,
                    $pr,
                    ProcurementAvailabilityException::CODE_PR_UNAVAILABLE,
                    __('supplier.copy.this_requisition_is_not_available_for_quotation'),
                );
            }

            return redirect()->route('supplier.quotations.index')->with('error', __('supplier.copy.this_requisition_is_not_available_for_quotation'));
        }

        $payload = StoreQuotationRequest::validateFor($request, $pr, $this->submissionService);

        try {
            return $this->submissionService->save(
                $request,
                $pr,
                $pr_id,
                $payload['validated'],
                $payload['allUnavailable'],
            );
        } catch (ProcurementAvailabilityException $exception) {
            return $this->cancelledAvailabilityResponse($request, $pr, $exception->errorCode, $exception->getMessage());
        }
    }

    /**
     * Display quotation details.
     */
    public function show($id)
    {
        $quotation = Quotation::with(['items.prItem', 'items.attachments', 'items.award.purchaseOrder', 'purchaseOrders', 'purchaseRequisition.period', 'exchange_rate'])
            ->findOrFail($id);

        Gate::authorize('view', $quotation);

        $conversation = Conversation::where('conversable_type', PurchaseRequisition::class)
            ->where('conversable_id', $quotation->pr_id)
            ->where('supplier_user_id', auth()->id())
            ->first();

        return view('supplier.quotations.show', compact('quotation', 'conversation'));
    }

    private function importableRequisition($prId): PurchaseRequisition
    {
        Gate::authorize('create', Quotation::class);
        $supplier = auth()->user();

        if (! $supplier || ! $supplier->is_active) {
            abort(403, __('supplier.copy.this_supplier_account_is_not_active'));
        }

        $supplierId = (int) $supplier->id;
        $pr = PurchaseRequisition::with([
            'items',
            'allInvitedSuppliers',
            'quotations' => fn ($query) => $query->where('supplier_id', $supplierId),
        ])->findOrFail($prId);

        if (! $pr->isAccessibleBySupplier($supplierId)) {
            abort(403, __('supplier.copy.you_are_not_invited_to_submit_a_quotation_for_this_requisition'));
        }

        if ($pr->isCancelledForSupplier($supplierId)) {
            throw new ProcurementAvailabilityException(
                $pr->isCancelled()
                    ? ProcurementAvailabilityException::CODE_PR_CANCELLED
                    : ProcurementAvailabilityException::CODE_INVITATION_CANCELLED,
                $pr->isCancelled()
                    ? __('supplier_requests.availability.pr_cancelled_toast')
                    : __('supplier_requests.availability.invitation_cancelled_toast'),
            );
        }

        if (! in_array($pr->status, ['submitted', 'bidding'], true)) {
            abort(403, __('supplier.copy.this_requisition_is_not_available_for_quotation_import'));
        }

        $quotation = $pr->quotations->first();

        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            abort(403, __('supplier.copy.this_quotation_can_no_longer_be_imported_or_changed'));
        }
        if ($quotation) {
            Gate::authorize('update', $quotation);
        }

        return $pr;
    }

    private function cancelledAvailabilityResponse(
        Request $request,
        PurchaseRequisition $pr,
        string $code,
        ?string $message = null,
        bool $forceJson = false,
    ): JsonResponse|RedirectResponse {
        $resolvedMessage = $message ?? match ($code) {
            ProcurementAvailabilityException::CODE_PR_CANCELLED => __('supplier_requests.availability.pr_cancelled_toast'),
            ProcurementAvailabilityException::CODE_INVITATION_CANCELLED => __('supplier_requests.availability.invitation_cancelled_toast'),
            default => __('supplier.copy.this_requisition_is_no_longer_available_for_quotation'),
        };

        $isCancelledCode = in_array($code, [
            ProcurementAvailabilityException::CODE_PR_CANCELLED,
            ProcurementAvailabilityException::CODE_INVITATION_CANCELLED,
        ], true);

        $redirectUrl = $isCancelledCode
            ? route('supplier.requests.show', $pr)
            : route('supplier.quotations.index');

        if ($forceJson || $request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'code' => $code,
                'status' => $isCancelledCode ? 'cancelled' : 'closed',
                'message' => $resolvedMessage,
                'redirect' => $redirectUrl,
            ], 409);
        }

        return redirect($redirectUrl)->with($isCancelledCode ? 'warning' : 'error', $resolvedMessage);
    }

    private function importFailurePayload(array $messages): array
    {
        return [
            'success' => false,
            'rows' => [],
            'warnings' => [],
            'summary' => [
                'total' => 0,
                'valid' => 0,
                'invalid' => 0,
            ],
            'errors' => collect($messages)
                ->map(fn (string $message) => [
                    'row' => null,
                    'column' => 'import_file',
                    'message' => $message,
                ])
                ->values()
                ->all(),
        ];
    }
}
