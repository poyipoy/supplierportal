<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveHsCodeRuleRequest;
use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Services\Materials\HsCodeRuleConflictDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class HsCodeRuleController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $query = HsCodeRule::query()->orderBy('priority')->orderBy('id');
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()));
        $query->when($request->filled('category'), fn ($q) => $q->where('material_category', $request->string('category')->toString()));
        $query->when($request->filled('shape'), fn ($q) => $q->where('shape', $request->string('shape')->toString()));

        return DataTables::eloquent($query)
            ->addIndexColumn()
            ->removeColumn('rule_key')
            ->addColumn('material_category_label', fn (HsCodeRule $rule) => MaterialMaster::hsCategoryLabel($rule->material_category))
            ->addColumn('shape_label', fn (HsCodeRule $rule) => PrItem::shapeLabel($rule->shape))
            ->addColumn('conditions_display', fn (HsCodeRule $rule) => e($this->conditionSummary($rule->conditions)))
            ->addColumn('status_badge', fn (HsCodeRule $rule) => match ($rule->status) {
                HsCodeRule::STATUS_ACTIVE => '<span class="ui-status-chip ui-status-chip--success">'.e(__('admin.copy.active')).'</span>',
                HsCodeRule::STATUS_CONFLICT => '<span class="ui-status-chip ui-status-chip--error">'.e(__('admin.copy.conflict')).'</span>',
                default => '<span class="ui-status-chip ui-status-chip--neutral">'.e(__('admin.copy.inactive')).'</span>',
            })
            ->addColumn('source_display', fn (HsCodeRule $rule) => e(collect($rule->source_refs)
                ->map(fn (array $ref) => basename($ref['file'] ?? __('admin.copy.admin')).' #'.implode(',', $ref['entries'] ?? []))
                ->join('; ') ?: __('admin.copy.admin')))
            ->addColumn('updated_date', fn (HsCodeRule $rule) => $rule->updated_at ? \App\Support\BusinessTime::format($rule->updated_at, 'd M Y', false) : '-')
            ->addColumn('action', function (HsCodeRule $rule) {
                $payload = e(json_encode([
                    'id' => $rule->id,
                    'hs_code' => $rule->hs_code,
                    'material_category' => $rule->material_category,
                    'shape' => $rule->shape,
                    'conditions' => $rule->conditions,
                    'priority' => $rule->priority,
                    'status' => $rule->status,
                    'notes' => $rule->notes,
                ], JSON_THROW_ON_ERROR));

                $stateLabel = $rule->status === HsCodeRule::STATUS_ACTIVE ? __('admin.copy.deactivate_rule') : __('admin.copy.activate_rule');

                return '<div class="d-inline-flex align-items-center gap-1">'
                    .'<button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-edit-rule" data-rule="'.$payload.'" aria-label="'.e(__('purchasing.action_names.edit_hs_code_rule', ['name' => $rule->hs_code])).'">'.e(__('admin.copy.edit')).'</button>'
                    .'<div class="dropdown"><button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="'.e(__('purchasing.action_names.more_actions_for_hs_code', ['name' => $rule->hs_code])).'">'.e(__('admin.copy.more')).'</button>'
                    .'<ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item btn-toggle-rule" data-id="'.$rule->id.'" data-status="'.($rule->status === HsCodeRule::STATUS_ACTIVE ? 'inactive' : 'active').'">'.$stateLabel.'</button></li></ul></div>'
                    .'</div>';
            })
            ->rawColumns(['status_badge', 'action'])
            ->toJson();
    }

    public function store(SaveHsCodeRuleRequest $request, HsCodeRuleConflictDetector $conflicts): RedirectResponse
    {
        $rule = new HsCodeRule($this->payload($request->validated(), true));
        $this->guardActivation($rule, $conflicts);
        $rule->save();

        return redirect()->to(route('admin.material-hs-code.index').'#rules')
            ->with('success', __('admin.copy.hs_code_rule_successfully_created'));
    }

    public function update(
        SaveHsCodeRuleRequest $request,
        HsCodeRule $hsCodeRule,
        HsCodeRuleConflictDetector $conflicts,
    ): RedirectResponse {
        $hsCodeRule->fill($this->payload($request->validated(), false));
        $this->guardActivation($hsCodeRule, $conflicts);
        $hsCodeRule->save();

        return redirect()->to(route('admin.material-hs-code.index').'#rules')
            ->with('success', __('admin.copy.hs_code_rule_successfully_updated'));
    }

    public function status(
        Request $request,
        HsCodeRule $hsCodeRule,
        HsCodeRuleConflictDetector $conflicts,
    ): JsonResponse {
        $validated = $request->validate(['status' => ['required', 'in:active,inactive']]);
        $hsCodeRule->status = $validated['status'];
        $hsCodeRule->updated_by = auth()->id();
        $this->guardActivation($hsCodeRule, $conflicts);
        $hsCodeRule->save();

        return response()->json(['success' => true]);
    }

    private function payload(array $validated, bool $creating): array
    {
        $payload = [
            ...collect($validated)->except(['conditions_json', 'rule_key'])->all(),
            'source_refs' => $validated['source_refs'] ?? [['source' => 'admin', 'user_id' => auth()->id()]],
            'updated_by' => auth()->id(),
        ];

        if ($creating) {
            $payload['rule_key'] = $this->newInternalRuleKey();
            $payload['created_by'] = auth()->id();
        }

        return $payload;
    }

    private function newInternalRuleKey(): string
    {
        do {
            $key = 'rule-'.strtolower((string) Str::ulid());
        } while (HsCodeRule::query()->where('rule_key', $key)->exists());

        return $key;
    }

    private function guardActivation(HsCodeRule $rule, HsCodeRuleConflictDetector $conflicts): void
    {
        if ($rule->status === HsCodeRule::STATUS_ACTIVE && $conflicts->hasBlockingConflict($rule)) {
            throw ValidationException::withMessages([
                'conditions' => __('admin.copy.this_rule_overlaps_an_active_rule_with_the_same_priority_and_a_different_hs_code'),
            ]);
        }
    }

    private function conditionSummary(array $conditions): string
    {
        return collect($conditions)->map(function (array $bounds, string $dimension) {
            $parts = [];
            if (($bounds['min'] ?? null) !== null) {
                $parts[] = ($bounds['min_inclusive'] ?? true ? '≥ ' : '> ').$bounds['min'];
            }
            if (($bounds['max'] ?? null) !== null) {
                $parts[] = ($bounds['max_inclusive'] ?? true ? '≤ ' : '< ').$bounds['max'];
            }

            return PrItem::dimensionLabel($dimension).' '.implode(' '.__('admin.copy.condition_and').' ', $parts);
        })->join('; ');
    }
}
