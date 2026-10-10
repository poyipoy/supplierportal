<?php

namespace Tests\Feature\LocalInvoice;

use App\Jobs\ProcessLocalPoDocumentBatch;
use App\Models\Attachment;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class LocalPoDocumentBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        config(['po_documents.async_enabled' => true, 'po_documents.host_verified' => true, 'po_documents.runtime_seconds' => 120,
            'po_documents.slice_seconds' => 20, 'po_documents.disk_bytes' => 16 * 1024 * 1024,
            'po_documents.reserve_bytes' => 1024, 'po_documents.max_active' => 1,
            'po_documents.max_queued_per_user' => 2, 'po_documents.max_backlog' => 10, 'po_documents.max_queue_seconds' => 3600,
            'po_documents.retry_limit' => 3, 'po_documents.timeout_seconds' => 40,
            'po_documents.lease_seconds' => 60, 'po_documents.retention_seconds' => 3600]);
    }

    public function test_pending_batch_is_private_and_duplicate_delivery_publishes_once(): void
    {
        [$actor, $supplier, $po] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $batch = app(LocalPoDocumentBatchService::class)->start($actor, $supplier, $file, (string) Str::uuid());
            $this->assertSame('PENDING', $batch->status);
            $this->assertSame(0, $po->attachments()->count());
            app(LocalPoDocumentBatchService::class)->process($batch->id, $batch->dispatch_token);
            app(LocalPoDocumentBatchService::class)->process($batch->id, $batch->dispatch_token);
            $this->assertSame('COMPLETED', $batch->fresh()->status);
            $this->assertSame(1, $po->attachments()->count());
            $this->assertDatabaseCount('local_finance_audit_logs', 1);
        } finally {
            @unlink($file->getPathname());
        }
    }

    public function test_idempotency_key_returns_same_batch_and_different_bytes_are_rejected(): void
    {
        [$actor, $supplier] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        $other = $this->zip('PO-OTHER.pdf');
        $key = (string) Str::uuid();
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, $key);
            $this->assertSame($batch->id, $service->start($actor, $supplier, $file, $key)->id);
            $this->expectException(ValidationException::class);
            $service->start($actor, $supplier, $other, $key);
        } finally {
            @unlink($file->getPathname());
            @unlink($other->getPathname());
        }
    }

    public function test_owner_change_after_admission_rejects_whole_batch(): void
    {
        [$actor, $supplier, $po] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $batch = app(LocalPoDocumentBatchService::class)->start($actor, $supplier, $file, (string) Str::uuid());
            $po->update(['supplier_id' => User::factory()->create(['role' => 'supplier'])->id]);
            app(LocalPoDocumentBatchService::class)->process($batch->id, $batch->dispatch_token);
            $this->assertSame('REJECTED', $batch->fresh()->status);
            $this->assertSame(0, Attachment::where('attachable_type', LocalPurchaseOrder::class)->count());
        } finally {
            @unlink($file->getPathname());
        }
    }

    public function test_other_operator_cannot_read_batch_and_missing_host_budgets_block_async_admission(): void
    {
        [$actor, $supplier] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $batch = app(LocalPoDocumentBatchService::class)->start($actor, $supplier, $file, (string) Str::uuid());
            $other = User::factory()->create(['role' => 'finance']);
            $this->actingAs($other)->getJson(route('finance.local-procurement.po-documents.status', $batch))->assertForbidden();
            config(['po_documents.disk_bytes' => null]);
            $this->expectException(ValidationException::class);
            app(LocalPoDocumentBatchService::class)->start($actor, $supplier, $file, (string) Str::uuid());
        } finally {
            @unlink($file->getPathname());
        }
    }

    public function test_reconciliation_dispatches_committed_pending_intent(): void
    {
        [$actor, $supplier] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $batch = app(LocalPoDocumentBatchService::class)->start($actor, $supplier, $file, (string) Str::uuid(), false);
            $this->assertNull($batch->dispatched_at);
            app(LocalPoDocumentBatchService::class)->reconcile();
            Queue::assertPushed(ProcessLocalPoDocumentBatch::class, fn ($job) => $job->batchId === $batch->id);
        } finally {
            @unlink($file->getPathname());
        }
    }

    public function test_publication_failure_rolls_back_all_attachments_and_audits_and_cleans_files(): void
    {
        [$actor, $supplier] = $this->actors();
        LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => 'PO-BATCH-2',
            'po_date' => '2026-10-09', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
        $file = $this->zip('PO-BATCH-1.pdf');
        $zip = new ZipArchive;
        $zip->open($file->getPathname());
        $zip->addFromString('PO-BATCH-2.pdf', LocalPoDocumentSecurityTest::pdf());
        $zip->close();
        $counter = 0;
        Attachment::creating(function () use (&$counter) {
            if (++$counter === 2) {
                throw new \RuntimeException('Injected publication failure');
            }
        });
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, (string) Str::uuid());
            $service->process($batch->id, $batch->dispatch_token);
            $this->assertSame('FAILED', $batch->fresh()->status);
            $this->assertDatabaseCount('attachments', 0);
            $this->assertDatabaseCount('local_finance_audit_logs', 0);
            $this->assertSame([], Storage::disk('private')->allFiles('attachments/po-documents'));
        } finally {
            Attachment::flushEventListeners();
            unlink($file->getPathname());
        }
    }

    public function test_expired_lease_is_fenced_and_retry_preserves_original_integrity(): void
    {
        [$actor, $supplier, $po] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, (string) Str::uuid(), false);
            $oldToken = $batch->dispatch_token;
            $batch->update(['status' => 'PROCESSING', 'run_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subSecond()]);
            $service->reconcile();
            $batch->refresh();
            $this->assertSame('PENDING', $batch->status);
            $this->assertNotSame($oldToken, $batch->dispatch_token);
            $service->process($batch->id, $oldToken);
            $this->assertSame(0, $po->attachments()->count());
            $service->process($batch->id, $batch->dispatch_token);
            $this->assertSame('COMPLETED', $batch->fresh()->status);
            $this->assertSame(1, $po->attachments()->count());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_reconciler_does_not_mutate_a_batch_while_the_process_lock_is_held(): void
    {
        [$actor, $supplier] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, (string) Str::uuid(), false);
            $batch->update(['status' => 'PROCESSING', 'run_token' => 'current-worker', 'lease_expires_at' => now()->subSecond()]);
            $path = Storage::disk('private')->path('po-documents/processing.lock');
            $lock = fopen($path, 'c+b');
            flock($lock, LOCK_EX);
            try {
                $service->reconcile();
                $this->assertSame('PROCESSING', $batch->fresh()->status);
                $this->assertSame('current-worker', $batch->fresh()->run_token);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $service->reconcile();
            $this->assertSame('PENDING', $batch->fresh()->status);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_reconciler_removes_abandoned_original_but_preserves_accepted_original(): void
    {
        [$actor, $supplier] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, (string) Str::uuid(), false);
            $orphan = 'po-documents/originals/'.Str::uuid().'.zip';
            Storage::disk('private')->put($orphan, 'abandoned upload');
            touch(Storage::disk('private')->path($orphan), time() - 300);
            touch(Storage::disk('private')->path($batch->original_path), time() - 300);
            $service->reconcile();
            Storage::disk('private')->assertMissing($orphan);
            Storage::disk('private')->assertExists($batch->original_path);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_one_hundred_pdfs_publish_with_one_attachment_and_audit_per_po(): void
    {
        [$actor, $supplier] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        $archive = new ZipArchive;
        $archive->open($file->getPathname());
        for ($number = 2; $number <= 100; $number++) {
            LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => 'PO-BATCH-'.$number,
                'po_date' => '2026-10-09', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
            $archive->addFromString('PO-BATCH-'.$number.'.pdf', LocalPoDocumentSecurityTest::pdf());
        }
        $archive->close();
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, (string) Str::uuid(), false, false);
            $this->assertDatabaseCount('attachments', 0);
            $service->process($batch->id, $batch->dispatch_token);
            $this->assertSame('COMPLETED', $batch->fresh()->status);
            $this->assertSame(100, $batch->fresh()->processed_count);
            $this->assertDatabaseCount('attachments', 100);
            $this->assertDatabaseCount('local_finance_audit_logs', 100);
            $this->assertSame(100, $batch->entries()->whereNotNull('attachment_id')->count());
            Storage::disk('private')->assertMissing($batch->original_path);
            $this->assertNotNull($batch->fresh()->cleaned_at);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_failed_original_cleanup_keeps_completed_documents_and_is_reconciled(): void
    {
        [$actor, $supplier, $po] = $this->actors();
        $file = $this->zip('PO-BATCH-1.pdf');
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, (string) Str::uuid(), false);
            $disk = Storage::disk('private');
            $manager = Storage::getFacadeRoot();
            $proxy = \Mockery::mock($disk)->makePartial();
            $proxy->shouldReceive('delete')->with($batch->original_path)->once()->andReturn(false);
            Storage::shouldReceive('disk')->with('private')->andReturn($proxy);
            $service->process($batch->id, $batch->dispatch_token);
            $this->assertSame('COMPLETED', $batch->fresh()->status);
            $this->assertSame(1, $po->attachments()->count());
            $this->assertNull($batch->fresh()->cleaned_at);
            $this->assertTrue($disk->exists($batch->original_path));
            Storage::swap($manager);
            $this->travel(3601)->seconds();
            $service->reconcile();
            $this->assertFalse($disk->exists($batch->original_path));
            $this->assertNotNull($batch->fresh()->cleaned_at);
            $this->assertSame(1, $po->attachments()->count());
        } finally {
            unlink($file->getPathname());
        }
    }

    private function actors(): array
    {
        $actor = User::factory()->create(['role' => 'finance']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        $po = LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => 'PO-BATCH-1',
            'po_date' => '2026-10-09', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);

        return [$actor, $supplier, $po];
    }

    private function zip(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'po-batch-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($name, LocalPoDocumentSecurityTest::pdf());
        $zip->close();

        return new UploadedFile($path, 'documents.zip', 'application/zip', null, true);
    }
}
