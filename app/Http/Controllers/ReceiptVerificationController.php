<?php

namespace App\Http\Controllers;

use App\Models\GaClaimReceipt;
use App\Models\LocalInvoiceReceipt;
use Illuminate\Http\Request;

class ReceiptVerificationController extends Controller
{
    public function verifySupplier(Request $request, string $receiptNumber)
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('local_invoice.feedback.invalid_receipt'));
        }

        $receipt = LocalInvoiceReceipt::where('receipt_number', $receiptNumber)
            ->with(['invoice.supplier.supplier'])
            ->firstOrFail();

        $invoice = $receipt->invoice;

        return view('receipts.verify-supplier', compact('receipt', 'invoice'));
    }

    public function verifyGa(Request $request, string $receiptNumber)
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('local_invoice.feedback.invalid_receipt'));
        }

        $receipt = GaClaimReceipt::where('receipt_number', $receiptNumber)
            ->with(['claim.employee'])
            ->firstOrFail();

        $claim = $receipt->claim;

        return view('receipts.verify-ga', compact('receipt', 'claim'));
    }
}
