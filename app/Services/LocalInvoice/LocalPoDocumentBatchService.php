<?php

namespace App\Services\LocalInvoice;

use App\Jobs\ProcessLocalPoDocumentBatch;
use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\LocalPoDocumentBatch;
use App\Models\LocalPoDocumentEntry;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\FileSecurity\NativeArchiveInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocalPoDocumentBatchService
{
    public function assertAsyncReady(): void
    {
        if (! config('po_documents.async_enabled') || ! config('po_documents.host_verified')) {
            $this->reject('configuration');
        }
        foreach (['runtime_seconds', 'slice_seconds', 'disk_bytes', 'reserve_bytes', 'max_active', 'max_queued_per_user',
            'max_backlog', 'max_queue_seconds', 'retry_limit', 'timeout_seconds', 'lease_seconds', 'retention_seconds'] as $key) {
            if (! is_numeric(config('po_documents.'.$key)) || config('po_documents.'.$key) <= 0) {
                $this->reject('configuration');
            }
        }
        if (config('queue.connections.database.after_commit') !== false
            || (config('queue.connections.database.connection') ?: config('database.default')) !== config('database.default')
            || config('po_documents.timeout_seconds') >= config('queue.connections.database.retry_after')
            || config('po_documents.slice_seconds') >= config('po_documents.timeout_seconds')
            || config('po_documents.lease_seconds') <= config('po_documents.timeout_seconds')) {
            $this->reject('configuration');
        }
    }

    public function start(User $actor, User $supplier, UploadedFile $file, string $key, bool $dispatch = true, bool $asynchronous = true): LocalPoDocumentBatch
    {
        $path = Storage::disk('private')->path('po-documents/admission.lock');
        File::ensureDirectoryExists(dirname($path));
        $lock = fopen($path, 'c+b');
        if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->reject('capacity');
        }
        try {
            return $this->admit($actor, $supplier, $file, $key, $dispatch, $asynchronous);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function admit(User $actor, User $supplier, UploadedFile $file, string $key, bool $dispatch, bool $asynchronous): LocalPoDocumentBatch
    {
        if ($asynchronous) {
            $this->assertAsyncReady();
        }
        if (! Str::isUuid($key)) {
            $this->reject('configuration');
        }
        app(LocalPoDocumentService::class)->authorizePublication($actor, $supplier);
        $inspection = app(FileInspectionService::class)->inspectUpload($file, 'po_zip');
        $manifest = app(NativeArchiveInspector::class)->inspect($file->getPathname());
        $targets = [];
        $expanded = 0;
        foreach ($manifest as $entry) {
            if ($entry['ignored']) {
                continue;
            }
            $po = app(LocalPoDocumentService::class)->matchPo($supplier, $entry['name']);
            if (isset($targets[$po->id])) {
                $this->reject('rejected');
            }
            $expanded += $entry['declared_size'];
            $targets[$po->id] = $entry;
        }
        $existing = LocalPoDocumentBatch::where('user_id', $actor->id)->where('request_key', $key)->first();
        if ($existing) {
            $this->sameRequest($existing, $supplier, $inspection->sha256);

            return $existing;
        }
        $reservation = $inspection->fileSize + 2 * $expanded;
        $path = 'po-documents/originals/'.Str::uuid().'.zip';
        $active = LocalPoDocumentBatch::whereIn('status', LocalPoDocumentBatch::ACTIVE);
        if (! $asynchronous && (clone $active)->where('asynchronous', false)->exists()) {
            $this->reject('capacity');
        }
        if ($asynchronous && ((clone $active)->where('user_id', $actor->id)->count() >= config('po_documents.max_queued_per_user')
            || (clone $active)->count() >= config('po_documents.max_backlog'))) {
            $this->reject('capacity');
        }
        if ($asynchronous && (clone $active)->sum('reserved_bytes') + LocalPoDocumentBatch::where('status', 'FAILED')->whereNull('cleaned_at')->sum('reserved_bytes') + $reservation > config('po_documents.disk_bytes')) {
            $this->reject('storage_limit');
        }
        $stored = app(FileInspectionService::class)->storeUpload($file, 'po_zip', $path);
        try {
            $batch = DB::transaction(function () use ($actor, $supplier, $key, $stored, $inspection, $targets, $reservation, $asynchronous) {
                DB::table('local_po_document_capacity')->where('id', 1)->lockForUpdate()->firstOrFail();
                app(LocalPoDocumentService::class)->authorizePublication($actor->fresh(), $supplier->fresh());
                $existing = LocalPoDocumentBatch::where('user_id', $actor->id)->where('request_key', $key)->first();
                if ($existing) {
                    $this->sameRequest($existing, $supplier, $inspection->sha256);

                    return $existing;
                }
                $active = LocalPoDocumentBatch::whereIn('status', LocalPoDocumentBatch::ACTIVE);
                if ($asynchronous && ((clone $active)->where('user_id', $actor->id)->count() >= config('po_documents.max_queued_per_user')
                    || (clone $active)->count() >= config('po_documents.max_backlog'))) {
                    $this->reject('capacity');
                }
                if ($asynchronous && (clone $active)->sum('reserved_bytes') + LocalPoDocumentBatch::where('status', 'FAILED')->whereNull('cleaned_at')->sum('reserved_bytes') + $reservation > config('po_documents.disk_bytes')) {
                    $this->reject('storage_limit');
                }
                $free = disk_free_space(dirname(Storage::disk('private')->path($stored['file_path'])));
                $reserve = $asynchronous ? (int) config('po_documents.reserve_bytes') : (int) config('native_file_security.zip.minimum_free_bytes');
                if ($free === false || $free < $reservation + $reserve
                    || (! $asynchronous && $reservation > (int) config('native_file_security.zip.max_working_bytes'))) {
                    $this->reject('storage_limit');
                }
                $batch = LocalPoDocumentBatch::create(['user_id' => $actor->id, 'supplier_id' => $supplier->id,
                    'request_key' => $key, 'locale' => app()->getLocale(), 'status' => 'PENDING', 'asynchronous' => $asynchronous,
                    'budget' => ['zip' => config('native_file_security.zip'), 'policy_version' => config('native_file_security.policy_version')],
                    'original_path' => $stored['file_path'], 'original_filename' => $stored['file_name'], 'sha256' => $inspection->sha256,
                    'upload_bytes' => $inspection->fileSize, 'original_file_inspection_id' => $stored['file_inspection_id'],
                    'reserved_bytes' => $reservation, 'pdf_count' => count($targets), 'dispatch_token' => (string) Str::uuid(), 'dispatch_requested_at' => now()]);
                FileInspection::whereKey($stored['file_inspection_id'])->update(['metadata' => ['po_document_batch_id' => $batch->id]]);
                foreach ($targets as $poId => $entry) {
                    $batch->entries()->create(['entry_index' => $entry['entry_index'], 'filename' => $entry['name'],
                        'local_purchase_order_id' => $poId, 'declared_bytes' => $entry['declared_size']]);
                }

                return $batch;
            });
        } catch (\Throwable $exception) {
            $this->removePrivateFile($path);
            throw $exception;
        }
        if ($batch->original_path !== $path) {
            $this->removePrivateFile($path);
        }
        if ($dispatch && $asynchronous) {
            // A post-commit enqueue failure preserves the committed dispatch intent.
            DB::afterCommit(fn () => $this->dispatch($batch));
        }

        return $batch;
    }

    private function sameRequest(LocalPoDocumentBatch $batch, User $supplier, string $sha): void
    {
        if (! hash_equals($batch->sha256, $sha) || (int) $batch->supplier_id !== (int) $supplier->id) {
            $this->reject('changed');
        }
    }

    public function dispatch(LocalPoDocumentBatch $batch): void
    {
        try {
            $pending = ProcessLocalPoDocumentBatch::dispatch($batch->id, $batch->dispatch_token)
                ->onConnection('database')->onQueue(config('po_documents.queue'))->afterCommit();
            unset($pending);
            $batch->update(['dispatched_at' => now()]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function process(int $id, string $token): void
    {
        $disk = Storage::disk('private');
        $lockPath = $disk->path('po-documents/processing.lock');
        File::ensureDirectoryExists(dirname($lockPath));
        $lock = fopen($lockPath, 'c+b');
        if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return;
        }
        $started = microtime(true);
        $run = (string) Str::uuid();
        $batch = null;
        $locale = app()->getLocale();
        $originalLimits = config('native_file_security.zip');
        try {
            $batch = DB::transaction(function () use ($id, $token, $run) {
                DB::table('local_po_document_capacity')->where('id', 1)->lockForUpdate()->firstOrFail();
                $record = LocalPoDocumentBatch::whereKey($id)->lockForUpdate()->first();
                if (! $record || ! hash_equals($record->dispatch_token, $token)
                    || ! in_array($record->status, LocalPoDocumentBatch::ACTIVE, true) || $record->lease_expires_at?->isFuture()) {
                    return null;
                }
                $record->update(['status' => 'PROCESSING', 'run_token' => $run, 'attempt' => $record->attempt + 1,
                    'lease_expires_at' => now()->addSeconds($record->asynchronous ? (int) config('po_documents.lease_seconds') : 120)]);

                return $record;
            });
            if (! $batch) {
                return;
            }
            app()->setLocale($batch->locale);
            foreach ($batch->budget['zip'] ?? [] as $setting => $limit) {
                if (is_numeric($limit) && is_numeric($originalLimits[$setting] ?? null) && $setting !== 'minimum_free_bytes') {
                    config(['native_file_security.zip.'.$setting => min($limit, $originalLimits[$setting])]);
                }
            }
            if ($batch->asynchronous) {
                $this->assertAsyncReady();
            }
            $original = $disk->path($batch->original_path);
            if (! is_file($original) || ! hash_equals($batch->sha256, (string) hash_file('sha256', $original))) {
                $this->reject('changed');
            }
            $actor = User::findOrFail($batch->user_id);
            $supplier = User::findOrFail($batch->supplier_id);
            app(LocalPoDocumentService::class)->authorizePublication($actor, $supplier);
            foreach ($batch->entries()->get() as $entry) {
                if ($entry->final_path && $this->prepared($entry)) {
                    continue;
                }
                $this->deadline($batch, $started);
                if ($batch->asynchronous && microtime(true) - $started >= config('po_documents.slice_seconds')) {
                    $this->continueBatch($batch, $run);

                    return;
                }
                if (app(LocalPoDocumentService::class)->matchPo($supplier, $entry->filename)->id !== $entry->local_purchase_order_id) {
                    $this->reject('changed');
                }
                $directory = $disk->path('po-documents/staging/'.$batch->id.'/'.$run);
                File::ensureDirectoryExists($directory);
                $files = app(NativeArchiveInspector::class)->withDeadline($started + ($batch->asynchronous ? (int) config('po_documents.slice_seconds') : (int) config('native_file_security.zip.max_runtime_seconds')))->extractPdfArchive($original, $directory, null, [$entry->entry_index]);
                $file = $files[0] ?? null;
                if (! $file || $file['file_size'] !== $entry->declared_bytes) {
                    $this->reject('changed');
                }
                $relative = 'po-documents/staging/'.$batch->id.'/'.$run.'/'.basename($file['path']);
                $this->owned($batch, $run, fn () => $entry->update(['staging_path' => $relative, 'actual_bytes' => $file['file_size'], 'sha256' => $file['sha256']]));
                $destination = 'attachments/po-documents/'.$batch->id.'/'.$run.'/'.Str::uuid().'.pdf';
                $this->owned($batch, $run, fn () => $entry->update(['final_path' => $destination]));
                $stored = app(FileInspectionService::class)->storePath($file['path'], $entry->filename, 'po_zip_pdf', $destination);
                $this->owned($batch, $run, function () use ($entry, $stored, $batch) {
                    $entry->update(['file_inspection_id' => $stored['file_inspection_id']]);
                    FileInspection::whereKey($stored['file_inspection_id'])->update(['metadata' => ['po_document_batch_id' => $batch->id]]);
                    $actualBytes = (int) $batch->entries()->sum('actual_bytes');
                    if ($actualBytes > (int) config('native_file_security.zip.max_total_bytes')) {
                        $this->reject('storage_limit');
                    }
                    $batch->update(['processed_count' => $batch->entries()->whereNotNull('file_inspection_id')->count(), 'actual_bytes' => $actualBytes]);
                });
                if (! $disk->delete($relative)) {
                    $this->reject('failed');
                }
            }
            $this->owned($batch, $run, fn () => $batch->update(['status' => 'VALIDATED']));
            FileInspection::whereKey($batch->original_file_inspection_id)->update(['status' => 'VALIDATED']);
            $this->owned($batch, $run, fn () => $batch->update(['status' => 'PUBLISHING']));
            $this->deadline($batch, $started);
            DB::transaction(function () use ($batch, $run, $actor, $supplier, $started) {
                $locked = LocalPoDocumentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                if ($locked->run_token !== $run || $locked->status !== 'PUBLISHING') {
                    throw new RuntimeException('PO attempt superseded.');
                }
                $lockedSupplier = User::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                app(LocalPoDocumentService::class)->authorizePublication($actor->fresh(), $lockedSupplier);
                foreach ($batch->entries()->orderBy('local_purchase_order_id')->get() as $entry) {
                    $this->deadline($batch, $started);
                    $po = LocalPurchaseOrder::whereKey($entry->local_purchase_order_id)->lockForUpdate()->firstOrFail();
                    if ((int) $po->supplier_id !== (int) $supplier->id
                        || app(LocalPoDocumentService::class)->matchPo($lockedSupplier, $entry->filename)->id !== $po->id || ! $this->prepared($entry)) {
                        $this->reject('changed');
                    }
                    $attachment = $po->attachments()->create(['file_path' => $entry->final_path, 'file_name' => $entry->filename,
                        'file_type' => 'application/pdf', 'file_inspection_id' => $entry->file_inspection_id, 'uploaded_by' => $actor->id]);
                    $entry->update(['attachment_id' => $attachment->id]);
                    app(LocalFinanceAuditService::class)->record($po, 'po_document_uploaded', $actor, null,
                        ['attachment_id' => $attachment->id, 'file_name' => $entry->filename], ['batch' => $batch->id, 'source' => 'zip']);
                }
                $this->deadline($batch, $started);
                $locked->update(['status' => 'COMPLETED', 'finished_at' => now(), 'lease_expires_at' => null]);
            });
            $this->cleanup($batch->fresh(), true);
        } catch (\Throwable $exception) {
            if ($batch) {
                $changed = LocalPoDocumentBatch::whereKey($batch->id)->where('run_token', $run)->whereIn('status', LocalPoDocumentBatch::ACTIVE)
                    ->update(['status' => $exception instanceof ValidationException ? 'REJECTED' : 'FAILED',
                        'failure_code' => $this->failureCode($exception), 'finished_at' => now(), 'lease_expires_at' => null]);
                if ($changed === 1) {
                    FileInspection::whereKey($batch->original_file_inspection_id)->update(['status' => $exception instanceof ValidationException ? 'REJECTED' : 'ERROR']);
                    $this->cleanup($batch->fresh(), ! $batch->asynchronous);
                }
            }
            if (! $exception instanceof ValidationException) {
                report($exception);
            }
        } finally {
            if ($batch) {
                LocalPoDocumentBatch::whereKey($batch->id)->where('run_token', $run)->increment('processing_milliseconds', (int) ((microtime(true) - $started) * 1000));
            }
            config(['native_file_security.zip' => $originalLimits]);
            app()->setLocale($locale);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function owned(LocalPoDocumentBatch $batch, string $run, callable $operation): void
    {
        DB::transaction(function () use ($batch, $run, $operation) {
            $locked = LocalPoDocumentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->run_token !== $run || ! in_array($locked->status, LocalPoDocumentBatch::ACTIVE, true)) {
                throw new RuntimeException('PO attempt superseded.');
            }
            $operation();
            $locked->update(['lease_expires_at' => now()->addSeconds($batch->asynchronous ? (int) config('po_documents.lease_seconds') : 120)]);
        });
    }

    private function prepared(LocalPoDocumentEntry $entry): bool
    {
        $path = $entry->final_path ? Storage::disk('private')->path($entry->final_path) : null;
        $inspection = $entry->file_inspection_id ? FileInspection::find($entry->file_inspection_id) : null;

        return $inspection?->status === 'VALIDATED' && $inspection->file_path === $entry->final_path && $path && is_file($path) && filesize($path) === $entry->actual_bytes
            && hash_equals($entry->sha256, (string) hash_file('sha256', $path));
    }

    private function deadline(LocalPoDocumentBatch $batch, float $started): void
    {
        $limit = $batch->asynchronous ? (int) config('po_documents.runtime_seconds') : (int) config('native_file_security.zip.max_runtime_seconds');
        if ($batch->processing_milliseconds / 1000 + microtime(true) - $started > $limit) {
            $this->reject('timeout');
        }
    }

    private function continueBatch(LocalPoDocumentBatch $batch, string $run): void
    {
        $this->owned($batch, $run, fn () => $batch->update(['status' => 'PENDING', 'dispatch_token' => (string) Str::uuid(), 'dispatched_at' => null]));
        $batch->update(['lease_expires_at' => null]);
        $this->dispatch($batch->fresh());
    }

    public function retry(User $actor, LocalPoDocumentBatch $batch): LocalPoDocumentBatch
    {
        return $this->withProcessLock(fn () => $this->retryLocked($actor, $batch->fresh()));
    }

    private function retryLocked(User $actor, LocalPoDocumentBatch $batch): LocalPoDocumentBatch
    {
        $this->assertAsyncReady();
        Gate::forUser($actor)->authorize('retry', $batch);
        $this->cleanupInterruptedAttempt($batch);
        DB::transaction(function () use ($batch) {
            $locked = LocalPoDocumentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'FAILED' || $locked->retry_count >= config('po_documents.retry_limit') || ! Storage::disk('private')->exists($locked->original_path)) {
                $this->reject('failed');
            }
            $locked->update(['status' => 'PENDING', 'retry_count' => $locked->retry_count + 1, 'failure_code' => null,
                'finished_at' => null, 'cleaned_at' => null, 'dispatched_at' => null, 'dispatch_token' => (string) Str::uuid(), 'run_token' => null]);
        });
        $this->dispatch($batch->fresh());

        return $batch->fresh();
    }

    public function reconcile(): void
    {
        try {
            $pending = $this->withProcessLock(fn () => $this->withProcessLock(fn () => $this->reconcileLocked(), 'admission'));
        } catch (ValidationException) {
            return;
        }
        foreach ($pending as $batch) {
            $this->dispatch($batch);
        }
    }

    private function reconcileLocked(): array
    {
        $pending = [];
        foreach (LocalPoDocumentBatch::whereIn('status', LocalPoDocumentBatch::ACTIVE)->get() as $batch) {
            if (! $batch->asynchronous && $batch->status === 'PENDING' && $batch->created_at->lt(now()->subSeconds(2 * (int) config('native_file_security.zip.max_runtime_seconds')))) {
                $batch->update(['status' => 'FAILED', 'failure_code' => 'failed', 'finished_at' => now()]);
                $this->cleanup($batch, true);

                continue;
            }
            if ($batch->asynchronous && $batch->status === 'PENDING' && config('po_documents.max_queue_seconds') > 0
                && $batch->created_at->lt(now()->subSeconds((int) config('po_documents.max_queue_seconds')))) {
                $batch->update(['status' => 'FAILED', 'failure_code' => 'timeout', 'finished_at' => now()]);

                continue;
            }
            if ($batch->lease_expires_at?->isPast()) {
                $this->cleanupInterruptedAttempt($batch);
                LocalPoDocumentBatch::whereKey($batch->id)->where('lease_expires_at', $batch->lease_expires_at)->update([
                    'status' => $batch->asynchronous && $batch->retry_count < (int) config('po_documents.retry_limit') ? 'PENDING' : 'FAILED', 'lease_expires_at' => null,
                    'retry_count' => $batch->retry_count + 1, 'processing_milliseconds' => $batch->processing_milliseconds + 1000 * (int) config('po_documents.timeout_seconds', 0),
                    'dispatched_at' => null, 'dispatch_token' => (string) Str::uuid(), 'run_token' => null,
                    'failure_code' => $batch->asynchronous ? null : 'failed']);
                $batch->refresh();
                if (! $batch->asynchronous && $batch->status === 'FAILED') {
                    $this->cleanup($batch, true);
                }
            }
            if ($batch->asynchronous && $batch->status === 'PENDING' && (! $batch->dispatched_at || $batch->dispatched_at->lt(now()->subSeconds((int) config('queue.connections.database.retry_after', 660))))) {
                try {
                    $this->assertAsyncReady();
                    $pending[] = $batch;
                } catch (ValidationException) {
                }
            }
        }
        $retention = (int) config('po_documents.retention_seconds', 0);
        if ($retention > 0) {
            foreach (LocalPoDocumentBatch::whereIn('status', LocalPoDocumentBatch::TERMINAL)->whereNull('cleaned_at')->where('updated_at', '<', now()->subSeconds($retention))->get() as $batch) {
                $this->cleanup($batch, true);
            }
        }

        $this->cleanupAbandonedOriginals();

        return $pending;
    }

    private function withProcessLock(callable $operation, string $name = 'processing'): mixed
    {
        $path = Storage::disk('private')->path('po-documents/'.$name.'.lock');
        File::ensureDirectoryExists(dirname($path));
        $lock = fopen($path, 'c+b');
        if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->reject('capacity');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cleanupInterruptedAttempt(LocalPoDocumentBatch $batch): void
    {
        $disk = Storage::disk('private');
        $directory = 'po-documents/staging/'.$batch->id;
        if (is_dir($disk->path($directory)) && ! $disk->deleteDirectory($directory)) {
            throw new RuntimeException('Cannot remove interrupted PO staging.');
        }
        foreach ($batch->entries()->get() as $entry) {
            if ($entry->final_path && ! $this->prepared($entry)) {
                if (Attachment::where('file_path', $entry->final_path)->exists()) {
                    throw new RuntimeException('Incomplete PO tracking has a published attachment.');
                }
                if ($disk->exists($entry->final_path) && ! $disk->delete($entry->final_path)) {
                    throw new RuntimeException('Cannot remove interrupted PO preparation.');
                }
                $entry->update(['final_path' => null, 'file_inspection_id' => null]);
            }
            $entry->update(['staging_path' => null]);
        }
        foreach ($disk->allFiles('attachments/po-documents/'.$batch->id) as $path) {
            if (! $batch->entries()->where('final_path', $path)->exists() && ! Attachment::where('file_path', $path)->exists()
                && ! $disk->delete($path)) {
                throw new RuntimeException('Cannot remove untracked PO preparation.');
            }
        }
    }

    private function cleanupAbandonedOriginals(): void
    {
        // Both admission and processing locks are held. Accepted batches own
        // their retention; this sweeps only uploads abandoned before tracking.
        $disk = Storage::disk('private');
        $before = time() - 2 * (int) config('native_file_security.zip.max_runtime_seconds');
        foreach ($disk->allFiles('po-documents/originals') as $path) {
            if (! preg_match('~^po-documents/originals/[a-f0-9-]{36}\\.zip$~i', $path)
                || $disk->lastModified($path) > $before) {
                continue;
            }
            DB::transaction(function () use ($disk, $path) {
                $inspection = FileInspection::where('file_path', $path)->lockForUpdate()->first();
                if (LocalPoDocumentBatch::where('original_path', $path)->exists()) {
                    return;
                }
                foreach (['attachments', 'local_invoice_documents', 'ga_claim_documents', 'supplier_master_documents'] as $table) {
                    if (DB::table($table)->where('file_path', $path)->exists()) {
                        return;
                    }
                }
                if (! $disk->delete($path)) {
                    report(new RuntimeException('PO document admission orphan cleanup failed.'));

                    return;
                }
                $inspection?->update(['status' => 'ERROR', 'error_code' => 'abandoned_admission']);
            });
        }
    }

    private function cleanup(LocalPoDocumentBatch $batch, bool $removeOriginal): void
    {
        if (! in_array($batch->status, LocalPoDocumentBatch::TERMINAL, true)) {
            return;
        }
        $disk = Storage::disk('private');
        $complete = true;
        foreach ($batch->entries()->get() as $entry) {
            if ($entry->staging_path) {
                $complete = $this->removePrivateFile($entry->staging_path) && $complete;
            }
            if ($entry->final_path && ! $entry->attachment_id && ! Attachment::where('file_path', $entry->final_path)->exists()) {
                if ($this->removePrivateFile($entry->final_path)) {
                    $entry->update(['final_path' => null, 'file_inspection_id' => null]);
                } else {
                    $complete = false;
                }
            }
        }
        $staging = 'po-documents/staging/'.$batch->id;
        if (is_dir($disk->path($staging)) && ! $disk->deleteDirectory($staging)) {
            $complete = false;
        }
        foreach ($disk->allFiles('attachments/po-documents/'.$batch->id) as $path) {
            if (! Attachment::where('file_path', $path)->exists()) {
                $complete = $this->removePrivateFile($path) && $complete;
            }
        }
        if ($removeOriginal || $batch->status === 'REJECTED') {
            $complete = $this->removePrivateFile($batch->original_path) && $complete;
            if ($complete) {
                $batch->update(['cleaned_at' => now()]);
            }
        }
    }

    private function removePrivateFile(string $path): bool
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            return true;
        }
        if (! $disk->delete($path)) {
            report(new RuntimeException('PO document compensation failed; reconciliation must retry.'));

            return false;
        }

        return true;
    }

    private function failureCode(\Throwable $exception): string
    {
        if (! $exception instanceof ValidationException) {
            return 'failed';
        }
        $messages = Arr::flatten($exception->errors());
        foreach (['po_documents.storage_limit', 'file_security.errors.disk', 'file_security.errors.archive_storage'] as $key) {
            if (in_array(__($key), $messages, true)) {
                return 'storage_limit';
            }
        }

        return 'rejected';
    }

    private function reject(string $code): never
    {
        throw ValidationException::withMessages(['file' => __('po_documents.'.$code)]);
    }
}
