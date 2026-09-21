<?php

namespace App\Policies;

use App\Models\SupplierMasterDocument;
use App\Models\User;

class SupplierMasterDocumentPolicy
{
    public function view(User $user, SupplierMasterDocument $document): bool
    {
        if ($user->isFinance() || $user->isPurchasing() || $user->isAdmin()) {
            return true;
        }

        if ($user->isSupplier()) {
            return (int) $document->supplier_id === (int) $user->id;
        }

        return false;
    }
}
