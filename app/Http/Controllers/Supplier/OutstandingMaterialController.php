<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Services\Procurement\OutstandingMaterialQueryService;
use Illuminate\Http\Request;

class OutstandingMaterialController extends Controller
{
    public function index(Request $request, OutstandingMaterialQueryService $service)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $rows = $service->paginate($request->user(), [
            'search' => $filters['search'] ?? null,
            'status' => $filters['status'] ?? null,
            'page' => $request->integer('page', 1),
        ]);

        $statusOptions = $service->statusOptions();

        return view('supplier.outstanding-materials.index', compact('rows', 'statusOptions', 'filters'));
    }
}
