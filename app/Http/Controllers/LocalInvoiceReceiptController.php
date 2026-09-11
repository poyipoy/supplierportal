<?php

namespace App\Http\Controllers;

use App\Models\LocalInvoice;
use Illuminate\Support\Facades\Gate;

class LocalInvoiceReceiptController extends Controller
{
    public function show(LocalInvoice $invoice)
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['receipt', 'supplier.supplier']);
        abort_unless($invoice->receipt, 404);
        Gate::authorize('view', $invoice->receipt);

        return response()->view('local-supplier.invoices.receipt', compact('invoice'))->header('Cache-Control', 'private, no-store');
    }
}
