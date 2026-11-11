<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isPurchasing() || $user->isAdmin() || $user->isQc() || $user->isImportEligible());
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isPurchasing() || $user->isAdmin() || $user->isQc()) {
            return true;
        }

        return $user->isImportEligible() && (int) $purchaseOrder->supplier_id === (int) $user->id;
    }

    public function acknowledge(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->is_active
            && $user->isImportEligible()
            && (int) $purchaseOrder->supplier_id === (int) $user->id;
    }

    public function respondPromiseChange(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->is_active && ($user->isPurchasing() || $user->isAdmin());
    }
}
