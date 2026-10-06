<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveMaterialMasterRequest;
use App\Models\MaterialAlias;
use App\Models\MaterialMaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class MaterialMasterController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $query = MaterialMaster::query()->orderBy('material_code');
        $query->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->string('status')->toString() === 'active'));
        $query->when($request->filled('category'), fn ($q) => $q->where('hs_category', $request->string('category')->toString()));
        $query->when($request->filled('density'), fn ($q) => $q->where('density_profile', $request->string('density')->toString()));
        $query->when($request->filled('manufacturer'), fn ($q) => $q->where('manufacturer_scope', $request->string('manufacturer')->toString()));

        return DataTables::eloquent($query)
            ->addIndexColumn()
            ->addColumn('hs_category_label', fn (MaterialMaster $material) => $material->hs_category
                ? MaterialMaster::hsCategoryLabel($material->hs_category)
                : '-')
            ->addColumn('density_profile_label', fn (MaterialMaster $material) => MaterialMaster::densityProfileLabel($material->density_profile))
            ->addColumn('manufacturer_scope_label', fn (MaterialMaster $material) => MaterialMaster::manufacturerScopeLabel($material->manufacturer_scope))
            ->addColumn('status_badge', fn (MaterialMaster $material) => $material->is_active
                ? '<span class="ui-status-chip ui-status-chip--success">'.e(__('admin.copy.active')).'</span>'
                : '<span class="ui-status-chip ui-status-chip--neutral">'.e(__('admin.copy.inactive')).'</span>')
            ->addColumn('source_display', fn (MaterialMaster $material) => e(__('admin.copy.source_sheet_row', [
                'sheet' => $material->source_sheet ?? __('admin.copy.admin'),
                'row' => $material->source_row ?? '-',
            ])))
            ->addColumn('updated_date', fn (MaterialMaster $material) => $material->updated_at ? \App\Support\BusinessTime::format($material->updated_at, 'd M Y', false) : '-')
            ->addColumn('action', function (MaterialMaster $material) {
                $payload = e(json_encode([
                    'id' => $material->id,
                    'material_code' => $material->material_code,
                    'raw_category' => $material->raw_category,
                    'hs_category' => $material->hs_category,
                    'density_profile' => $material->density_profile,
                    'manufacturer_scope' => $material->manufacturer_scope,
                    'is_active' => $material->is_active,
                ], JSON_THROW_ON_ERROR));

                $stateLabel = $material->is_active ? __('admin.copy.deactivate_material') : __('admin.copy.activate_material');

                return '<div class="d-inline-flex align-items-center gap-1">'
                    .'<button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-edit-material" data-material="'.$payload.'" aria-label="'.e(__('purchasing.action_names.edit_material', ['name' => $material->material_code])).'">'.e(__('admin.copy.edit')).'</button>'
                    .'<div class="dropdown"><button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="'.e(__('purchasing.action_names.more_actions_for', ['name' => $material->material_code])).'">'.e(__('admin.copy.more')).'</button>'
                    .'<ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item btn-toggle-material" data-id="'.$material->id.'" data-active="'.($material->is_active ? '0' : '1').'">'.$stateLabel.'</button></li></ul></div>'
                    .'</div>';
            })
            ->rawColumns(['status_badge', 'action'])
            ->toJson();
    }

    public function store(SaveMaterialMasterRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $this->guardCodeAgainstAliases($validated['normalized_code']);

        MaterialMaster::create([
            ...$validated,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()->to(route('admin.material-hs-code.index').'#materials')
            ->with('success', __('admin.copy.material_master_successfully_created'));
    }

    public function update(
        SaveMaterialMasterRequest $request,
        MaterialMaster $materialMaster,
    ): RedirectResponse {
        $validated = $request->validated();
        $this->guardCodeAgainstAliases($validated['normalized_code']);

        $materialMaster->update([
            ...$validated,
            'updated_by' => auth()->id(),
        ]);

        return redirect()->to(route('admin.material-hs-code.index').'#materials')
            ->with('success', __('admin.copy.material_master_successfully_updated'));
    }

    public function status(Request $request, MaterialMaster $materialMaster): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $materialMaster->update([
            'is_active' => $validated['is_active'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json(['success' => true]);
    }

    private function guardCodeAgainstAliases(string $normalizedCode): void
    {
        if (MaterialAlias::where('normalized_alias', $normalizedCode)->exists()) {
            throw ValidationException::withMessages([
                'material_code' => __('admin.copy.this_material_code_is_already_used_as_an_alias'),
            ]);
        }
    }
}
