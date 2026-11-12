<?php

namespace App\Policies;

use App\Models\ReferencePriceRuleSet;
use App\Models\User;

class ReferencePriceRuleSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, ReferencePriceRuleSet $ruleSet): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, ReferencePriceRuleSet $ruleSet): bool
    {
        return $this->manages($user);
    }

    public function activate(User $user, ReferencePriceRuleSet $ruleSet): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'purchasing'], true);
    }
}
