@extends('layouts.public-document')
@section('title', __('ga.receipt.public_title'))

@section('content')
    @include('receipts.partials.verified-card', [
        'title' => __('ga.receipt.verified'),
        'unit' => __('ga.receipt.company_title'),
        'receiptNumber' => $receipt->receipt_number,
        'rows' => [
            ['label' => __('common.labels_review.claim_number'), 'value' => $claim->claim_number, 'emphasis' => true, 'numeric' => true],
            ['label' => __('ga.labels.employee_name'), 'value' => trim(($claim->employee?->name ?? '').($claim->employee?->department ? ' ('.$claim->employee->department.')' : '')), 'emphasis' => true],
            ['label' => __('ga.labels.claim_type'), 'value' => \App\Models\GaClaim::claimTypeLabel($claim->claim_type)],
            ['label' => __('ga.labels.claim_date'), 'value' => $claim->claim_date?->format('d/m/Y'), 'numeric' => true],
            ['label' => __('local_invoice.labels.submitted_time'), 'value' => \App\Support\BusinessTime::format($claim->created_at, 'd/m/Y H:i'), 'numeric' => true],
        ],
        'amountLabel' => __('ga.labels.claim_amount'),
        'amount' => app(\App\Services\RegionalDisplayFormatter::class)->money($claim->amount, 0),
        'statusLabel' => __('ga.receipt.registered'),
        'statement' => __('ga.receipt.statement'),
    ])
@endsection
