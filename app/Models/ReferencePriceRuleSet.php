<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferencePriceRuleSet extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_DRAFT = 'draft';

    public const MODE_REPLACE = 'replace';

    public const MODE_MERGE = 'merge';

    public const MODE_MANUAL = 'manual';

    protected $fillable = [
        'version_no',
        'status',
        'import_mode',
        'source_file_name',
        'source_file_hash',
        'source_sheet',
        'created_by',
        'activated_by',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ReferencePriceRule::class, 'rule_set_id')->orderBy('sort_order')->orderBy('dimension_min');
    }

    public function activeRules(): HasMany
    {
        return $this->rules()->where('status', ReferencePriceRule::STATUS_ACTIVE);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
