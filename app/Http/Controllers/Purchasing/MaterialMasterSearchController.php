<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\MaterialMaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialMasterSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $term = trim((string) ($validated['q'] ?? ''));

        $materials = MaterialMaster::query()
            ->active()
            ->with('aliases:id,material_master_id,alias')
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($nested) use ($term) {
                    $nested->where('material_code', 'like', "%{$term}%")
                        ->orWhere('raw_category', 'like', "%{$term}%")
                        ->orWhere('hs_category', 'like', "%{$term}%")
                        ->orWhereHas('aliases', fn ($aliases) => $aliases->where('alias', 'like', "%{$term}%"));
                });
            })
            ->orderBy('material_code')
            ->limit(20)
            ->get();

        return response()->json([
            'results' => $materials->map(fn (MaterialMaster $material) => [
                'id' => $material->id,
                'text' => $material->material_code,
                'material_code' => $material->material_code,
                'category' => $material->hs_category,
                'raw_category' => $material->raw_category,
                'density_profile' => $material->density_profile,
                'aliases' => $material->aliases->pluck('alias')->all(),
            ])->values(),
        ]);
    }
}
