<?php

namespace App\Policies;

use App\Models\MaterialReferencePrice;
use App\Models\User;

/**
 * Mission 06B: Admin and Purchasing manage reference prices (create,
 * supersede, void, notes). Prices are never edited or deleted. Suppliers and
 * every other role are denied; reference prices are internal evaluation data.
 */
class MaterialReferencePricePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function supersede(User $user, MaterialReferencePrice $price): bool
    {
        return $this->manages($user);
    }

    public function void(User $user, MaterialReferencePrice $price): bool
    {
        return $this->manages($user);
    }

    public function updateNotes(User $user, MaterialReferencePrice $price): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'purchasing'], true);
    }
}
