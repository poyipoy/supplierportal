<?php

namespace App\Policies;

use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'purchasing'], true);
    }
}

