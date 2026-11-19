<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Procurement\OutstandingMaterialQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutstandingMaterialController extends Controller
{
    public function index(Request $request, OutstandingMaterialQueryService $service)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:50'],
            'supplier_id' => ['nullable', 'string', 'max:255'],
        ]);

        $supplierId = null;
        if (! empty($filters['supplier_id'])) {
            abort_unless(! ctype_digit((string) $filters['supplier_id']), 404);
            $supplier = (new User)->resolveRouteBinding($filters['supplier_id']);
            abort_unless($supplier instanceof User && $supplier->role === 'supplier', 404);
            $supplierId = (int) $supplier->id;
        }

        $rows = $service->paginate(null, [
            'search' => $filters['search'] ?? null,
            'status' => $filters['status'] ?? null,
            'supplier_id' => $supplierId,
            'page' => $request->integer('page', 1),
        ]);

        $suppliers = User::importEligible()
            ->orWhereIn('id', DB::table('purchase_orders')->distinct()->pluck('supplier_id'))
            ->orderBy('name')
            ->get();

        $statusOptions = $service->statusOptions();

        return view('purchasing.outstanding-materials.index', compact('rows', 'suppliers', 'statusOptions', 'filters'));
    }
}
