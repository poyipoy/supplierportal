<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Http\Requests\LocalInvoice\ResubmitLocalInvoiceRequest;
use App\Http\Requests\LocalInvoice\StoreLocalInvoiceRequest;
use App\Models\LocalInvoice;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class InvoiceController extends Controller
{
    public function dashboard(InvoiceQuery $query)
    {
        return view('local-supplier.dashboard', $query->dashboard(auth()->id()));
    }

    public function index(InvoiceFilterRequest $request, InvoiceQuery $query)
    {
        return view('local-supplier.invoices.index', ['invoices' => $query->filtered($request->validated(), $request->user()->id)->latest('id')->paginate(25)->withQueryString()]);
    }

    public function searchPurchaseOrders(Request $request, LocalPoReferenceService $poReferenceService): JsonResponse
    {
        Gate::authorize('create', LocalInvoice::class);

        $query = (string) $request->input('q', '');
        $authoritative = Schema::hasColumn('local_invoices', 'local_purchase_order_id')
            && Schema::hasColumn('local_goods_receipts', 'status')
            && LocalPurchaseOrder::where('supplier_id', $request->user()->id)->exists();
        if ($authoritative) {
            $orders = LocalPurchaseOrder::where('supplier_id', $request->user()->id)
                ->where('status', LocalPurchaseOrder::STATUS_OPEN)
                ->whereHas('goodsReceipts', fn ($q) => $q->where('status', LocalGoodsReceipt::STATUS_AVAILABLE))
                ->when($query !== '', fn ($q) => $q->where('po_number', 'like', '%'.addcslashes($query, '%_').'%'))
                ->with(['goodsReceipts' => fn ($q) => $q->where('status', LocalGoodsReceipt::STATUS_AVAILABLE)->orderBy('gr_date')])
                ->latest('id')->limit(10)->get();

            return response()->json(['data' => $orders->map(fn (LocalPurchaseOrder $po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'description' => $po->description,
                'total_amount' => (float) $po->total_amount,
                'formatted_total_amount' => 'Rp '.number_format($po->total_amount, 0, ',', '.'),
                'remaining_amount' => (float) $po->goodsReceipts->sum('received_amount'),
                'formatted_remaining_amount' => 'Rp '.number_format($po->goodsReceipts->sum('received_amount'), 0, ',', '.'),
                'has_gr' => true,
                'gr_reference' => $po->goodsReceipts->pluck('gr_number')->implode(', '),
                'status' => $po->status,
                'goods_receipts' => $po->goodsReceipts->map(fn (LocalGoodsReceipt $gr) => [
                    'id' => $gr->id, 'gr_number' => $gr->gr_number, 'gr_date' => $gr->gr_date?->format('Y-m-d'), 'received_amount' => (float) $gr->received_amount,
                ])->values(),
            ])->values()]);
        }
        $results = $poReferenceService->searchInternalPos($request->user(), $query, 10);

        return response()->json([
            'data' => $results,
        ]);
    }

    public function create()
    {
        Gate::authorize('create', LocalInvoice::class);

        return view('local-supplier.invoices.create', ['purchaseOrders' => $this->eligiblePurchaseOrders()]);
    }

    public function store(StoreLocalInvoiceRequest $request, InvoiceSubmissionService $service)
    {
        $invoice = $service->submit($request->user(), $request->validated(), $request->allFiles());

        return redirect()->route('local-supplier.invoices.receipt', $invoice)->with('success', 'Invoice berhasil disubmit. Silakan cetak tanda terima dan serahkan dokumen fisik ke loket Kasir.');
    }

    public function show(LocalInvoice $invoice)
    {
        Gate::authorize('view', $invoice);
        $invoice->load([
            'supplier.supplier',
            'receipt',
            'revisions.documents',
            'statusHistories.actor',
            'physicalVerifications.actor',
            'localPurchaseOrder',
            'goodsReceiptHistories.goodsReceipt',
            'voucher',
            'payment.transfers',
            'payment.overpayment',
        ]);

        return view('local-supplier.invoices.show', compact('invoice'));
    }

    public function revision(LocalInvoice $invoice)
    {
        Gate::authorize('resubmit', $invoice);
        abort_unless($invoice->status === 'NEED_REVISION', 409);
        $invoice->load(['statusHistories', 'goodsReceiptHistories.goodsReceipt']);

        return view('local-supplier.invoices.revision', ['invoice' => $invoice, 'purchaseOrders' => $this->eligiblePurchaseOrders($invoice)]);
    }

    public function resubmit(ResubmitLocalInvoiceRequest $request, LocalInvoice $invoice, InvoiceSubmissionService $service)
    {
        $service->resubmit($request->user(), $invoice, $request->validated(), $request->allFiles());

        return redirect()->route('local-supplier.invoices.receipt', $invoice)->with('success', 'Revisi invoice berhasil dikirim. Dokumen fisik terbaru wajib diverifikasi kembali.');
    }

    public function cancel(Request $request, LocalInvoice $invoice, InvoiceSubmissionService $service)
    {
        $service->cancel($request->user(), $invoice);
        return redirect()->route('local-supplier.invoices.index')->with('success', 'Invoice berhasil dibatalkan dan reservasi GR telah dilepas kembali.');
    }

    private function eligiblePurchaseOrders(?LocalInvoice $invoice = null)
    {
        if (! Schema::hasColumn('local_invoices', 'local_purchase_order_id') || ! Schema::hasColumn('local_goods_receipts', 'status')) {
            return collect();
        }

        return LocalPurchaseOrder::where('supplier_id', auth()->id())
            ->where(function ($q) use ($invoice) {
                $q->where('status', LocalPurchaseOrder::STATUS_OPEN);
                if ($invoice?->local_purchase_order_id) $q->orWhereKey($invoice->local_purchase_order_id);
            })
            ->whereHas('goodsReceipts', fn ($q) => $q->where('status', LocalGoodsReceipt::STATUS_AVAILABLE)
                ->when($invoice, fn ($q) => $q->orWhere('current_invoice_id', $invoice->id)))
            ->with(['goodsReceipts' => fn ($q) => $q->where('status', LocalGoodsReceipt::STATUS_AVAILABLE)
                ->when($invoice, fn ($q) => $q->orWhere('current_invoice_id', $invoice->id))->orderBy('gr_date')])
            ->orderByDesc('po_date')->get();
    }
}
