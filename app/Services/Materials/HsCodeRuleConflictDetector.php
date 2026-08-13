<?php

namespace App\Services\Materials;

use App\Data\Materials\HsCodeConditionSet;
use App\Models\HsCodeRule;
use Illuminate\Support\Collection;

final class HsCodeRuleConflictDetector
{
    public function overlapsFor(HsCodeRule $candidate): Collection
    {
        $candidateConditions = HsCodeConditionSet::fromArray($candidate->conditions);

        return HsCodeRule::query()
            ->active()
            ->where('material_category', $candidate->material_category)
            ->where('shape', $candidate->shape)
            ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->getKey()))
            ->get()
            ->filter(fn (HsCodeRule $rule) => $candidateConditions->overlaps(
                HsCodeConditionSet::fromArray($rule->conditions)
            ))
            ->map(function (HsCodeRule $rule) use ($candidate, $candidateConditions) {
                $existingConditions = HsCodeConditionSet::fromArray($rule->conditions);
                $differentCode = $rule->hs_code !== $candidate->hs_code;

                return [
                    'rule_id' => $rule->id,
                    'rule_key' => $rule->rule_key,
                    'hs_code' => $rule->hs_code,
                    'priority' => $rule->priority,
                    'type' => $candidateConditions->exactlyEquals($existingConditions)
                        ? ($differentCode ? 'exact_conflict' : 'exact_duplicate')
                        : ($differentCode ? 'overlap_conflict' : 'same_code_overlap'),
                    'blocks_activation' => $differentCode && $rule->priority === $candidate->priority,
                ];
            })
            ->values();
    }

    public function hasBlockingConflict(HsCodeRule $candidate): bool
    {
        return $this->overlapsFor($candidate)->contains('blocks_activation', true);
    }
}
