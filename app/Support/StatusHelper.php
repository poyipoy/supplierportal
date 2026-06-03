<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

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

    public static function quotationValidityMeta(mixed $validityPeriod): array
    {
        $date = self::asDate($validityPeriod);

        if (! $date) {
            return [
                'label' => 'Masa Berlaku Kosong',
                'class' => 'bg-warning text-dark',
                'description' => 'Supplier belum mengisi masa berlaku penawaran.',
            ];
        }

        $today = today();
        $days = (int) $today->diffInDays($date, false);

        if ($date->lt($today)) {
            return [
                'label' => 'Kadaluarsa',
                'class' => 'bg-danger',
                'description' => 'Penawaran kadaluarsa dan tidak bisa dibuat PO sebelum supplier mengirim revisi.',
            ];
        }

        if ($days <= 7) {
            return [
                'label' => 'Akan Kadaluarsa',
                'class' => 'bg-warning text-dark',
                'description' => "Masa berlaku tersisa {$days} hari.",
            ];
        }

        return [
            'label' => 'Berlaku',
            'class' => 'bg-success',
            'description' => 'Penawaran masih berlaku.',
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

    public static function poArrivalMeta(mixed $estimatedArrival, bool $isOverdue = false, ?string $status = null, mixed $actualArrival = null): array
    {
        if ($isOverdue) {
            return [
                'label' => 'Overdue',
                'class' => 'bg-danger',
                'description' => 'Estimasi kedatangan sudah lewat dan material belum dikonfirmasi tiba.',
            ];
        }

        $actualDate = self::asDate($actualArrival);
        if ($status === 'waiting_qc' && $actualDate) {
            $daysWaiting = (int) $actualDate->diffInDays(today(), false);

            if ($daysWaiting > 2) {
                return [
                    'label' => 'Menunggu QC > 2 hari',
                    'class' => 'bg-warning text-dark',
                    'description' => "Material sudah tiba {$daysWaiting} hari dan masih menunggu QC.",
                ];
            }

            return [
                'label' => 'Menunggu QC',
                'class' => 'bg-info text-dark',
                'description' => 'Material sudah tiba dan sedang menunggu inspeksi QC.',
            ];
        }

        $date = self::asDate($estimatedArrival);
        if (! $date) {
            return [
                'label' => 'Estimasi Kosong',
                'class' => 'bg-secondary',
                'description' => 'Estimasi kedatangan belum tersedia.',
            ];
        }

        $days = (int) today()->diffInDays($date, false);
        if ($status === 'active' && $days >= 0 && $days <= 7) {
            return [
                'label' => 'Tiba <= 7 hari',
                'class' => 'bg-info text-dark',
                'description' => "Estimasi material tiba dalam {$days} hari.",
            ];
        }

        return [
            'label' => 'Terjadwal',
            'class' => 'bg-light text-muted border',
            'description' => 'Estimasi kedatangan masih sesuai jadwal.',
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
        'responded' => 'Direspon',
        'resolved' => 'Resolved',
        'escalated' => 'Escalated',
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

    public static function claimDeadlineMeta(mixed $deadline, ?string $status = null): array
    {
        $date = self::asDate($deadline);

        if (! $date) {
            return [
                'label' => 'Deadline Kosong',
                'class' => 'bg-secondary',
                'description' => 'Deadline respons klaim belum tersedia.',
            ];
        }

        if ($status !== 'pending') {
            return [
                'label' => 'Sudah Diproses',
                'class' => 'bg-light text-muted border',
                'description' => 'Klaim tidak lagi menunggu respons supplier.',
            ];
        }

        $days = (int) today()->diffInDays($date, false);

        if ($date->lt(today())) {
            return [
                'label' => 'Lewat Deadline',
                'class' => 'bg-danger',
                'description' => 'Supplier sudah melewati batas waktu respons klaim.',
            ];
        }

        if ($days <= 3) {
            return [
                'label' => 'Deadline <= 3 hari',
                'class' => 'bg-warning text-dark',
                'description' => "Deadline respons tersisa {$days} hari.",
            ];
        }

        return [
            'label' => 'Masih Aman',
            'class' => 'bg-success',
            'description' => 'Deadline respons klaim masih aman.',
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
        'pending' => 'Belum Upload',
        'uploaded' => 'Uploaded',
        'received' => 'Diterima',
        'done' => 'Selesai',
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

    public static function documentProgressMeta(int $completed, int $total = 4): array
    {
        $total = max($total, 4);
        $isComplete = $completed >= $total;

        return [
            'label' => "{$completed}/{$total} lengkap",
            'class' => $isComplete ? 'bg-success' : 'bg-warning text-dark',
            'description' => $isComplete
                ? 'Semua dokumen impor sudah lengkap.'
                : 'Masih ada dokumen impor yang perlu dilengkapi atau diverifikasi.',
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
