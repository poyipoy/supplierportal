<?php

namespace App\Support;

/**
 * Centralized status badge & label helper.
 *
 * Menggantikan match() yang berulang di banyak controller dan view.
 * Panggil: StatusHelper::prBadge($status), StatusHelper::prLabel($status), dst.
 */
class StatusHelper
{
    // ─── Purchase Requirement ───

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
        'accepted' => 'Diterima',
        'rejected' => 'Ditolak',
        'revision_requested' => 'Revisi Diminta',
    ];

    public static function quotationBadge(string $status): string
    {
        return self::$quotationBadges[$status] ?? 'bg-secondary';
    }

    public static function quotationLabel(string $status): string
    {
        return self::$quotationLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
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
     * PO badge — otomatis return 'bg-danger' jika $isOverdue true.
     */
    public static function poBadge(string $status, bool $isOverdue = false): string
    {
        if ($isOverdue) {
            return 'bg-danger';
        }

        return self::$poBadges[$status] ?? 'bg-secondary';
    }

    /**
     * PO label — otomatis return 'Overdue' jika $isOverdue true.
     */
    public static function poLabel(string $status, bool $isOverdue = false): string
    {
        if ($isOverdue) {
            return 'Overdue';
        }

        return self::$poLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    // ─── Material Claim ───

    private static array $claimBadges = [
        'pending' => 'bg-warning text-dark',
        'in_progress' => 'bg-info text-dark',
        'responded' => 'bg-primary',
        'resolved' => 'bg-success',
        'rejected' => 'bg-danger',
        'closed' => 'bg-secondary',
    ];

    private static array $claimLabels = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'responded' => 'Direspon',
        'resolved' => 'Resolved',
        'rejected' => 'Ditolak',
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

    // ─── PO Document ───

    private static array $docBadges = [
        'pending' => 'bg-warning text-dark',
        'uploaded' => 'bg-info text-dark',
        'verified' => 'bg-success',
        'rejected' => 'bg-danger',
    ];

    private static array $docLabels = [
        'pending' => 'Belum Upload',
        'uploaded' => 'Uploaded',
        'verified' => 'Terverifikasi',
        'rejected' => 'Ditolak',
    ];

    public static function docBadge(string $status): string
    {
        return self::$docBadges[$status] ?? 'bg-secondary';
    }

    public static function docLabel(string $status): string
    {
        return self::$docLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
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
}
