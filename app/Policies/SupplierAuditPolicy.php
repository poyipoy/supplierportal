<?php

namespace App\Policies;

use App\Models\SupplierAudit;
use App\Models\User;

class SupplierAuditPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->purchasing($user) || $this->localSupplier($user);
    }

    public function view(User $user, SupplierAudit $audit): bool
    {
        return $this->purchasing($user) || $this->owner($user, $audit);
    }

    public function fill(User $user, SupplierAudit $audit): bool
    {
        return $this->owner($user, $audit) && $audit->isEditableBySupplier();
    }

    public function create(User $user): bool
    {
        return $this->purchasing($user);
    }

    public function changeDeadline(User $user, SupplierAudit $audit): bool
    {
        return $this->purchasing($user) && $audit->isActive();
    }

    public function requestRevision(User $user, SupplierAudit $audit): bool
    {
        return $this->purchasing($user) && $audit->status === SupplierAudit::STATUS_SUBMITTED;
    }

    public function cancel(User $user, SupplierAudit $audit): bool
    {
        return $this->purchasing($user) && in_array($audit->status, SupplierAudit::CANCELLABLE_STATUSES, true);
    }

    public function uploadResult(User $user, SupplierAudit $audit): bool
    {
        return $this->purchasing($user) && in_array($audit->status, SupplierAudit::RESULT_UPLOADABLE_STATUSES, true);
    }

    public function export(User $user, SupplierAudit $audit): bool
    {
        return $this->purchasing($user) && $audit->isExportable();
    }

    private function purchasing(User $user): bool
    {
        return (bool) $user->is_active && $user->isPurchasing();
    }

    private function localSupplier(User $user): bool
    {
        return (bool) $user->is_active && $user->isSupplier() && $user->hasSupplierScope('local');
    }

    private function owner(User $user, SupplierAudit $audit): bool
    {
        return $this->localSupplier($user) && (int) $audit->supplier_id === (int) $user->id;
    }
}
