@extends('layouts.public-document')
@section('title', __('local_invoice.receipt.title'))

@section('content')
    @include('receipts.partials.verified-card', [
        'title' => __('local_invoice.receipt.valid'),
        'unit' => __('local_invoice.receipt.company_title'),
        'receiptNumber' => $receipt->receipt_number,
        'rows' => [
            ['label' => __('common.labels_review.submission_number'), 'value' => $invoice->submission_number, 'numeric' => true],
            ['label' => __('common.labels_review.invoice_number'), 'value' => $invoice->invoice_number, 'emphasis' => true, 'numeric' => true],
            ['label' => __('common.labels_review.po_number'), 'value' => $invoice->po_number, 'numeric' => true],
            ['label' => __('common.final_review.partner_name'), 'value' => $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name, 'emphasis' => true],
            ['label' => __('local_invoice.labels.submitted_time'), 'value' => \App\Support\BusinessTime::format($invoice->submitted_at, 'd/m/Y H:i'), 'numeric' => true],
        ],
        'amountLabel' => __('common.final_review.invoice_total'),
        'amount' => app(\App\Services\RegionalDisplayFormatter::class)->money($invoice->invoice_amount, 0),
        'statusLabel' => __('local_invoice.receipt.registered'),
        'statement' => __('local_invoice.receipt.statement'),
    ])
@endsection
