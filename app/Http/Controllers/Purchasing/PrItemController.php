<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PrItem;
use App\Models\PurchaseRequirement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrItemController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Mostly handled en-masse via PurchaseRequirementController
        // But if needed for single AJAX adds:
        $request->merge(PrItem::sanitizeMaterialData($request->all()));

        $validated = $request->validate($this->materialValidationRules([
            'pr_id' => 'required|exists:purchase_requirements,id',
        ]));

        $pr = PurchaseRequirement::findOrFail($validated['pr_id']);
        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return response()->json(['error' => 'Tidak dapat menambah item pada PR ini.'], 403);
        }

        $item = $pr->items()->create(PrItem::sanitizeMaterialData($validated));

        return response()->json(['success' => true, 'item' => $item]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $item = PrItem::with('purchaseRequirement')->findOrFail($id);
        $pr = $item->purchaseRequirement;

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return response()->json(['error' => 'Tidak dapat mengedit item pada PR ini.'], 403);
        }

        $request->merge(PrItem::sanitizeMaterialData($request->all()));
        $validated = $request->validate($this->materialValidationRules());

        $item->update(PrItem::sanitizeMaterialData($validated));

        return response()->json(['success' => true, 'item' => $item]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $item = PrItem::with('purchaseRequirement')->findOrFail($id);
        $pr = $item->purchaseRequirement;

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return response()->json(['error' => 'Tidak dapat menghapus item pada PR ini.'], 403);
        }

        $item->delete();

        return response()->json(['success' => true]);
    }

    private function materialValidationRules(array $extra = []): array
    {
        return $extra + [
            'material_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'weight_needed' => 'required|numeric|min:0.01',
            'hs_code' => 'required|string|max:100',
            'shape' => ['nullable', Rule::in(PrItem::SHAPES)],
            'thickness' => 'nullable|numeric|min:0',
            'd_inner' => 'nullable|numeric|min:0',
            'd_outer' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
        ];
    }
}
