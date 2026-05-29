<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;

class AttachmentPolicy
{
    /**
     * Admin & Purchasing boleh melihat semua attachment.
     * QC boleh melihat attachment inspeksi dan PO.
     * Supplier hanya boleh melihat attachment milik datanya sendiri.
     */
    public function view(User $user, Attachment $attachment): bool
    {
        // Admin & Purchasing: akses penuh
        if (in_array($user->role, ['admin', 'purchasing'])) {
            return true;
        }

        $attachable = $attachment->attachable;

        if (! $attachable) {
            return false;
        }

        $type = $attachment->attachable_type;

        // ── QC ──
        if ($user->role === 'qc') {
            return in_array($type, [
                QcInspection::class,
                PurchaseOrder::class,
                MaterialClaim::class,
            ]);
        }

        // ── Supplier ── hanya data milik sendiri
        if ($user->role === 'supplier') {
            return match ($type) {
                Quotation::class => $attachable->supplier_id === $user->id,

                PurchaseOrder::class => $attachable->supplier_id === $user->id,

                QcInspection::class => $attachable->purchaseOrder
                    && $attachable->purchaseOrder->supplier_id === $user->id,

                MaterialClaim::class => $attachable->supplier_id === $user->id,

                default => false,
            };
        }

        return false;
    }
}
