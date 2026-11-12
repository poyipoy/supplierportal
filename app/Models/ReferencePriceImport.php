<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferencePriceImport extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uploaded_by',
        'source_file_name',
        'private_storage_path',
        'source_file_hash',
        'source_sheet',
        'import_mode',
        'status',
        'parsed_count',
        'preview_diff',
        'validation_errors',
        'confirmed_rule_set_id',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_count' => 'integer',
            'preview_diff' => 'array',
            'validation_errors' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmedRuleSet(): BelongsTo
    {
        return $this->belongsTo(ReferencePriceRuleSet::class, 'confirmed_rule_set_id');
    }
}
