<?php

namespace App\Services\LocalInvoice;

use App\Exceptions\ImportAttemptSuperseded;
use App\Exceptions\ImportBudgetExceeded;
use App\Jobs\ProcessLocalProcurementImport;
use App\Models\FileInspection;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\FileSecurity\FileInspectionService;
use App\Support\LocalPoPrNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Exception\OpenSpoutException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LocalProcurementImportService
{
    public function start(User $actor, UploadedFile $file, string $kind): array
    {
        Gate::forUser($actor)->authorize('create', LocalProcurementImport::class);
        $this->assertAdmissionEnabled();
        if (! in_array($kind, LocalProcurementImport::KINDS, true)) {
            throw new RuntimeException('Unsupported import kind.');
        }
        $reader = app(LocalProcurementStreamingReader::class);
        $format = $reader->inspectFile($file->getPathname(), $file->getClientOriginalExtension());
        $path = 'imports/uploads/'.Str::uuid().'.'.$format;
        $stored = app(FileInspectionService::class)->storeUpload($file, 'spreadsheet_import', $path, 'import_file');
        $absolutePath = Storage::disk('private')->path($path);
        $fastPathMax = max(0, (int) config('local_procurement_imports.fast_path_max_rows', 2000));
        $started = microtime(true);

        try {
            $import = DB::transaction(function () use ($actor, $file, $kind, $path, $stored, $absolutePath, $fastPathMax, $reader, $started) {
                $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($lockedActor)->authorize('create', LocalProcurementImport::class);
                $active = LocalProcurementImport::where('user_id', $actor->id)
                    ->whereIn('kind', LocalProcurementImport::KINDS)
                    ->whereIn('status', LocalProcurementImport::ACTIVE)
                    ->where(fn ($q) => $q->where('expires_at', '>', now())->orWhereIn('status', ['READING', 'VALIDATING', 'IMPORTING']))->count();
                if ($active >= (int) config('local_procurement_imports.active_per_user')) {
                    throw ValidationException::withMessages(['import_file' => __('local_procurement.large_import.capacity')]);
                }

                $checksum = hash_file('sha256', $absolutePath);
                $filename = mb_substr($file->getClientOriginalName(), 0, 255);
                $expiresAt = now()->addHours((int) config('local_procurement_imports.preview_hours'));
                $bufferedRows = [];
                $spilled = $fastPathMax === 0;
                $readerError = null;

                if (! $spilled) {
                    [$bufferedRows, $spilled, $readerError] = $this->readWithinFastPath($reader, $absolutePath, $kind, $fastPathMax);
                }

                if ($spilled) {
                    unset($bufferedRows);

                    return $this->enqueueNewPreview($actor, $kind, $checksum, $filename, $expiresAt, $path, $stored);
                }

                $runKey = (string) Str::uuid();
                $import = LocalProcurementImport::create([
                    'user_id' => $actor->id, 'kind' => $kind, 'locale' => app()->getLocale(), 'status' => 'READING',
                    'token_hash' => hash('sha256', Str::random(40)), 'checksum' => $checksum,
                    'original_filename' => $filename, 'attempt' => 1, 'owner_job_key' => $runKey,
                    'expires_at' => $expiresAt,
                ]);
                $import->fill(['token_hash' => hash('sha256', $this->generationTokenFor($import))])->save();
                $import->attachments()->create(['file_path' => $path, 'file_name' => $import->original_filename,
                    'file_type' => $stored['file_type'], 'file_inspection_id' => $stored['file_inspection_id'], 'uploaded_by' => $actor->id]);

                if ($readerError !== null) {
                    $this->failReaderValidation($import, $readerError, $reader->warnings, count($bufferedRows));

                    return $import;
                }

                $this->runFastPathPreview($import, $lockedActor, $kind, $bufferedRows, $reader->warnings, $started);

                return $import;
            });
        } catch (\Throwable $exception) {
            try {
                Storage::disk('private')->delete($path);
                FileInspection::whereKey($stored['file_inspection_id'])
                    ->whereIn('status', ['PENDING', 'VALIDATED'])
                    ->update(['status' => 'ERROR', 'error_code' => 'admission_failed']);
            } catch (\Throwable $cleanupFailure) {
                report($cleanupFailure);
            }
            throw $exception;
        }

        $fresh = $import->fresh();

        return [$fresh, $this->tokenFor($fresh)];
    }

    public function confirm(User $actor, string $token, string $kind): LocalProcurementImport
    {
        $this->assertAdmissionEnabled();
        abort_unless(in_array($kind, LocalProcurementImport::KINDS, true), 404);

        $runKey = null;
        $runSyncCommit = false;
        $import = DB::transaction(function () use ($actor, $token, $kind, &$runKey, &$runSyncCommit) {
            $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $import = LocalProcurementImport::where('token_hash', hash('sha256', $token))
                ->where('user_id', $actor->id)->where('kind', $kind)->lockForUpdate()->first();
            abort_unless($import, 419, __('local_procurement.feedback.preview_expired'));
            Gate::forUser($lockedActor)->authorize('confirm', $import);
            if (in_array($import->status, ['IMPORTING', 'COMPLETED'], true)) {
                return $import;
            }
            abort_if($import->expires_at->isPast(), 419, __('local_procurement.feedback.preview_expired'));
            abort_unless($import->status === 'READY', 409, __('local_procurement.large_import.not_ready'));
            $newCount = $import->summary[$kind === 'PO' ? 'new_po' : 'new_gr'] ?? null;
            abort_unless(is_int($newCount) && $newCount >= 0, 409, __('local_procurement.large_import.not_ready'));
            if ($kind === 'PO') {
                $prCount = $import->summary['po_pr_filled'] ?? 0;
                abort_unless(is_int($prCount) && $prCount >= 0, 409, __('local_procurement.large_import.not_ready'));
                $newCount += $prCount;
            }
            if ($newCount === 0) {
                throw ValidationException::withMessages(['import_file' => __('local_procurement.import.no_new_records')]);
            }

            $commitFastMax = max(0, (int) config('local_procurement_imports.fast_path_commit_max_records', 1500));
            $recordCount = $commitFastMax > 0
                ? DB::table('local_procurement_import_records')->where('import_id', $import->id)->where('attempt', $import->attempt)->where('kind', $import->kind)->count()
                : PHP_INT_MAX;

            if ($commitFastMax > 0 && $recordCount <= $commitFastMax) {
                $runKey = (string) Str::uuid();
                $runSyncCommit = true;
                $import->update([
                    'status' => 'IMPORTING',
                    'failure' => null,
                    'owner_job_key' => $runKey,
                    'lease_expires_at' => now()->addSeconds((int) config('local_procurement_imports.commit_seconds') + 30),
                ]);

                return $import->fresh();
            }

            $this->assertQueue();
            $job = new ProcessLocalProcurementImport($import->id, 'confirm');
            $import->update(['status' => 'IMPORTING', 'failure' => null, 'owner_job_key' => $job->runKey]);
            dispatch($job->onQueue(config('local_procurement_imports.queue')));

            return $import->fresh();
        });

        if ($runSyncCommit) {
            try {
                $this->executeCommit($import->id, $runKey, rethrowBudgetExceeded: false);
            } catch (\Throwable $exception) {
                // Synchronous web requests have no queue retry loop; release the operator slot cleanly after rollback.
                LocalProcurementImport::whereKey($import->id)->where('owner_job_key', $runKey)->where('status', 'IMPORTING')->update([
                    'status' => 'FAILED',
                    'failure' => __('local_procurement.large_import.failed'),
                    'finished_at' => now(),
                    'lease_expires_at' => null,
                ]);
                app(LocalProcurementImportProgress::class)->forget($import);
                throw $exception;
            }

            return $import->fresh();
        }

        if ($import->status === 'IMPORTING') {
            app(LocalProcurementImportProgress::class)->write($import, 'WAITING_SAVE', 0,
                (int) ($import->summary[$kind === 'PO' ? 'unique_pos' : 'consolidated_gr'] ?? 0), '', true);
        }

        return $import;
    }

    public function tokenFor(LocalProcurementImport $import): string
    {
        $legacy = substr(hash_hmac('sha256', $import->id.':'.$import->user_id.':'.$import->checksum, config('app.key')), 0, 40);

        return hash_equals((string) $import->token_hash, hash('sha256', $legacy)) ? $legacy : $this->generationTokenFor($import);
    }

    public function generationTokenFor(LocalProcurementImport $import): string
    {
        return substr(hash_hmac('sha256', $import->id.':'.$import->user_id.':'.$import->checksum.':'.$import->owner_job_key, config('app.key')), 0, 40);
    }

    public function canRevalidate(LocalProcurementImport $import): bool
    {
        if (! in_array($import->status, ['READY', 'VALIDATION_FAILED', 'FAILED'], true)
            || $import->expires_at->isPast() || $import->cleaned_at !== null) {
            return false;
        }
        $import->loadMissing('attachments.fileInspection');
        $attachment = $import->attachments->first();
        if (! $attachment || ! is_file(Storage::disk('private')->path($attachment->file_path))) {
            return false;
        }
        $inspection = $attachment->fileInspection;
        if ($inspection?->status === 'VALIDATED') {
            return $inspection->file_path === $attachment->file_path
                && filesize(Storage::disk('private')->path($attachment->file_path)) === $inspection->file_size;
        }

        return app(FileAccessGuard::class)->classification($attachment) === 'HISTORICAL_UNVERIFIED';
    }

    public function revalidate(User $actor, LocalProcurementImport $candidate, int $expectedAttempt): LocalProcurementImport
    {
        Gate::forUser($actor)->authorize('revalidate', $candidate);
        $this->assertAdmissionEnabled();
        Validator::make(['expected_attempt' => $expectedAttempt], ['expected_attempt' => ['integer', 'min:0']], [
            'expected_attempt.min' => __('local_procurement.revalidation.refresh_required'),
        ])->validate();
        $fastPathMax = max(0, (int) config('local_procurement_imports.fast_path_max_rows', 2000));
        $started = microtime(true);

        $import = DB::transaction(function () use ($actor, $candidate, $expectedAttempt, $fastPathMax, $started) {
            $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $import = LocalProcurementImport::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($lockedActor)->authorize('revalidate', $import);
            abort_unless(in_array($import->status, ['READY', 'VALIDATION_FAILED', 'FAILED'], true), 409, __('local_procurement.revalidation.not_available'));
            abort_unless((int) $import->attempt === $expectedAttempt, 409, __('local_procurement.revalidation.refresh_required'));
            abort_if($import->expires_at->isPast(), 419, __('local_procurement.feedback.preview_expired'));
            if (! $this->canRevalidate($import)) {
                throw ValidationException::withMessages(['import_file' => __('local_procurement.revalidation.file_unavailable')]);
            }
            try {
                app(FileAccessGuard::class)->assertReadable($import->attachments->first());
            } catch (HttpExceptionInterface $exception) {
                throw ValidationException::withMessages(['import_file' => __('local_procurement.revalidation.file_unavailable')]);
            }
            $active = LocalProcurementImport::where('user_id', $actor->id)->where('id', '!=', $import->id)
                ->whereIn('kind', LocalProcurementImport::KINDS)->whereIn('status', LocalProcurementImport::ACTIVE)
                ->where(fn ($q) => $q->where('expires_at', '>', now())->orWhereIn('status', ['READING', 'VALIDATING', 'IMPORTING']))->count();
            if ($active >= (int) config('local_procurement_imports.active_per_user')) {
                throw ValidationException::withMessages(['import_file' => __('local_procurement.large_import.capacity')]);
            }

            app(LocalProcurementImportProgress::class)->forget($import);

            $bufferedRows = [];
            $spilled = $fastPathMax === 0;
            $readerError = null;
            $reader = app(LocalProcurementStreamingReader::class);

            if (! $spilled) {
                $path = Storage::disk('private')->path($import->attachments->firstOrFail()->file_path);
                if (! hash_equals($import->checksum, hash_file('sha256', $path))) {
                    throw new RuntimeException('Import checksum mismatch.');
                }
                [$bufferedRows, $spilled, $readerError] = $this->readWithinFastPath($reader, $path, $import->kind, $fastPathMax);
            }

            if ($spilled) {
                unset($bufferedRows);
                $this->assertQueue();
                $job = new ProcessLocalProcurementImport($import->id, 'preview');
                $import->fill(['status' => 'QUEUED', 'owner_job_key' => $job->runKey, 'summary' => null,
                    'failure' => null, 'warnings' => null, 'finished_at' => null, 'lease_expires_at' => null,
                    'processed_rows' => 0, 'source_rows' => 0]);
                $import->fill(['token_hash' => hash('sha256', $this->generationTokenFor($import))])->save();
                dispatch($job->onQueue(config('local_procurement_imports.queue')));

                return $import;
            }

            $runKey = (string) Str::uuid();
            $import->fill(['status' => 'READING', 'attempt' => (int) $import->attempt + 1, 'owner_job_key' => $runKey,
                'summary' => null, 'failure' => null, 'warnings' => null, 'finished_at' => null,
                'lease_expires_at' => null, 'processed_rows' => 0, 'source_rows' => 0]);
            $import->fill(['token_hash' => hash('sha256', $this->generationTokenFor($import))])->save();

            if ($readerError !== null) {
                $this->failReaderValidation($import, $readerError, $reader->warnings, count($bufferedRows));

                return $import;
            }

            $this->runFastPathPreview($import, $lockedActor, $import->kind, $bufferedRows, $reader->warnings, $started);

            return $import;
        });

        return $import->fresh();
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
        $stats = $this->emptySummaryAccumulator();
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
                    $this->stage($import, $batch, $stats);
                    $batch = [];
                    $this->deadline($started, (int) config('local_procurement_imports.timeout_seconds'));
                }
            }
            if ($batch !== []) {
                $this->stage($import, $batch, $stats);
            }
            $this->ownedUpdate($import, ['status' => 'VALIDATING', 'warnings' => $reader->warnings]);
            $this->consolidate($import, $import->kind, $started, $stats);
            $summary = $this->finalizeSummary($stats, $import->processed_rows);
            $noNewRecords = $summary['invalid'] === 0 && ($summary[$import->kind === 'PO' ? 'new_po' : 'new_gr'] + ($import->kind === 'PO' ? $summary['po_pr_filled'] : 0)) === 0;
            $failed = $summary['invalid'] > 0 || $noNewRecords;
            DB::transaction(function () use ($import, $summary, $actor, $noNewRecords, $failed) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('view', $import);
                $this->ownedUpdate($import, ['status' => $failed ? 'VALIDATION_FAILED' : 'READY', 'summary' => $summary,
                    'failure' => $noNewRecords ? __('local_procurement.import.no_new_records') : null,
                    'source_rows' => $import->processed_rows, 'lease_expires_at' => null,
                    'finished_at' => $failed ? now() : null]);
                $this->validator($import->kind)->recordPreview($actor, $import->original_filename,
                    ['success' => ! $failed, 'rows' => [], 'errors' => [], 'source_row_count' => $import->processed_rows, 'summary' => $summary, 'checksum' => $import->checksum]);
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
        $this->executeCommit($id, $runKey, rethrowBudgetExceeded: true);
    }

    private function executeCommit(int $id, ?string $runKey, bool $rethrowBudgetExceeded): void
    {
        if ($this->retireUnsupported($id)) {
            return;
        }
        $owner = $runKey ?? LocalProcurementImport::find($id)?->owner_job_key;
        $userId = LocalProcurementImport::whereKey($id)->value('user_id');
        if (! $userId) {
            return;
        }
        $executionNumber = 0;
        try {
            DB::transaction(function () use ($id, $runKey, $userId, &$executionNumber) {
                $actor = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $import = LocalProcurementImport::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($import->status !== 'IMPORTING' || ($runKey !== null && $import->owner_job_key !== $runKey)) {
                    return;
                }
                $started = microtime(true);
                $executionNumber++;
                $execution = (string) Str::uuid();
                $telemetry = app(LocalProcurementImportProgress::class);
                $totalRecords = DB::table('local_procurement_import_records')->where('import_id', $id)
                    ->where('attempt', $import->attempt)->where('kind', $import->kind)->count();
                $telemetry->write($import, $executionNumber > 1 ? 'RETRYING' : 'PREPARING', 0, $totalRecords, $execution);
                Gate::forUser($actor)->authorize('confirm', $import);
                if ($import->kind === 'PO') {
                    // Supplier users before POs, the order InvoiceSubmissionService uses.
                    // createImportBatch() takes these same shared locks per batch; taking
                    // them first prevents a deadlock with a concurrent invoice submission
                    // when the file repeats one of that supplier's registered POs.
                    $supplierIds = DB::table('local_procurement_import_records')->where('import_id', $id)
                        ->where('attempt', $import->attempt)->where('kind', 'PO')
                        ->selectRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.supplier_id')) AS supplier_id")
                        ->pluck('supplier_id')->map(fn ($value) => (int) $value)->filter(fn (int $value) => $value > 0)->unique()->sort()->values()->all();
                    foreach (array_chunk($supplierIds, $this->batchSize()) as $chunk) {
                        User::whereIn('id', $chunk)->orderBy('id')->sharedLock()->pluck('id');
                    }
                }
                // Lock existing parents once, globally ordered, before any GR writes.
                $ids = [];
                foreach ($this->recordBatches($import, ['id', 'values']) as $records) {
                    $poNumbers = $records->map(fn ($r) => mb_strtolower(trim((string) (json_decode($r->values, true)['po_number'] ?? ''))))
                        ->filter()->unique()->values()->all();
                    if ($poNumbers !== []) {
                        array_push($ids, ...LocalPurchaseOrder::forceIndex('local_purchase_orders_po_number_unique')->whereIn('po_number', $poNumbers)->pluck('id')->all());
                    }
                }
                $ids = array_values(array_unique($ids));
                sort($ids, SORT_NUMERIC);
                $lockedPoMap = collect();
                foreach (array_chunk($ids, $this->batchSize()) as $chunk) {
                    foreach (LocalPurchaseOrder::whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get() as $lockedPo) {
                        $lockedPoMap->put(mb_strtolower($lockedPo->po_number), $lockedPo);
                    }
                    $this->commitDeadline($started);
                }
                $counts = ['newPo' => 0, 'existingPo' => 0, 'newGr' => 0, 'existingGr' => 0, 'filledPoPr' => 0,
                    'sourceRows' => $import->source_rows, 'totalConsolidated' => (int) ($import->summary['consolidated_gr'] ?? 0), 'total' => $import->source_rows];
                $processedRecords = 0;
                $telemetry->write($import, 'SAVING', 0, $totalRecords, $execution);
                foreach ($this->recordBatches($import) as $records) {
                    $rows = $records->map(fn ($r) => json_decode($r->values, true))->all();
                    if ($records->first()->kind === 'GR') {
                        // Staging is already normalized and sealed. The domain batch below
                        // rechecks live PO state, quantity/UOM and duplicates under locks.
                        // Re-running workbook grouping here would duplicate that work.
                        $normalized = $rows;
                    } else {
                        // Supplier names are matched again at commit time. The
                        // registered-PO checks run once, under lock, in createImportBatch().
                        $result = app(LocalPoImportService::class)->validate($rows, checkDatabase: false);
                        if (! $result['success']) {
                            throw ValidationException::withMessages(['import_file' => array_slice(array_column($result['errors'], 'message'), 0, 10)]);
                        }
                        $normalized = $result['rows'];
                    }
                    $batchCounts = app(LocalProcurementMasterService::class)->createImportBatch($actor, $import->kind, $records->all(), $normalized, $lockedPoMap);
                    foreach ($batchCounts as $key => $value) {
                        $counts[$key] += $value;
                    }
                    $processedRecords += $records->count();
                    $telemetry->write($import, $processedRecords >= $totalRecords ? 'FINALIZING' : 'SAVING', $processedRecords, $totalRecords, $execution);
                    $this->commitDeadline($started);
                }
                if ($counts['newPo'] + $counts['newGr'] + $counts['filledPoPr'] === 0) {
                    throw ValidationException::withMessages(['import_file' => __('local_procurement.import.no_new_records')]);
                }
                app(LocalFinanceAuditService::class)->record($actor, match ($import->kind) {
                    'PO' => 'po_import_confirmed', 'GR' => 'gr_import_confirmed',
                }, $actor, null, null, array_merge($counts, ['import_id' => $import->id, 'original_filename' => $import->original_filename,
                    'source_row_count' => $import->source_rows, 'checksum' => $import->checksum, 'confirmed_at' => now()->toIso8601String()]));
                $import->update(['status' => 'COMPLETED', 'summary' => array_merge($import->summary ?? [], $counts, [
                    'new_po' => $counts['newPo'], 'existing_po' => $counts['existingPo'],
                    'po_pr_filled' => $counts['filledPoPr'],
                    'new_gr' => $counts['newGr'], 'existing_gr' => $counts['existingGr'],
                ]),
                    'finished_at' => now(), 'lease_expires_at' => null]);
            }, 3);
        } catch (ValidationException $exception) {
            LocalProcurementImport::whereKey($id)->where('owner_job_key', $owner)->where('status', 'IMPORTING')->update(['status' => 'VALIDATION_FAILED',
                'failure' => implode(' ', $exception->validator->errors()->all()), 'finished_at' => now(), 'lease_expires_at' => null]);
        } catch (ImportBudgetExceeded $exception) {
            // The transaction has already rolled back every master and audit row.
            // Fenced like the other terminal writes: a newer attempt keeps its state.
            LocalProcurementImport::whereKey($id)->where('owner_job_key', $owner)->where('status', 'IMPORTING')->update(['status' => 'FAILED',
                'failure' => __('local_procurement.large_import.budget_exceeded'), 'finished_at' => now(), 'lease_expires_at' => null]);
            if ($rethrowBudgetExceeded) {
                throw $exception;
            }
        } finally {
            $finished = LocalProcurementImport::find($id);
            if ($finished && $finished->owner_job_key === $owner) {
                app(LocalProcurementImportProgress::class)->forget($finished);
            }
        }
    }

    private function readWithinFastPath(LocalProcurementStreamingReader $reader, string $path, string $kind, int $fastPathMax): array
    {
        $rows = [];
        try {
            foreach ($reader->rows($path, $kind) as $row) {
                if (count($rows) >= $fastPathMax) {
                    return [[], true, null];
                }
                $rows[] = $row;
            }
        } catch (ValidationException $exception) {
            return [$rows, false, $exception];
        } catch (OpenSpoutException|RuntimeException $exception) {
            return [
                $rows,
                false,
                ValidationException::withMessages(['import_file' => __('local_procurement.formats.invalid_file')]),
            ];
        }

        return [$rows, false, null];
    }

    private function enqueueNewPreview(
        User $actor,
        string $kind,
        string $checksum,
        string $filename,
        \DateTimeInterface $expiresAt,
        string $path,
        array $stored,
    ): LocalProcurementImport {
        $this->assertQueue();
        $job = new ProcessLocalProcurementImport(0, 'preview');
        $import = LocalProcurementImport::create([
            'user_id' => $actor->id,
            'kind' => $kind,
            'locale' => app()->getLocale(),
            'status' => 'QUEUED',
            'token_hash' => hash('sha256', Str::random(40)),
            'checksum' => $checksum,
            'original_filename' => $filename,
            'attempt' => 0,
            'owner_job_key' => $job->runKey,
            'expires_at' => $expiresAt,
        ]);
        $import->fill(['token_hash' => hash('sha256', $this->generationTokenFor($import))])->save();
        $import->attachments()->create([
            'file_path' => $path,
            'file_name' => $import->original_filename,
            'file_type' => $stored['file_type'],
            'file_inspection_id' => $stored['file_inspection_id'],
            'uploaded_by' => $actor->id,
        ]);
        $job->importId = $import->id;
        dispatch($job->onQueue(config('local_procurement_imports.queue')));

        return $import;
    }

    private function failReaderValidation(
        LocalProcurementImport $import,
        ValidationException $exception,
        array $warnings,
        int $processedRows,
    ): void {
        $this->ownedUpdate($import, [
            'status' => 'VALIDATION_FAILED',
            'failure' => implode(' ', array_slice($exception->validator->errors()->all(), 0, 10)),
            'warnings' => $warnings,
            'processed_rows' => $processedRows,
            'source_rows' => 0,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ]);
    }

    private function runFastPathPreview(
        LocalProcurementImport $import,
        User $actor,
        string $kind,
        array $bufferedRows,
        array $warnings,
        float $started,
    ): void {
        $stats = $this->emptySummaryAccumulator();
        $processedRows = 0;
        $groups = [];
        $errorStageInserts = [];

        foreach (array_chunk($bufferedRows, $this->batchSize()) as $batch) {
            [$normalized, $errors] = $this->validateStageBatch($kind, $batch);
            foreach ($batch as $raw) {
                $rowNumber = $raw['_row'];
                $values = $normalized->get($rowNumber, $raw);
                $values['_formula_columns'] ??= [];
                $rowErrors = $errors->get($rowNumber, collect())->all();
                if ($rowErrors !== []) {
                    $stats['invalid']++;
                    $errorStageInserts[] = [
                        'import_id' => $import->id,
                        'attempt' => $import->attempt,
                        'source_row' => $rowNumber,
                        'po_key' => mb_substr(mb_strtolower(trim((string) ($values['po_number'] ?? ''))), 0, 100),
                        'gr_key' => filled($values['gr_number'] ?? null) ? mb_substr(mb_strtolower(trim($values['gr_number'])), 0, 100) : null,
                        'values' => json_encode($values, JSON_THROW_ON_ERROR),
                        'errors' => json_encode($rowErrors),
                    ];

                    continue;
                }

                $docKey = $kind === 'PO'
                    ? mb_substr(mb_strtolower(trim((string) ($values['po_number'] ?? ''))), 0, 100)
                    : (filled($values['gr_number'] ?? null) ? mb_substr(mb_strtolower(trim($values['gr_number'])), 0, 100) : null);
                if ($docKey === null) {
                    continue;
                }

                $group = $groups[$docKey] ?? null;
                $this->mergeIntoGroup($group, $docKey, $values, $rowNumber, $kind);
                $groups[$docKey] = $group;
            }
            $processedRows += count($batch);
            $this->deadline($started, (int) config('local_procurement_imports.timeout_seconds'));
        }
        unset($bufferedRows);

        foreach (array_chunk($errorStageInserts, $this->batchSize()) as $chunk) {
            DB::table('local_procurement_import_rows')->insert($chunk);
        }
        unset($errorStageInserts);

        ksort($groups, SORT_STRING);
        $pending = [];
        foreach ($groups as $group) {
            $pending[] = $this->finishGroup($group, $import, $kind);
            if (count($pending) === $this->batchSize()) {
                $this->insertValidatedRecords($import, $pending, $started, $stats, refreshLease: false);
                $pending = [];
            }
        }
        unset($groups);
        if ($pending !== []) {
            $this->insertValidatedRecords($import, $pending, $started, $stats, refreshLease: false);
        }

        $summary = $this->finalizeSummary($stats, $processedRows);
        $noNewRecords = $summary['invalid'] === 0 && ($summary[$kind === 'PO' ? 'new_po' : 'new_gr'] + ($kind === 'PO' ? $summary['po_pr_filled'] : 0)) === 0;
        $failed = $summary['invalid'] > 0 || $noNewRecords;
        $this->ownedUpdate($import, [
            'status' => $failed ? 'VALIDATION_FAILED' : 'READY',
            'processed_rows' => $processedRows,
            'source_rows' => $processedRows,
            'warnings' => $warnings,
            'summary' => $summary,
            'failure' => $noNewRecords ? __('local_procurement.import.no_new_records') : null,
            'lease_expires_at' => null,
            'finished_at' => $failed ? now() : null,
        ]);
        $this->validator($kind)->recordPreview($actor, $import->original_filename, [
            'success' => ! $failed,
            'rows' => [],
            'errors' => [],
            'source_row_count' => $processedRows,
            'summary' => $summary,
            'checksum' => $import->checksum,
        ]);
    }

    private function validateStageBatch(string $kind, array $batch): array
    {
        $result = $kind === 'GR'
            ? app(LocalGrImportService::class)->validate($batch, true)
            : $this->validator($kind)->validate($batch, false);
        $normalized = collect($result['rows'])->keyBy('_row');
        foreach ($batch as $row) {
            foreach (['po_number', 'gr_number'] as $field) {
                if (mb_strlen(trim((string) ($row[$field] ?? ''))) > 100) {
                    $result['errors'][] = [
                        'row' => $row['_row'],
                        'column' => $field,
                        'message' => __('validation.max.string', ['attribute' => $field, 'max' => 100]),
                    ];
                }
            }
        }

        return [$normalized, collect($result['errors'])->groupBy('row')];
    }

    private function stage(LocalProcurementImport $import, array $batch, array &$stats): void
    {
        [$normalized, $errors] = $this->validateStageBatch($import->kind, $batch);
        $invalidCount = 0;
        DB::transaction(function () use ($import, $batch, $normalized, $errors, &$invalidCount) {
            $this->ownedUpdate($import, ['processed_rows' => $import->processed_rows + count($batch), 'lease_expires_at' => now()->addSeconds(600)]);
            $inserts = [];
            $invalidCount = 0;
            foreach ($batch as $raw) {
                $values = $normalized->get($raw['_row'], $raw);
                $values['_formula_columns'] ??= [];
                $rowErrors = $errors->get($raw['_row'], collect())->all();
                if ($rowErrors !== []) {
                    $invalidCount++;
                }
                $inserts[] = ['import_id' => $import->id, 'attempt' => $import->attempt, 'source_row' => $raw['_row'],
                    'po_key' => mb_substr(mb_strtolower(trim((string) ($values['po_number'] ?? ''))), 0, 100),
                    'gr_key' => filled($values['gr_number'] ?? null) ? mb_substr(mb_strtolower(trim($values['gr_number'])), 0, 100) : null,
                    'values' => json_encode($values, JSON_THROW_ON_ERROR), 'errors' => json_encode($rowErrors)];
            }
            DB::table('local_procurement_import_rows')->insert($inserts);
        });
        $stats['invalid'] += $invalidCount;
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

    private function consolidate(LocalProcurementImport $import, string $kind, float $started, array &$stats): void
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
                        $this->insertValidatedRecords($import, $pending, $started, $stats);
                        $pending = [];
                    }
                }
                $this->mergeIntoGroup($group, $row->{$column}, $values, (int) $row->source_row, $kind);
            }
            $this->ownedUpdate($import, ['lease_expires_at' => now()->addSeconds(600)]);
            $this->deadline($started, (int) config('local_procurement_imports.timeout_seconds'));
        }
        if ($group) {
            $pending[] = $this->finishGroup($group, $import, $kind);
        }
        if ($pending) {
            $this->insertValidatedRecords($import, $pending, $started, $stats);
        }
    }

    private function mergeIntoGroup(?array &$group, string $documentKey, array $values, int $sourceRow, string $kind): void
    {
        if (! $group) {
            $group = [
                'document_key' => $documentKey,
                'values' => $values,
                'source_row' => $sourceRow,
                'source_count' => 0,
                'qty' => '0.0000',
                'errors' => [],
                'descriptions' => [],
                'description_length' => 0,
            ];
        }
        $first = $group['values'];
        if ($kind === 'PO' && filled($values['pr_number'] ?? null)) {
            if (filled($first['pr_number'] ?? null) && LocalPoPrNumber::key($first['pr_number']) !== LocalPoPrNumber::key($values['pr_number'])) {
                $group['errors']['pr_number'] = ['row' => $sourceRow, 'column' => 'pr_number',
                    'message' => __('local_procurement.pr_import.file_conflict', ['po' => $first['po_number']])];
            } elseif (blank($first['pr_number'] ?? null)) {
                $group['values']['pr_number'] = $values['pr_number'];
            }
        }
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

    /**
     * Validates one batch of consolidated records against live master data and
     * writes it once. Batches hold the same records, in the same order, as the
     * former insert-then-upsert pass over 500 records ordered by id.
     */
    private function insertValidatedRecords(
        LocalProcurementImport $import,
        array $records,
        float $started,
        array &$stats,
        bool $refreshLease = true,
    ): void {
        $result = $this->validator($import->kind)->validate(array_map(fn (array $record) => json_decode($record['values'], true), $records));
        $errorsByRow = collect($result['errors'])->groupBy('row');
        $normalized = collect($result['rows'])->keyBy('_row');
        foreach ($records as &$record) {
            $values = array_merge(json_decode($record['values'], true), $normalized->get($record['source_row'], []));
            $mergedErrors = array_merge(json_decode($record['errors'], true), $errorsByRow->get($record['source_row'], collect())->all());
            $action = $values['action'] ?? null;

            if ($import->kind === 'PO') {
                $stats['unique_pos']++;
                if ($mergedErrors === [] && $action === 'NEW') {
                    $stats['new_po']++;
                } elseif ($mergedErrors === [] && $action === 'FILL_PR') {
                    $stats['po_pr_filled']++;
                }
                if (in_array($action, ['EXISTING', 'FILL_PR'], true)) {
                    $stats['existing_po']++;
                }
            } else {
                $stats['consolidated_gr']++;
                if ($mergedErrors === [] && $action === 'NEW') {
                    $stats['new_gr']++;
                }
                if ($action === 'EXISTING') {
                    $stats['existing_gr']++;
                }
            }
            if ($mergedErrors !== []) {
                $stats['invalid']++;
            }
            if ($action === 'CONFLICT') {
                $stats['conflicts']++;
            }
            if ($action === 'UNMATCHED_PO') {
                $stats['unmatched_po']++;
            }

            $record['values'] = json_encode($values);
            $record['errors'] = json_encode($mergedErrors);
        }
        unset($record);
        DB::table('local_procurement_import_records')->insert($records);
        if ($refreshLease) {
            $this->ownedUpdate($import, ['lease_expires_at' => now()->addSeconds(600)]);
        }
        $this->deadline($started, (int) config('local_procurement_imports.timeout_seconds'));
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

    private function recordBatches(LocalProcurementImport $import, array $columns = ['*']): \Generator
    {
        $records = fn () => DB::table('local_procurement_import_records')->where('import_id', $import->id)
            ->where('attempt', $import->attempt)->where('kind', $import->kind);
        $maxId = (int) $records()->max('id');
        $last = 0;
        do {
            // Without the hint MySQL sometimes picks local_import_document_unique and
            // filesorts every record of the import for each batch (seen in EXPLAIN).
            // The bounded primary-key range returns the same rows in the same order.
            $batch = $records()->forceIndex('PRIMARY')->where('id', '>', $last)->where('id', '<=', $maxId)
                ->orderBy('id')->limit($this->batchSize())->get($columns);
            if ($batch->isEmpty()) {
                break;
            }
            yield $batch;
            $last = $batch->last()->id;
        } while ($batch->count() === $this->batchSize());
    }

    private function emptySummaryAccumulator(): array
    {
        return [
            'unique_pos' => 0,
            'consolidated_gr' => 0,
            'new_po' => 0,
            'po_pr_filled' => 0,
            'existing_po' => 0,
            'new_gr' => 0,
            'existing_gr' => 0,
            'invalid' => 0,
            'conflicts' => 0,
            'unmatched_po' => 0,
        ];
    }

    private function finalizeSummary(array $stats, int $sourceRows): array
    {
        return [
            'total_rows' => $sourceRows,
            'source_rows' => $sourceRows,
            'unique_pos' => $stats['unique_pos'],
            'consolidated_gr' => $stats['consolidated_gr'],
            'new_po' => $stats['new_po'],
            'po_pr_filled' => $stats['po_pr_filled'],
            'existing_po' => $stats['existing_po'],
            'new_gr' => $stats['new_gr'],
            'existing_gr' => $stats['existing_gr'],
            'invalid' => $stats['invalid'],
            'conflicts' => $stats['conflicts'],
            'unmatched_po' => $stats['unmatched_po'],
        ];
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

    private function commitDeadline(float $started): void
    {
        if (microtime(true) - $started > (int) config('local_procurement_imports.commit_seconds')) {
            throw new ImportBudgetExceeded('Import commit transaction time budget exceeded.');
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
