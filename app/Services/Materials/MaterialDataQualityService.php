<?php

namespace App\Services\Materials;

use App\Data\Materials\HsCodeConditionSet;
use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use App\Models\PrItem;

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
        $rules = HsCodeRule::query()->orderBy('priority')->orderBy('id')->get();
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
                    'category' => $left->material_category,
                    'shape' => $left->shape,
                    'hs_codes' => array_values(array_unique([$left->hs_code, $right->hs_code])),
                    'same_code' => $sameCode,
                    'same_priority' => $left->priority === $right->priority,
                ];
            }
        }

        $mappedCategories = $materials->pluck('hs_category')->filter()->unique();
        $ruleCategories = $activeRules->pluck('material_category')->unique();
        $duplicateCoverage = collect($overlaps)
            ->where('same_code', true)
            ->values();
        $rulesNeedingReview = collect($overlaps)
            ->filter(fn (array $overlap) => ! $overlap['same_code'] && $overlap['same_priority'])
            ->map(fn (array $overlap) => [
                'category' => $this->categoryLabel($overlap['category']),
                'shape' => PrItem::shapeLabel($overlap['shape']),
                'hs_codes' => $overlap['hs_codes'],
                'message' => __('materials.copy.two_active_rules_can_match_the_same_dimensions_and_return_different_hs_codes'),
            ])
            ->values();

        return [
            'summary' => [
                'materials' => $materials->count(),
                'materials_with_hs_mapping' => $materials->whereNotNull('hs_category')->count(),
                'materials_needing_hs_mapping' => $materials->whereNull('hs_category')->count(),
                'active_hs_rules' => $activeRules->count(),
                'rules_needing_review' => $rulesNeedingReview->count(),
            ],
            'needs_attention' => [
                'materials_without_hs_mapping' => $materials->whereNull('hs_category')->pluck('material_code')->values(),
                'categories_without_active_hs_rules' => $mappedCategories
                    ->diff($ruleCategories)
                    ->map(fn (string $category) => $this->categoryLabel($category))
                    ->values(),
                'rules_needing_review' => $rulesNeedingReview,
            ],
            'reference_notes' => [
                'duplicate_rule_coverage' => [
                    'count' => $duplicateCoverage->count(),
                    'message' => $duplicateCoverage->isEmpty()
                        ? __('materials.copy.all_active_rules_currently_cover_distinct_ranges')
                        : __('materials.copy.some_active_rules_cover_the_same_range_and_return_the_same_hs_code_no_action_is_required'),
                ],
                'inactive_rules_kept_for_reference' => $rules
                    ->where('status', HsCodeRule::STATUS_INACTIVE)
                    ->map(fn (HsCodeRule $rule) => [
                        'hs_code' => $rule->hs_code,
                        'note' => $this->referenceNote($rule->notes),
                    ])->values(),
                'rule_categories_not_used_by_materials' => $ruleCategories
                    ->diff($mappedCategories)
                    ->map(fn (string $category) => $this->categoryLabel($category))
                    ->values(),
                'reference_only_materials' => self::UNREACHABLE_REFERENCE_MATERIALS,
            ],
        ];
    }

    private function categoryLabel(string $category): string
    {
        return MaterialMaster::hsCategoryLabel($category);
    }

    private function referenceNote(?string $note): string
    {
        if (blank($note)) {
            return __('materials.copy.kept_inactive_for_reference');
        }

        if (preg_match('/^Inactive alternative retained for audit; (\d{4}\.\d{2}\.\d{2}) was selected\.$/', $note, $match)) {
            return __('materials.copy.inactive_alternative_retained_for_audit', ['selected' => $match[1]]);
        }

        return $note;
    }
}
