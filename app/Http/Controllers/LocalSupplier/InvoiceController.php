<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Http\Requests\LocalInvoice\ResubmitLocalInvoiceRequest;
use App\Http\Requests\LocalInvoice\StoreLocalInvoiceRequest;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use App\Services\SupplierAudit\SupplierAuditInvoiceGate;
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

    public function searchPurchaseOrders(Request $request, LocalPoReferenceService $poReferenceService, SupplierAuditInvoiceGate $auditGate): JsonResponse
    {
        Gate::authorize('create', LocalInvoice::class);
        abort_if($auditGate->blockingAudit($request->user()) !== null, 403, __('supplier_audit.invoice_block.short'));

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

            return response()->json(['data' => $orders->map(function (LocalPurchaseOrder $po) {
                $previouslyInvoiced = (float) LocalInvoice::where('local_purchase_order_id', $po->id)
                    ->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])
                    ->sum('invoice_amount');
                $remainingAmount = max(0.0, round((float) $po->total_amount - $previouslyInvoiced, 2));

                return [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'description' => $po->description,
                    'total_amount' => (float) $po->total_amount,
                    'formatted_total_amount' => 'Rp '.number_format($po->total_amount, 0, ',', '.'),
                    'remaining_amount' => $remainingAmount,
                    'formatted_remaining_amount' => 'Rp '.number_format($remainingAmount, 0, ',', '.'),
                    'has_gr' => true,
                    'gr_reference' => $po->goodsReceipts->pluck('gr_number')->implode(', '),
                    'status' => $po->status,
                    'goods_receipts' => $po->goodsReceipts->map(fn (LocalGoodsReceipt $gr) => [
                        'id' => $gr->id, 'gr_number' => $gr->gr_number, 'gr_date' => $gr->gr_date?->format('Y-m-d'), 'qty' => (float) $gr->qty, 'uom' => $gr->uom,
                    ])->values(),
                ];
            })->values()]);
        }
        $results = $poReferenceService->searchInternalPos($request->user(), $query, 10);

        return response()->json([
            'data' => $results,
        ]);
    }

    public function create(SupplierAuditInvoiceGate $auditGate)
    {
        Gate::authorize('create', LocalInvoice::class);

        // D13: arahkan ke form audit yang terlambat; pengajuan invoice baru dibuka lagi setelah submit.
        if ($blockingAudit = $auditGate->blockingAudit(auth()->user())) {
            return redirect()->route('local-supplier.supplier-audits.edit', $blockingAudit)
                ->with('warning', $auditGate->message($blockingAudit));
        }

        return view('local-supplier.invoices.create', ['purchaseOrders' => $this->eligiblePurchaseOrders()]);
    }

    public function store(StoreLocalInvoiceRequest $request, InvoiceSubmissionService $service)
    {
        $invoice = $service->submit($request->user(), $request->validated(), $request->allFiles());

        return $this->submittedResponse($request, $invoice, __('local_invoice.feedback.submitted'));
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

        return $this->submittedResponse($request, $invoice, __('local_invoice.feedback.resubmitted'));
    }

    /**
     * Async (file-preserving) submits receive a JSON redirect; classic posts keep the redirect.
     */
    private function submittedResponse(Request $request, LocalInvoice $invoice, string $message)
    {
        $url = route('local-supplier.invoices.receipt', $invoice);

        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['redirect' => $url]);
        }

        return redirect()->to($url)->with('success', $message);
    }

    public function cancel(Request $request, LocalInvoice $invoice, InvoiceSubmissionService $service)
    {
        $service->cancel($request->user(), $invoice);

        return redirect()->route('local-supplier.invoices.index')->with('success', __('local_invoice.feedback.cancelled'));
    }

    private function eligiblePurchaseOrders(?LocalInvoice $invoice = null)
    {
        if (! Schema::hasColumn('local_invoices', 'local_purchase_order_id') || ! Schema::hasColumn('local_goods_receipts', 'status')) {
            return collect();
        }

        return LocalPurchaseOrder::where('supplier_id', auth()->id())
            ->where(function ($q) use ($invoice) {
                $q->where('status', LocalPurchaseOrder::STATUS_OPEN);
                if ($invoice?->local_purchase_order_id) {
                    $q->orWhereKey($invoice->local_purchase_order_id);
                }
            })
            ->whereHas('goodsReceipts', fn ($q) => $q->where('status', LocalGoodsReceipt::STATUS_AVAILABLE)
                ->when($invoice, fn ($q) => $q->orWhere('current_invoice_id', $invoice->id)))
            ->with([
                'goodsReceipts' => fn ($q) => $q->where('status', LocalGoodsReceipt::STATUS_AVAILABLE)
                    ->when($invoice, fn ($q) => $q->orWhere('current_invoice_id', $invoice->id))->orderBy('gr_date'),
                'invoices' => fn ($q) => $q->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED]),
            ])
            ->orderByDesc('po_date')->get();
    }
}
