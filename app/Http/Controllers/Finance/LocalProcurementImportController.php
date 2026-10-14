<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\RevalidateLocalProcurementImportRequest;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportProgress;
use App\Services\LocalInvoice\LocalProcurementImportService;
use App\Services\RegionalDisplayFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LocalProcurementImportController extends Controller
{
    public function index(Request $request, RegionalDisplayFormatter $formatter, LocalProcurementImportService $service)
    {
        Gate::authorize('create', LocalProcurementImport::class);
        $request->validate(['active' => ['sometimes', 'boolean'], 'include_recoverable' => ['sometimes', 'boolean']]);
        $base = LocalProcurementImport::where('user_id', $request->user()->id)->whereIn('kind', LocalProcurementImport::KINDS)
            ->with('attachments.fileInspection');
        $columns = ['id', 'user_id', 'kind', 'status', 'original_filename', 'processed_rows', 'created_at', 'updated_at',
            'attempt', 'owner_job_key', 'summary', 'expires_at', 'cleaned_at'];
        if ($request->boolean('active')) {
            $imports = (clone $base)->whereIn('status', LocalProcurementImport::ACTIVE)
                ->where(fn ($q) => $q->where('expires_at', '>', now())->orWhereIn('status', ['READING', 'VALIDATING', 'IMPORTING']))
                ->latest('id')->limit(100)->get($columns);
            if ($request->boolean('include_recoverable') && $imports->count() < 100) {
                $recoverable = (clone $base)->whereIn('status', ['VALIDATION_FAILED', 'FAILED'])
                    ->where('expires_at', '>', now())->whereNull('cleaned_at')->latest('id')->limit(100 - $imports->count())->get($columns);
                $imports = $imports->merge($recoverable)->sortByDesc('id')->values();
            }
        } else {
            $imports = $base->where(fn ($q) => $q->whereIn('status', LocalProcurementImport::ACTIVE)->orWhere('created_at', '>', now()->subDays(3)))
                ->latest('id')->limit(100)->get($columns);
        }

        return response()->json($imports->map(fn ($import) => ['id' => $import->hash, 'kind' => $import->kind,
            'status' => $import->status, 'filename' => $import->original_filename,
            'created_at' => $import->created_at ? $formatter->timestamp($import->created_at) : null,
            'processed_rows' => (int) $import->processed_rows,
            'summary' => Arr::only($import->summary ?? [], ['source_rows', 'new_po', 'new_gr', 'po_pr_filled', 'existing_po', 'existing_gr', 'invalid']),
            'validation_attempt' => (int) $import->attempt, 'can_revalidate' => $service->canRevalidate($import),
            'expires_at' => $import->expires_at->toIso8601String(),
            'revalidate_url' => route($this->prefix($request).'.imports.revalidate', $import),
            'progress' => app(LocalProcurementImportProgress::class)->read($import),
            'status_url' => route($this->prefix($request).'.imports.status', $import)]))->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, LocalProcurementImport $procurementImport, LocalProcurementImportService $service)
    {
        Gate::authorize('view', $procurementImport);
        $request->validate(['progress_only' => ['sometimes', 'boolean']]);
        $status = in_array($procurementImport->status, ['QUEUED', 'READY', 'VALIDATION_FAILED', 'FAILED'], true) && $procurementImport->expires_at->isPast()
            ? 'EXPIRED' : $procurementImport->status;
        $supported = in_array($procurementImport->kind, LocalProcurementImport::KINDS, true);
        $progress = app(LocalProcurementImportProgress::class)->read($procurementImport, $status);
        if ($request->boolean('progress_only')) {
            return response()->json(['id' => $procurementImport->hash, 'kind' => $procurementImport->kind, 'status' => $status,
                'filename' => $procurementImport->original_filename, 'processed_rows' => (int) $procurementImport->processed_rows,
                'source_rows' => (int) $procurementImport->source_rows, 'failure' => $procurementImport->failure,
                'validation_attempt' => (int) $procurementImport->attempt,
                'progress' => $progress])->header('Cache-Control', 'private, no-store');
        }
        $page = $this->page($procurementImport, false);
        $errors = $this->page($procurementImport, true);

        return response()->json(['id' => $procurementImport->hash, 'kind' => $procurementImport->kind, 'status' => $status,
            'filename' => $procurementImport->original_filename,
            'progress' => $progress,
            'validation_attempt' => (int) $procurementImport->attempt,
            'can_revalidate' => $supported && $service->canRevalidate($procurementImport),
            'expires_at' => $procurementImport->expires_at->toIso8601String(),
            'revalidate_url' => $supported ? route($this->prefix($request).'.imports.revalidate', $procurementImport) : null,
            'results_url' => $status === 'COMPLETED' ? $this->resultsUrl($request, $procurementImport) : null,
            'processed_rows' => $procurementImport->processed_rows, 'source_rows' => $procurementImport->source_rows,
            'failure' => $procurementImport->failure, 'warnings' => array_slice($procurementImport->warnings ?? [], 0, 100),
            'warnings_total' => count($procurementImport->warnings ?? []),
            'token' => $supported && $status === 'READY' ? $service->tokenFor($procurementImport) : null,
            'confirm_url' => $supported ? route($this->prefix($request).'.import.'.strtolower($procurementImport->kind).'.confirm') : null,
            'preview' => ['success' => $supported && $status === 'READY', 'summary' => $procurementImport->summary ?? [],
                'rows' => $page['data'], 'errors' => $errors['data'], 'source_row_count' => $procurementImport->source_rows],
            'records_url' => route($this->prefix($request).'.imports.records', $procurementImport),
            'records_total' => $page['total'], 'errors_total' => $errors['total'],
            'errors_url' => route($this->prefix($request).'.imports.errors', $procurementImport)])->header('Cache-Control', 'private, no-store');
    }

    public function records(Request $request, LocalProcurementImport $procurementImport)
    {
        Gate::authorize('view', $procurementImport);

        return response()->json($this->page($procurementImport, false))->header('Cache-Control', 'private, no-store');
    }

    public function errors(Request $request, LocalProcurementImport $procurementImport)
    {
        Gate::authorize('view', $procurementImport);

        return response()->json($this->page($procurementImport, true))->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, LocalProcurementImport $procurementImport)
    {
        Gate::authorize('confirm', $procurementImport);
        DB::transaction(function () use ($request, $procurementImport) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $import = LocalProcurementImport::whereKey($procurementImport->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('confirm', $import);
            abort_if(in_array($import->status, ['IMPORTING', 'COMPLETED'], true), 409, __('local_procurement.large_import.not_ready'));
            $import->update(['status' => 'CANCELLED', 'finished_at' => now(), 'lease_expires_at' => null]);
        });

        return response()->json(['status' => 'CANCELLED']);
    }

    public function revalidate(RevalidateLocalProcurementImportRequest $request, LocalProcurementImport $procurementImport, LocalProcurementImportService $service)
    {
        $import = $service->revalidate($request->user(), $procurementImport, (int) $request->validated('expected_attempt'));

        return response()->json(['id' => $import->hash, 'status' => $import->status,
            'validation_attempt' => (int) $import->attempt, 'status_url' => route($this->prefix($request).'.imports.status', $import)], 202)
            ->header('Cache-Control', 'private, no-store');
    }

    private function page(LocalProcurementImport $import, bool $errors): array
    {
        $request = request();
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $base = DB::table('local_procurement_import_records')->where('import_id', $import->id)->where('attempt', $import->attempt);
        if ($errors) {
            $source = DB::table('local_procurement_import_rows')->where('import_id', $import->id)->where('attempt', $import->attempt)
                ->whereRaw('JSON_LENGTH(errors) > 0')->select(['source_row', 'errors']);
            $query = DB::query()->fromSub($base->whereRaw('JSON_LENGTH(errors) > 0')->select(['source_row', 'errors'])->unionAll($source), 'import_errors');
        } else {
            $query = $base->select(['source_row', 'source_count', 'kind', 'values']);
        }
        $page = $query->orderBy('source_row')->paginate((int) config('local_procurement_imports.page_size'));
        $data = $page->getCollection()->map(function ($row) use ($errors) {
            if ($errors) {
                return json_decode($row->errors, true);
            }
            $values = json_decode($row->values, true);
            $action = $values['action'] ?? 'NEW';
            $label = match ($action) {
                'NEW' => __('finance.async_copy.'.($row->kind === 'PO' ? 'new_po' : 'new_gr')),
                'FILL_PR' => __('local_procurement.pr_import.fill_action'),
                'EXISTING' => __('finance.async_copy.existing'), 'CONFLICT' => __('finance.async_copy.conflict'),
                'UNMATCHED_PO' => __('finance.async_copy.unmatched'), 'PO_CLOSED' => __('finance.async_copy.closed'),
                default => __('finance.async_copy.unknown'),
            };

            return array_merge($values, ['source_rows_count' => $row->source_count, 'kind' => $row->kind, 'action_label' => $label]);
        })->all();
        if ($errors) {
            $data = array_merge([], ...$data);
            $data = $this->errorActions($request, $data);
        }

        return ['data' => $data, 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()];
    }

    private function errorActions(Request $request, array $errors): array
    {
        $references = collect($errors)->pluck('context');
        $poNumbers = $references->pluck('po_number')->filter(fn ($number) => is_string($number) && mb_strlen($number) <= 100)->unique()->values();
        $grNumbers = $references->pluck('gr_number')->filter(fn ($number) => is_string($number) && mb_strlen($number) <= 100)->unique()->values();
        $pos = $poNumbers->isEmpty() ? collect() : LocalPurchaseOrder::whereIn('po_number', $poNumbers)->get(['id', 'po_number'])->keyBy(fn ($po) => mb_strtolower($po->po_number));
        $grs = $grNumbers->isEmpty() ? collect() : LocalGoodsReceipt::whereIn('gr_number', $grNumbers)->get(['id', 'gr_number', 'local_purchase_order_id'])->keyBy(fn ($gr) => mb_strtolower($gr->gr_number));
        $parentIds = $grs->pluck('local_purchase_order_id')->unique()->values();
        $parents = $parentIds->isEmpty() ? collect() : LocalPurchaseOrder::whereIn('id', $parentIds)->get(['id'])->keyBy('id');
        $prefix = $this->prefix($request);

        return array_map(function ($error) use ($pos, $grs, $parents, $prefix) {
            $code = $error['code'] ?? 'ROW_INVALID';
            $context = $error['context'] ?? [];
            $po = $pos->get(mb_strtolower((string) ($context['po_number'] ?? '')));
            $gr = $grs->get(mb_strtolower((string) ($context['gr_number'] ?? '')));
            $action = null;
            if ($code === 'GR_CANCELLED' || ($code === 'IDENTITY_CONFLICT' && $gr)) {
                if ($gr && ($parent = $parents->get($gr->local_purchase_order_id))) {
                    $action = ['url' => route($prefix.'.show', $parent), 'label' => __('local_procurement.large_import.open_document')];
                }
            } elseif (in_array($code, ['PO_NOT_FOUND', 'PO_CANCELLED', 'PO_CLOSED', 'IDENTITY_CONFLICT', 'PR_CONFLICT'], true) && ! empty($context['po_number'])) {
                $action = ['url' => $po ? route($prefix.'.show', $po) : route($prefix.'.index', ['q' => $context['po_number']]),
                    'label' => __($po ? 'local_procurement.large_import.open_document' : 'local_procurement.large_import.open_po')];
            }

            return array_merge($error, ['code' => $code, 'action' => $action]);
        }, $errors);
    }

    private function resultsUrl(Request $request, LocalProcurementImport $import): string
    {
        $prefix = $this->prefix($request);
        if ($import->kind === 'GR') {
            $keys = DB::table('local_procurement_import_rows')->where('import_id', $import->id)->where('attempt', $import->attempt)
                ->whereNotNull('po_key')->distinct()->limit(2)->pluck('po_key');
            if ($keys->count() === 1 && ($po = LocalPurchaseOrder::where('po_number', $keys->first())->first(['id']))) {
                return route($prefix.'.show', $po);
            }
        }

        return route($prefix.'.index');
    }

    private function prefix(Request $request): string
    {
        return str_starts_with($request->route()->getName(), 'purchasing.') ? 'purchasing.local-procurement' : 'finance.local-procurement';
    }
}
