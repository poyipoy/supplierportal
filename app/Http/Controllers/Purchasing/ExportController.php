<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Exports\RequirementsExport;
use App\Exports\PurchaseOrdersExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function requirements(Request $request)
    {
        return Excel::download(new RequirementsExport($request->period_id, $request->status),
            'rekap_permintaan_' . now()->format('Ymd_His') . '.xlsx');
    }

    public function purchaseOrders(Request $request)
    {
        return Excel::download(new PurchaseOrdersExport($request->supplier_id, $request->start_date, $request->end_date),
            'rekap_po_' . now()->format('Ymd_His') . '.xlsx');
    }
}
