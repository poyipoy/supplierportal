<?php

namespace App\Http\Controllers\Qc;

use App\Http\Controllers\Controller;
use App\Exports\InspectionsExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class QcExportController extends Controller
{
    public function inspections(Request $request)
    {
        return Excel::download(new InspectionsExport($request->start_date, $request->end_date, $request->status),
            'rekap_inspeksi_qc_' . now()->format('Ymd_His') . '.xlsx');
    }
}
