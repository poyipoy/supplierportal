<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PrItem;
use App\Models\PurchaseRequirement;
use Illuminate\Http\Request;

class PrItemController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Mostly handled en-masse via PurchaseRequirementController
        // But if needed for single AJAX adds:
        $request->validate([
            'pr_id' => 'required|exists:purchase_requirements,id',
            'material_name' => 'required|string|max:255',
            'weight_needed' => 'required|numeric|min:0.01',
        ]);

        $pr = PurchaseRequirement::findOrFail($request->pr_id);
        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return response()->json(['error' => 'Tidak dapat menambah item pada PR ini.'], 403);
        }

        $item = $pr->items()->create($request->all());

        return response()->json(['success' => true, 'item' => $item]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $item = PrItem::with('purchase_requirement')->findOrFail($id);
        $pr = $item->purchase_requirement;

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return response()->json(['error' => 'Tidak dapat mengedit item pada PR ini.'], 403);
        }

        $request->validate([
            'material_name' => 'required|string|max:255',
            'weight_needed' => 'required|numeric|min:0.01',
        ]);

        $item->update($request->all());

        return response()->json(['success' => true, 'item' => $item]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $item = PrItem::with('purchase_requirement')->findOrFail($id);
        $pr = $item->purchase_requirement;

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return response()->json(['error' => 'Tidak dapat menghapus item pada PR ini.'], 403);
        }

        $item->delete();

        return response()->json(['success' => true]);
    }
}
