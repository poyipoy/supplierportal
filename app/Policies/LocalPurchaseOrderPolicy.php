<?php

namespace App\Policies;

use App\Models\LocalPurchaseOrder;
use App\Models\User;

class LocalPurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && (
            $user->isLocalOperator()
            || $user->isAdmin()
            || $user->isPurchasing()
            || $user->hasSupplierScope('local')
        );
    }

    public function view(User $user, LocalPurchaseOrder $purchaseOrder): bool
    {
        return $user->is_active && (
            $user->isLocalOperator()
            || $user->isAdmin()
            || $user->isPurchasing()
            || ($user->hasSupplierScope('local') && (int) $purchaseOrder->supplier_id === (int) $user->id)
        );
    }

    public function uploadDocument(User $user): bool
    {
        return $user->is_active && (
            $user->isLocalOperator()
            || $user->isAdmin()
            || $user->isPurchasing()
        );
    }
}
