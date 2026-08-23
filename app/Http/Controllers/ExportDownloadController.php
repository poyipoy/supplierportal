<?php

namespace App\Http\Controllers;

use App\Models\ExportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExportDownloadController extends Controller
{
    public function index(Request $request)
    {
        $query = ExportJob::query()
            ->where('user_id', $request->user()->getKey())
            ->latest()
            ->orderByDesc('id');
        $jobs = $query->paginate(20)->withQueryString();
        $items = $jobs->getCollection()
            ->map(fn (ExportJob $exportJob) => $this->serialize($exportJob))
            ->values();
        $hasPending = ExportJob::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('status', [ExportJob::STATUS_QUEUED, ExportJob::STATUS_PROCESSING])
            ->exists();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $items,
                'has_pending' => $hasPending,
            ]);
        }

        return view('exports.index', compact('jobs', 'items', 'hasPending'));
    }

    public function download(Request $request, ExportJob $exportJob)
    {
        abort_unless((int) $exportJob->user_id === (int) $request->user()->getKey(), 403);

        if (! $exportJob->isDownloadable()) {
            abort(404, 'The export file was not found or has not finished processing.');
        }

        $disk = Storage::disk($exportJob->disk);

        return response()->download(
            $disk->path($exportJob->file_path),
            $exportJob->file_name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function status(Request $request, ExportJob $exportJob)
    {
        abort_unless((int) $exportJob->user_id === (int) $request->user()->getKey(), 403);

        $downloadUrl = $exportJob->isDownloadable()
            ? route('exports.download', $exportJob, absolute: false)
            : null;

        $message = match ($exportJob->status) {
            ExportJob::STATUS_QUEUED, ExportJob::STATUS_PROCESSING => $exportJob->progressMessage(),
            ExportJob::STATUS_COMPLETED => $downloadUrl
                ? 'The export is complete and ready to download.'
                : 'The export file is unavailable or has expired.',
            ExportJob::STATUS_FAILED => 'The export could not be processed. Please try again.',
            default => 'The export status is not recognized.',
        };

        return response()->json([
            'id' => $exportJob->getRouteKey(),
            'status' => $exportJob->status,
            'stage' => $exportJob->progress_stage,
            'progress' => $exportJob->progress,
            'processed_rows' => $exportJob->processed_rows,
            'total_rows' => $exportJob->total_rows,
            'message' => $message,
            'file_name' => $downloadUrl ? $exportJob->file_name : null,
            'download_url' => $downloadUrl,
            'expires_at' => $exportJob->expires_at?->toIso8601String(),
        ]);
    }

    private function serialize(ExportJob $exportJob): array
    {
        return [
            'id' => $exportJob->getRouteKey(),
            'label' => $exportJob->label,
            'status' => $exportJob->status,
            'stage' => $exportJob->progress_stage,
            'progress' => $exportJob->progress,
            'processed_rows' => $exportJob->processed_rows,
            'total_rows' => $exportJob->total_rows,
            'file_name' => $exportJob->file_name,
            'created_at' => $exportJob->created_at?->toIso8601String(),
            'completed_at' => $exportJob->completed_at?->toIso8601String(),
            'expires_at' => $exportJob->expires_at?->toIso8601String(),
            'download_url' => $exportJob->isDownloadable()
                ? route('exports.download', $exportJob, absolute: false)
                : null,
        ];
    }
}
