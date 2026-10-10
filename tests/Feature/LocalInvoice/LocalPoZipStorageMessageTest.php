<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;
use ZipArchive;

class LocalPoZipStorageMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
    }

    public function test_seventy_mib_zip_is_rejected_by_the_backend_upload_limit_in_both_locales(): void
    {
        foreach (['en', 'id'] as $locale) {
            [$actor, $supplier] = $this->actors('finance', $locale);
            $this->actingAs($actor)->postJson(route('finance.local-procurement.upload-po'), [
                'supplier_id' => $supplier->id,
                'file' => UploadedFile::fake()->create('documents.zip', 70 * 1024, 'application/zip'),
            ])->assertUnprocessable()->assertJsonPath('errors.file.0', trans('local_procurement.validation.po_file_size', [], $locale));
        }
        $this->assertNoPublication();
        $this->assertDatabaseCount('local_po_document_batches', 0);
    }

    public function test_sync_working_storage_limit_returns_localized_file_error_without_publication(): void
    {
        config(['po_documents.async_enabled' => false, 'native_file_security.zip.max_working_bytes' => 1]);
        foreach (['finance', 'purchasing'] as $role) {
            foreach (['en', 'id'] as $locale) {
                [$actor, $supplier] = $this->actors($role, $locale);
                $file = $this->zip($supplier);
                try {
                    $this->actingAs($actor)->postJson(route($role.'.local-procurement.upload-po'), [
                        'supplier_id' => $supplier->id, 'file' => $file,
                    ])->assertUnprocessable()->assertJsonPath('errors.file.0', trans('po_documents.storage_limit', [], $locale));
                } finally {
                    unlink($file->getPathname());
                }
            }
        }
        $this->assertNoPublication();
        $this->assertDatabaseCount('local_po_document_batches', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('po-documents/originals'));
    }

    public function test_expanded_zip_limit_has_a_specific_storage_error(): void
    {
        [$actor, $supplier] = $this->actors('finance', 'id');
        config(['native_file_security.zip.max_total_bytes' => 64]);
        $file = $this->zip($supplier);
        try {
            $this->actingAs($actor)->postJson(route('finance.local-procurement.upload-po'), [
                'supplier_id' => $supplier->id, 'file' => $file,
            ])->assertUnprocessable()->assertJsonPath('errors.file.0', trans('file_security.errors.archive_storage', [], 'id'));
            $this->assertNoPublication();
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_insufficient_free_space_returns_storage_error_for_a_normal_form_submission(): void
    {
        [$actor, $supplier] = $this->actors('finance', 'id');
        config(['po_documents.async_enabled' => false, 'native_file_security.zip.minimum_free_bytes' => PHP_INT_MAX]);
        $file = $this->zip($supplier);
        try {
            $this->actingAs($actor)->from(route('finance.local-procurement.index'))->post(route('finance.local-procurement.upload-po'), [
                'supplier_id' => $supplier->id, 'file' => $file,
            ])->assertRedirect(route('finance.local-procurement.index'))
                ->assertSessionHasErrors(['file' => trans('po_documents.storage_limit', [], 'id')]);
            $this->assertNoPublication();
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_async_storage_admission_limit_is_distinct_from_busy_queue_capacity(): void
    {
        [$actor, $supplier] = $this->actors('finance', 'en');
        $this->enableTestAsync();
        config(['po_documents.disk_bytes' => 1]);
        $file = $this->zip($supplier);
        try {
            $this->actingAs($actor)->postJson(route('finance.local-procurement.upload-po'), [
                'supplier_id' => $supplier->id, 'file' => $file,
            ])->assertUnprocessable()->assertJsonPath('errors.file.0', trans('po_documents.storage_limit', [], 'en'));
            $this->assertDatabaseCount('local_po_document_batches', 0);
            $this->assertNoPublication();
            Queue::assertNothingPushed();
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_storage_failure_during_processing_survives_async_status_polling_in_both_locales(): void
    {
        [$actor, $supplier] = $this->actors('finance', 'en');
        $this->enableTestAsync();
        $file = $this->zip($supplier);
        try {
            $service = app(LocalPoDocumentBatchService::class);
            $batch = $service->start($actor, $supplier, $file, (string) Str::uuid(), false);
            config(['native_file_security.zip.minimum_free_bytes' => PHP_INT_MAX]);
            $service->process($batch->id, $batch->dispatch_token);
            $this->assertSame('REJECTED', $batch->fresh()->status);
            $this->assertSame('storage_limit', $batch->fresh()->failure_code);
            foreach (['en', 'id'] as $locale) {
                $actor->preference()->update(['locale' => $locale]);
                $actor->unsetRelation('preference');
                $this->actingAs($actor)->getJson(route('finance.local-procurement.po-documents.status', $batch))
                    ->assertOk()->assertJsonPath('status', 'REJECTED')
                    ->assertJsonPath('message', trans('po_documents.storage_limit', [], $locale));
            }
            $this->assertNoPublication();
            $this->assertSame([], Storage::disk('private')->allFiles('attachments/po-documents'));
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_busy_queue_keeps_its_capacity_error_instead_of_a_storage_error(): void
    {
        [$actor, $supplier] = $this->actors('finance', 'en');
        $this->enableTestAsync();
        config(['po_documents.max_queued_per_user' => 1]);
        $file = $this->zip($supplier);
        try {
            app(LocalPoDocumentBatchService::class)->start($actor, $supplier, $file, (string) Str::uuid(), false);
            $this->actingAs($actor)->postJson(route('finance.local-procurement.upload-po'), [
                'supplier_id' => $supplier->id, 'file' => $file,
            ])->assertUnprocessable()->assertJsonPath('errors.file.0', trans('po_documents.capacity', [], 'en'));
            $this->assertDatabaseCount('local_po_document_batches', 1);
            $this->assertNoPublication();
        } finally {
            unlink($file->getPathname());
        }
    }

    private function actors(string $role, string $locale): array
    {
        $actor = User::factory()->create(['role' => $role]);
        $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        $supplier = User::factory()->create(['role' => 'supplier']);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => 'PO-STORAGE-'.$supplier->id,
            'po_date' => '2026-10-09', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);

        return [$actor, $supplier];
    }

    private function zip(User $supplier): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'po-storage-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('PO-STORAGE-'.$supplier->id.'.pdf', NativeFileFixtures::pdf());
        $zip->close();

        return new UploadedFile($path, 'documents.zip', 'application/zip', null, true);
    }

    private function enableTestAsync(): void
    {
        config(['po_documents.async_enabled' => true, 'po_documents.host_verified' => true, 'po_documents.runtime_seconds' => 120,
            'po_documents.slice_seconds' => 20, 'po_documents.disk_bytes' => 16 * 1024 * 1024, 'po_documents.reserve_bytes' => 1024,
            'po_documents.max_active' => 1, 'po_documents.max_queued_per_user' => 2, 'po_documents.max_backlog' => 10,
            'po_documents.max_queue_seconds' => 3600, 'po_documents.retry_limit' => 3, 'po_documents.timeout_seconds' => 40,
            'po_documents.lease_seconds' => 60, 'po_documents.retention_seconds' => 3600]);
    }

    private function assertNoPublication(): void
    {
        $this->assertDatabaseCount('attachments', 0);
        $this->assertDatabaseCount('local_finance_audit_logs', 0);
    }
}
