<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaterialCalculationRequest;
use App\Models\MaterialMaster;
use App\Services\Materials\PrItemProcessor;
use Illuminate\Http\JsonResponse;

class MaterialCalculationController extends Controller
{
    public function __construct(private readonly PrItemProcessor $processor) {}

    public function preview(MaterialCalculationRequest $request): JsonResponse
    {
        $result = $this->processor->process(
            $request->validated(),
            false,
            auth()->id(),
        );
        $material = MaterialMaster::findOrFail($result->data['material_master_id']);
        $weight = $result->weight->toArray();

        if (($result->data['weight_calculation_status'] ?? null) === 'manual') {
            $unitKg = (float) ($result->data['weight_needed'] ?? 0);
            $quantity = max(1, (int) ($result->data['quantity'] ?? 1));
            $weight['status'] = 'manual';
            $weight['unit_kg'] = $unitKg;
            $weight['total_kg'] = round($unitKg * $quantity, 4, PHP_ROUND_HALF_UP);
            $weight['formula_key'] = 'manual';
            $weight['factor'] = null;
            $weight['message'] = 'KG per unit was entered manually.';
        }

        return response()->json([
            'success' => $result->isValid(),
            'material' => [
                'id' => $material->id,
                'code' => $material->material_code,
                'category' => $material->hs_category,
                'density_profile' => $material->density_profile,
            ],
            'hs_code' => [
                ...$result->hsCode->toArray(),
                'selected_code' => $result->data['hs_code'],
                'source' => $result->data['hs_code_source'],
                'manual_allowed' => $result->hsCode->allowsManualSelection(),
            ],
            'weight' => $weight,
            'errors' => $result->errors,
        ], $result->isValid() ? 200 : 422);
    }
}
