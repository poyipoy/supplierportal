<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnnualExchangeRate extends Model
{
    public const CURRENCIES = ['USD', 'JPY', 'CNY'];

    protected $fillable = ['year', 'revision', 'currency', 'rate_to_idr', 'created_by'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'revision' => 'integer', 'rate_to_idr' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Annual exchange rates are append-only.'));
        static::deleting(fn () => throw new LogicException('Annual exchange rates are append-only.'));
    }

    public function scopeLatestRevisions(Builder $query): void
    {
        $query->whereRaw('revision = (SELECT MAX(latest.revision) FROM annual_exchange_rates AS latest WHERE latest.year = annual_exchange_rates.year)');
    }
}
