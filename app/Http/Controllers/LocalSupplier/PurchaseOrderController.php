<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = LocalPurchaseOrder::where('supplier_id', $request->user()->id)
            ->with(['attachments' => fn ($q) => $q->latest('id')])
            ->withCount(['goodsReceipts as active_gr_count' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)])
            ->withSum(['goodsReceipts as active_gr_qty' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)], 'qty')
            ->withSum(['invoices as invoiced_amount' => fn ($q) => $q->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])], 'invoice_amount');

        if ($request->filled('q')) {
            $query->where('po_number', 'like', '%'.addcslashes($request->string('q'), '%_').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('po_date', '>=', $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('po_date', '<=', $request->string('date_to'));
        }

        $purchaseOrders = $query->latest('po_date')->paginate(15)->withQueryString();

        return view('local-supplier.purchase-orders.index', [
            'purchaseOrders' => $purchaseOrders,
        ]);
    }

    public function show(LocalPurchaseOrder $purchaseOrder)
    {
        abort_unless((int) $purchaseOrder->supplier_id === (int) auth()->id(), 403);
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load([
            'attachments' => fn ($q) => $q->latest('id'),
            'goodsReceipts' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)->orderBy('gr_date'),
            'invoices' => fn ($q) => $q->latest('invoice_date'),
        ]);

        return view('local-supplier.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }
}
