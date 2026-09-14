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
        abort(404, 'Legacy accounting workflow is disabled. Use Finance V2 verification.');
    }

    public function startReview(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        abort(404, 'Legacy accounting workflow is disabled. Use Finance V2 verification.');
    }

    public function requestRevision(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        abort(404, 'Legacy accounting workflow is disabled. Use Finance V2 verification.');
    }

    public function reject(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        abort(404, 'Legacy accounting workflow is disabled. Use Finance V2 verification.');
    }

    public function approve(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        abort(404, 'Legacy accounting workflow is disabled. Use Finance V2 verification.');
    }

    public function schedulePayment(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        abort(404, 'Legacy accounting workflow is disabled. Use Finance V2 verification.');
    }

    public function completePayment(WorkflowRequest $request, LocalInvoice $invoice, InvoiceWorkflowService $service)
    {
        abort(404, 'Legacy accounting workflow is disabled. Use Finance V2 verification.');
    }
}
