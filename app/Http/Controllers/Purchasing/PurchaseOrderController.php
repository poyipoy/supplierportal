<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleArgumentException;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\MaterialProgressService;
use App\Services\NotificationService;
use App\Services\Procurement\PoAcknowledgementService;
use App\Services\Procurement\PurchaseOrderStateEngine;
use App\Services\RegionalDisplayFormatter;
use App\Support\BusinessTime;
use App\Support\HashidCodec;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use App\Support\ServerTabsResponse;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * List all POs.
     */
    public function index(Request $request, RegionalDisplayFormatter $regionalFormatter)
    {
        $filters = PurchaseOrderExportFilters::validated($request, index: true);

        $query = PurchaseOrder::query()
            ->select([
                'purchase_orders.id',
                'purchase_orders.supplier_id',
                'purchase_orders.po_number',
                'purchase_orders.status',
                'purchase_orders.estimated_arrival',
                'purchase_orders.actual_arrival',
                'purchase_orders.notes',
                'purchase_orders.created_at',
            ])
            ->withResolvedTotalIdr()
            ->with([
                'supplier:id,name',
                'quotations:id,pr_id',
                'quotations.purchaseRequisition:id,period_id,pr_number',
                'quotations.purchaseRequisition.period:id,name,month,year',
            ])
            ->selectSub(
                MaterialClaim::query()
                    ->select('material_claims.id')
                    ->whereColumn('material_claims.po_id', 'purchase_orders.id')
                    ->whereIn('material_claims.status', ['pending', 'responded', 'escalated'])
                    ->latest('material_claims.created_at')
                    ->limit(1),
                'active_claim_id',
            )
            ->selectSub(
                QcInspection::query()
                    ->select('qc_inspections.id')
                    ->whereColumn('qc_inspections.po_id', 'purchase_orders.id')
                    ->where('qc_inspections.status', 'ng')
                    // PUR-08: only an NG inspection without any claim (soft-deleted ones included)
                    // can open a claim; claims.store rejects the others.
                    ->whereNotExists(fn ($claims) => $claims->selectRaw('1')
                        ->from('material_claims')
                        ->whereColumn('material_claims.inspection_id', 'qc_inspections.id'))
                    ->latest('qc_inspections.inspected_at')
                    ->limit(1),
                'latest_ng_inspection_id',
            )
            ->orderBy('created_at', 'desc');

        PurchaseOrderExportFilters::apply($query, $filters);
        // PUR-08: is_overdue reads this flag instead of querying per row.
        $query->withExists('shipmentItems as has_shipment_items');
        // E7: Distinguish Active POs with vs without an active/arrived shipment.
        $query->withExists([
            'shipmentItems as has_active_shipments' => fn ($q) => $q->whereHas(
                'shipment',
                fn ($s) => $s->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED])
            ),
        ]);

        if ($request->ajax()) {
            $payload = DataTables::eloquent($query)
                ->filter(fn ($query) => null)
                ->setRowId(fn (PurchaseOrder $po): string => $po->getRouteKey())
                ->setRowAttr([
                    'data-mmt-row-url' => fn (PurchaseOrder $po): string => route('purchasing.purchase-orders.satellite', $po, false),
                    'data-mmt-row-ref' => fn (PurchaseOrder $po): string => (string) $po->po_number,
                    'tabindex' => '0',
                    'role' => 'row',
                    'aria-selected' => 'false',
                ])
                ->addColumn('po_number_display', fn ($po) => $po->po_number)
                ->addColumn('supplier_name', fn ($po) => $po->supplier->name ?? '-')
                ->addColumn('period_name', function ($po) {
                    $periods = $po->quotations->map(fn ($q) => $q->purchaseRequisition?->period?->display_label)->filter()->unique();

                    return $periods->count() > 1
                        ? $periods->first().' +'.($periods->count() - 1)
                        : ($periods->first() ?? '-');
                })
                ->addColumn('pr_reference', fn ($po) => e($po->pr_reference))
                ->addColumn('remark_display', function ($po) {
                    $notes = trim((string) $po->notes);

                    if ($notes === '') {
                        return '-';
                    }

                    $preview = Str::limit((string) preg_replace('/\s+/u', ' ', $notes), 40);

                    return '<span title="'.e($notes).'">'.e($preview).'</span>';
                })
                // Q4C: a foreign PO without a snapshot rate has no IDR total.
                ->addColumn('total_idr', fn ($po) => $po->resolved_total_idr === null
                    ? '— ('.__('purchasing.copy.exchange_rate_unavailable').')'
                    : 'Rp '.$regionalFormatter->number(number_format((float) $po->resolved_total_idr, 0, ',', '.'), 'indonesian'))
                ->addColumn('status_badge', function ($po) {
                    return StatusHelper::poStatusChip(
                        $po->status,
                        $po->is_overdue,
                        $po->has_active_shipments
                    );
                })
                ->addColumn('estimated_date', function ($po) use ($regionalFormatter) {
                    $meta = StatusHelper::poArrivalMeta(
                        $po->estimated_arrival,
                        $po->is_overdue,
                        $po->status,
                        $po->actual_arrival
                    );
                    $date = $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival, 'human') : '-';

                    return '<div class="d-flex flex-column align-items-start gap-1">'
                        .'<span>'.e($date).'</span>'
                        .StatusHelper::badgeWithTooltip($meta['class'], $meta['label'], $meta['description'])
                        .'</div>';
                })
                ->addColumn('action', function ($po) {
                    $html = '<div class="d-inline-flex gap-1 justify-content-end flex-wrap">';
                    if ($po->status === 'claim_needed') {
                        if ($po->active_claim_id) {
                            $html .= '<a href="'.PurchasingNavigation::toRoute('purchasing.claims.show', HashidCodec::encode((int) $po->active_claim_id)).'" class="ui-data-action ui-data-action--danger ui-focus-ring">Claim</a>';
                        } elseif ($po->latest_ng_inspection_id) {
                            $html .= '<a href="'.PurchasingNavigation::toRoute('purchasing.claims.create', HashidCodec::encode((int) $po->latest_ng_inspection_id)).'" class="ui-data-action ui-data-action--danger ui-focus-ring">Create Claim</a>';
                        }
                    }
                    $html .= '<a href="'.PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po).'" class="ui-data-action ui-data-action--primary ui-focus-ring">'.e(__('purchasing.copy.details')).'</a>';
                    $html .= '</div>';

                    return $html;
                })
                ->filterColumn('supplier_name', function ($query, $keyword) {
                    $query->whereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', '%'.$keyword.'%'));
                })
                ->filterColumn('period_name', function ($query, $keyword) {
                    $query->whereHas('quotations.purchaseRequisition.period', fn ($periodQuery) => $periodQuery->where('name', 'like', '%'.$keyword.'%'));
                })
                ->filterColumn('pr_reference', function ($query, $keyword) {
                    $query->wherePrReferenceContains($keyword);
                })
                ->filterColumn('remark_display', function ($query, $keyword) {
                    $query->where('notes', 'like', '%'.$keyword.'%');
                })
                ->rawColumns(['status_badge', 'estimated_date', 'remark_display', 'action'])
                ->ignoreSelectsInCountQuery()
                ->only([
                    'po_number_display',
                    'supplier_name',
                    'period_name',
                    'pr_reference',
                    'remark_display',
                    'total_idr',
                    'status_badge',
                    'estimated_date',
                    'action',
                ])
                ->toArray();

            PurchasingNavigation::rememberRecordWindowFromDataTable('purchasing.purchase-orders.index', $payload, $request);

            return response()->json($payload);
        }

        $suppliers = User::importEligible()
            ->orWhereIn('id', DB::table('purchase_orders')->distinct()->pluck('supplier_id'))
            ->get();

        return view('purchasing.po.index', compact('suppliers'));
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
     * Redirect the retired quotation-level PO entry point to item-level awards.
     */
    public function create($quotation_id)
    {
        $quotation = Quotation::with('purchaseRequisition')->findOrFail($quotation_id);

        return redirect()->route('purchasing.comparison.show', $quotation->purchaseRequisition)
            ->with('error', __('purchasing.copy.new_purchase_orders_must_be_finalized_through_item_offer_selection'));
    }

    /**
     * Reject the retired quotation-level PO write contract.
     */
    public function store(Request $request)
    {
        $request->validate([
            'quotation_ids' => 'required|array|min:1',
            'quotation_ids.*' => 'required|integer|distinct|exists:quotations,id',
            'estimated_arrival' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        return back()->withInput()->with(
            'error',
            __('purchasing.copy.new_purchase_orders_must_be_created_from_valid_item_offer_selections')
        );
    }

    /**
     * PO details: Info, Documents, Timeline.
     */
    public function show($id)
    {
        $po = PurchaseOrder::with([
            'supplier.supplier',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.purchaseRequisition.period',
            'quotations.exchange_rate',
            'documents',
            'creator',
            'qcInspections.inspector',
            'qcInspections.items.prItem',
            'qcInspections.attachments',
            'qcInspections.materialClaims' => fn ($claims) => $claims->withTrashed(),
            'materialClaims',
            'awards.latestProgressUpdate.updatedByUser',
            'awards.prItem',
            'awards.quotation',
            'awards.quotationItem',
            'shipmentItems.shipment.documents.latestAttachment.fileInspection',
            'shipmentItems.shipment.items.purchaseOrder:id,po_number',
            'shipmentItems.shipment.mtcAttachments',
            'referencePriceSnapshots.award.prItem',
            'supplierSalesContractAttachment',
            'purchasingSalesContractAttachment',
        ])->findOrFail($id);

        // Collect rates per quotation for display
        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        // Material progress projections and summary
        $progressService = app(MaterialProgressService::class);
        $poProgressSummary = $progressService->poSummary($po);
        $itemProjections = collect($poProgressSummary['items'] ?? []);

        // Customs summary derives the PO-level status from active shipment
        // documents on read; po_documents is refreshed by every write path.
        $customsSummary = $po->customsDocumentationSummary();
        $customsProgress = $po->customsDocumentationProgress();

        // Compute document completion based on synchronized effective status
        $completedStatuses = ['received', 'verified', 'done'];
        $completedDocs = collect($customsSummary)->filter(function ($item) use ($completedStatuses) {
            return in_array($item['status'], $completedStatuses, true);
        })->count();
        $totalDocs = max(count($customsSummary), 4);
        $allDocsComplete = ($completedDocs >= 4);
        $docProgress = StatusHelper::documentProgressMeta($completedDocs, $totalDocs);
        $recordNav = PurchasingNavigation::recordWindowNavigation(
            'purchasing.purchase-orders.index',
            'purchasing.purchase-orders.show',
            $po->getRouteKey(),
        );

        return view('purchasing.po.show', compact('po', 'quotationRates', 'completedDocs', 'totalDocs', 'allDocsComplete', 'docProgress', 'itemProjections', 'poProgressSummary', 'customsSummary', 'customsProgress', 'recordNav'));
    }

    /**
     * Render the Master-Satellite preview tabs for a single Purchase Order.
     */
    public function satellite(Request $request, $id, RegionalDisplayFormatter $regionalFormatter)
    {
        $po = PurchaseOrder::query()
            ->with([
                'supplier:id,name',
                'awards.prItem',
                'awards.quotation',
                'awards.quotationItem',
                'quotations.items.prItem',
                'quotations.purchaseRequisition:id,pr_number,period_id',
                'quotations.exchange_rate',
                'shipmentItems.shipment',
                'materialClaims',
            ])
            ->findOrFail($id);

        Gate::authorize('view', $po);

        if (! ServerTabsResponse::wants($request)) {
            return redirect()->route('purchasing.purchase-orders.show', $po);
        }

        $orderedLines = $po->orderedLines();
        $shipments = $po->shipments();
        $claims = $po->materialClaims->sortByDesc('created_at')->values();

        $allowedTabs = ['items', 'shipments', 'claims'];
        $activeTab = (string) $request->query('tab', 'items');
        if (! in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'items';
        }

        $counts = [
            'items' => $orderedLines->count(),
            'shipments' => $shipments->count(),
            'claims' => $claims->count(),
        ];

        $viewData = [
            'po' => $po,
            'activeTab' => $activeTab,
            'counts' => $counts,
            'orderedLines' => $orderedLines,
            'shipments' => $shipments,
            'claims' => $claims,
            'regionalFormatter' => $regionalFormatter,
        ];

        return ServerTabsResponse::make(
            html: view('purchasing.po._mmt_satellite_content', $viewData)->render(),
            tab: $activeTab,
            request: $request,
            extra: [
                'nav' => view('purchasing.po._mmt_satellite_nav', $viewData)->render(),
            ],
        );
    }

    /**
     * Confirm material arrived.
     */
    public function confirmArrival(Request $request, $id)
    {
        $result = DB::transaction(function () use ($id) {
            $po = PurchaseOrder::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $po->isLegacyArrivalEligible()) {
                return [
                    'po' => $po,
                    'error' => __('purchasing.copy.this_purchase_order_uses_shipment_based_receiving_confirm_physical_arrival_from_the_relevant_shipmen'),
                ];
            }

            if (! in_array($po->status, ['active', 'overdue'], true)) {
                return [
                    'po' => $po,
                    'error' => __('purchasing.copy.material_arrival_can_only_be_confirmed_for_active_or_overdue_po_records'),
                ];
            }

            $po = app(PurchaseOrderStateEngine::class)->transition(
                model: $po,
                target: PurchaseOrderStatus::WAITING_QC,
                actor: auth()->user(),
                metadata: [
                    'attributes' => [
                        'actual_arrival' => BusinessTime::today()->toDateString(),
                    ],
                ]
            );

            return ['po' => $po->fresh(), 'error' => null];
        });

        $po = $result['po'];
        if ($result['error']) {
            return redirect()->route('purchasing.purchase-orders.show', $po)
                ->with('error', $result['error']);
        }

        // Notify all QC users: material arrived
        $qcUsers = User::where('role', 'qc')->where('is_active', true)->get();
        $this->notifications->send(
            $qcUsers,
            'po.material_arrived',
            "po.material_arrived:{$po->id}",
            'purchasing.copy.material_arrived_ready_for_inspection',
            'purchasing.notify.arrived_body',
            route('qc.inspections.create', $po, absolute: false),
            'package text-warning',
            [
                'category' => NotificationCategory::OTHER,
                'po_id' => $po->id,
                'po_number' => $po->po_number,
            ],
            ['po' => $po->po_number],
        );

        return redirect()->route('purchasing.purchase-orders.show', $po)
            ->with('success', __('purchasing.copy.material_arrival_confirmed_qc_will_be_notified_for_inspection'));
    }

    public function respondPromiseChange(Request $request, $id, PoAcknowledgementService $acknowledgementService)
    {
        $po = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accept,reject'],
            'response_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updatedPo = $acknowledgementService->respondImportPromiseChange($po, $request->user(), $validated);
        } catch (BusinessRuleArgumentException|BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = $validated['decision'] === 'accept'
            ? __('purchasing.po_ack.messages.change_accepted')
            : __('purchasing.po_ack.messages.change_rejected');

        return redirect()->route('purchasing.purchase-orders.show', $updatedPo)
            ->with('success', $message);
    }

    public function uploadSignedSalesContract(Request $request, $id, PoAcknowledgementService $acknowledgementService)
    {
        $po = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'signed_sales_contract_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'sales_contract_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updatedPo = $acknowledgementService->uploadPurchasingSignedSalesContract(
                $po,
                $request->file('signed_sales_contract_file'),
                $request->user(),
                $validated['sales_contract_notes'] ?? null
            );
        } catch (BusinessRuleArgumentException|BusinessRuleException|\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('purchasing.copy.sales_contract_status_purchasing_signed'));

            return response()->json(['redirect' => route('purchasing.purchase-orders.show', $updatedPo)]);
        }

        return redirect()->route('purchasing.purchase-orders.show', $updatedPo)
            ->with('success', __('purchasing.copy.sales_contract_status_purchasing_signed'));
    }

    public function requestSalesContractRevision(Request $request, $id, PoAcknowledgementService $acknowledgementService)
    {
        $po = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'sales_contract_notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $updatedPo = $acknowledgementService->requestSalesContractRevision(
                $po,
                $request->user(),
                $validated['sales_contract_notes']
            );
        } catch (BusinessRuleArgumentException|BusinessRuleException|\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchasing.purchase-orders.show', $updatedPo)
            ->with('success', __('purchasing.copy.sales_contract_status_revision_requested'));
    }
}
