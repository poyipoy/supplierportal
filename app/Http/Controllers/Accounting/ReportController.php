<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\LocalInvoicesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\AdvancedExportRequest;
use App\Http\Requests\Export\Filters\LocalInvoiceExportFilters;
use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Models\User;
use App\Support\ExportDispatcher;

class ReportController extends Controller
{
    public function index(InvoiceFilterRequest $request)
    {
        return view('accounting.reports.index', [
            'payments' => true,
            'suppliers' => User::localEligible()->with('supplier')->orderBy('name')->get(),
        ]);
    }

    public function export(AdvancedExportRequest $request)
    {
        $filters = LocalInvoiceExportFilters::validated($request, accounting: true);
        $payments = $filters['report'] === 'payments';
        unset($filters['report']);
        $job = ExportDispatcher::dispatch(__($payments ? 'exports.job_labels.invoice_payments' : 'exports.job_labels.invoice_register'), LocalInvoicesExport::class, [$request->user()->id, $filters, $payments], 'local-invoices-'.now()->format('Ymd-His').'.xlsx', $request->exportOptions('accounting.local-invoices')); // biz-time:ignore instant filename
        if ($request->wantsJson()) {
            return response()->json(['message' => __('accounting.feedback.export_queued'), 'export_job_id' => $job->getRouteKey(), 'exports_url' => route('exports.index', absolute: false), 'status_url' => route('exports.status', $job, absolute: false), 'cancel_url' => route('exports.cancel', $job, absolute: false)], 202);
        }

        return redirect()->route('exports.index')->with('success', __('accounting.feedback.export_queued'));
    }
}
