<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class SupplierPurchaseOrderController extends Controller
{
    /**
     * Supplier: View accepted POs (read-only).
     */
    public function index(Request $request)
    {
        $supplierId = (int) auth()->id();
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
                'quotations:id,pr_id',
                'quotations.purchaseRequisition:id,period_id,pr_number',
                'quotations.purchaseRequisition.period:id,name,month,year',
            ])
            ->selectSub(
                MaterialClaim::query()
                    ->select('material_claims.id')
                    ->whereColumn('material_claims.po_id', 'purchase_orders.id')
                    ->where('material_claims.supplier_id', $supplierId)
                    ->where('material_claims.status', 'pending')
                    ->latest('material_claims.created_at')
                    ->limit(1),
                'pending_claim_id',
            )
            ->selectSub(
                MaterialClaim::query()
                    ->select('material_claims.id')
                    ->whereColumn('material_claims.po_id', 'purchase_orders.id')
                    ->where('material_claims.supplier_id', $supplierId)
                    ->latest('material_claims.created_at')
                    ->limit(1),
                'latest_claim_id',
            )
            ->where('supplier_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('po_number_display', fn ($po) => $po->po_number)
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
                    $tone = match (true) {
                        $po->is_overdue => 'error',
                        $po->status === 'active' => 'info',
                        $po->status === 'waiting_qc' => 'warning',
                        $po->status === 'claim_needed' => 'error',
                        $po->status === 'completed' => 'success',
                        default => 'neutral'
                    };
                    $statusLabel = match (true) {
                        $po->is_overdue => 'Overdue',
                        $po->status === 'active' => 'Active',
                        $po->status === 'waiting_qc' => 'Waiting QC',
                        $po->status === 'claim_needed' => 'Claim Needed',
                        $po->status === 'completed' => 'Completed',
                        default => ucwords(str_replace('_', ' ', $po->status)),
                    };

                    return '<span class="ui-status-chip ui-status-chip--'.$tone.'">'.e($statusLabel).'</span>';
                })
                ->addColumn('estimated_date', fn ($po) => $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-')
                ->addColumn('action', function ($po) {
                    $html = '<div class="d-inline-flex gap-1 justify-content-end flex-wrap">';
                    if ($po->pending_claim_id) {
                        $html .= '<a href="'.route('supplier.claims.show', Hashids::encode((int) $po->pending_claim_id)).'" class="ui-data-action ui-data-action--danger ui-focus-ring">Claim Response</a>';
                    } elseif ($po->latest_claim_id) {
                        $html .= '<a href="'.route('supplier.claims.show', Hashids::encode((int) $po->latest_claim_id)).'" class="ui-data-action ui-data-action--danger ui-focus-ring">View Claim</a>';
                    }
                    $html .= '<a href="'.route('supplier.purchase-orders.show', $po).'" class="ui-data-action ui-data-action--primary ui-focus-ring">Details</a>';
                    $html .= '</div>';

                    return $html;
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
                ->rawColumns(['status_badge', 'remark_display', 'action'])
                ->ignoreSelectsInCountQuery()
                ->only([
                    'po_number_display',
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

        return view('supplier.po.index');
    }

    /**
     * Supplier: View detail PO (read-only).
     */
    public function show($id)
    {
        $supplierId = auth()->id();

        $po = PurchaseOrder::with([
            'supplier',
            'quotations.items.prItem',
            'quotations.purchaseRequisition.period',
            'quotations.exchange_rate',
            'documents',
            'materialClaims' => fn ($q) => $q->where('supplier_id', $supplierId)->latest(),
        ])->findOrFail($id);

        // STRICT: only allow if this PO belongs to the logged-in supplier
        if ($po->supplier_id !== $supplierId) {
            abort(403, 'You do not have access to this Purchase Order.');
        }

        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        return view('supplier.po.show', compact('po', 'quotationRates'));
    }
}
