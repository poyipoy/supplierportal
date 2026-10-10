<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\LocalProcurementImport;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LocalProcurementImportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('create', LocalProcurementImport::class);

        return response()->json(LocalProcurementImport::where('user_id', $request->user()->id)
            ->whereIn('kind', LocalProcurementImport::KINDS)
            ->where(fn ($q) => $q->whereIn('status', ['QUEUED', 'READING', 'VALIDATING', 'READY', 'IMPORTING'])->orWhere('created_at', '>', now()->subDays(3)))
            ->latest('id')->limit(100)->get(['id', 'kind', 'status', 'original_filename'])->map(fn ($import) => ['id' => $import->hash, 'kind' => $import->kind,
                'status' => $import->status, 'filename' => $import->original_filename,
                'status_url' => route($this->prefix($request).'.imports.status', $import)]))->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, LocalProcurementImport $procurementImport, LocalProcurementImportService $service)
    {
        Gate::authorize('view', $procurementImport);
        $status = in_array($procurementImport->status, ['QUEUED', 'READY'], true) && $procurementImport->expires_at->isPast()
            ? 'EXPIRED' : $procurementImport->status;
        $supported = in_array($procurementImport->kind, LocalProcurementImport::KINDS, true);
        $page = $this->page($procurementImport, false);
        $errors = $this->page($procurementImport, true);

        return response()->json(['id' => $procurementImport->hash, 'kind' => $procurementImport->kind, 'status' => $status,
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
        DB::transaction(function () use ($procurementImport) {
            $import = LocalProcurementImport::whereKey($procurementImport->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($import->status, ['IMPORTING', 'COMPLETED'], true), 409, __('local_procurement.large_import.not_ready'));
            $import->update(['status' => 'CANCELLED', 'finished_at' => now(), 'lease_expires_at' => null]);
        });

        return response()->json(['status' => 'CANCELLED']);
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
                'EXISTING' => __('finance.async_copy.existing'), 'CONFLICT' => __('finance.async_copy.conflict'),
                'UNMATCHED_PO' => __('finance.async_copy.unmatched'), 'PO_CLOSED' => __('finance.async_copy.closed'),
                default => __('finance.async_copy.unknown'),
            };

            return array_merge($values, ['source_rows_count' => $row->source_count, 'kind' => $row->kind, 'action_label' => $label]);
        })->all();
        if ($errors) {
            $data = array_merge([], ...$data);
        }

        return ['data' => $data, 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()];
    }

    private function prefix(Request $request): string
    {
        return str_starts_with($request->route()->getName(), 'purchasing.') ? 'purchasing.local-procurement' : 'finance.local-procurement';
    }
}
