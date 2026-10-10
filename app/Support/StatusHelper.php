<?php

namespace App\Support;

use App\Models\Quotation;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Centralized status presentation and label helper.
 *
 * Replaces repeated match() blocks across controllers and views.
 * Usage: StatusHelper::prBadge($status), StatusHelper::prLabel($status), etc.
 */
class StatusHelper
{
    public static function localFinanceTone(string $status): string
    {
        return match (strtoupper($status)) {
            'OPEN', 'AVAILABLE', 'FINAL', 'FINALIZED', 'SETTLED', 'PAID', 'VERIFIED', 'APPROVED', 'ACTIVE', 'CONSUMED' => 'success',
            'RESERVED', 'CORRECTION_REQUIRED', 'PARTIALLY_PAID', 'UNPAID', 'PENDING' => 'warning',
            'INVOICED', 'CLOSED', 'DRAFT' => 'info',
            'CANCELLED', 'REJECTED', 'EXPIRED', 'REMOVED' => 'error',
            'RELEASED', 'INACTIVE' => 'neutral',
            default => 'neutral',
        };
    }

    public static function paymentBatchTone(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID' => 'success',
            'FINALIZED' => 'info',
            'PARTIALLY_PAID', 'UNPAID' => 'warning',
            'DRAFT' => 'neutral',
            'CANCELLED' => 'error',
            default => 'neutral',
        };
    }

    public static function gaClaimTone(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID', 'READY_TO_PAY' => 'success',
            'SUBMITTED', 'BASIC_VERIFIED', 'UNDER_VERIFICATION', 'UNDER_REVIEW' => 'info',
            'NEED_REVISION' => 'warning',
            'CANCELLED', 'REJECTED' => 'error',
            'DRAFT' => 'neutral',
            default => 'neutral',
        };
    }

    public static function registrationTone(string $status): string
    {
        return match (strtoupper($status)) {
            'APPROVED', 'ACTIVE' => 'success',
            'PENDING' => 'warning',
            'REVISION' => 'info',
            'REJECTED' => 'error',
            default => 'neutral',
        };
    }

    public static function supplierAuditTone(string $status): string
    {
        return match (strtoupper($status)) {
            'ASSIGNED', 'SUBMITTED' => 'info',
            'REVISION_REQUESTED' => 'warning',
            'RESULT_PUBLISHED' => 'success',
            default => 'neutral',
        };
    }

    public static function supplierAuditLabel(string $status): string
    {
        return self::label('supplier_audit', strtolower($status), $status);
    }

    public static function localInvoiceLabel(string $status): string
    {
        return self::label('local_invoice', strtolower($status), $status);
    }

    public static function gaClaimLabel(string $status): string
    {
        return self::label('ga', strtolower($status), $status);
    }

    public static function registrationLabel(string $status): string
    {
        return self::label('registration', strtolower($status), $status);
    }

    public static function vendorCategoryLabel(?string $category): string
    {
        $key = match (strtolower(trim((string) $category))) {
            'barang' => 'goods',
            'jasa' => 'services',
            'lainnya' => 'other',
            default => null,
        };

        return $key === null ? (string) $category : __('local_procurement.vendor_ui.'.$key);
    }

    public static function localFinanceLabel(string $status): string
    {
        return self::label('finance', strtolower($status), $status);
    }

    private static function label(string $domain, string $status, ?string $unknownValue = null): string
    {
        $key = 'status.'.$domain.'.'.$status;
        if (app('translator')->has($key)) {
            return __($key);
        }

        $unknownValue ??= $status;

        return $unknownValue === '' ? '' : __('status.meta.unknown_value', ['value' => $unknownValue]);
    }

    public static function localInvoiceTone(string $status): string
    {
        return [
            'SUBMITTED' => 'primary',
            'WAITING_PHYSICAL_DOCUMENT' => 'warning',
            'UNDER_REVIEW' => 'info',
            'UNDER_VERIFICATION' => 'info',
            'NEED_REVISION' => 'warning',
            'REJECTED' => 'error',
            'CANCELLED' => 'error',
            'EXPIRED' => 'error',
            'APPROVED' => 'success',
            'READY_TO_PAY' => 'success',
            'PAYMENT_SCHEDULED' => 'info',
            'PAID' => 'success',
            'COMPLETED' => 'success',
        ][$status] ?? 'neutral';
    }

    // ─── Purchase Requisition ───

    private static array $prBadges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'bidding' => 'bg-warning text-dark',
        'completed' => 'bg-success',
    ];

    public static function prBadge(string $status): string
    {
        return self::$prBadges[$status] ?? 'bg-secondary';
    }

    public static function prLabel(string $status): string
    {
        return self::label('pr', $status);
    }

    // ─── Quotation ───

    private static array $quotationBadges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'accepted' => 'bg-success',
        'rejected' => 'bg-danger',
        'revision_requested' => 'bg-warning text-dark',
        'all_unavailable' => 'bg-secondary',
    ];

    public static function quotationBadge(string $status): string
    {
        return self::$quotationBadges[$status] ?? 'bg-secondary';
    }

    public static function quotationLabel(string $status): string
    {
        return self::label('quotation', $status);
    }

    public static function quotationValidityMeta(mixed $validityPeriod, ?string $status = null): array
    {
        if ($status === Quotation::STATUS_ALL_UNAVAILABLE) {
            return [
                'label' => __('status.meta.not_applicable'),
                'class' => 'bg-secondary',
                'description' => __('status.meta.no_available_items'),
            ];
        }

        $date = self::asDate($validityPeriod);

        if (! $date) {
            return [
                'label' => __('status.meta.valid_until_missing'),
                'class' => 'bg-warning text-dark',
                'description' => __('status.meta.validity_missing'),
            ];
        }

        $today = BusinessTime::today();
        $days = (int) $today->diffInDays($date, false);

        if ($date->lt($today)) {
            return [
                'label' => __('status.local_invoice.expired'),
                'class' => 'bg-danger',
                'description' => __('status.meta.quotation_expired'),
            ];
        }

        if ($days <= 7) {
            return [
                'label' => __('status.meta.expiring_soon'),
                'class' => 'bg-warning text-dark',
                'description' => trans_choice('status.meta.validity_days', $days, ['count' => $days]),
            ];
        }

        return [
            'label' => __('status.meta.valid'),
            'class' => 'bg-success',
            'description' => __('status.meta.quotation_valid'),
        ];
    }

    // ─── Purchase Order ───

    private static array $poBadges = [
        'active' => 'bg-primary',
        'waiting_qc' => 'bg-warning text-dark',
        'completed' => 'bg-success',
        'overdue' => 'bg-danger',
        'claim_needed' => 'bg-danger',
        'cancelled' => 'bg-secondary',
    ];

    /**
     * PO badge - automatically returns 'bg-danger' when $isOverdue is true.
     */
    public static function poBadge(string $status, bool $isOverdue = false): string
    {
        if ($isOverdue) {
            return 'bg-danger';
        }

        return self::$poBadges[$status] ?? 'bg-secondary';
    }

    /**
     * PO label - automatically returns 'Overdue' when $isOverdue is true.
     */
    public static function poLabel(string $status, bool $isOverdue = false): string
    {
        if ($isOverdue) {
            return self::label('po', 'overdue');
        }

        return self::label('po', $status);
    }

    public static function poArrivalMeta(mixed $estimatedArrival, bool $isOverdue = false, ?string $status = null, mixed $actualArrival = null): array
    {
        if ($isOverdue) {
            return [
                'label' => __('status.po.overdue'),
                'class' => 'bg-danger',
                'description' => __('status.meta.arrival_overdue'),
            ];
        }

        $actualDate = self::asDate($actualArrival);
        if ($status === 'waiting_qc' && $actualDate) {
            $daysWaiting = (int) $actualDate->diffInDays(BusinessTime::today(), false);

            if ($daysWaiting > 2) {
                return [
                    'label' => __('status.meta.waiting_qc_days'),
                    'class' => 'bg-warning text-dark',
                    'description' => trans_choice('status.meta.qc_wait', $daysWaiting, ['count' => $daysWaiting]),
                ];
            }

            return [
                'label' => __('status.po.waiting_qc'),
                'class' => 'bg-info text-dark',
                'description' => __('status.meta.waiting_inspection'),
            ];
        }

        $date = self::asDate($estimatedArrival);
        if (! $date) {
            return [
                'label' => __('status.meta.estimated_missing'),
                'class' => 'bg-secondary',
                'description' => __('status.meta.arrival_missing'),
            ];
        }

        $days = (int) BusinessTime::today()->diffInDays($date, false);
        if ($status === 'active' && $days >= 0 && $days <= 7) {
            return [
                'label' => __('status.meta.arrives_soon'),
                'class' => 'bg-info text-dark',
                'description' => trans_choice('status.meta.arrival_days', $days, ['count' => $days]),
            ];
        }

        return [
            'label' => __('status.meta.on_schedule'),
            'class' => 'bg-light text-muted border',
            'description' => __('status.meta.arrival_on_schedule'),
        ];
    }

    // ─── Material Claim ───

    private static array $claimBadges = [
        'pending' => 'bg-warning text-dark',
        'in_progress' => 'bg-info text-dark',
        'responded' => 'bg-primary',
        'resolved' => 'bg-success',
        'escalated' => 'bg-danger',
        'rejected' => 'bg-danger',
        'closed' => 'bg-secondary',
    ];

    public static function claimBadge(string $status): string
    {
        return self::$claimBadges[$status] ?? 'bg-secondary';
    }

    public static function claimLabel(string $status): string
    {
        return self::label('claim', $status);
    }

    public static function claimDeadlineMeta(mixed $deadline, ?string $status = null): array
    {
        $date = self::asDate($deadline);

        if (! $date) {
            return [
                'label' => __('status.meta.deadline_missing'),
                'class' => 'bg-secondary',
                'description' => __('status.meta.claim_deadline_missing'),
            ];
        }

        if ($status !== 'pending') {
            return [
                'label' => __('status.meta.processed'),
                'class' => 'bg-light text-muted border',
                'description' => __('status.meta.claim_processed'),
            ];
        }

        $days = (int) BusinessTime::today()->diffInDays($date, false);

        if ($date->lt(BusinessTime::today())) {
            return [
                'label' => __('status.meta.past_deadline'),
                'class' => 'bg-danger',
                'description' => __('status.meta.claim_overdue'),
            ];
        }

        if ($days <= 3) {
            return [
                'label' => __('status.meta.deadline_soon'),
                'class' => 'bg-warning text-dark',
                'description' => trans_choice('status.meta.deadline_days', $days, ['count' => $days]),
            ];
        }

        return [
            'label' => __('status.meta.safe'),
            'class' => 'bg-success',
            'description' => __('status.meta.claim_safe'),
        ];
    }

    // ─── PO Document ───

    private static array $docBadges = [
        'pending' => 'bg-warning text-dark',
        'uploaded' => 'bg-info text-dark',
        'received' => 'bg-info text-dark',
        'done' => 'bg-success',
        'verified' => 'bg-success',
        'rejected' => 'bg-danger',
    ];

    public static function docBadge(string $status): string
    {
        return self::$docBadges[$status] ?? 'bg-secondary';
    }

    public static function docLabel(string $status): string
    {
        return self::label('document', $status);
    }

    public static function documentProgressMeta(int $completed, int $total = 4): array
    {
        $total = max($total, 4);
        $isComplete = $completed >= $total;

        return [
            'label' => __('status.meta.document_progress', ['completed' => $completed, 'total' => $total]),
            'class' => $isComplete ? 'bg-success' : 'bg-warning text-dark',
            'description' => $isComplete
                ? __('status.meta.documents_complete')
                : __('status.meta.documents_incomplete'),
            'complete' => $isComplete,
        ];
    }

    // ─── QC Inspection ───

    private static array $qcBadges = [
        'ok' => 'bg-success',
        'ng' => 'bg-danger',
        'pending' => 'bg-warning text-dark',
    ];

    public static function qcBadge(string $status): string
    {
        return self::$qcBadges[$status] ?? 'bg-secondary';
    }

    public static function qcLabel(string $status): string
    {
        return in_array($status, ['ok', 'ng'], true) ? strtoupper($status) : self::label('qc', $status);
    }

    // ─── Shipment ───

    private static array $shipmentBadges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'arrived' => 'bg-success',
        'cancelled' => 'bg-danger',
    ];

    public static function shipmentBadge(string $status): string
    {
        return self::$shipmentBadges[$status] ?? 'bg-secondary';
    }

    public static function shipmentLabel(string $status): string
    {
        return self::label('shipment', $status);
    }

    public static function shipmentTone(string $status): string
    {
        return match ($status) {
            'submitted' => 'info',
            'arrived' => 'success',
            'cancelled' => 'error',
            default => 'neutral',
        };
    }

    public static function shipmentLifecycleBadge(string $status): string
    {
        return self::shipmentBadge($status);
    }

    public static function shipmentLifecycleLabel(string $status): string
    {
        return self::shipmentLabel($status);
    }

    public static function shipmentLifecycleTone(string $status): string
    {
        return self::shipmentTone($status);
    }

    // ─── Material Progress ───

    private static array $materialProgressBadges = [
        'awaiting_confirmation' => 'bg-secondary',
        'order_confirmed' => 'bg-info',
        'material_preparation' => 'bg-warning text-dark',
        'on_production' => 'bg-primary',
        'ready_to_ship' => 'bg-success',
    ];

    public static function materialProgressBadge(string $status): string
    {
        return self::$materialProgressBadges[$status] ?? 'bg-secondary';
    }

    public static function materialProgressLabel(string $status): string
    {
        return self::label('material_progress', $status);
    }

    public static function materialProgressTone(string $status): string
    {
        return match ($status) {
            'ready_to_ship' => 'success',
            'on_production' => 'info',
            'material_preparation' => 'warning',
            'order_confirmed' => 'info',
            default => 'neutral',
        };
    }

    // ─── Shipment Document ───

    private static array $shipmentDocBadges = [
        'pending' => 'bg-warning text-dark',
        'received' => 'bg-info',
        'processing' => 'bg-warning text-dark',
        'issued' => 'bg-primary',
        'verified' => 'bg-success',
        'done' => 'bg-success',
    ];

    public static function shipmentDocBadge(string $status): string
    {
        return self::$shipmentDocBadges[$status] ?? 'bg-secondary';
    }

    public static function shipmentDocLabel(string $status): string
    {
        return self::label('shipment_document', $status);
    }

    public static function shipmentDocTone(string $status): string
    {
        return match ($status) {
            'verified', 'done' => 'success',
            'received', 'issued' => 'info',
            'pending', 'processing' => 'warning',
            default => 'neutral',
        };
    }

    // ─── Generic Helper ───

    /**
     * Render a semantic status chip.
     *
     * @param  string  $badgeClass  CSS class (e.g., 'bg-success')
     * @param  string  $label  Display text
     * @return string Raw HTML string
     */
    public static function badge(string $badgeClass, string $label): string
    {
        $escapedLabel = e($label);
        $tone = self::semanticTone($badgeClass);

        return '<span class="ui-status-chip ui-status-chip--'.$tone.'">'.$escapedLabel.'</span>';
    }

    public static function badgeWithTooltip(string $badgeClass, string $label, ?string $description = null): string
    {
        $escapedLabel = e($label);
        $tone = self::semanticTone($badgeClass);
        $tooltip = $description
            ? ' data-bs-toggle="tooltip" data-bs-title="'.e($description).'"'
            : '';

        return '<span class="ui-status-chip ui-status-chip--'.$tone.'"'.$tooltip.'>'.$escapedLabel.'</span>';
    }

    private static function semanticTone(string $badgeClass): string
    {
        return match (true) {
            str_contains($badgeClass, 'danger') => 'error',
            str_contains($badgeClass, 'warning') => 'warning',
            str_contains($badgeClass, 'success') => 'success',
            str_contains($badgeClass, 'primary'), str_contains($badgeClass, 'info') => 'info',
            default => 'neutral',
        };
    }

    private static function asDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        return Carbon::parse($value)->startOfDay();
    }
}
