<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;

class SupplierPurchaseOrderController extends Controller
{
    /**
     * Supplier: Lihat daftar PO yang diterima (read-only).
     */
    public function index()
    {
        $purchaseOrders = PurchaseOrder::with([
                'quotation.purchaseRequirement.period',
                'quotation.exchange_rate',
                'quotation.items.prItem',
                'documents'
            ])
            ->whereHas('quotation', fn($q) => $q->where('supplier_id', auth()->id()))
            ->orderBy('created_at', 'desc')
            ->get();

        return view('supplier.po.index', compact('purchaseOrders'));
    }

    /**
     * Supplier: Lihat detail PO (read-only).
     */
    public function show($id)
    {
        $po = PurchaseOrder::with([
                'quotation.supplier',
                'quotation.items.prItem',
                'quotation.purchaseRequirement.period',
                'quotation.exchange_rate',
                'documents'
            ])->findOrFail($id);

        // STRICT: only allow if this PO belongs to the logged-in supplier
        if ($po->quotation->supplier_id !== auth()->id()) {
            abort(403, 'Anda tidak memiliki akses ke Purchase Order ini.');
        }

        $rate = $po->quotation->exchange_rate;

        return view('supplier.po.show', compact('po', 'rate'));
    }
}
