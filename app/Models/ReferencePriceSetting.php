<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferencePriceSetting extends Model
{
    protected $fillable = [
        'active_rule_set_id',
        'updated_by',
    ];

    public function activeRuleSet(): BelongsTo
    {
        return $this->belongsTo(ReferencePriceRuleSet::class, 'active_rule_set_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function getActiveRuleSet(): ?ReferencePriceRuleSet
    {
        $setting = static::query()->with(['activeRuleSet.rules'])->first();

        return $setting?->activeRuleSet;
    }
}
