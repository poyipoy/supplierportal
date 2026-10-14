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
}
