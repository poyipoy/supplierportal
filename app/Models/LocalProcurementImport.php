<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LocalProcurementImport extends Model
{
    use HasHashids;

    public const ACTIVE = ['QUEUED', 'READING', 'VALIDATING', 'READY', 'IMPORTING'];

    public const KINDS = ['PO', 'GR'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'warnings' => 'array', 'lease_expires_at' => 'datetime',
            'expires_at' => 'datetime', 'finished_at' => 'datetime', 'cleaned_at' => 'datetime'];
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
