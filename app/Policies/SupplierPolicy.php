<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    /**
     * Payment term menentukan jatuh tempo invoice lokal, jadi hanya Finance
     * dan Admin aktif yang boleh mengubahnya, dan hanya untuk supplier local
     * yang eligible.
     */
    public function updatePaymentTerm(User $user, Supplier $supplier): bool
    {
        return (bool) $user->is_active
            && ($user->isFinance() || $user->isAdmin())
            && $supplier->user?->isLocalEligible() === true;
    }

    /** Import supplier directory with contact-person review (Purchasing domain). */
    public function viewImportDirectory(User $user): bool
    {
        return (bool) $user->is_active && ($user->isPurchasing() || $user->isAdmin());
    }

    /** Approve, reject, or directly set an Import supplier's contact person. */
    public function manageImportContact(User $user): bool
    {
        return $this->viewImportDirectory($user);
    }
}
