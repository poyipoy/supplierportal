<?php

namespace App\Http\Controllers\Qc;

use App\Exports\InspectionsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\AdvancedExportRequest;
use App\Http\Requests\Export\Filters\InspectionExportFilters;
use App\Support\ExportDispatcher;

class QcExportController extends Controller
{
    public function inspections(AdvancedExportRequest $request)
    {
        $filters = InspectionExportFilters::validated($request);

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.qc_summary'),
            InspectionsExport::class,
            [
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                $filters['status'] ?? null,
            ],
            'summary_qc_inspections_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
            $request->exportOptions('qc.inspections'),
        );

        $message = __('qc.copy.the_export_request_was_accepted_the_file_will_download_automatically_when_ready');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'export_job_id' => $exportJob->getRouteKey(),
                'exports_url' => route('exports.index', absolute: false),
                'status_url' => route('exports.status', $exportJob, absolute: false),
                'cancel_url' => route('exports.cancel', $exportJob, absolute: false),
            ], 202);
        }

        return back()->with('info', $message);
    }
}
