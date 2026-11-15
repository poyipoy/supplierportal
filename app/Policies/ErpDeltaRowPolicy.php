<?php

namespace App\Policies;

use App\Models\ErpDeltaRow;
use App\Models\User;

class ErpDeltaRowPolicy
{
    public function viewHashes(User $user, ?User $targetSupplier = null): bool
    {
        return $this->canActForTargetSupplier($user, $targetSupplier);
    }

    public function sync(User $user, ?User $targetSupplier = null): bool
    {
        return $this->canActForTargetSupplier($user, $targetSupplier);
    }

    public function reconcile(User $user, ?User $targetSupplier = null): bool
    {
        return $this->canActForTargetSupplier($user, $targetSupplier);
    }

    public function apply(User $user, ?User $targetSupplier = null): bool
    {
        return $this->isActive($user)
            && $this->isProcurementStaff($user)
            && ($targetSupplier === null || $targetSupplier->isSupplier());
    }

    public function ignore(User $user, ?User $targetSupplier = null): bool
    {
        return $this->apply($user, $targetSupplier);
    }

    public function view(User $user, ErpDeltaRow $erpDeltaRow): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        if ($this->isProcurementStaff($user)) {
            return true;
        }

        return $this->isEligibleSupplier($user)
            && (int) $erpDeltaRow->supplier_id === (int) $user->id;
    }

    public function update(User $user, ErpDeltaRow $erpDeltaRow): bool
    {
        return $this->view($user, $erpDeltaRow);
    }

    private function canActForTargetSupplier(User $user, ?User $targetSupplier): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        if ($this->isProcurementStaff($user)) {
            return $targetSupplier === null || $targetSupplier->isSupplier();
        }

        if (! $this->isEligibleSupplier($user)) {
            return false;
        }

        return $targetSupplier === null || (int) $targetSupplier->id === (int) $user->id;
    }

    private function isActive(User $user): bool
    {
        return (bool) $user->is_active && $user->account_status === User::ACCOUNT_STATUS_ACTIVE;
    }

    private function isProcurementStaff(User $user): bool
    {
        return $user->isFinance() || $user->isPurchasing() || $user->isAdmin();
    }

    private function isEligibleSupplier(User $user): bool
    {
        return $user->isSupplier() && ($user->isImportEligible() || $user->isLocalEligible());
    }
}
