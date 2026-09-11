@extends('layouts.app')
@section('title', $invoice->receipt->receipt_number)
@section('page-title', 'Invoice Receipt')
@section('content')
<div class="local-invoice-receipt">
<x-ui.page-header title="Invoice Submission Receipt" :description="$invoice->receipt->receipt_number" />
<x-ui.card title="ADASI Supplier Portal">
<dl class="row">
<dt class="col-sm-4">Receipt Number</dt><dd class="col-sm-8">{{ $invoice->receipt->receipt_number }}</dd>
<dt class="col-sm-4">Submission ID</dt><dd class="col-sm-8">{{ $invoice->submission_number }}</dd>
<dt class="col-sm-4">Invoice Number</dt><dd class="col-sm-8">{{ $invoice->invoice_number }}</dd>
<dt class="col-sm-4">PO Number</dt><dd class="col-sm-8">{{ $invoice->po_number }}</dd>
<dt class="col-sm-4">Vendor</dt><dd class="col-sm-8">{{ $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name }}</dd>
<dt class="col-sm-4">Invoice Amount / PPN (IDR)</dt><dd class="col-sm-8">{{ $invoice->invoice_amount }} / {{ $invoice->tax_amount }}</dd>
<dt class="col-sm-4">Submission Timestamp</dt><dd class="col-sm-8">{{ $invoice->submitted_at->format('d M Y H:i') }}</dd>
<dt class="col-sm-4">Revision</dt><dd class="col-sm-8">{{ $invoice->revision_number }}</dd>
</dl><p>Include this receipt with the physical Invoice and Faktur Pajak documents. This receipt acknowledges digital submission; it does not confirm approval or payment.</p>
<x-ui.button type="button" data-print-receipt class="d-print-none">Print Receipt</x-ui.button>
</x-ui.card>
</div>
@include('local-invoices.scripts')
@endsection
