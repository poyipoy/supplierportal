<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SlaEscalationLog extends Model
{
    public const STAGE_REMINDER = 'reminder';

    public const STAGE_ESCALATION = 'escalation';

    protected $fillable = [
        'rule_key',
        'subject_type',
        'subject_id',
        'stage',
        'recipient_user_id',
        'recipient_role',
        'dispatched_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
