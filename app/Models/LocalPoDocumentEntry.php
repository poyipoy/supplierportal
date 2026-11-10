<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalPoDocumentEntry extends Model
{
    protected $fillable = [
        'batch_id',
        'entry_index',
        'filename',
        'local_purchase_order_id',
        'declared_bytes',
        'actual_bytes',
        'sha256',
        'staging_path',
        'final_path',
        'file_inspection_id',
        'attachment_id',
        'created_at',
        'updated_at',
    ];
}
