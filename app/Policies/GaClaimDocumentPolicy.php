<?php

namespace App\Policies;

use App\Models\GaClaimDocument;
use App\Models\User;

class GaClaimDocumentPolicy
{
    public function view(User $user, GaClaimDocument $document): bool
    {
        if ($user->isFinance() || $user->isAdmin()) {
            return true;
        }

        if ($user->isGa()) {
            return (int) $document->claim?->submitted_by === (int) $user->id;
        }

        return false;
    }
}
