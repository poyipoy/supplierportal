<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileInspection extends Model
{
    public const STATUS_VALIDATED = 'VALIDATED';

    protected $fillable = ['profile', 'status', 'policy_version', 'file_path', 'file_size', 'file_type', 'sha256', 'inspected_at', 'error_code', 'metadata'];

    protected $casts = ['file_size' => 'integer', 'inspected_at' => 'datetime', 'metadata' => 'array'];
}
