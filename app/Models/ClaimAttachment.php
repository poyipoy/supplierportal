<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimAttachment extends Model
{
    protected $fillable = [
        'claim_id',
        'file_path',
        'uploaded_by',
    ];

    // ─── Relationships ───

    public function materialClaim(): BelongsTo
    {
        return $this->belongsTo(MaterialClaim::class, 'claim_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
