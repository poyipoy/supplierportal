<?php

namespace App\Http\Controllers;

use App\Models\LocalInvoice;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class LocalInvoiceReceiptController extends Controller
{
    public function show(LocalInvoice $invoice)
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['receipt', 'supplier.supplier']);
        abort_unless($invoice->receipt, 404);
        Gate::authorize('view', $invoice->receipt);

        $signedUrl = URL::signedRoute('receipts.verify-supplier', ['receipt' => $invoice->receipt->receipt_number]);
        $qrCode = (new QRCode([
            'outputBase64' => true,
            'scale' => 4,
        ]))->render($signedUrl);

        return response()->view('local-supplier.invoices.receipt', compact('invoice', 'qrCode', 'signedUrl'))->header('Cache-Control', 'private, no-store');
    }
}
