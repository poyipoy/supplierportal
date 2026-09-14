<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Models\LocalInvoice;
use App\Services\LocalInvoice\InvoiceQuery;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function dashboard(InvoiceQuery $query)
    {
        return view('accounting.dashboard', $query->dashboard());
    }

    public function index(InvoiceFilterRequest $request, InvoiceQuery $query)
    {
        $filters = $request->validated();
        $physical = $request->routeIs('accounting.physical-verification');
        $payments = $request->routeIs('accounting.payment-schedule');
        if ($physical) {
            $filters['status'] = 'WAITING_PHYSICAL_DOCUMENT';
        }
        $title = $physical ? 'Physical Verification' : ($payments ? 'Payment Schedule' : 'Invoice Register');

        return view('accounting.invoices.index', ['invoices' => $query->filtered($filters, payments: $payments)->latest('id')->paginate(25)->withQueryString(), 'title' => $title, 'payments' => $payments]);
    }

    public function show(LocalInvoice $invoice)
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['supplier.supplier', 'receipt', 'revisions.documents', 'statusHistories.actor', 'physicalVerifications.actor']);
        $workflowActions = [];

        return view('accounting.invoices.show', compact('invoice', 'workflowActions'));
    }
}
