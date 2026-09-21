<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\MaterialClaim;
use App\Models\Message;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\ShipmentDocument;
use App\Models\SupplierOverpaymentRefund;
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
        if ($user->role === 'admin') {
            return true;
        }
        if ($user->role === 'purchasing' && $attachment->attachable_type !== SupplierOverpaymentRefund::class) return true;

        $attachable = $attachment->attachable;

        if (! $attachable) {
            return false;
        }

        $type = $attachment->attachable_type;

        if ($user->role === 'finance') {
            return $type === SupplierOverpaymentRefund::class;
        }

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

                QuotationItem::class => $attachable->quotation
                    && $attachable->quotation->supplier_id === $user->id,

                PurchaseOrder::class => $attachable->supplier_id === $user->id,

                QcInspection::class => $attachable->purchaseOrder
                    && $attachable->purchaseOrder->supplier_id === $user->id,

                MaterialClaim::class => $attachable->supplier_id === $user->id,

                ShipmentDocument::class => $attachable->shipment
                    && (int) $attachable->shipment->supplier_id === (int) $user->id,

                Message::class => $attachable->conversation
                    && $attachable->conversation->isMember($user->id),

                default => false,
            };
        }

        return false;
    }
}
