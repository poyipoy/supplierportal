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
        'pr'            => StatusHelper::prLabel($status),
        'quotation'     => StatusHelper::quotationLabel($status),
        'po'            => StatusHelper::poLabel($status, $isOverdue),
        'claim'         => StatusHelper::claimLabel($status),
        'qc'            => StatusHelper::qcLabel($status),
        'doc'           => StatusHelper::docLabel($status),
        'shipment'      => StatusHelper::shipmentLabel($status),
        'local_invoice' => StatusHelper::localInvoiceLabel($status),
        'finance'       => StatusHelper::localFinanceLabel($status),
        'ga'            => StatusHelper::gaClaimLabel($status),
        'registration'  => StatusHelper::registrationLabel($status),
        default         => StatusHelper::localFinanceLabel($status),
    };

    $tone = match($type) {
        'shipment'      => StatusHelper::shipmentTone($status),
        'local_invoice' => StatusHelper::localInvoiceTone($status),
        'finance'       => StatusHelper::localFinanceTone($status),
        'ga'            => StatusHelper::gaClaimTone($status),
        'registration'  => StatusHelper::registrationTone($status),
        default         => (
            str_contains($badgeClass, 'danger')
                ? 'error'
                : (str_contains($badgeClass, 'warning')
                    ? 'warning'
                    : (str_contains($badgeClass, 'success')
                        ? 'success'
                        : (str_contains($badgeClass, 'primary') || str_contains($badgeClass, 'info') ? 'info' : 'neutral')))
        ),
    };
@endphp

<x-ui.status-chip :tone="$tone" :size="$size === 'lg' ? 'md' : 'sm'" {{ $attributes }}>{{ $label }}</x-ui.status-chip>
