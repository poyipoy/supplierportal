<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SupplierAuditTemplate extends Model
{
    public const CODE_ISO = 'ISO_14001_9001_AGC_AFC';

    protected $fillable = ['code', 'version', 'title', 'is_active'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SupplierAuditSection::class)->orderBy('sort_order');
    }

    public function criteria(): HasManyThrough
    {
        return $this->hasManyThrough(SupplierAuditCriterion::class, SupplierAuditSection::class)
            ->orderBy('supplier_audit_criteria.sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function activeFor(string $code = self::CODE_ISO): ?self
    {
        return static::query()->active()->where('code', $code)->orderByDesc('version')->first();
    }
}
