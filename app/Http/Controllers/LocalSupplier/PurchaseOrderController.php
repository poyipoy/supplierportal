<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Services\RegionalDisplayFormatter;
use App\Support\ServerTabsResponse;
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
        $purchaseOrder->setRelation('goodsReceipts', $purchaseOrder->goodsReceipts()->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)->with(['currentInvoice', 'latestGrAttachment'])->orderByDesc('gr_date')->orderByDesc('id')->limit(25)->get());

        return view('local-supplier.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    public function satellite(Request $request, LocalPurchaseOrder $purchaseOrder, RegionalDisplayFormatter $regionalFormatter)
    {
        abort_unless((int) $purchaseOrder->supplier_id === (int) $request->user()->id, 403);
        $this->authorize('view', $purchaseOrder);

        if (! ServerTabsResponse::wants($request)) {
            return redirect()->route('local-supplier.purchase-orders.show', $purchaseOrder);
        }

        $purchaseOrder->load([
            'goodsReceipts' => fn ($query) => $query
                ->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)
                ->with(['currentInvoice', 'latestGrAttachment'])
                ->orderByDesc('gr_date')
                ->orderByDesc('id'),
            'invoices' => fn ($query) => $query
                ->where('supplier_id', $request->user()->id)
                ->latest('invoice_date')
                ->latest('id'),
            'attachments' => fn ($query) => $query->latest('id'),
        ]);

        $allowedTabs = ['goods-receipts', 'invoices', 'documents'];
        $activeTab = (string) $request->query('tab', 'goods-receipts');
        if (! in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'goods-receipts';
        }

        $goodsReceipts = $purchaseOrder->goodsReceipts;
        $invoices = $purchaseOrder->invoices;
        $documents = $purchaseOrder->attachments;

        $counts = [
            'goods-receipts' => $goodsReceipts->count(),
            'invoices' => $invoices->count(),
            'documents' => $documents->count(),
        ];

        $viewData = [
            'purchaseOrder' => $purchaseOrder,
            'routePrefix' => 'local-supplier.purchase-orders',
            'activeTab' => $activeTab,
            'counts' => $counts,
            'goodsReceipts' => $goodsReceipts,
            'invoices' => $invoices,
            'documents' => $documents,
            'regionalFormatter' => $regionalFormatter,
        ];

        return ServerTabsResponse::make(
            html: view('finance.local-procurement._mmt_satellite_content', $viewData)->render(),
            tab: $activeTab,
            request: $request,
            extra: [
                'nav' => view('finance.local-procurement._mmt_satellite_nav', $viewData)->render(),
            ],
        );
    }
}
