<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAuditStatusHistory extends Model
{
    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_DRAFT_STARTED = 'draft_started';

    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_REVISION_REQUESTED = 'revision_requested';

    public const EVENT_CANCELLED = 'cancelled';

    public const EVENT_RESULT_PUBLISHED = 'result_published';

    public const EVENT_RESULT_REPLACED = 'result_replaced';

    public const EVENT_DEADLINE_CHANGED = 'deadline_changed';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(SupplierAudit::class, 'supplier_audit_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
