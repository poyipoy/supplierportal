<?php

namespace App\Policies;

use App\Models\LocalInvoice;
use App\Models\User;

class LocalInvoicePolicy
{
    public function view(User $user, LocalInvoice $invoice): bool
    {
        return $user->is_active && ($user->isLocalOperator() || $user->isAdmin() || $user->isPurchasing()
            || ($user->hasSupplierScope('local') && (int) $invoice->supplier_id === (int) $user->id));
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasSupplierScope('local');
    }

    public function resubmit(User $user, LocalInvoice $invoice): bool
    {
        return $this->create($user) && (int) $invoice->supplier_id === (int) $user->id;
    }

    public function verifyPhysical(User $user, LocalInvoice $invoice): bool
    {
        return $this->operate($user);
    }

    public function startReview(User $user, LocalInvoice $invoice): bool
    {
        return $this->operate($user);
    }

    public function requestRevision(User $user, LocalInvoice $invoice): bool
    {
        return $this->operate($user);
    }

    public function reject(User $user, LocalInvoice $invoice): bool
    {
        return $this->operate($user);
    }

    public function approve(User $user, LocalInvoice $invoice): bool
    {
        return $this->operate($user);
    }

    public function schedulePayment(User $user, LocalInvoice $invoice): bool
    {
        return $this->operate($user);
    }

    public function completePayment(User $user, LocalInvoice $invoice): bool
    {
        return $this->operate($user);
    }

    private function operate(User $user): bool
    {
        return $user->is_active && $user->isLocalOperator();
    }
}
