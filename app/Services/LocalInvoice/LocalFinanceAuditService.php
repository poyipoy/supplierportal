<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LocalFinanceAuditService
{
    public function record(Model $subject, string $action, User $actor, ?array $before = null, ?array $after = null, ?array $metadata = null): void
    {
        LocalFinanceAuditLog::create([
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'actor_id' => $actor->id,
            'before_values' => $before,
            'after_values' => $after,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
