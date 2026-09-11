<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\LocalInvoicesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Support\ExportDispatcher;

class ReportController extends Controller
{
    public function index(InvoiceFilterRequest $request)
    {
        return view('accounting.reports.index', ['payments' => true]);
    }

    public function export(InvoiceFilterRequest $request)
    {
        $request->validate(['report' => 'required|in:register,payments']);
        ExportDispatcher::dispatch('Local Invoice '.ucfirst($request->report), LocalInvoicesExport::class, [$request->user()->id, $request->validated(), $request->report === 'payments'], 'local-invoices-'.now()->format('Ymd-His').'.xlsx');

        return redirect()->route('exports.index')->with('success', 'Local invoice export queued.');
    }
}
