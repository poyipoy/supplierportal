<?php

namespace App\Policies;

use App\Models\GaClaim;
use App\Models\User;

class GaClaimPolicy
{
    public function resubmit(User $user, GaClaim $claim): bool
    {
        return $user->is_active && ($user->isAdmin() || ($user->isGa() && (int) $claim->submitted_by === (int) $user->id));
    }

    public function reverifyBank(User $user, GaClaim $claim): bool
    {
        return $user->is_active && ($user->isFinance() || $user->isAdmin()) && $claim->isReadyToPay();
    }
}
