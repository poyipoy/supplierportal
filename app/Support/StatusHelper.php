<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Centralized status badge & label helper.
 *
 * Replaces repeated match() blocks across controllers and views.
 * Usage: StatusHelper::prBadge($status), StatusHelper::prLabel($status), etc.
 */
class StatusHelper
{
    // ─── Purchase Requisition ───

    private static array $prBadges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'bidding' => 'bg-warning text-dark',
        'completed' => 'bg-success',
    ];

    private static array $prLabels = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'bidding' => 'Bidding',
        'completed' => 'Completed',
    ];

    public static function prBadge(string $status): string
    {
        return self::$prBadges[$status] ?? 'bg-secondary';
    }

    public static function prLabel(string $status): string
    {
        return self::$prLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    // ─── Quotation ───

    private static array $quotationBadges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'accepted' => 'bg-success',
        'rejected' => 'bg-danger',
        'revision_requested' => 'bg-warning text-dark',
    ];

    private static array $quotationLabels = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'revision_requested' => 'Revision Requested',
    ];

    public static function quotationBadge(string $status): string
    {
        return self::$quotationBadges[$status] ?? 'bg-secondary';
    }

    public static function quotationLabel(string $status): string
    {
        return self::$quotationLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    public static function quotationValidityMeta(mixed $validityPeriod): array
    {
        $date = self::asDate($validityPeriod);

        if (! $date) {
            return [
                'label' => 'Valid Until Missing',
                'class' => 'bg-warning text-dark',
                'description' => 'The supplier has not filled in the quotation validity date.',
            ];
        }

        $today = today();
        $days = (int) $today->diffInDays($date, false);

        if ($date->lt($today)) {
            return [
                'label' => 'Expired',
                'class' => 'bg-danger',
                'description' => 'The quotation has expired and cannot be used to create a PO until the supplier submits a revision.',
            ];
        }

        if ($days <= 7) {
            return [
                'label' => 'Expiring Soon',
                'class' => 'bg-warning text-dark',
                'description' => "The quotation validity expires in {$days} days.",
            ];
        }

        return [
            'label' => 'Valid',
            'class' => 'bg-success',
            'description' => 'The quotation is still valid.',
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

    private static array $poLabels = [
        'active' => 'Active',
        'waiting_qc' => 'Waiting QC',
        'completed' => 'Completed',
        'overdue' => 'Overdue',
        'claim_needed' => 'Claim Needed',
        'cancelled' => 'Cancelled',
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
            return 'Overdue';
        }

        return self::$poLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    public static function poArrivalMeta(mixed $estimatedArrival, bool $isOverdue = false, ?string $status = null, mixed $actualArrival = null): array
    {
        if ($isOverdue) {
            return [
                'label' => 'Overdue',
                'class' => 'bg-danger',
                'description' => 'The estimated arrival date has passed and the material has not been confirmed as arrived.',
            ];
        }

        $actualDate = self::asDate($actualArrival);
        if ($status === 'waiting_qc' && $actualDate) {
            $daysWaiting = (int) $actualDate->diffInDays(today(), false);

            if ($daysWaiting > 2) {
                return [
                    'label' => 'Waiting QC > 2 days',
                    'class' => 'bg-warning text-dark',
                    'description' => "The material arrived {$daysWaiting} days ago and is still waiting for QC.",
                ];
            }

            return [
                'label' => 'Waiting QC',
                'class' => 'bg-info text-dark',
                'description' => 'The material has arrived and is waiting for QC inspection.',
            ];
        }

        $date = self::asDate($estimatedArrival);
        if (! $date) {
            return [
                'label' => 'Estimated Date Missing',
                'class' => 'bg-secondary',
                'description' => 'Estimated arrival is not available yet.',
            ];
        }

        $days = (int) today()->diffInDays($date, false);
        if ($status === 'active' && $days >= 0 && $days <= 7) {
            return [
                'label' => 'Arrives <= 7 days',
                'class' => 'bg-info text-dark',
                'description' => "The material is estimated to arrive in {$days} days.",
            ];
        }

        return [
            'label' => 'On Schedule',
            'class' => 'bg-light text-muted border',
            'description' => 'The estimated arrival is still on schedule.',
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

    private static array $claimLabels = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'responded' => 'Responded',
        'resolved' => 'Resolved',
        'escalated' => 'Escalated',
        'rejected' => 'Rejected',
        'closed' => 'Closed',
    ];

    public static function claimBadge(string $status): string
    {
        return self::$claimBadges[$status] ?? 'bg-secondary';
    }

    public static function claimLabel(string $status): string
    {
        return self::$claimLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    public static function claimDeadlineMeta(mixed $deadline, ?string $status = null): array
    {
        $date = self::asDate($deadline);

        if (! $date) {
            return [
                'label' => 'Deadline Missing',
                'class' => 'bg-secondary',
                'description' => 'Deadline response claim is not available yet.',
            ];
        }

        if ($status !== 'pending') {
            return [
                'label' => 'Processed',
                'class' => 'bg-light text-muted border',
                'description' => 'The claim is no longer waiting for a supplier response.',
            ];
        }

        $days = (int) today()->diffInDays($date, false);

        if ($date->lt(today())) {
            return [
                'label' => 'Past Deadline',
                'class' => 'bg-danger',
                'description' => 'The supplier has passed the claim response deadline.',
            ];
        }

        if ($days <= 3) {
            return [
                'label' => 'Deadline <= 3 days',
                'class' => 'bg-warning text-dark',
                'description' => "The response deadline is in {$days} days.",
            ];
        }

        return [
            'label' => 'Safe',
            'class' => 'bg-success',
            'description' => 'The claim response deadline is still safe.',
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

    private static array $docLabels = [
        'pending' => 'Not Uploaded',
        'uploaded' => 'Uploaded',
        'received' => 'Accepted',
        'done' => 'Completed',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
    ];

    public static function docBadge(string $status): string
    {
        return self::$docBadges[$status] ?? 'bg-secondary';
    }

    public static function docLabel(string $status): string
    {
        return self::$docLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    public static function documentProgressMeta(int $completed, int $total = 4): array
    {
        $total = max($total, 4);
        $isComplete = $completed >= $total;

        return [
            'label' => "{$completed}/{$total} complete",
            'class' => $isComplete ? 'bg-success' : 'bg-warning text-dark',
            'description' => $isComplete
                ? 'All import documents are complete.'
                : 'Some import documents still need to be completed or verified.',
            'complete' => $isComplete,
        ];
    }

    // ─── QC Inspection ───

    private static array $qcBadges = [
        'ok' => 'bg-success',
        'ng' => 'bg-danger',
        'pending' => 'bg-warning text-dark',
    ];

    private static array $qcLabels = [
        'ok' => 'OK',
        'ng' => 'NG',
        'pending' => 'Pending',
    ];

    public static function qcBadge(string $status): string
    {
        return self::$qcBadges[$status] ?? 'bg-secondary';
    }

    public static function qcLabel(string $status): string
    {
        return self::$qcLabels[$status] ?? strtoupper($status);
    }

    // ─── Generic Helper ───

    /**
     * Render HTML badge span.
     *
     * @param  string  $badgeClass  CSS class (e.g., 'bg-success')
     * @param  string  $label       Display text
     * @return string  Raw HTML string
     */
    public static function badge(string $badgeClass, string $label): string
    {
        $escapedLabel = e($label);

        return '<span class="badge ' . $badgeClass . ' text-uppercase" style="font-size:.65rem">' . $escapedLabel . '</span>';
    }

    public static function badgeWithTooltip(string $badgeClass, string $label, ?string $description = null): string
    {
        $escapedLabel = e($label);
        $tooltip = $description
            ? ' data-bs-toggle="tooltip" data-bs-title="' . e($description) . '"'
            : '';

        return '<span class="badge ' . $badgeClass . ' text-uppercase" style="font-size:.65rem"' . $tooltip . '>' . $escapedLabel . '</span>';
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
