<?php

namespace App\Policies;

use App\Models\SupplierGoodsLocation;
use App\Models\User;

class SupplierGoodsLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isImportEligible();
    }

    public function create(User $user): bool
    {
        return $user->isImportEligible();
    }

    public function update(User $user, SupplierGoodsLocation $location): bool
    {
        return $user->isImportEligible()
            && (int) $location->supplier_id === (int) $user->id;
    }

    public function delete(User $user, SupplierGoodsLocation $location): bool
    {
        return $this->update($user, $location);
    }

    public function setDefault(User $user, SupplierGoodsLocation $location): bool
    {
        return $this->update($user, $location);
    }
}
