<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\WorkflowRequest;
use App\Models\LocalInvoice;
use App\Services\LocalInvoice\InvoiceWorkflowService;

class InvoiceWorkflowController extends Controller
{
    public function physicalVerification(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        $service->act($request->user(), $invoice, 'verifyPhysical', $request->validated());

        return back()->with('success', 'Invoice updated.');
    }

    public function startReview(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        $service->act($request->user(), $invoice, 'startReview', $request->validated());

        return back()->with('success', 'Invoice updated.');
    }

    public function requestRevision(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        $service->act($request->user(), $invoice, 'requestRevision', $request->validated());

        return back()->with('success', 'Invoice updated.');
    }

    public function reject(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        $service->act($request->user(), $invoice, 'reject', $request->validated());

        return back()->with('success', 'Invoice updated.');
    }

    public function approve(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        $service->act($request->user(), $invoice, 'approve', $request->validated());

        return back()->with('success', 'Invoice updated.');
    }

    public function schedulePayment(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        $service->act($request->user(), $invoice, 'schedulePayment', $request->validated());

        return back()->with('success', 'Invoice updated.');
    }

    public function completePayment(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        $service->act($request->user(), $invoice, 'completePayment', $request->validated());

        return back()->with('success', 'Invoice updated.');
    }
}
