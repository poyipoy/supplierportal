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
            ->addColumn('status_badge', fn (MaterialMaster $material) => $material->is_active
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-secondary">Inactive</span>')
            ->addColumn('source_display', fn (MaterialMaster $material) => e(
                ($material->source_sheet ?? 'Admin').' row '.($material->source_row ?? '-')
            ))
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

                return '<button type="button" class="btn btn-sm btn-outline-primary btn-edit-material" data-material="'.$payload.'" aria-label="Edit material" title="Edit material"><i class="bi bi-pencil" aria-hidden="true"></i></button> '
                    .'<button type="button" class="btn btn-sm '.($material->is_active ? 'btn-outline-secondary' : 'btn-outline-success').' btn-toggle-material" data-id="'.$material->id.'" data-active="'.($material->is_active ? '0' : '1').'" aria-label="'.($material->is_active ? 'Deactivate material' : 'Activate material').'" title="'.($material->is_active ? 'Deactivate material' : 'Activate material').'">'
                    .'<i class="bi '.($material->is_active ? 'bi-pause-circle' : 'bi-play-circle').'" aria-hidden="true"></i></button>';
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
            ->with('success', 'Material master successfully created.');
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
            ->with('success', 'Material master successfully updated.');
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
                'material_code' => 'This material code is already used as an alias.',
            ]);
        }
    }
}
