<?php

namespace App\Policies;

use App\Models\MaterialMaster;
use App\Models\User;

/**
 * Decision 28 / Q3: Admin and Purchasing manage the MaterialMaster master, including the
 * guarded hard delete. No other role, and no other Admin area, is implied.
 */
class MaterialMasterPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, MaterialMaster $model): bool
    {
        return $this->manages($user);
    }

    public function toggleStatus(User $user, MaterialMaster $model): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, MaterialMaster $model): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'purchasing'], true);
    }
}
