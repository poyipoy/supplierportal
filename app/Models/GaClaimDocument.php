<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaClaimDocument extends Model
{
    use HasHashids;

    protected $guarded = ['id'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(GaClaim::class, 'ga_claim_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
