<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;

class PurchaseRequisitionController extends Controller
{
    public function show(string $requisition)
    {
        $pr = PurchaseRequisition::with(['period', 'creator', 'items'])->findOrFail($requisition);

        return view('admin.requisitions.show', compact('pr'));
    }
}
