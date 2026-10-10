<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Ga\GaClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DomainNativeFileSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function supplier(): User
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);

        return $supplier;
    }

    public function test_master_document_links_the_native_validation_verdict(): void
    {
        $supplier = $this->supplier();
        $this->actingAs($supplier)->postJson(route('local-supplier.vendor-profile.documents.upload'), [
            'document_type' => 'NIB',
            'document' => UploadedFile::fake()->image('nib.png', 20, 20),
        ])->assertOk()->assertJsonStructure(['redirect']);

        $document = SupplierMasterDocument::where('supplier_id', $supplier->id)->sole();
        $this->assertNotNull($document->file_inspection_id);
        $this->assertDatabaseHas('file_inspections', ['id' => $document->file_inspection_id, 'status' => 'VALIDATED']);
        Storage::disk('private')->assertExists($document->file_path);
    }

    public function test_truncated_pdf_is_not_published_as_a_master_document(): void
    {
        $this->actingAs($this->supplier())->postJson(route('local-supplier.vendor-profile.documents.upload'), [
            'document_type' => 'NIB',
            'document' => UploadedFile::fake()->createWithContent('nib.pdf', "%PDF-1.4\ntruncated"),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('supplier_master_documents', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_image_content_with_a_pdf_extension_is_rejected(): void
    {
        $this->actingAs($this->supplier())->postJson(route('local-supplier.vendor-profile.documents.upload'), [
            'document_type' => 'NIB',
            'document' => UploadedFile::fake()->image('nib.pdf', 20, 20),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('supplier_master_documents', 0);
    }

    public function test_ga_service_rejects_malformed_files_without_creating_a_claim(): void
    {
        $actor = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $employee = Employee::create([
            'name' => 'Native file inspection employee', 'department' => 'GA',
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder_name' => 'Employee', 'is_active' => true,
        ]);

        try {
            app(GaClaimService::class)->submitClaim($actor, [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_ENTERTAINMENT,
                'claim_date' => '2026-10-09', 'amount' => '1000.00',
            ], ['supporting' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nincomplete")]);
            $this->fail('Malformed supporting file must not publish a claim.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ga_claims', 0);
            $this->assertSame([], Storage::disk('private')->allFiles());
        }
    }

    public function test_file_is_compensated_when_master_document_insert_fails(): void
    {
        $this->withoutExceptionHandling();
        SupplierMasterDocument::creating(fn () => throw new \RuntimeException('Injected publication failure'));

        try {
            $this->actingAs($this->supplier())->postJson(route('local-supplier.vendor-profile.documents.upload'), [
                'document_type' => 'NIB',
                'document' => UploadedFile::fake()->image('nib.png', 20, 20),
            ]);
            $this->fail('The injected document insert failure must escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected publication failure', $exception->getMessage());
            $this->assertSame([], Storage::disk('private')->allFiles());
            $this->assertDatabaseCount('supplier_master_documents', 0);
        } finally {
            SupplierMasterDocument::flushEventListeners();
        }
    }
}
