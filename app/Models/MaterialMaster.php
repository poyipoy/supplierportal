<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialMaster extends Model
{
    public const HS_CATEGORY_HIGH_SPEED_ROUND_BAR = 'high_speed_steel_round_bar';

    public const HS_CATEGORY_ALLOY_ROUND_BAR_ROLLED = 'alloy_steel_round_bar_rolled';

    public const HS_CATEGORY_ALLOY_FLAT_ROLLED_ROLLED = 'alloy_steel_flat_rolled_rolled';

    public const HS_CATEGORY_ALLOY_FLAT_BAR_FORGED = 'alloy_steel_flat_bar_forged';

    public const HS_CATEGORY_ALLOY_ROUND_BAR_FORGED = 'alloy_steel_round_bar_forged';

    public const HS_CATEGORY_ALLOY_FLAT_BAR_ROLLED = 'alloy_steel_flat_bar_rolled';

    public const HS_CATEGORY_ALLOY_FLAT_ROLLED_STRIP_ROLLED = 'alloy_steel_flat_rolled_strip_rolled';

    public const HS_CATEGORY_HIGH_SPEED_FLAT_ROLLED_ROLLED = 'high_speed_steel_flat_rolled_rolled';

    public const HS_CATEGORY_CARBON_ROUND_BAR = 'carbon_steel_round_bar';

    public const HS_CATEGORY_CARBON_FLAT_BAR = 'carbon_steel_flat_bar';

    public const HS_CATEGORY_CARBON_ROUND_BAR_FORGED = 'carbon_steel_round_bar_forged';

    public const HS_CATEGORY_CARBON_FLAT_BAR_FORGED = 'carbon_steel_flat_bar_forged';

    public const HS_CATEGORY_HONED_TUBE_TUBE = 'honed_tube_steel_tube';

    public const HS_CATEGORY_STRIP_STRIP = 'strip_steel_strip';

    public const HS_CATEGORY_ALLOY_HOLLOW_COLD_DRAWN = 'alloy_steel_hollow_cold_drawn';

    public const HS_CATEGORY_ALLOY_HOLLOW_HOT_DRAWN = 'alloy_steel_hollow_hot_drawn';

    public const HS_CATEGORY_ALLOY = 'alloy_steel';

    public const HS_CATEGORY_HIGH_SPEED = 'high_speed_steel';

    public const HS_CATEGORY_CARBON = 'carbon_steel';

    public const HS_CATEGORY_HONED_TUBE = 'honed_tube_steel';

    public const HS_CATEGORY_STRIP = 'strip_steel';

    public const HS_CATEGORY_OTHER = 'other';

    public const HS_CATEGORIES = [
        self::HS_CATEGORY_HIGH_SPEED_ROUND_BAR,
        self::HS_CATEGORY_ALLOY_ROUND_BAR_ROLLED,
        self::HS_CATEGORY_ALLOY_FLAT_ROLLED_ROLLED,
        self::HS_CATEGORY_ALLOY_FLAT_BAR_FORGED,
        self::HS_CATEGORY_ALLOY_ROUND_BAR_FORGED,
        self::HS_CATEGORY_ALLOY_FLAT_BAR_ROLLED,
        self::HS_CATEGORY_ALLOY_FLAT_ROLLED_STRIP_ROLLED,
        self::HS_CATEGORY_HIGH_SPEED_FLAT_ROLLED_ROLLED,
        self::HS_CATEGORY_CARBON_ROUND_BAR,
        self::HS_CATEGORY_CARBON_FLAT_BAR,
        self::HS_CATEGORY_CARBON_ROUND_BAR_FORGED,
        self::HS_CATEGORY_CARBON_FLAT_BAR_FORGED,
        self::HS_CATEGORY_HONED_TUBE_TUBE,
        self::HS_CATEGORY_STRIP_STRIP,
        self::HS_CATEGORY_ALLOY_HOLLOW_COLD_DRAWN,
        self::HS_CATEGORY_ALLOY_HOLLOW_HOT_DRAWN,
        self::HS_CATEGORY_OTHER,
    ];

    public const LEGACY_HS_CATEGORIES = [
        self::HS_CATEGORY_ALLOY,
        self::HS_CATEGORY_HIGH_SPEED,
        self::HS_CATEGORY_CARBON,
        self::HS_CATEGORY_HONED_TUBE,
        self::HS_CATEGORY_STRIP,
    ];

    public const VALID_HS_CATEGORIES = [
        ...self::HS_CATEGORIES,
        ...self::LEGACY_HS_CATEGORIES,
    ];

    public static function hsCategoryLabel(string $category): string
    {
        return in_array($category, self::VALID_HS_CATEGORIES, true)
            ? __('materials.categories.'.$category)
            : __('common.unknown');
    }

    public static function hsCategoryFamily(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return null;
        }

        return match ($category) {
            self::HS_CATEGORY_ALLOY,
            self::HS_CATEGORY_ALLOY_ROUND_BAR_ROLLED,
            self::HS_CATEGORY_ALLOY_FLAT_ROLLED_ROLLED,
            self::HS_CATEGORY_ALLOY_FLAT_BAR_FORGED,
            self::HS_CATEGORY_ALLOY_ROUND_BAR_FORGED,
            self::HS_CATEGORY_ALLOY_FLAT_BAR_ROLLED,
            self::HS_CATEGORY_ALLOY_FLAT_ROLLED_STRIP_ROLLED,
            self::HS_CATEGORY_ALLOY_HOLLOW_COLD_DRAWN,
            self::HS_CATEGORY_ALLOY_HOLLOW_HOT_DRAWN => self::HS_CATEGORY_ALLOY,

            self::HS_CATEGORY_HIGH_SPEED,
            self::HS_CATEGORY_HIGH_SPEED_ROUND_BAR,
            self::HS_CATEGORY_HIGH_SPEED_FLAT_ROLLED_ROLLED => self::HS_CATEGORY_HIGH_SPEED,

            self::HS_CATEGORY_CARBON,
            self::HS_CATEGORY_CARBON_ROUND_BAR,
            self::HS_CATEGORY_CARBON_FLAT_BAR,
            self::HS_CATEGORY_CARBON_ROUND_BAR_FORGED,
            self::HS_CATEGORY_CARBON_FLAT_BAR_FORGED => self::HS_CATEGORY_CARBON,

            self::HS_CATEGORY_HONED_TUBE,
            self::HS_CATEGORY_HONED_TUBE_TUBE => self::HS_CATEGORY_HONED_TUBE,

            self::HS_CATEGORY_STRIP,
            self::HS_CATEGORY_STRIP_STRIP => self::HS_CATEGORY_STRIP,

            default => $category,
        };
    }

    public static function densityProfileLabel(string $profile): string
    {
        return in_array($profile, self::DENSITY_PROFILES, true)
            ? __('admin.copy.density_'.$profile)
            : __('common.unknown');
    }

    public static function manufacturerScopeLabel(string $scope): string
    {
        return in_array($scope, self::MANUFACTURER_SCOPES, true)
            ? __('admin.copy.manufacturer_scope_'.$scope)
            : __('common.unknown');
    }

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
