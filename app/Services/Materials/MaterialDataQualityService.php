<?php

namespace App\Services\Materials;

use App\Data\Materials\HsCodeConditionSet;
use App\Models\HsCodeRule;
use App\Models\MaterialMaster;

final class MaterialDataQualityService
{
    /**
     * Reference-only labels found during the approved cross-source audit. They
     * are intentionally not promoted into the selected master-material source.
     */
    private const UNREACHABLE_REFERENCE_MATERIALS = [
        'S35C',
        'Q345D',
        'Q235',
        'A3',
        'WEARPLATE',
    ];

    public function report(): array
    {
        $materials = MaterialMaster::query()->orderBy('material_code')->get();
        $rules = HsCodeRule::query()->orderBy('priority')->orderBy('rule_key')->get();
        $activeRules = $rules->where('status', HsCodeRule::STATUS_ACTIVE)->values();
        $overlaps = [];

        for ($leftIndex = 0; $leftIndex < $activeRules->count(); $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $activeRules->count(); $rightIndex++) {
                $left = $activeRules[$leftIndex];
                $right = $activeRules[$rightIndex];
                if ($left->material_category !== $right->material_category || $left->shape !== $right->shape) {
                    continue;
                }

                $leftConditions = HsCodeConditionSet::fromArray($left->conditions);
                $rightConditions = HsCodeConditionSet::fromArray($right->conditions);
                if (! $leftConditions->overlaps($rightConditions)) {
                    continue;
                }

                $sameCode = $left->hs_code === $right->hs_code;
                $overlaps[] = [
                    'left' => $left->rule_key,
                    'right' => $right->rule_key,
                    'category' => $left->material_category,
                    'shape' => $left->shape,
                    'codes' => array_values(array_unique([$left->hs_code, $right->hs_code])),
                    'same_priority' => $left->priority === $right->priority,
                    'type' => $leftConditions->exactlyEquals($rightConditions)
                        ? ($sameCode ? 'exact_duplicate' : 'exact_conflict')
                        : ($sameCode ? 'same_code_overlap' : 'priority_overlap'),
                ];
            }
        }

        $mappedCategories = $materials->pluck('hs_category')->filter()->unique();
        $ruleCategories = $activeRules->pluck('material_category')->unique();
        $sourceEntries = $rules->flatMap(function (HsCodeRule $rule) {
            return collect($rule->source_refs)->flatMap(fn (array $ref) => $ref['entries'] ?? []);
        })->unique()->count();

        return [
            'counts' => [
                'materials' => $materials->count(),
                'mapped_materials' => $materials->whereNotNull('hs_category')->count(),
                'unmapped_materials' => $materials->whereNull('hs_category')->count(),
                'active_rules' => $rules->where('status', HsCodeRule::STATUS_ACTIVE)->count(),
                'inactive_rules' => $rules->where('status', HsCodeRule::STATUS_INACTIVE)->count(),
                'conflict_rules' => $rules->where('status', HsCodeRule::STATUS_CONFLICT)->count(),
                'source_rule_entries' => $sourceEntries,
                'same_code_overlaps' => collect($overlaps)->whereIn('type', ['exact_duplicate', 'same_code_overlap'])->count(),
                'blocking_conflicts' => collect($overlaps)
                    ->whereIn('type', ['exact_conflict', 'priority_overlap'])
                    ->where('same_priority', true)
                    ->count(),
            ],
            'unmapped_materials' => $materials->whereNull('hs_category')->pluck('material_code')->values(),
            'categories_without_rules' => $mappedCategories->diff($ruleCategories)->values(),
            'unreachable_rule_categories' => $ruleCategories->diff($mappedCategories)->values(),
            'unreachable_reference_materials' => self::UNREACHABLE_REFERENCE_MATERIALS,
            'overlaps' => $overlaps,
            'resolved_source_conflicts' => $rules
                ->where('status', HsCodeRule::STATUS_INACTIVE)
                ->map(fn (HsCodeRule $rule) => [
                    'rule_key' => $rule->rule_key,
                    'hs_code' => $rule->hs_code,
                    'notes' => $rule->notes,
                ])->values(),
        ];
    }
}
