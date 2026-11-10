<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaClaimStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ga_claim_id',
        'from_status',
        'to_status',
        'actor_id',
        'event',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(GaClaim::class, 'ga_claim_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
