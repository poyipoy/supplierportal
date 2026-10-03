<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationMute extends Model
{
    use HasFactory;

    public const TYPE_CONVERSATION = 'conversation';

    public const ALLOWED_SUBJECT_TYPES = [
        self::TYPE_CONVERSATION,
    ];

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
