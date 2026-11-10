<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaClaimDocument extends Model
{
    use HasHashids;

    protected $fillable = [
        'ga_claim_id',
        'revision_number',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_by',
        'file_inspection_id',
        'created_at',
        'updated_at',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(GaClaim::class, 'ga_claim_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
