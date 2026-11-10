<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaClaimReceipt extends Model
{
    protected $fillable = [
        'ga_claim_id',
        'receipt_number',
        'issued_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(GaClaim::class, 'ga_claim_id');
    }
}
