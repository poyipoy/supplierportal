<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalPoDocumentBatch extends Model
{
    use HasHashids;

    public const ACTIVE = ['PENDING', 'PROCESSING', 'VALIDATED', 'PUBLISHING'];

    public const TERMINAL = ['COMPLETED', 'REJECTED', 'FAILED'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['budget' => 'array', 'asynchronous' => 'boolean', 'dispatch_requested_at' => 'datetime', 'dispatched_at' => 'datetime',
            'lease_expires_at' => 'datetime', 'finished_at' => 'datetime', 'cleaned_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LocalPoDocumentEntry::class, 'batch_id');
    }
}
