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

    protected $fillable = [
        'user_id',
        'supplier_id',
        'request_key',
        'locale',
        'status',
        'asynchronous',
        'budget',
        'original_path',
        'original_filename',
        'sha256',
        'upload_bytes',
        'original_file_inspection_id',
        'pdf_count',
        'processed_count',
        'actual_bytes',
        'reserved_bytes',
        'attempt',
        'retry_count',
        'processing_milliseconds',
        'dispatch_token',
        'run_token',
        'dispatch_requested_at',
        'dispatched_at',
        'lease_expires_at',
        'finished_at',
        'cleaned_at',
        'failure_code',
        'created_at',
        'updated_at',
    ];

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
