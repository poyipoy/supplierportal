<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveHsCodeRuleRequest;
use App\Models\HsCodeRule;
use App\Services\Materials\HsCodeRuleConflictDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class HsCodeRuleController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $query = HsCodeRule::query()->orderBy('priority')->orderBy('rule_key');
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()));
        $query->when($request->filled('category'), fn ($q) => $q->where('material_category', $request->string('category')->toString()));
        $query->when($request->filled('shape'), fn ($q) => $q->where('shape', $request->string('shape')->toString()));

        return DataTables::eloquent($query)
            ->addIndexColumn()
            ->addColumn('conditions_display', fn (HsCodeRule $rule) => e($this->conditionSummary($rule->conditions)))
            ->addColumn('status_badge', fn (HsCodeRule $rule) => match ($rule->status) {
                HsCodeRule::STATUS_ACTIVE => '<span class="badge bg-success">Active</span>',
                HsCodeRule::STATUS_CONFLICT => '<span class="badge bg-danger">Conflict</span>',
                default => '<span class="badge bg-secondary">Inactive</span>',
            })
            ->addColumn('source_display', fn (HsCodeRule $rule) => e(collect($rule->source_refs)
                ->map(fn (array $ref) => basename($ref['file'] ?? 'Admin').' #'.implode(',', $ref['entries'] ?? []))
                ->join('; ') ?: 'Admin'))
            ->addColumn('action', function (HsCodeRule $rule) {
                $payload = e(json_encode([
                    'id' => $rule->id,
                    'rule_key' => $rule->rule_key,
                    'hs_code' => $rule->hs_code,
                    'material_category' => $rule->material_category,
                    'shape' => $rule->shape,
                    'conditions' => $rule->conditions,
                    'priority' => $rule->priority,
                    'status' => $rule->status,
                    'notes' => $rule->notes,
                ], JSON_THROW_ON_ERROR));

                return '<button type="button" class="btn btn-sm btn-outline-primary btn-edit-rule" data-rule="'.$payload.'"><i class="bi bi-pencil"></i></button> '
                    .'<button type="button" class="btn btn-sm '.($rule->status === HsCodeRule::STATUS_ACTIVE ? 'btn-outline-secondary' : 'btn-outline-success').' btn-toggle-rule" data-id="'.$rule->id.'" data-status="'.($rule->status === HsCodeRule::STATUS_ACTIVE ? 'inactive' : 'active').'">'
                    .'<i class="bi '.($rule->status === HsCodeRule::STATUS_ACTIVE ? 'bi-pause-circle' : 'bi-play-circle').'"></i></button>';
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
            ->with('success', 'HS Code rule successfully created.');
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
            ->with('success', 'HS Code rule successfully updated.');
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
        return [
            ...collect($validated)->except('conditions_json')->all(),
            'source_refs' => $validated['source_refs'] ?? [['source' => 'admin', 'user_id' => auth()->id()]],
            $creating ? 'created_by' : 'updated_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ];
    }

    private function guardActivation(HsCodeRule $rule, HsCodeRuleConflictDetector $conflicts): void
    {
        if ($rule->status === HsCodeRule::STATUS_ACTIVE && $conflicts->hasBlockingConflict($rule)) {
            throw ValidationException::withMessages([
                'conditions' => 'This rule overlaps an active rule with the same priority and a different HS Code.',
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

            return $dimension.' '.implode(' and ', $parts);
        })->join('; ');
    }
}
