<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Http\Requests\LocalInvoice\ResubmitLocalInvoiceRequest;
use App\Http\Requests\LocalInvoice\StoreLocalInvoiceRequest;
use App\Models\LocalInvoice;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use Illuminate\Support\Facades\Gate;

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

    public function create()
    {
        Gate::authorize('create', LocalInvoice::class);

        return view('local-supplier.invoices.create');
    }

    public function store(StoreLocalInvoiceRequest $request, InvoiceSubmissionService $service)
    {
        $invoice = $service->submit($request->user(), $request->validated(), $request->allFiles());

        return redirect()->route('local-supplier.invoices.show', $invoice)->with('success', 'Invoice submitted. Print the receipt and deliver the physical documents.');
    }

    public function show(LocalInvoice $invoice)
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['supplier.supplier', 'receipt', 'revisions.documents', 'statusHistories.actor', 'physicalVerifications.actor']);

        return view('local-supplier.invoices.show', compact('invoice'));
    }

    public function revision(LocalInvoice $invoice)
    {
        Gate::authorize('resubmit', $invoice);
        abort_unless($invoice->status === 'NEED_REVISION', 409);
        $invoice->load('statusHistories');

        return view('local-supplier.invoices.revision', compact('invoice'));
    }

    public function resubmit(ResubmitLocalInvoiceRequest $request, LocalInvoice $invoice, InvoiceSubmissionService $service)
    {
        $service->resubmit($request->user(), $invoice, $request->validated(), $request->allFiles());

        return redirect()->route('local-supplier.invoices.show', $invoice)->with('success', 'Revision submitted. Updated physical documents must be verified.');
    }
}
