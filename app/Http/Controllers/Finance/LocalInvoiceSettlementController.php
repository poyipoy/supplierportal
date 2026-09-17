<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoiceVoucher;
use App\Models\PaymentItem;
use App\Models\SupplierOverpaymentRefund;
use App\Models\User;
use App\Services\Payment\LocalInvoicePaymentService;
use App\Services\Payment\LocalInvoiceVoucherService;
use App\Services\Payment\SupplierOverpaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocalInvoiceSettlementController extends Controller
{
    public function finalizeVoucher(Request $request, PaymentItem $item, LocalInvoiceVoucherService $service)
    {
        $data = $request->validate(['voucher_date' => ['required', 'date_format:Y-m-d'], 'payment_method' => ['required', Rule::in(['BANK', 'KAS'])], 'remarks' => ['nullable', 'string', 'max:1000']]);
        $voucher = $service->finalize($item, $data, $request->user());
        return redirect()->route('finance.vouchers.show', $voucher)->with('success', 'Voucher Bayar finalized.');
    }

    public function generateVoucher(Request $request, PaymentItem $item, LocalInvoiceVoucherService $service)
    {
        $data = $request->validate([
            'voucher_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(['BANK', 'KAS'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $voucher = $service->finalize($item, $data, $request->user());
        return redirect()->route('finance.vouchers.show', $voucher)->with('success', 'Voucher Bayar berhasil dibuat (Generated).');
    }

    public function showVoucher(LocalInvoiceVoucher $voucher)
    {
        $voucher->load(['invoice.supplier.supplier', 'batch', 'group', 'payment.transfers']);
        return view('finance.vouchers.show', compact('voucher'));
    }

    public function printVoucher(Request $request, LocalInvoiceVoucher $voucher)
    {
        $voucher->load(['invoice.supplier.supplier', 'batch', 'group']);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $voucher->voucher_number).'.pdf';
        $pdf = Pdf::loadView('finance.vouchers.print', compact('voucher'))->setPaper('a4');
        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }
        return $pdf->stream($filename);
    }
    public function primaryPayment(Request $request, LocalInvoiceVoucher $voucher, LocalInvoicePaymentService $service)
    {
        abort(403, 'Pencatatan transfer primer tidak tersedia di halaman ini. Pelunasan primer wajib dilakukan secara terpusat melalui menu DRP Paid.');
    }
    public function correction(Request $request, LocalInvoicePayment $payment, LocalInvoicePaymentService $service)
    {
        $service->recordCorrection($payment, $this->paymentData($request, true), $request->user()); return back()->with('success', 'Corrective transfer recorded.');
    }
    public function overpayments(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([SupplierOverpaymentRefund::STATUS_OPEN, SupplierOverpaymentRefund::STATUS_SETTLED])],
            'supplier_id' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $query = SupplierOverpaymentRefund::with(['supplier.supplier', 'invoice', 'payment.transfers', 'attachments']);
        if (filled($filters['q'] ?? null)) {
            $search = addcslashes((string) $filters['q'], '%_');
            $query->where(function ($q) use ($search) {
                $q->whereHas('invoice', fn ($invoice) => $invoice->where('invoice_number', 'like', "%{$search}%"))
                    ->orWhereHas('payment.transfers', fn ($transfer) => $transfer->where('transfer_reference', 'like', "%{$search}%"));
            });
        }
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);
        if (filled($filters['date_from'] ?? null)) $query->whereDate('created_at', '>=', $filters['date_from']);
        if (filled($filters['date_to'] ?? null)) $query->whereDate('created_at', '<=', $filters['date_to']);
        if (filled($filters['supplier_id'] ?? null)) {
            $value = $filters['supplier_id'];
            abort_unless(is_string($value) && ! ctype_digit($value), 404);
            $supplier = (new User)->resolveRouteBinding($value);
            abort_unless($supplier instanceof User && $supplier->isLocalEligible(), 404);
            $query->where('supplier_id', $supplier->id);
        }
        return view('finance.overpayments.index', [
            'overpayments' => $query->latest()->paginate(25)->withQueryString(),
            'suppliers' => User::localEligible()->with('supplier')->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }
    public function refund(Request $request, SupplierOverpaymentRefund $refund, SupplierOverpaymentService $service)
    {
        $data = $request->validate(['refund_amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'], 'refund_reference' => ['required', 'string', 'max:100'], 'refund_date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:1000'], 'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $service->settle($refund, $data, $request->file('proof'), $request->user()); return back()->with('success', 'Full supplier refund settled.');
    }
    private function paymentData(Request $request, bool $correction = false): array
    {
        return $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'], 'transfer_reference' => ['required', 'string', 'max:100'], 'transfer_date' => ['required', 'date_format:Y-m-d'], 'correction_reason' => [$correction ? 'required' : 'nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:1000']]);
    }
}
