<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class SupplierPurchaseOrderController extends Controller
{
    /**
     * Supplier: Lihat daftar PO yang diterima (read-only).
     */
    public function index(Request $request)
    {
        $query = PurchaseOrder::with([
            'quotations.purchaseRequirement.period',
            'quotations.exchange_rate',
            'quotations.items',
            'materialClaims',
        ])
            ->where('supplier_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('po_number_display', fn($po) => $po->po_number)
                ->addColumn('period_name', function ($po) {
                    $periods = $po->quotations->map(fn($q) => $q->purchaseRequirement?->period?->name)->filter()->unique();
                    return $periods->count() > 1
                        ? $periods->first() . ' +' . ($periods->count() - 1)
                        : ($periods->first() ?? '-');
                })
                ->addColumn('total_idr', function ($po) {
                    $totalIdr = 0;
                    foreach ($po->quotations as $quotation) {
                        $rate = $quotation->exchange_rate;
                        foreach ($quotation->items as $item) {
                            $totalIdr += $item->amount * ($rate ? $rate->rate_to_idr : 1);
                        }
                    }
                    return 'Rp ' . number_format($totalIdr, 0, ',', '.');
                })
                ->addColumn('status_badge', function ($po) {
                    $badgeClass = match(true) {
                        $po->is_overdue => 'bg-danger',
                        $po->status === 'active' => 'bg-primary',
                        $po->status === 'waiting_qc' => 'bg-warning text-dark',
                        $po->status === 'claim_needed' => 'bg-danger',
                        $po->status === 'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                    $statusLabel = match(true) {
                        $po->is_overdue => 'Overdue',
                        $po->status === 'active' => 'Active',
                        $po->status === 'waiting_qc' => 'Waiting QC',
                        $po->status === 'claim_needed' => 'Claim Needed',
                        $po->status === 'completed' => 'Completed',
                        default => ucwords(str_replace('_', ' ', $po->status)),
                    };
                    return '<span class="badge ' . $badgeClass . ' text-uppercase" style="font-size: 0.7rem;">' . $statusLabel . '</span>';
                })
                ->addColumn('estimated_date', fn($po) => $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-')
                ->addColumn('action', function ($po) {
                    $html = '<div class="d-inline-flex gap-1 justify-content-end flex-wrap">';
                    $pendingClaim = $po->materialClaims->where('status', 'pending')->sortByDesc('created_at')->first();
                    $latestClaim = $po->materialClaims->sortByDesc('created_at')->first();
                    if ($pendingClaim) {
                        $html .= '<a href="' . route('supplier.claims.show', $pendingClaim->id) . '" class="btn btn-sm btn-danger"><i class="bi bi-reply me-1"></i> Respons Klaim</a>';
                    } elseif ($latestClaim) {
                        $html .= '<a href="' . route('supplier.claims.show', $latestClaim->id) . '" class="btn btn-sm btn-outline-danger"><i class="bi bi-exclamation-octagon me-1"></i> Lihat Klaim</a>';
                    }
                    $html .= '<a href="' . route('supplier.purchase-orders.show', $po->id) . '" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i> Detail</a>';
                    $html .= '</div>';
                    return $html;
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('supplier.po.index');
    }

    /**
     * Supplier: Lihat detail PO (read-only).
     */
    public function show($id)
    {
        $supplierId = auth()->id();

        $po = PurchaseOrder::with([
                'supplier',
                'quotations.items.prItem',
                'quotations.purchaseRequirement.period',
                'quotations.exchange_rate',
                'documents',
                'materialClaims' => fn($q) => $q->where('supplier_id', $supplierId)->latest(),
            ])->findOrFail($id);

        // STRICT: only allow if this PO belongs to the logged-in supplier
        if ($po->supplier_id !== $supplierId) {
            abort(403, 'Anda tidak memiliki akses ke Purchase Order ini.');
        }

        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        return view('supplier.po.show', compact('po', 'quotationRates'));
    }
}
