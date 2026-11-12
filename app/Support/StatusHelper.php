<?php

namespace App\Support;

use App\Enums\CustomsDocumentStatus;
use App\Enums\GaClaimStatus;
use App\Enums\LocalGoodsReceiptStatus;
use App\Enums\LocalInvoicePaymentStatus;
use App\Enums\LocalInvoiceStatus;
use App\Enums\LocalPurchaseOrderStatus;
use App\Enums\MaterialClaimStatus;
use App\Enums\PaymentBatchStatus;
use App\Enums\PaymentGroupStatus;
use App\Enums\PoAcknowledgementStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequisitionStatus;
use App\Enums\QcInspectionStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Enums\SupplierAuditStatus;
use App\Enums\SupplierBankAccountStatus;
use App\Enums\SupplierChangeRequestStatus;
use App\Enums\SupplierRegistrationAttemptStatus;
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
    public static function localFinanceTone(
        string|LocalGoodsReceiptStatus|PaymentBatchStatus|LocalPurchaseOrderStatus|PaymentGroupStatus|LocalInvoicePaymentStatus|SupplierBankAccountStatus $status
    ): string {
        if ($status instanceof LocalGoodsReceiptStatus
            || $status instanceof LocalPurchaseOrderStatus
            || $status instanceof PaymentGroupStatus
            || $status instanceof LocalInvoicePaymentStatus
            || $status instanceof SupplierBankAccountStatus) {
            return $status->tone();
        }

        if ($status instanceof PaymentBatchStatus) {
            return self::localFinanceTone($status->value);
        }

        return match (strtoupper($status)) {
            'OPEN', 'AVAILABLE', 'FINAL', 'FINALIZED', 'SETTLED', 'PAID', 'VERIFIED', 'APPROVED', 'ACTIVE', 'CONSUMED' => 'success',
            'RESERVED', 'CORRECTION_REQUIRED', 'PARTIALLY_PAID', 'UNPAID', 'PENDING' => 'warning',
            'INVOICED', 'CLOSED', 'DRAFT' => 'info',
            'CANCELLED', 'REJECTED', 'EXPIRED', 'REMOVED' => 'error',
            'RELEASED', 'INACTIVE' => 'neutral',
            default => 'neutral',
        };
    }

    public static function paymentBatchTone(string|PaymentBatchStatus $status): string
    {
        if ($status instanceof PaymentBatchStatus) {
            return $status->tone();
        }

        return match (strtoupper($status)) {
            'UNPAID' => 'warning',
            default => PaymentBatchStatus::tryFromWithLegacyOrNull($status)?->tone() ?? 'neutral',
        };
    }

    public static function gaClaimTone(string|GaClaimStatus $status): string
    {
        if ($status instanceof GaClaimStatus) {
            return $status->tone();
        }

        return match (strtoupper($status)) {
            'DRAFT' => 'neutral',
            default => GaClaimStatus::tryFromWithLegacyOrNull($status)?->tone() ?? 'neutral',
        };
    }

    public static function registrationTone(string|SupplierRegistrationAttemptStatus $status): string
    {
        if ($status instanceof SupplierRegistrationAttemptStatus) {
            return $status->tone();
        }

        return match (strtoupper($status)) {
            'APPROVED', 'ACTIVE' => 'success',
            'PENDING' => 'warning',
            'REVISION' => 'info',
            'REJECTED' => 'error',
            default => 'neutral',
        };
    }

    public static function supplierAuditTone(string|SupplierAuditStatus $status): string
    {
        if ($status instanceof SupplierAuditStatus) {
            return $status->tone();
        }

        return match (strtoupper($status)) {
            'ASSIGNED', 'SUBMITTED' => 'info',
            'REVISION_REQUESTED' => 'warning',
            'RESULT_PUBLISHED' => 'success',
            default => 'neutral',
        };
    }

    public static function supplierAuditLabel(string|SupplierAuditStatus $status): string
    {
        if ($status instanceof SupplierAuditStatus) {
            return $status->label();
        }

        return self::label('supplier_audit', strtolower($status), $status);
    }

    /** Maintenance display status (MaintenanceRecord::displayStatus()); active blocks users, overdue still blocks. */
    public static function maintenanceTone(string $status): string
    {
        return match (strtolower($status)) {
            'scheduled' => 'info',
            'active' => 'warning',
            'overdue' => 'error',
            'completed' => 'success',
            default => 'neutral',
        };
    }

    public static function maintenanceLabel(string $status): string
    {
        return self::label('maintenance', strtolower($status), $status);
    }

    public static function localInvoiceLabel(string|LocalInvoiceStatus $status): string
    {
        if ($status instanceof LocalInvoiceStatus) {
            return $status->label();
        }

        return self::label('local_invoice', strtolower($status), $status);
    }

    public static function gaClaimLabel(string|GaClaimStatus $status): string
    {
        if ($status instanceof GaClaimStatus) {
            return $status->label();
        }

        return self::label('ga', strtolower($status), $status);
    }

    public static function registrationLabel(string|SupplierRegistrationAttemptStatus $status): string
    {
        if ($status instanceof SupplierRegistrationAttemptStatus) {
            return $status->label();
        }

        return self::label('registration', strtolower($status), $status);
    }

    public static function contactRequestTone(string|SupplierChangeRequestStatus $status): string
    {
        if ($status instanceof SupplierChangeRequestStatus) {
            return $status->tone();
        }

        return match (strtoupper($status)) {
            'PENDING' => 'warning',
            'APPROVED' => 'success',
            'REJECTED' => 'error',
            default => 'neutral',
        };
    }

    public static function contactRequestLabel(string|SupplierChangeRequestStatus $status): string
    {
        if ($status instanceof SupplierChangeRequestStatus) {
            return $status->label();
        }

        return self::label('supplier_contact', strtolower($status), $status);
    }

    public static function poAcknowledgementTone(PoAcknowledgementStatus|string|null $status): string
    {
        return PoAcknowledgementStatus::tryFromOrDefault($status)->tone();
    }

    public static function poAcknowledgementLabel(PoAcknowledgementStatus|string|null $status): string
    {
        return PoAcknowledgementStatus::tryFromOrDefault($status)->label();
    }

    public static function threeWayMatchTone(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'EXACT_MATCH' => 'success',
            'WITHIN_TOLERANCE' => 'info',
            'VARIANCE_DETECTED' => 'warning',
            default => 'neutral',
        };
    }

    public static function threeWayMatchLabel(?string $status): string
    {
        $key = strtolower((string) ($status ?: 'PO_CEILING_ONLY'));

        return __('finance.three_way_match.status.'.$key);
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

    public static function localFinanceLabel(
        string|LocalGoodsReceiptStatus|PaymentBatchStatus|LocalPurchaseOrderStatus|PaymentGroupStatus|LocalInvoicePaymentStatus|SupplierBankAccountStatus $status
    ): string {
        if ($status instanceof LocalGoodsReceiptStatus
            || $status instanceof PaymentBatchStatus
            || $status instanceof LocalPurchaseOrderStatus
            || $status instanceof PaymentGroupStatus
            || $status instanceof LocalInvoicePaymentStatus
            || $status instanceof SupplierBankAccountStatus) {
            return $status->label();
        }

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

    public static function localInvoiceTone(string|LocalInvoiceStatus $status): string
    {
        if ($status instanceof LocalInvoiceStatus) {
            return $status->tone();
        }

        return match ($status) {
            'SUBMITTED' => 'primary',
            'PAYMENT_SCHEDULED' => 'info',
            default => LocalInvoiceStatus::tryFromWithLegacyOrNull($status)?->tone() ?? 'neutral',
        };
    }

    // ─── Purchase Requisition ───

    private static array $prBadges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'bidding' => 'bg-warning text-dark',
        'completed' => 'bg-success',
    ];

    public static function prBadge(string|PurchaseRequisitionStatus $status): string
    {
        $val = $status instanceof PurchaseRequisitionStatus ? $status->value : $status;

        return self::$prBadges[$val] ?? 'bg-secondary';
    }

    public static function prLabel(string|PurchaseRequisitionStatus $status): string
    {
        if ($status instanceof PurchaseRequisitionStatus) {
            return $status->label();
        }

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

    public static function quotationBadge(string|QuotationStatus $status): string
    {
        $val = $status instanceof QuotationStatus ? $status->value : $status;

        return self::$quotationBadges[$val] ?? 'bg-secondary';
    }

    public static function quotationLabel(string|QuotationStatus $status): string
    {
        if ($status instanceof QuotationStatus) {
            return $status->label();
        }

        return self::label('quotation', $status);
    }

    /** Historical quotation comparison status (QuotationHistoryService::STATUS_*). */
    public static function quotationHistoryLabel(string $status): string
    {
        return self::label('quotation_history', $status);
    }

    /** Tone for x-ui.status-chip; the chip pairs the tone with an icon and the label. */
    public static function quotationHistoryTone(string $status): string
    {
        return match ($status) {
            'comparable' => 'success',
            'comparable_unverified' => 'info',
            'ambiguous', 'spec_incomplete', 'basis_differs', 'fx_unavailable' => 'warning',
            default => 'neutral',
        };
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
    public static function poBadge(string|PurchaseOrderStatus $status, bool $isOverdue = false): string
    {
        if ($isOverdue) {
            return 'bg-danger';
        }

        $val = $status instanceof PurchaseOrderStatus ? $status->value : $status;

        return self::$poBadges[$val] ?? 'bg-secondary';
    }

    /**
     * PO label - automatically returns 'Overdue' when $isOverdue is true.
     */
    public static function poLabel(string|PurchaseOrderStatus $status, bool $isOverdue = false): string
    {
        if ($isOverdue) {
            return self::label('po', 'overdue');
        }

        if ($status instanceof PurchaseOrderStatus) {
            return $status->label();
        }

        return self::label('po', $status);
    }

    public static function poArrivalMeta(mixed $estimatedArrival, bool $isOverdue = false, ?string $status = null, mixed $actualArrival = null): array
    {
        if ($status === 'cancelled') {
            return [
                'label' => __('status.po.cancelled'),
                'class' => 'bg-secondary',
                'description' => __('status.meta.order_cancelled'),
            ];
        }

        if ($status === 'claim_needed') {
            return [
                'label' => __('status.po.claim_needed'),
                'class' => 'bg-danger',
                'description' => __('status.meta.arrival_claim_needed'),
            ];
        }

        $actualDate = self::asDate($actualArrival);
        $estimatedDate = self::asDate($estimatedArrival);

        if ($status === 'completed') {
            if ($actualDate && $estimatedDate) {
                if ($actualDate->lte($estimatedDate)) {
                    return [
                        'label' => __('status.meta.arrived_on_time'),
                        'class' => 'bg-success',
                        'description' => __('status.meta.arrival_on_time'),
                    ];
                }

                return [
                    'label' => __('status.meta.arrived_late'),
                    'class' => 'bg-warning text-dark',
                    'description' => __('status.meta.arrival_late'),
                ];
            }

            if ($actualDate) {
                return [
                    'label' => __('status.meta.received'),
                    'class' => 'bg-success',
                    'description' => __('status.meta.arrival_received'),
                ];
            }

            return [
                'label' => __('status.po.completed'),
                'class' => 'bg-success',
                'description' => __('status.meta.arrival_received'),
            ];
        }

        if ($status === 'waiting_qc') {
            if ($actualDate) {
                $daysWaiting = (int) $actualDate->diffInDays(BusinessTime::today(), false);

                if ($daysWaiting > 2) {
                    return [
                        'label' => __('status.meta.waiting_qc_days'),
                        'class' => 'bg-warning text-dark',
                        'description' => trans_choice('status.meta.qc_wait', $daysWaiting, ['count' => $daysWaiting]),
                    ];
                }
            }

            return [
                'label' => __('status.po.waiting_qc'),
                'class' => 'bg-info text-dark',
                'description' => __('status.meta.waiting_inspection'),
            ];
        }

        if ($isOverdue) {
            return [
                'label' => __('status.po.overdue'),
                'class' => 'bg-danger',
                'description' => __('status.meta.arrival_overdue'),
            ];
        }

        if (! $estimatedDate) {
            return [
                'label' => __('status.meta.estimated_missing'),
                'class' => 'bg-secondary',
                'description' => __('status.meta.arrival_missing'),
            ];
        }

        $days = (int) BusinessTime::today()->diffInDays($estimatedDate, false);
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

    public static function claimBadge(string|MaterialClaimStatus $status): string
    {
        $val = $status instanceof MaterialClaimStatus ? $status->value : $status;

        return self::$claimBadges[$val] ?? 'bg-secondary';
    }

    public static function claimLabel(string|MaterialClaimStatus $status): string
    {
        if ($status instanceof MaterialClaimStatus) {
            return $status->label();
        }

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

    public static function docBadge(string|CustomsDocumentStatus $status): string
    {
        $val = $status instanceof CustomsDocumentStatus ? $status->value : $status;

        return self::$docBadges[$val] ?? 'bg-secondary';
    }

    public static function docLabel(string|CustomsDocumentStatus $status): string
    {
        $val = $status instanceof CustomsDocumentStatus ? $status->value : $status;

        return self::label('document', $val);
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

    public static function qcBadge(string|QcInspectionStatus $status): string
    {
        $val = $status instanceof QcInspectionStatus ? $status->value : $status;

        return self::$qcBadges[$val] ?? 'bg-secondary';
    }

    public static function qcLabel(string|QcInspectionStatus $status): string
    {
        if ($status instanceof QcInspectionStatus) {
            return $status->label();
        }

        return in_array($status, ['ok', 'ng'], true) ? strtoupper($status) : self::label('qc', $status);
    }

    // ─── Shipment ───

    private static array $shipmentBadges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'arrived' => 'bg-success',
        'cancelled' => 'bg-danger',
    ];

    public static function shipmentBadge(string|ShipmentStatus $status): string
    {
        $val = $status instanceof ShipmentStatus ? $status->value : $status;

        return self::$shipmentBadges[$val] ?? 'bg-secondary';
    }

    public static function shipmentLabel(string|ShipmentStatus $status): string
    {
        if ($status instanceof ShipmentStatus) {
            return $status->label();
        }

        return self::label('shipment', $status);
    }

    /** Maps a legacy badge class to the status-chip tone vocabulary. */
    private static function toneFromBadge(string $badgeClass): string
    {
        return match (true) {
            str_contains($badgeClass, 'success') => 'success',
            str_contains($badgeClass, 'danger') => 'error',
            str_contains($badgeClass, 'warning') => 'warning',
            str_contains($badgeClass, 'primary'), str_contains($badgeClass, 'info') => 'info',
            default => 'neutral',
        };
    }

    public static function prTone(string|PurchaseRequisitionStatus $status): string
    {
        return self::toneFromBadge(self::prBadge($status));
    }

    public static function quotationTone(string|QuotationStatus $status): string
    {
        return self::toneFromBadge(self::quotationBadge($status));
    }

    public static function poTone(string|PurchaseOrderStatus $status, bool $isOverdue = false): string
    {
        return self::toneFromBadge(self::poBadge($status, $isOverdue));
    }

    public static function claimTone(string|MaterialClaimStatus $status): string
    {
        return self::toneFromBadge(self::claimBadge($status));
    }

    public static function shipmentTone(string|ShipmentStatus $status): string
    {
        if ($status instanceof ShipmentStatus) {
            return $status->tone();
        }

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
        'arrived' => 'bg-success',
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
            'ready_to_ship', 'arrived' => 'success',
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

    public static function shipmentDocBadge(string|CustomsDocumentStatus $status): string
    {
        $val = $status instanceof CustomsDocumentStatus ? $status->value : $status;

        return self::$shipmentDocBadges[$val] ?? 'bg-secondary';
    }

    public static function shipmentDocLabel(string|CustomsDocumentStatus $status): string
    {
        if ($status instanceof CustomsDocumentStatus) {
            return $status->label();
        }

        return self::label('shipment_document', $status);
    }

    public static function shipmentDocTone(string|CustomsDocumentStatus $status): string
    {
        if ($status instanceof CustomsDocumentStatus) {
            return $status->tone();
        }

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
