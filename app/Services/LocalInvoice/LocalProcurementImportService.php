<?php

namespace App\Services\LocalInvoice;

use App\Exceptions\ImportAttemptSuperseded;
use App\Jobs\ProcessLocalProcurementImport;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocalProcurementImportService
{
    public function start(User $actor, UploadedFile $file, string $kind): array
    {
        Gate::forUser($actor)->authorize('create', LocalProcurementImport::class);
        $this->assertAdmissionEnabled();
        if (! in_array($kind, LocalProcurementImport::KINDS, true)) {
            throw new RuntimeException('Unsupported import kind.');
        }
        $this->assertQueue();
        $format = app(LocalProcurementStreamingReader::class)->inspectFile($file->getPathname(), $file->getClientOriginalExtension());
        $token = Str::random(40);
        $path = 'imports/uploads/'.Str::uuid().'.'.$format;
        $stored = app(FileInspectionService::class)->storeUpload($file, 'spreadsheet_import', $path, 'import_file');
        try {
            $import = DB::transaction(function () use ($actor, $file, $kind, $token, $path, $stored) {
                $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($lockedActor)->authorize('create', LocalProcurementImport::class);
                $active = LocalProcurementImport::where('user_id', $actor->id)
                    ->whereIn('kind', LocalProcurementImport::KINDS)
                    ->whereIn('status', LocalProcurementImport::ACTIVE)
                    ->where(fn ($q) => $q->where('expires_at', '>', now())->orWhereIn('status', ['READING', 'VALIDATING', 'IMPORTING']))->count();
                if ($active >= (int) config('local_procurement_imports.active_per_user')) {
                    throw ValidationException::withMessages(['import_file' => __('local_procurement.large_import.capacity')]);
                }
                $import = LocalProcurementImport::create([
                    'user_id' => $actor->id, 'kind' => $kind, 'locale' => app()->getLocale(), 'status' => 'QUEUED',
                    'token_hash' => hash('sha256', $token), 'checksum' => hash_file('sha256', $file->getPathname()),
                    'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'expires_at' => now()->addHours((int) config('local_procurement_imports.preview_hours')),
                ]);
                $import->update(['token_hash' => hash('sha256', $this->tokenFor($import))]);
                $import->attachments()->create(['file_path' => $path, 'file_name' => $import->original_filename,
                    'file_type' => $stored['file_type'], 'file_inspection_id' => $stored['file_inspection_id'], 'uploaded_by' => $actor->id]);
                $job = new ProcessLocalProcurementImport($import->id, 'preview');
                $import->update(['owner_job_key' => $job->runKey]);
                dispatch($job->onQueue(config('local_procurement_imports.queue')));

                return $import;
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        return [$import->fresh(), $this->tokenFor($import)];
    }

    public function confirm(User $actor, string $token, string $kind): LocalProcurementImport
    {
        $this->assertAdmissionEnabled();
        abort_unless(in_array($kind, LocalProcurementImport::KINDS, true), 404);
        $this->assertQueue();

        return DB::transaction(function () use ($actor, $token, $kind) {
            $import = LocalProcurementImport::where('token_hash', hash('sha256', $token))
                ->where('user_id', $actor->id)->where('kind', $kind)->lockForUpdate()->first();
            abort_unless($import, 419, __('local_procurement.feedback.preview_expired'));
            $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($lockedActor)->authorize('confirm', $import);
            if (in_array($import->status, ['IMPORTING', 'COMPLETED'], true)) {
                return $import;
            }
            abort_if($import->expires_at->isPast(), 419, __('local_procurement.feedback.preview_expired'));
            abort_unless($import->status === 'READY', 409, __('local_procurement.large_import.not_ready'));
            $job = new ProcessLocalProcurementImport($import->id, 'confirm');
            $import->update(['status' => 'IMPORTING', 'failure' => null, 'owner_job_key' => $job->runKey]);
            dispatch($job->onQueue(config('local_procurement_imports.queue')));

            return $import->fresh();
        });
    }

    public function tokenFor(LocalProcurementImport $import): string
    {
        return substr(hash_hmac('sha256', $import->id.':'.$import->user_id.':'.$import->checksum, config('app.key')), 0, 40);
    }

    public function preview(int $id, ?string $runKey = null): void
    {
        if ($this->retireUnsupported($id)) {
            return;
        }
        $import = DB::transaction(function () use ($id, $runKey) {
            $record = LocalProcurementImport::whereKey($id)->lockForUpdate()->first();
            if (! $record || ($runKey !== null && $record->owner_job_key !== $runKey) || ! in_array($record->status, ['QUEUED', 'READING', 'VALIDATING'], true)
                || ($record->lease_expires_at && $record->lease_expires_at->isFuture())) {
                return null;
            }
            if ($record->expires_at->isPast()) {
                $record->update(['status' => 'EXPIRED', 'finished_at' => now(), 'lease_expires_at' => null]);

                return null;
            }
            $record->update(['status' => 'READING', 'attempt' => $record->attempt + 1, 'processed_rows' => 0,
                'source_rows' => 0, 'summary' => null, 'failure' => null, 'lease_expires_at' => now()->addSeconds(600)]);

            return $record;
        });
        if (! $import) {
            return;
        }
        $actor = User::findOrFail($import->user_id);
        $reader = app(LocalProcurementStreamingReader::class);
        $started = microtime(true);
        try {
            Gate::forUser($actor)->authorize('view', $import);
            $path = Storage::disk('private')->path($import->attachments()->firstOrFail()->file_path);
            if (! hash_equals($import->checksum, hash_file('sha256', $path))) {
                throw new RuntimeException('Import checksum mismatch.');
            }
            $batch = [];
            foreach ($reader->rows($path, $import->kind) as $row) {
                $batch[] = $row;
                if (count($batch) === $this->batchSize()) {
                    $this->stage($import, $batch);
                    $batch = [];
                    $this->deadline($started, (int) config('local_procurement_imports.timeout_seconds'));
                }
            }
            if ($batch !== []) {
                $this->stage($import, $batch);
            }
            $this->ownedUpdate($import, ['status' => 'VALIDATING', 'warnings' => $reader->warnings]);
            $this->consolidate($import, $import->kind, $started);
            foreach ($this->recordBatches($import) as $records) {
                $result = $this->validator($import->kind)->validate($records->map(fn ($r) => json_decode($r->values, true))->all());
                $errorsByRow = collect($result['errors'])->groupBy('row');
                $normalized = collect($result['rows'])->keyBy('_row');
                $updates = [];
                foreach ($records as $record) {
                    $values = json_decode($record->values, true);
                    $values = array_merge($values, $normalized->get($record->source_row, []));
                    $errors = array_merge(json_decode($record->errors ?? '[]', true), $errorsByRow->get($record->source_row, collect())->all());
                    $updates[] = ['id' => $record->id, 'import_id' => $record->import_id, 'attempt' => $record->attempt,
                        'kind' => $record->kind, 'document_key' => $record->document_key, 'source_row' => $record->source_row,
                        'source_count' => $record->source_count, 'values' => json_encode($values), 'errors' => json_encode($errors)];
                }
                DB::table('local_procurement_import_records')->upsert($updates, ['id'], ['values', 'errors']);
                $this->ownedUpdate($import, ['lease_expires_at' => now()->addSeconds(600)]);
                $this->deadline($started, (int) config('local_procurement_imports.timeout_seconds'));
            }
            $summary = $this->summary($import);
            DB::transaction(function () use ($import, $summary, $actor) {
                $this->ownedUpdate($import, ['status' => $summary['invalid'] ? 'VALIDATION_FAILED' : 'READY', 'summary' => $summary,
                    'expires_at' => now()->addHours((int) config('local_procurement_imports.preview_hours')),
                    'source_rows' => $import->processed_rows, 'lease_expires_at' => null,
                    'finished_at' => $summary['invalid'] ? now() : null]);
                $this->validator($import->kind)->recordPreview($actor, $import->original_filename,
                    ['success' => ! $summary['invalid'], 'rows' => [], 'errors' => [], 'source_row_count' => $import->processed_rows, 'summary' => $summary, 'checksum' => $import->checksum]);
            });
        } catch (ImportAttemptSuperseded $exception) {
            return;
        } catch (ValidationException $exception) {
            $this->ownedUpdate($import, ['status' => 'VALIDATION_FAILED', 'failure' => implode(' ', array_slice($exception->validator->errors()->all(), 0, 10)),
                'lease_expires_at' => null, 'finished_at' => now()]);
        } catch (\Throwable $exception) {
            $this->ownedUpdate($import, ['lease_expires_at' => null]);
            throw $exception;
        }
    }

    public function commit(int $id, ?string $runKey = null): void
    {
        if ($this->retireUnsupported($id)) {
            return;
        }
        $owner = $runKey ?? LocalProcurementImport::find($id)?->owner_job_key;
        try {
            DB::transaction(function () use ($id, $runKey) {
                $import = LocalProcurementImport::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($import->status !== 'IMPORTING' || ($runKey !== null && $import->owner_job_key !== $runKey)) {
                    return;
                }
                $started = microtime(true);
                $actor = User::whereKey($import->user_id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('confirm', $import);
                // Lock existing parents once, globally ordered, before any GR writes.
                $ids = [];
                foreach ($this->sourceBatches($import, 'po_key') as $rows) {
                    array_push($ids, ...LocalPurchaseOrder::forceIndex('local_purchase_orders_po_number_unique')->whereIn('po_number', $rows->pluck('po_key')->unique())->pluck('id')->all());
                }
                $ids = array_values(array_unique($ids));
                sort($ids, SORT_NUMERIC);
                foreach (array_chunk($ids, $this->batchSize()) as $chunk) {
                    LocalPurchaseOrder::whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get(['id']);
                    $this->deadline($started, (int) config('local_procurement_imports.commit_seconds'));
                }
                $counts = ['newPo' => 0, 'existingPo' => 0, 'newGr' => 0, 'existingGr' => 0,
                    'sourceRows' => $import->source_rows, 'totalConsolidated' => (int) ($import->summary['consolidated_gr'] ?? 0), 'total' => $import->source_rows];
                foreach ($this->recordBatches($import) as $records) {
                    $rows = $records->map(fn ($r) => json_decode($r->values, true))->all();
                    if ($records->first()->kind === 'GR') {
                        // Staging is already normalized and sealed. The domain batch below
                        // rechecks live PO state, quantity/UOM and duplicates under locks.
                        // Re-running workbook grouping here would duplicate that work.
                        $normalized = $rows;
                    } else {
                        $result = $this->validator($import->kind)->validate($rows);
                        if (! $result['success']) {
                            throw ValidationException::withMessages(['import_file' => array_slice(array_column($result['errors'], 'message'), 0, 10)]);
                        }
                        $normalized = $result['rows'];
                    }
                    $batchCounts = app(LocalProcurementMasterService::class)->createImportBatch($actor, $import->kind, $records->all(), $normalized);
                    foreach ($batchCounts as $key => $value) {
                        $counts[$key] += $value;
                    }
                    $this->deadline($started, (int) config('local_procurement_imports.commit_seconds'));
                }
                app(LocalFinanceAuditService::class)->record($actor, match ($import->kind) {
                    'PO' => 'po_import_confirmed', 'GR' => 'gr_import_confirmed',
                }, $actor, null, null, array_merge($counts, ['import_id' => $import->id, 'original_filename' => $import->original_filename,
                    'source_row_count' => $import->source_rows, 'checksum' => $import->checksum, 'confirmed_at' => now()->toIso8601String()]));
                $import->update(['status' => 'COMPLETED', 'summary' => array_merge($import->summary ?? [], $counts),
                    'finished_at' => now(), 'lease_expires_at' => null]);
            }, 3);
        } catch (ValidationException $exception) {
            LocalProcurementImport::whereKey($id)->where('owner_job_key', $owner)->where('status', 'IMPORTING')->update(['status' => 'VALIDATION_FAILED',
                'failure' => implode(' ', $exception->validator->errors()->all()), 'finished_at' => now()]);
        }
    }

    private function stage(LocalProcurementImport $import, array $batch): void
    {
        $result = $import->kind === 'GR' ? app(LocalGrImportService::class)->validate($batch, true) : $this->validator($import->kind)->validate($batch);
        $normalized = collect($result['rows'])->keyBy('_row');
        foreach ($batch as $row) {
            foreach (['po_number', 'gr_number'] as $field) {
                if (mb_strlen(trim((string) ($row[$field] ?? ''))) > 100) {
                    $result['errors'][] = ['row' => $row['_row'], 'column' => $field, 'message' => __('validation.max.string', ['attribute' => $field, 'max' => 100])];
                }
            }
        }
        $errors = collect($result['errors'])->groupBy('row');
        DB::transaction(function () use ($import, $batch, $normalized, $errors) {
            $this->ownedUpdate($import, ['processed_rows' => $import->processed_rows + count($batch), 'lease_expires_at' => now()->addSeconds(600)]);
            $inserts = [];
            foreach ($batch as $raw) {
                $values = $normalized->get($raw['_row'], $raw);
                $values['_formula_columns'] ??= [];
                $inserts[] = ['import_id' => $import->id, 'attempt' => $import->attempt, 'source_row' => $raw['_row'],
                    'po_key' => mb_substr(mb_strtolower(trim((string) ($values['po_number'] ?? ''))), 0, 100),
                    'gr_key' => filled($values['gr_number'] ?? null) ? mb_substr(mb_strtolower(trim($values['gr_number'])), 0, 100) : null,
                    'values' => json_encode($values, JSON_THROW_ON_ERROR), 'errors' => json_encode($errors->get($raw['_row'], collect())->all())];
            }
            DB::table('local_procurement_import_rows')->insert($inserts);
        });
        $import->processed_rows += count($batch);
    }

    public function retireUnsupported(int $id): bool
    {
        $candidate = LocalProcurementImport::find($id);
        if (! $candidate || in_array($candidate->kind, LocalProcurementImport::KINDS, true)) {
            return false;
        }
        DB::transaction(function () use ($id) {
            $import = LocalProcurementImport::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($import->kind, LocalProcurementImport::KINDS, true) && in_array($import->status, LocalProcurementImport::ACTIVE, true)) {
                $import->update(['status' => 'CANCELLED', 'failure' => __('local_procurement.formats.retired', [], $import->locale), 'lease_expires_at' => null, 'finished_at' => now()]);
            }
        });

        return true;
    }

    private function consolidate(LocalProcurementImport $import, string $kind, float $started): void
    {
        $column = $kind === 'PO' ? 'po_key' : 'gr_key';
        $group = null;
        $pending = [];
        foreach ($this->sourceBatches($import, $column) as $batch) {
            foreach ($batch as $row) {
                if (json_decode($row->errors, true) !== [] || $row->{$column} === null) {
                    continue;
                }
                $values = json_decode($row->values, true);
                if ($group && $group['document_key'] !== $row->{$column}) {
                    $pending[] = $this->finishGroup($group, $import, $kind);
                    $group = null;
                    if (count($pending) === $this->batchSize()) {
                        DB::table('local_procurement_import_records')->insert($pending);
                        $pending = [];
                    }
                }
                if (! $group) {
                    $group = ['document_key' => $row->{$column}, 'values' => $values, 'source_row' => $row->source_row,
                        'source_count' => 0, 'qty' => '0.0000', 'errors' => [], 'descriptions' => [], 'description_length' => 0];
                }
                $first = $group['values'];
                $fields = $kind === 'PO' ? ['supplier_id', 'po_date', 'po_amount'] : ['po_number', 'gr_date', 'uom'];
                foreach ($fields as $field) {
                    $a = trim((string) ($first[$field] ?? ''));
                    $b = trim((string) ($values[$field] ?? ''));
                    if ($field === 'po_number') {
                        $a = mb_strtolower($a);
                        $b = mb_strtolower($b);
                    }
                    if ($a !== $b) {
                        $group['errors'][$field] = ['row' => $group['source_row'], 'column' => $field,
                            'message' => $field === 'uom' ? __('local_procurement.import.mixed_uom', ['gr' => $first['gr_number']]) : __('local_procurement.large_import.group_conflict')];
                    }
                }
                if ($kind === 'GR') {
                    if (! isset($group['errors']['uom'])) {
                        $group['qty'] = bcadd($group['qty'], (string) $values['qty'], 4);
                    }
                    $description = trim((string) ($values['description'] ?? ''));
                    if ($description !== '' && $group['description_length'] <= 2000 && ! isset($group['descriptions'][$description])) {
                        $group['descriptions'][$description] = $description;
                        $group['description_length'] += mb_strlen($description) + 2;
                    }
                }
                $group['source_count']++;
            }
            $this->ownedUpdate($import, ['lease_expires_at' => now()->addSeconds(600)]);
            $this->deadline($started, (int) config('local_procurement_imports.timeout_seconds'));
        }
        if ($group) {
            $pending[] = $this->finishGroup($group, $import, $kind);
        }
        if ($pending) {
            DB::table('local_procurement_import_records')->insert($pending);
        }
    }

    private function finishGroup(array $group, LocalProcurementImport $import, string $kind): array
    {
        $values = $group['values'];
        if ($kind === 'GR') {
            $values['qty'] = isset($group['errors']['uom']) ? null : rtrim(rtrim($group['qty'], '0'), '.');
            if (isset($group['errors']['uom'])) {
                $values['uom'] = null;
            }
            $description = implode(', ', $group['descriptions']);
            $values['description'] = $description === '' ? null : (mb_strlen($description) > 2000 ? mb_substr($description, 0, 1997).'...' : $description);
        }

        return ['import_id' => $import->id, 'attempt' => $import->attempt, 'kind' => $kind, 'document_key' => $group['document_key'],
            'source_row' => $group['source_row'], 'source_count' => $group['source_count'], 'values' => json_encode($values, JSON_THROW_ON_ERROR),
            'errors' => json_encode(array_values($group['errors']))];
    }

    private function sourceBatches(LocalProcurementImport $import, string $column): \Generator
    {
        $key = null;
        $number = 0;
        do {
            $query = DB::table('local_procurement_import_rows')->where('import_id', $import->id)->where('attempt', $import->attempt);
            if ($column === 'gr_key') {
                $query->whereNotNull($column);
            }
            if ($key !== null) {
                $query->where(fn ($q) => $q->where($column, '>', $key)->orWhere(fn ($q) => $q->where($column, $key)->where('source_row', '>', $number)));
            }
            $batch = $query->orderBy($column)->orderBy('source_row')->limit($this->batchSize())->get();
            if ($batch->isEmpty()) {
                break;
            }
            yield $batch;
            $key = $batch->last()->{$column};
            $number = $batch->last()->source_row;
        } while ($batch->count() === $this->batchSize());
    }

    private function recordBatches(LocalProcurementImport $import): \Generator
    {
        $last = 0;
        do {
            $batch = DB::table('local_procurement_import_records')->where('import_id', $import->id)->where('attempt', $import->attempt)
                ->where('kind', $import->kind)->where('id', '>', $last)->orderBy('id')->limit($this->batchSize())->get();
            if ($batch->isEmpty()) {
                break;
            }
            yield $batch;
            $last = $batch->last()->id;
        } while ($batch->count() === $this->batchSize());
    }

    private function summary(LocalProcurementImport $import): array
    {
        $records = DB::table('local_procurement_import_records')->where('import_id', $import->id)->where('attempt', $import->attempt);
        $invalid = DB::table('local_procurement_import_rows')->where('import_id', $import->id)->where('attempt', $import->attempt)->whereRaw('JSON_LENGTH(errors) > 0')->count();
        $invalid += (clone $records)->whereRaw('JSON_LENGTH(errors) > 0')->count();
        $po = (clone $records)->where('kind', 'PO')->count();
        $gr = (clone $records)->where('kind', 'GR')->count();
        $existingPo = (clone $records)->where('kind', 'PO')->where('values->action', 'EXISTING')->count();
        $existingGr = (clone $records)->where('kind', 'GR')->where('values->action', 'EXISTING')->count();
        $conflicts = (clone $records)->where('values->action', 'CONFLICT')->count();
        $conflicts += DB::table('local_procurement_import_rows')->where('import_id', $import->id)->where('attempt', $import->attempt)
            ->where('values->action', 'CONFLICT')->distinct()->count($import->kind === 'GR' ? 'gr_key' : 'po_key');
        $unmatched = (clone $records)->where('values->action', 'UNMATCHED_PO')->count();

        return ['total_rows' => $import->processed_rows, 'source_rows' => $import->processed_rows, 'unique_pos' => $po,
            'consolidated_gr' => $gr, 'new_po' => $po - $existingPo, 'existing_po' => $existingPo,
            'new_gr' => $gr - $existingGr, 'existing_gr' => $existingGr, 'invalid' => $invalid, 'conflicts' => $conflicts, 'unmatched_po' => $unmatched];
    }

    private function validator(string $kind): LocalPoImportService|LocalGrImportService
    {
        return app(match ($kind) {
            'PO' => LocalPoImportService::class, 'GR' => LocalGrImportService::class,
        });
    }

    private function ownedUpdate(LocalProcurementImport $import, array $attributes): void
    {
        $query = LocalProcurementImport::whereKey($import->id)->where('owner_job_key', $import->owner_job_key)->where('attempt', $import->attempt)->whereIn('status', ['READING', 'VALIDATING']);
        if (! $query->update($attributes) && ! $query->exists()) {
            throw new ImportAttemptSuperseded('Import attempt lost ownership.');
        }
    }

    private function batchSize(): int
    {
        return (int) config('local_procurement_imports.batch_size');
    }

    private function deadline(float $started, int $seconds): void
    {
        if (microtime(true) - $started > $seconds) {
            throw new RuntimeException('Import processing time budget exceeded.');
        }
    }

    private function assertQueue(): void
    {
        $queue = config('queue.default');
        if ($queue === 'sync' && app()->environment('testing')) {
            return;
        }
        if (config("queue.connections.$queue.driver") !== 'database'
            || (config("queue.connections.$queue.connection") ?? config('database.default')) !== config('database.default')
            || config("queue.connections.$queue.after_commit") !== false) {
            throw new RuntimeException('Imports require the same database queue connection and after_commit=false.');
        }
    }

    private function assertAdmissionEnabled(): void
    {
        abort_if(Cache::get('local_procurement_imports.paused', false), 503, __('local_procurement.formats.paused'));
    }
}
