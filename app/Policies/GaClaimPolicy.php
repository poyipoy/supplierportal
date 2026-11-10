<?php

namespace App\Policies;

use App\Models\GaClaim;
use App\Models\User;

class GaClaimPolicy
{
    /**
     * Viewing a claim receipt, which prints employee payment details.
     * Mirrors GaClaimDocumentPolicy::view(): Finance and admin see every
     * claim, a GA user only the claims they submitted. The receipt routes
     * used to rely on role middleware alone.
     */
    public function view(User $user, GaClaim $claim): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isFinance() || $user->isAdmin()) {
            return true;
        }

        if ($user->isGa()) {
            return (int) $claim->submitted_by === (int) $user->id;
        }

        return false;
    }

    public function resubmit(User $user, GaClaim $claim): bool
    {
        return $user->is_active && ($user->isAdmin() || ($user->isGa() && (int) $claim->submitted_by === (int) $user->id));
    }

    public function reverifyBank(User $user, GaClaim $claim): bool
    {
        return $user->is_active && ($user->isFinance() || $user->isAdmin()) && $claim->isReadyToPay();
    }
}
