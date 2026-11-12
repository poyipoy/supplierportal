<?php

namespace App\Services\Pricing;

use App\Models\PrItem;
use App\Models\QuotationItem;
use App\Models\ReferencePriceRule;
use App\Models\ReferencePriceRuleSet;
use App\Models\ReferencePriceSetting;
use App\Support\Money;

class ReferencePriceRuleResolver
{
    public const DIMENSION_FIELD_BY_SHAPE = [
        PrItem::SHAPE_FLAT => 'thickness',
        PrItem::SHAPE_ROUND => 'd_outer',
        PrItem::SHAPE_HOLLOW => 'd_outer',
    ];

    public function resolve(
        ?string $shape,
        float|string|int|null $dimension,
        ?ReferencePriceRuleSet $ruleSet = null
    ): ReferencePriceRuleResult {
        if (empty($shape) || ! isset(self::DIMENSION_FIELD_BY_SHAPE[$shape])) {
            return new ReferencePriceRuleResult(
                matched: false,
                reasonCode: 'shape_unsupported',
            );
        }

        $dimensionField = self::DIMENSION_FIELD_BY_SHAPE[$shape];

        if ($dimension === null || $dimension === '') {
            return new ReferencePriceRuleResult(
                matched: false,
                dimensionField: $dimensionField,
                reasonCode: 'dimension_missing',
            );
        }

        $numericVal = (float) $dimension;
        if ($numericVal <= 0) {
            return new ReferencePriceRuleResult(
                matched: false,
                dimensionField: $dimensionField,
                reasonCode: 'dimension_missing',
            );
        }

        $rawFormatted = Money::normalize((string) $dimension, 4);
        $rounded = self::ceilDimension($dimension);

        $ruleSet ??= ReferencePriceSetting::getActiveRuleSet();
        if (! $ruleSet) {
            return new ReferencePriceRuleResult(
                matched: false,
                dimensionField: $dimensionField,
                rawDimension: $rawFormatted,
                roundedDimension: $rounded,
                reasonCode: 'rule_not_found',
            );
        }

        $rules = $ruleSet->relationLoaded('rules') ? $ruleSet->rules : $ruleSet->rules()->orderBy('sort_order')->get();

        $matchedRule = $rules->first(fn (ReferencePriceRule $r) => $rounded >= $r->dimension_min && $rounded <= $r->dimension_max);

        if (! $matchedRule) {
            return new ReferencePriceRuleResult(
                matched: false,
                dimensionField: $dimensionField,
                rawDimension: $rawFormatted,
                roundedDimension: $rounded,
                reasonCode: 'dimension_not_covered',
            );
        }

        if ($matchedRule->status !== ReferencePriceRule::STATUS_ACTIVE) {
            return new ReferencePriceRuleResult(
                matched: false,
                rule: $matchedRule,
                dimensionField: $dimensionField,
                rawDimension: $rawFormatted,
                roundedDimension: $rounded,
                reasonCode: 'rule_inactive',
            );
        }

        return new ReferencePriceRuleResult(
            matched: true,
            rule: $matchedRule,
            dimensionField: $dimensionField,
            rawDimension: $rawFormatted,
            roundedDimension: $rounded,
        );
    }

    public function resolveForPrItem(PrItem $prItem, ?ReferencePriceRuleSet $ruleSet = null): ReferencePriceRuleResult
    {
        $shape = $prItem->shape;
        $dimensionField = self::DIMENSION_FIELD_BY_SHAPE[$shape] ?? null;
        $dimension = $dimensionField ? $prItem->{$dimensionField} : null;

        return $this->resolve($shape, $dimension, $ruleSet);
    }

    public function resolveForQuotationItem(QuotationItem $quotationItem, ?ReferencePriceRuleSet $ruleSet = null): ReferencePriceRuleResult
    {
        $prItem = $quotationItem->prItem;
        $shape = $prItem?->shape;
        $dimensionField = self::DIMENSION_FIELD_BY_SHAPE[$shape] ?? null;
        $availableField = $dimensionField ? 'available_'.$dimensionField : null;
        $dimension = ($availableField && $quotationItem->{$availableField} !== null)
            ? $quotationItem->{$availableField}
            : ($dimensionField ? $prItem?->{$dimensionField} : null);

        return $this->resolve($shape, $dimension, $ruleSet);
    }

    public static function ceilDimension(string|float|int $dimension): int
    {
        $str = is_float($dimension) ? sprintf('%.8F', $dimension) : (string) $dimension;
        $str = rtrim(rtrim($str, '0'), '.');
        if (str_contains($str, '.')) {
            [$intPart, $fracPart] = explode('.', $str, 2);
            if ($fracPart !== '' && preg_match('/[1-9]/', $fracPart)) {
                return (int) $intPart + 1;
            }

            return (int) $intPart;
        }

        return (int) $str;
    }
}
