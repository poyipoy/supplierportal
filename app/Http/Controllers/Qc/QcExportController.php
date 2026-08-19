<?php

namespace App\Http\Controllers\Qc;

use App\Exports\InspectionsExport;
use App\Http\Controllers\Controller;
use App\Support\ExportDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QcExportController extends Controller
{
    public function inspections(Request $request)
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['ok', 'ng'])],
        ]);

        $exportJob = ExportDispatcher::dispatch(
            'Rekap Inspeksi QC',
            InspectionsExport::class,
            [
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                $filters['status'] ?? null,
            ],
            'rekap_inspeksi_qc_'.now()->format('Ymd_His').'.xlsx',
        );

        $message = 'Permintaan export diterima. File akan terunduh otomatis ketika siap.';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'export_job_id' => $exportJob->getRouteKey(),
                'exports_url' => route('exports.index', absolute: false),
                'status_url' => route('exports.status', $exportJob, absolute: false),
            ], 202);
        }

        return back()->with('info', $message);
    }
}
