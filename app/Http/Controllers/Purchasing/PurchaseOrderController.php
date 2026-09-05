<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * List all POs.
     */
    public function index(Request $request)
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
                                ->whereDate('estimated_arrival', '<', today())
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
                ->addColumn('total_idr', fn ($po) => 'Rp '.number_format((float) $po->resolved_total_idr, 0, ',', '.'))
                ->addColumn('status_badge', function ($po) {
                    return StatusHelper::badge(
                        StatusHelper::poBadge($po->status, $po->is_overdue),
                        StatusHelper::poLabel($po->status, $po->is_overdue)
                    );
                })
                ->addColumn('estimated_date', function ($po) {
                    $meta = StatusHelper::poArrivalMeta(
                        $po->estimated_arrival,
                        $po->is_overdue,
                        $po->status,
                        $po->actual_arrival
                    );
                    $date = $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-';

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
                    $html .= '<a href="'.PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po).'" class="ui-data-action ui-data-action--primary ui-focus-ring">Details</a>';
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

        $suppliers = User::where('role', 'supplier')->get();

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
            ->with('error', 'New Purchase Orders must be finalized through item-level award selection.');
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
            'New Purchase Orders must be created from valid item-level awards.'
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
        ])->findOrFail($id);

        // Collect rates per quotation for display
        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        // Compute document completion
        $completedStatuses = ['received', 'verified', 'done'];
        $completedDocs = $po->documents->filter(function ($doc) use ($completedStatuses) {
            return in_array($doc->status, $completedStatuses);
        })->count();
        $totalDocs = max($po->documents->count(), 4);
        $allDocsComplete = ($completedDocs >= 4);
        $docProgress = StatusHelper::documentProgressMeta($completedDocs, $totalDocs);

        return view('purchasing.po.show', compact('po', 'quotationRates', 'completedDocs', 'totalDocs', 'allDocsComplete', 'docProgress'));
    }

    /**
     * Confirm material arrived.
     */
    public function confirmArrival(Request $request, $id)
    {
        $po = PurchaseOrder::findOrFail($id);

        if (! in_array($po->status, ['active', 'overdue'])) {
            return redirect()->route('purchasing.purchase-orders.show', $po)
                ->with('error', 'Material arrival can only be confirmed for Active or Overdue PO records.');
        }

        $po->update([
            'actual_arrival' => now()->toDateString(),
            'status' => 'waiting_qc',
        ]);

        // Notify all QC users: material arrived
        $qcUsers = User::where('role', 'qc')->where('is_active', true)->get();
        $this->notifications->send(
            $qcUsers,
            'po.material_arrived',
            "po.material_arrived:{$po->id}",
            'Material Arrived - Ready for Inspection',
            "Material from PO {$po->po_number} has arrived. Please perform QC inspection.",
            route('qc.inspections.create', $po, absolute: false),
            'package text-warning',
            [
                'category' => NotificationCategory::OTHER,
                'po_id' => $po->id,
                'po_number' => $po->po_number,
            ],
        );

        return redirect()->route('purchasing.purchase-orders.show', $po)
            ->with('success', 'Material arrival confirmed. QC will be notified for inspection.');
    }
}
