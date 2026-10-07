<?php

namespace App\Services\Materials;

use App\Data\Materials\HsCodeConditionSet;
use App\Data\Materials\HsCodeResolutionResult;
use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use Illuminate\Support\Collection;

final class HsCodeResolver
{
    public function resolve(
        MaterialMaster $material,
        ?string $shape,
        array $dimensions,
        ?Collection $rules = null,
    ): HsCodeResolutionResult {
        if ($material->hs_category === null || $material->hs_category === '') {
            return new HsCodeResolutionResult(
                'unmapped_material',
                null,
                null,
                [],
                __('materials.copy.the_selected_material_has_no_canonical_hs_category'),
                $dimensions,
            );
        }

        if (! in_array($shape, PrItem::SHAPES, true)) {
            return new HsCodeResolutionResult(
                'insufficient_data',
                null,
                null,
                [],
                __('materials.copy.select_a_material_shape_to_resolve_the_hs_code'),
                $dimensions,
            );
        }

        $candidates = ($rules ?? HsCodeRule::query()->active()->get())
            ->where('status', HsCodeRule::STATUS_ACTIVE)
            ->where('material_category', $material->hs_category)
            ->where('shape', $shape)
            ->sortBy(fn (HsCodeRule $rule) => sprintf('%05d:%s', $rule->priority, $rule->rule_key))
            ->values();

        if ($candidates->isEmpty()) {
            return new HsCodeResolutionResult(
                'no_rule',
                null,
                null,
                [],
                __('materials.copy.no_active_hs_code_rule_covers_this_category_and_shape'),
                $dimensions,
            );
        }

        $matches = collect();
        $hasIncompleteEvaluation = false;

        foreach ($candidates as $rule) {
            $evaluation = HsCodeConditionSet::fromArray($rule->conditions)->evaluate($dimensions);
            if ($evaluation === null) {
                $hasIncompleteEvaluation = true;
            } elseif ($evaluation) {
                $matches->push($rule);
            }
        }

        if ($matches->isEmpty()) {
            return new HsCodeResolutionResult(
                $hasIncompleteEvaluation ? 'insufficient_data' : 'no_rule',
                null,
                null,
                [],
                $hasIncompleteEvaluation
                    ? __('materials.copy.complete_the_dimensions_needed_to_evaluate_the_hs_code_rules')
                    : __('materials.copy.no_active_hs_code_rule_matches_the_supplied_dimensions'),
                $dimensions,
            );
        }

        $winningPriority = (int) $matches->min('priority');
        $winners = $matches->where('priority', $winningPriority)->values();
        $codes = $winners->pluck('hs_code')->unique()->values();
        $candidatePayload = $winners->map(fn (HsCodeRule $rule) => [
            'rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'hs_code' => $rule->hs_code,
            'priority' => $rule->priority,
        ])->all();

        if ($codes->count() > 1) {
            return new HsCodeResolutionResult(
                'ambiguous',
                null,
                null,
                $candidatePayload,
                __('materials.copy.more_than_one_top_priority_rule_produces_a_different_hs_code'),
                $dimensions,
            );
        }

        /** @var HsCodeRule $winner */
        $winner = $winners->first();

        return new HsCodeResolutionResult(
            'matched',
            $winner->hs_code,
            $winner->id,
            $candidatePayload,
            __('materials.copy.hs_code_matched_automatically'),
            $dimensions,
        );
    }
}
