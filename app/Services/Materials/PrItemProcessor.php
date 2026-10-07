<?php

namespace App\Services\Materials;

use App\Data\Materials\HsCodeResolutionResult;
use App\Data\Materials\ProcessedPrItemResult;
use App\Data\Materials\WeightCalculationResult;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Support\Materials\MaterialDimensionRules;
use Illuminate\Support\Collection;

final class PrItemProcessor
{
    public function __construct(
        private readonly MaterialResolver $materials,
        private readonly HsCodeResolver $hsCodes,
        private readonly MaterialWeightCalculator $weights,
    ) {}

    public function process(
        array $input,
        bool $submitting,
        ?int $actorId,
        ?PrItem $existing = null,
        ?Collection $rules = null,
        ?MaterialMaster $resolvedMaterial = null,
    ): ProcessedPrItemResult {
        $errors = [];
        $materialId = filter_var($input['material_master_id'] ?? null, FILTER_VALIDATE_INT);
        $material = $resolvedMaterial?->is_active && $resolvedMaterial->id === (int) $materialId
            ? $resolvedMaterial
            : ($materialId ? $this->materials->resolveById((int) $materialId) : null);

        if ($material === null) {
            $errors['material_master_id'] = __('materials.copy.select_an_active_material_from_the_master_list');

            return new ProcessedPrItemResult(
                [],
                $errors,
                new HsCodeResolutionResult('unmapped_material', null, null, [], __('materials.copy.material_could_not_be_resolved')),
                new WeightCalculationResult('incomplete', null, null, null, null, __('materials.copy.material_could_not_be_resolved')),
            );
        }

        $shape = in_array($input['shape'] ?? null, PrItem::SHAPES, true) ? $input['shape'] : null;
        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $dimensions = [];
        foreach (PrItem::DIMENSION_FIELDS as $field) {
            $dimensions[$field] = in_array($field, PrItem::relevantDimensionFields($shape), true)
                ? $this->nullableNumeric($input[$field] ?? null)
                : null;
        }

        foreach (PrItem::relevantDimensionFields($shape) as $field) {
            if ($dimensions[$field] !== null && $dimensions[$field] <= 0) {
                $errors[$field] = __('materials.copy.dimension_must_be_greater_than_zero_when_provided');
            }
        }
        if ($shape === PrItem::SHAPE_HOLLOW
            && ! MaterialDimensionRules::hasValidHollowDiameterPair($dimensions['d_inner'] ?? null, $dimensions['d_outer'] ?? null)) {
            $errors['d_inner'] = __('materials.copy.inner_diameter_must_be_smaller_than_outer_diameter');
        }

        $hsCode = $this->hsCodes->resolve($material, $shape, $dimensions, $rules);
        $weight = $this->weights->calculate($material, $shape, $dimensions, $quantity);

        $storedHsCode = $hsCode->hsCode;
        $storedRuleId = $hsCode->ruleId;
        $hsSource = 'auto';
        $manualSelectedBy = null;
        $manualSelectedAt = null;
        $hsManualOverride = $this->isTruthy($input['hs_code_manual_override'] ?? false);
        $manualRaw = $hsManualOverride
            ? trim((string) ($input['hs_code'] ?? ''))
            : trim((string) ($input['manual_hs_code'] ?? ''));
        $manualSelectionAllowed = $hsManualOverride
            || (! $hsCode->isMatched() && $hsCode->allowsManualSelection());

        if ($manualSelectionAllowed && $manualRaw !== '') {
            $canonicalManual = $this->canonicalHsCode($manualRaw);
            if ($canonicalManual === null) {
                $errors[$hsManualOverride ? 'hs_code' : 'manual_hs_code'] = __('materials.copy.hs_code_must_contain_exactly_eight_digits');
            } else {
                $storedHsCode = $canonicalManual;
                $storedRuleId = null;
                $hsSource = 'manual';

                if ($existing?->hs_code_source === 'manual' && $existing->hs_code === $canonicalManual) {
                    $manualSelectedBy = $existing->hs_code_manual_selected_by;
                    $manualSelectedAt = $existing->hs_code_manual_selected_at;
                } else {
                    $manualSelectedBy = $actorId;
                    $manualSelectedAt = now();
                }
            }
        }

        $storedWeight = $weight->unitKg ?? 0.0;
        $storedWeightStatus = $weight->status;
        $storedWeightFormula = $weight->formulaKey;
        $storedWeightFactor = $weight->factor;
        $storedWeightCalculatedAt = $weight->isCalculated() ? now() : null;
        $weightManualOverride = $this->isTruthy($input['weight_manual_override'] ?? false);
        $manualWeightRaw = $input['weight_needed'] ?? null;

        if ($weightManualOverride && $manualWeightRaw !== null && $manualWeightRaw !== '') {
            if (! is_numeric($manualWeightRaw) || (float) $manualWeightRaw <= 0) {
                $errors['weight_needed'] = __('materials.copy.manual_kg_per_unit_must_be_greater_than_zero');
            } else {
                $storedWeight = round((float) $manualWeightRaw, 4, PHP_ROUND_HALF_UP);
                $storedWeightStatus = PrItem::WEIGHT_STATUS_MANUAL;
                $storedWeightFormula = 'manual';
                $storedWeightFactor = null;
                $storedWeightCalculatedAt = null;
            }
        }

        if ($submitting) {
            if ($shape === null) {
                $errors['shape'] = __('materials.copy.shape_is_required_before_submitting');
            }
            foreach (PrItem::relevantDimensionFields($shape) as $field) {
                if ($dimensions[$field] === null) {
                    $errors[$field] = __('materials.copy.this_dimension_is_required_before_submitting');
                }
            }
            if (! $weight->isCalculated() || ($weight->unitKg ?? 0) <= 0) {
                $errors['weight_needed'] = $weight->messageKey !== null ? __($weight->messageKey) : $weight->message;
            }
        }

        $data = [
            'material_master_id' => $material->id,
            'material_name' => $material->material_code,
            'quantity' => $quantity,
            'shape' => $shape,
            ...$dimensions,
            'hs_code' => $storedHsCode,
            'hs_code_rule_id' => $storedRuleId,
            'hs_code_source' => $hsSource,
            'hs_code_resolution_status' => $hsCode->status,
            'hs_code_manual_selected_by' => $manualSelectedBy,
            'hs_code_manual_selected_at' => $manualSelectedAt,
            'weight_needed' => $storedWeight,
            'weight_calculation_status' => $storedWeightStatus,
            'weight_formula_key' => $storedWeightFormula,
            'weight_factor' => $storedWeightFactor,
            'weight_calculated_at' => $storedWeightCalculatedAt,
            'remark' => $this->nullableText($input['remark'] ?? null),
        ];

        return new ProcessedPrItemResult($data, $errors, $hsCode, $weight);
    }

    public function canonicalHsCode(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{8}$/', $value)) {
            $digits = $value;
        } elseif (preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $value)) {
            $digits = str_replace('.', '', $value);
        } else {
            return null;
        }

        return substr($digits, 0, 4).'.'.substr($digits, 4, 2).'.'.substr($digits, 6, 2);
    }

    private function nullableNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
