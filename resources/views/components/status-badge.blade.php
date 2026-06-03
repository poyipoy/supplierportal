@props([
    'type',       // pr, quotation, po, claim, qc, doc
    'status',
    'isOverdue' => false,
    'size' => 'sm', // sm or lg
])

@php
    use App\Support\StatusHelper;

    $badgeClass = match($type) {
        'pr'        => StatusHelper::prBadge($status),
        'quotation' => StatusHelper::quotationBadge($status),
        'po'        => StatusHelper::poBadge($status, $isOverdue),
        'claim'     => StatusHelper::claimBadge($status),
        'qc'        => StatusHelper::qcBadge($status),
        'doc'       => StatusHelper::docBadge($status),
        default     => 'bg-secondary',
    };

    $label = match($type) {
        'pr'        => StatusHelper::prLabel($status),
        'quotation' => StatusHelper::quotationLabel($status),
        'po'        => StatusHelper::poLabel($status, $isOverdue),
        'claim'     => StatusHelper::claimLabel($status),
        'qc'        => StatusHelper::qcLabel($status),
        'doc'       => StatusHelper::docLabel($status),
        default     => ucwords(str_replace('_', ' ', $status)),
    };

    $fontSize = $size === 'lg' ? 'font-size:.75rem' : 'font-size:.65rem';
    $padding = $size === 'lg' ? 'px-3 py-2' : '';
@endphp

<span {{ $attributes->merge(['class' => "badge {$badgeClass} text-uppercase {$padding}", 'style' => $fontSize]) }}>{{ $label }}</span>
