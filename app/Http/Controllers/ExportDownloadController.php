<?php

namespace App\Http\Controllers;

use App\Exports\LocalInvoicesExport;
use App\Exports\PaymentBatchDrpExport;
use App\Exports\PaymentBatchTransferExport;
use App\Models\ExportJob;
use App\Services\ExportProgressService;
use App\Support\Export\ExportOptions;
use App\Support\Export\ExportOptionsResolver;
use App\Support\ExportDispatcher;
use App\Support\PortalPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ExportDownloadController extends Controller
{
    private const LOCAL_EXPORT_CLASSES = [
        LocalInvoicesExport::class,
        PaymentBatchDrpExport::class,
        PaymentBatchTransferExport::class,
    ];

    private const FILTERABLE_STATUSES = [
        ExportJob::STATUS_QUEUED,
        ExportJob::STATUS_PROCESSING,
        ExportJob::STATUS_COMPLETED,
        ExportJob::STATUS_FAILED,
        ExportJob::STATUS_CANCELLED,
    ];

    private const FILTERABLE_FORMATS = ['xlsx', 'csv'];

    public function index(Request $request)
    {
        $statusFilter = $request->query('status');
        $statusFilter = is_string($statusFilter) && in_array($statusFilter, self::FILTERABLE_STATUSES, true)
            ? $statusFilter
            : null;

        $formatFilter = $request->query('format');
        $formatFilter = is_string($formatFilter) && in_array($formatFilter, self::FILTERABLE_FORMATS, true)
            ? $formatFilter
            : null;

        $baseQuery = ExportJob::query()
            ->where('user_id', $request->user()->getKey())
            ->when($request->user()->isLocalOperator(), fn ($query) => $query->whereIn('export_class', self::LOCAL_EXPORT_CLASSES))
            ->when(! $request->user()->isLocalOperator() && ! $request->user()->isAdmin(), fn ($query) => $query->whereNotIn('export_class', self::LOCAL_EXPORT_CLASSES));

        $query = (clone $baseQuery)
            ->when($statusFilter !== null, fn ($query) => $query->where('status', $statusFilter))
            ->when($formatFilter !== null, fn ($query) => $query->where('format', $formatFilter))
            ->latest()
            ->orderByDesc('id');

        $jobs = $query->paginate(20)->withQueryString();
        $items = $jobs->getCollection()
            ->map(fn (ExportJob $exportJob) => $this->serialize($exportJob, $request))
            ->values();

        $hasPending = (clone $baseQuery)
            ->when($statusFilter !== null, fn ($query) => $query->where('status', $statusFilter))
            ->when($formatFilter !== null, fn ($query) => $query->where('format', $formatFilter))
            ->whereIn('status', [ExportJob::STATUS_QUEUED, ExportJob::STATUS_PROCESSING])
            ->exists();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $items,
                'has_pending' => $hasPending,
            ]);
        }

        return view('exports.index', compact('jobs', 'items', 'hasPending', 'statusFilter', 'formatFilter'));
    }

    public function download(Request $request, ExportJob $exportJob)
    {
        $this->authorizeDomain($request, $exportJob);
        abort_unless((int) $exportJob->user_id === (int) $request->user()->getKey(), 403);

        if (! $exportJob->isDownloadable()) {
            abort(404, __('exports.feedback.not_ready'));
        }

        $format = $exportJob->format ?? 'xlsx';
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        $disk = Storage::disk($exportJob->disk);

        return $disk->download(
            $exportJob->file_path,
            $exportJob->file_name,
            [
                'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function status(Request $request, ExportJob $exportJob)
    {
        $this->authorizeDomain($request, $exportJob);
        abort_unless((int) $exportJob->user_id === (int) $request->user()->getKey(), 403);

        $downloadUrl = $exportJob->isDownloadable()
            ? PortalPath::routePath('exports.download', $exportJob, $request)
            : null;

        $message = match ($exportJob->status) {
            ExportJob::STATUS_QUEUED, ExportJob::STATUS_PROCESSING => $exportJob->progressMessage(),
            ExportJob::STATUS_COMPLETED => $downloadUrl
                ? __('exports.progress.completed')
                : __('exports.feedback.unavailable'),
            ExportJob::STATUS_FAILED => $this->safeFailureMessage($exportJob),
            ExportJob::STATUS_CANCELLED => __('exports.progress.cancelled'),
            default => __('exports.feedback.unknown_status'),
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
            'cancel_url' => $exportJob->isPending()
                ? PortalPath::routePath('exports.cancel', $exportJob, $request)
                : null,
            'rerun_url' => $this->canRerun($exportJob)
                ? PortalPath::routePath('exports.rerun', $exportJob, $request)
                : null,
        ]);
    }

    public function cancel(Request $request, ExportJob $exportJob, ExportProgressService $progress)
    {
        $this->authorizeDomain($request, $exportJob);
        abort_unless((int) $exportJob->user_id === (int) $request->user()->getKey(), 403);

        $cancelled = $progress->cancel((int) $exportJob->getKey());

        if (in_array($cancelled->status, [ExportJob::STATUS_COMPLETED, ExportJob::STATUS_FAILED], true)) {
            return response()->json([
                'status' => $cancelled->status,
                'message' => __('exports.feedback.already_finished'),
            ], 409);
        }

        if (! $request->wantsJson()) {
            return redirect()->route('exports.index')->with('success', $cancelled->progressMessage());
        }

        return response()->json([
            'id' => $cancelled->getRouteKey(),
            'status' => $cancelled->status,
            'stage' => $cancelled->progress_stage,
            'progress' => $cancelled->progress,
            'processed_rows' => $cancelled->processed_rows,
            'total_rows' => $cancelled->total_rows,
            'message' => $cancelled->progressMessage(),
            'file_name' => null,
            'download_url' => null,
            'cancel_url' => null,
            'rerun_url' => $this->canRerun($cancelled)
                ? PortalPath::routePath('exports.rerun', $cancelled, $request)
                : null,
        ]);
    }

    public function rerun(Request $request, ExportJob $exportJob)
    {
        $this->authorizeDomain($request, $exportJob);
        abort_unless((int) $exportJob->user_id === (int) $request->user()->getKey(), 403);

        if (! $exportJob->isTerminal()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => $exportJob->status,
                    'message' => __('exports.feedback.cannot_rerun_pending'),
                ], 409);
            }

            return redirect()->route('exports.index')->with('error', __('exports.feedback.cannot_rerun_pending'));
        }

        if (! ExportDispatcher::isSupported($exportJob->export_class)) {
            throw ValidationException::withMessages([
                'export' => __('exports.feedback.invalid_configuration'),
            ]);
        }

        $options = null;
        if ($exportJob->export_options !== null) {
            try {
                $options = ExportOptions::fromArray($exportJob->export_options);
                ExportOptionsResolver::authorizeStored($exportJob->export_class, $request->user(), $options);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'options' => __('exports.feedback.invalid_configuration'),
                ]);
            }
        }

        $newJob = ExportDispatcher::dispatch(
            $exportJob->label,
            $exportJob->export_class,
            is_array($exportJob->export_args) ? $exportJob->export_args : [],
            $exportJob->file_name,
            $options,
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('exports.feedback.rerun_queued'),
                'export_job_id' => $newJob->getRouteKey(),
                'status_url' => PortalPath::routePath('exports.status', $newJob, $request),
                'cancel_url' => PortalPath::routePath('exports.cancel', $newJob, $request),
                'exports_url' => PortalPath::routePath('exports.index', [], $request),
                'item' => $this->serialize($newJob, $request),
            ], 202);
        }

        return redirect()->route('exports.index')->with('success', __('exports.feedback.rerun_queued'));
    }

    private function authorizeDomain(Request $request, ExportJob $exportJob): void
    {
        $local = in_array($exportJob->export_class, self::LOCAL_EXPORT_CLASSES, true);
        abort_if($request->user()->isLocalOperator() && ! $local, 403);
        abort_if($local && ! $request->user()->isLocalOperator() && ! $request->user()->isAdmin(), 403);
    }

    private function canRerun(ExportJob $exportJob): bool
    {
        return $exportJob->isTerminal() && ExportDispatcher::isSupported($exportJob->export_class);
    }

    private function safeFailureMessage(ExportJob $exportJob): string
    {
        $raw = (string) ($exportJob->error_message ?? '');

        if (str_contains($raw, 'Export row limit exceeded')) {
            return __('exports.advanced.row_limit', ['limit' => number_format((int) config('exports.max_rows'))]);
        }

        if (str_contains($raw, 'could not be stored')) {
            return __('exports.feedback.storage_failed');
        }

        if (str_contains($raw, 'Unsupported') || str_contains($raw, 'does not accept')) {
            return __('exports.feedback.invalid_configuration');
        }

        return __('exports.progress.failed');
    }

    private function serialize(ExportJob $exportJob, ?Request $request = null): array
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
            'format' => $exportJob->format ?? 'xlsx',
            'failure_message' => $exportJob->status === ExportJob::STATUS_FAILED
                ? $this->safeFailureMessage($exportJob)
                : null,
            'created_at' => $exportJob->created_at?->toIso8601String(),
            'completed_at' => $exportJob->completed_at?->toIso8601String(),
            'expires_at' => $exportJob->expires_at?->toIso8601String(),
            'download_url' => $exportJob->isDownloadable()
                ? PortalPath::routePath('exports.download', $exportJob, $request)
                : null,
            'cancel_url' => $exportJob->isPending()
                ? PortalPath::routePath('exports.cancel', $exportJob, $request)
                : null,
            'rerun_url' => $this->canRerun($exportJob)
                ? PortalPath::routePath('exports.rerun', $exportJob, $request)
                : null,
        ];
    }
}
