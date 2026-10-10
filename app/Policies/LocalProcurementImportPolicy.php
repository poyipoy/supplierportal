<?php

namespace App\Policies;

use App\Models\LocalProcurementImport;
use App\Models\User;

class LocalProcurementImportPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->account_status === User::ACCOUNT_STATUS_ACTIVE
            && ($user->isFinance() || $user->isPurchasing() || $user->isAdmin());
    }

    public function view(User $user, LocalProcurementImport $import): bool
    {
        return $this->create($user) && (int) $import->user_id === (int) $user->id;
    }

    public function confirm(User $user, LocalProcurementImport $import): bool
    {
        return $this->view($user, $import);
    }
}
