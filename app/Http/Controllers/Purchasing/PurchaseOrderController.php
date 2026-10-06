<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Services\MaterialProgressService;
use App\Services\NotificationService;
use App\Services\RegionalDisplayFormatter;
use App\Support\BusinessTime;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * List all POs.
     */
    public function index(Request $request, RegionalDisplayFormatter $regionalFormatter)
    {
        $supplierFilter = $this->resolveSupplierFilter($request->query('supplier_id'));

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
                    ->latest('qc_inspections.inspected_at')
                    ->limit(1),
                'latest_ng_inspection_id',
            )
            ->orderBy('created_at', 'desc');

        if ($request->filled('po_number')) {
            $query->where('po_number', 'like', '%'.trim($request->po_number).'%');
        }

        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->where(function ($q) {
                    $q->where('status', 'overdue')
                        ->orWhere(function ($q) {
                            $q->where('status', 'active')
                                ->whereNotNull('estimated_arrival')
                                ->whereDate('estimated_arrival', '<', BusinessTime::today()->toDateString())
                                ->whereNull('actual_arrival');
                        });
                });
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($supplierFilter) {
            $query->where('supplier_id', $supplierFilter->getKey());
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
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
                ->addColumn('total_idr', fn ($po) => 'Rp '.$regionalFormatter->number(number_format((float) $po->resolved_total_idr, 0, ',', '.'), 'indonesian'))
                ->addColumn('status_badge', function ($po) {
                    return StatusHelper::badge(
                        StatusHelper::poBadge($po->status, $po->is_overdue),
                        StatusHelper::poLabel($po->status, $po->is_overdue)
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
                            $html .= '<a href="'.PurchasingNavigation::toRoute('purchasing.claims.show', Hashids::encode((int) $po->active_claim_id)).'" class="ui-data-action ui-data-action--danger ui-focus-ring">Claim</a>';
                        } elseif ($po->latest_ng_inspection_id) {
                            $html .= '<a href="'.PurchasingNavigation::toRoute('purchasing.claims.create', Hashids::encode((int) $po->latest_ng_inspection_id)).'" class="ui-data-action ui-data-action--danger ui-focus-ring">Create Claim</a>';
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
                ->make(true);
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
            'supplier',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.purchaseRequisition.period',
            'quotations.exchange_rate',
            'documents',
            'creator',
            'qcInspections.inspector',
            'qcInspections.items.prItem',
            'qcInspections.attachments',
            'materialClaims',
            'awards.latestProgressUpdate.updatedByUser',
            'awards.prItem',
            'awards.quotation',
            'awards.quotationItem',
            'shipmentItems.shipment.documents.latestAttachment',
        ])->findOrFail($id);

        // Collect rates per quotation for display
        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        // Material progress projections and summary
        $progressService = app(MaterialProgressService::class);
        $poProgressSummary = $progressService->poSummary($po);
        $itemProjections = collect($poProgressSummary['items'] ?? []);

        // Customs summary & synchronized documents. Reconciliation is explicit
        // now that customsDocumentationSummary() is a pure read.
        $po->reconcileCustomsDocumentationStatus();
        $customsSummary = $po->customsDocumentationSummary();

        // Compute document completion based on synchronized effective status
        $completedStatuses = ['received', 'verified', 'done'];
        $completedDocs = collect($customsSummary)->filter(function ($item) use ($completedStatuses) {
            return in_array($item['status'], $completedStatuses, true);
        })->count();
        $totalDocs = max(count($customsSummary), 4);
        $allDocsComplete = ($completedDocs >= 4);
        $docProgress = StatusHelper::documentProgressMeta($completedDocs, $totalDocs);

        return view('purchasing.po.show', compact('po', 'quotationRates', 'completedDocs', 'totalDocs', 'allDocsComplete', 'docProgress', 'itemProjections', 'poProgressSummary', 'customsSummary'));
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

            $po->update([
                'actual_arrival' => BusinessTime::today()->toDateString(),
                'status' => 'waiting_qc',
            ]);

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
}
