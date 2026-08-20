<?php

namespace App\Services\Materials;

use App\Data\Materials\HsCodeResolutionResult;
use App\Data\Materials\ProcessedPrItemResult;
use App\Data\Materials\WeightCalculationResult;
use App\Models\MaterialMaster;
use App\Models\PrItem;
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
            $errors['material_master_id'] = 'Select an active material from the master list.';

            return new ProcessedPrItemResult(
                [],
                $errors,
                new HsCodeResolutionResult('unmapped_material', null, null, [], 'Material could not be resolved.'),
                new WeightCalculationResult('incomplete', null, null, null, null, 'Material could not be resolved.'),
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
                $errors[$field] = 'Dimension must be greater than zero when provided.';
            }
        }
        if ($shape === PrItem::SHAPE_HOLLOW
            && $dimensions['d_inner'] !== null
            && $dimensions['d_outer'] !== null
            && $dimensions['d_inner'] >= $dimensions['d_outer']) {
            $errors['d_inner'] = 'Inner diameter must be smaller than outer diameter.';
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
                $errors[$hsManualOverride ? 'hs_code' : 'manual_hs_code'] = 'HS Code must contain exactly eight digits.';
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
                $errors['weight_needed'] = 'Manual KG per unit must be greater than zero.';
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
                $errors['shape'] = 'Shape is required before submitting.';
            }
            foreach (PrItem::relevantDimensionFields($shape) as $field) {
                if ($dimensions[$field] === null) {
                    $errors[$field] = 'This dimension is required before submitting.';
                }
            }
            if (! $weight->isCalculated() || ($weight->unitKg ?? 0) <= 0) {
                $errors['weight_needed'] = $weight->message;
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
