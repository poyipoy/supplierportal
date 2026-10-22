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
            ->with('latestPoAttachment')
            ->withCount(['goodsReceipts as active_gr_count' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)])
            ->withSum(['invoices as invoiced_amount' => fn ($q) => $q->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])], 'invoice_amount');

        if ($request->filled('q')) {
            $search = '%'.addcslashes((string) $request->string('q'), '%_').'%';
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', $search)
                    ->orWhere('pr_number', 'like', $search);
            });
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
            'invoices' => fn ($q) => $q->latest('invoice_date'),
        ]);

        $purchaseOrder->loadCount(['goodsReceipts as active_gr_count' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)]);
        $purchaseOrder->setRelation('goodsReceipts', $purchaseOrder->goodsReceipts()->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)->with('currentInvoice')->orderByDesc('gr_date')->orderByDesc('id')->limit(25)->get());

        return view('local-supplier.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }
}
