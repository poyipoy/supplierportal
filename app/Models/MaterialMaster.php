<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialMaster extends Model
{
    public const HS_CATEGORY_ALLOY = 'alloy_steel';

    public const HS_CATEGORY_HIGH_SPEED = 'high_speed_steel';

    public const HS_CATEGORY_CARBON = 'carbon_steel';

    public const HS_CATEGORY_HONED_TUBE = 'honed_tube_steel';

    public const HS_CATEGORY_STRIP = 'strip_steel';

    public const HS_CATEGORY_OTHER = 'other';

    public const HS_CATEGORIES = [
        self::HS_CATEGORY_ALLOY,
        self::HS_CATEGORY_HIGH_SPEED,
        self::HS_CATEGORY_CARBON,
        self::HS_CATEGORY_HONED_TUBE,
        self::HS_CATEGORY_STRIP,
        self::HS_CATEGORY_OTHER,
    ];

    public const DENSITY_STEEL = 'steel';

    public const DENSITY_ALUMINIUM = 'aluminium';

    public const DENSITY_PROFILES = [self::DENSITY_STEEL, self::DENSITY_ALUMINIUM];

    public const MANUFACTURER_DAIDO = 'daido';

    public const MANUFACTURER_NON_DAIDO = 'non_daido';

    public const MANUFACTURER_UNKNOWN = 'unknown';

    public const MANUFACTURER_SCOPES = [
        self::MANUFACTURER_DAIDO,
        self::MANUFACTURER_NON_DAIDO,
        self::MANUFACTURER_UNKNOWN,
    ];

    protected $fillable = [
        'material_code',
        'normalized_code',
        'raw_category',
        'hs_category',
        'density_profile',
        'manufacturer_scope',
        'is_active',
        'source_file',
        'source_sheet',
        'source_row',
        'hs_source_ref',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'source_row' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MaterialAlias::class);
    }

    public function prItems(): HasMany
    {
        return $this->hasMany(PrItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
