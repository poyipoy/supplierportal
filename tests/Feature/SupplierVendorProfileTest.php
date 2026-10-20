<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class SupplierVendorProfileTest extends TestCase
{
    use RefreshDatabase;

    private function createLocalSupplier(array $supplierData = []): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);

        Supplier::create(array_merge([
            'user_id' => $user->id,
            'company_name' => 'PT Baja Unggul',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ], $supplierData));

        return $user;
    }

    public function test_local_supplier_can_view_vendor_profile_with_existing_data(): void
    {
        $user = $this->createLocalSupplier([
            'company_name' => 'PT Baja Unggul',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'npwp' => '01.234.567.8-999.000',
            'phone' => '021-555555',
            'address' => 'Kawasan Industri MM2100',
            'payment_term_days' => 45,
            'pic_name' => 'Budi Santoso',
            'pic_email' => 'budi@bajaunggul.com',
            'pic_phone' => '08123456789',
        ]);

        SupplierBankAccount::create([
            'supplier_id' => $user->id,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Baja Unggul',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        SupplierMasterDocument::create([
            'supplier_id' => $user->id,
            'document_type' => 'NIB',
            'file_path' => 'supplier-documents/'.$user->id.'/nib.pdf',
            'original_filename' => 'NIB_Baja_Unggul.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 204800,
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('local-supplier.vendor-profile.show'));

        $response->assertOk();
        $response->assertSee('PT Baja Unggul');
        $response->assertSee('Kawasan Industri MM2100');
        $response->assertDontSee('Net 45 Hari');
        $response->assertDontSee('Payment Term');
        $response->assertSee('1234567890');
        $response->assertSee('NIB_Baja_Unggul.pdf');
    }

    public function test_supplier_submits_profile_only_change_request(): void
    {
        $user = $this->createLocalSupplier();

        $response = $this->actingAs($user)->post(route('local-supplier.vendor-profile.change-requests.store'), [
            'company_name' => 'PT Baja Unggul Mandiri',
            'phone' => '021-222222',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('supplier_change_requests', [
            'supplier_id' => $user->id,
            'status' => SupplierChangeRequest::STATUS_PENDING,
            'change_type' => SupplierChangeRequest::TYPE_PROFILE,
        ]);

        $cr = SupplierChangeRequest::where('supplier_id', $user->id)->first();
        $this->assertSame('PT Baja Unggul Mandiri', $cr->proposed_data['company_name']);
        $this->assertSame('PT Baja Unggul', $cr->current_data_snapshot['company_name']);
        $this->assertArrayNotHasKey('bank_name', $cr->proposed_data);
    }

    public function test_supplier_cannot_submit_bank_change_without_confirmation_document(): void
    {
        $user = $this->createLocalSupplier();

        $response = $this->actingAs($user)->from(route('local-supplier.vendor-profile.show'))->post(route('local-supplier.vendor-profile.change-requests.store'), [
            'bank_name' => 'Mandiri',
            'account_number' => '987654321',
            'account_holder_name' => 'PT Baja Unggul Mandiri',
        ]);

        $response->assertRedirect(route('local-supplier.vendor-profile.show'));
        $response->assertSessionHasErrors(['bank_confirmation_document_id']);
        $this->assertDatabaseCount('supplier_change_requests', 0);
    }

    public function test_supplier_cannot_submit_incomplete_bank_account_fields(): void
    {
        $user = $this->createLocalSupplier();

        $response = $this->actingAs($user)->from(route('local-supplier.vendor-profile.show'))->post(route('local-supplier.vendor-profile.change-requests.store'), [
            'bank_name' => 'Mandiri',
        ]);

        $response->assertRedirect(route('local-supplier.vendor-profile.show'));
        $response->assertSessionHasErrors(['account_number', 'account_holder_name', 'bank_confirmation_document_id']);
        $this->assertDatabaseCount('supplier_change_requests', 0);
    }

    public function test_supplier_bank_document_identifier_rejects_non_scalar_values(): void
    {
        $user = $this->createLocalSupplier();

        foreach ([[1], true] as $documentId) {
            $this->actingAs($user)->postJson(route('local-supplier.vendor-profile.change-requests.store'), [
                'bank_name' => 'Mandiri',
                'account_number' => '987654321',
                'account_holder_name' => 'PT Baja Unggul',
                'bank_confirmation_document_id' => $documentId,
            ])->assertUnprocessable()->assertJsonValidationErrors('bank_confirmation_document_id');
        }

        $this->assertDatabaseCount('supplier_change_requests', 0);
    }

    public function test_supplier_submits_bank_change_request_with_valid_document(): void
    {
        Storage::fake('private');
        $user = $this->createLocalSupplier();

        $stored = app(FileInspectionService::class)->storeUpload(
            NativeFileFixtures::upload('bank.pdf', 10),
            'vendor',
            'vendor-documents/bank-test.pdf'
        );
        $document = SupplierMasterDocument::create([
            'supplier_id' => $user->id,
            'document_type' => SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING,
            'file_path' => $stored['file_path'],
            'original_filename' => 'bank.pdf',
            'mime_type' => $stored['file_type'],
            'file_size' => $stored['file_size'],
            'file_inspection_id' => $stored['file_inspection_id'],
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('local-supplier.vendor-profile.change-requests.store'), [
            'company_name' => 'PT Baja Unggul Mandiri',
            'phone' => '021-222222',
            'bank_name' => 'Mandiri',
            'account_number' => '987654321',
            'account_holder_name' => 'PT Baja Unggul Mandiri',
            'bank_confirmation_document_id' => $document->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('supplier_change_requests', [
            'supplier_id' => $user->id,
            'status' => SupplierChangeRequest::STATUS_PENDING,
            'change_type' => SupplierChangeRequest::TYPE_BANK_ACCOUNT,
        ]);

        $cr = SupplierChangeRequest::where('supplier_id', $user->id)->first();
        $this->assertSame('PT Baja Unggul Mandiri', $cr->proposed_data['company_name']);
        $this->assertSame('Mandiri', $cr->proposed_data['bank_name']);
        $this->assertSame($document->id, $cr->proposed_data['bank_confirmation_document_id']);
    }

    public function test_supplier_uploads_master_document(): void
    {
        Storage::fake('private');

        $user = $this->createLocalSupplier();

        $file = NativeFileFixtures::upload('sppkp.pdf', 500);

        $response = $this->actingAs($user)->post(route('local-supplier.vendor-profile.documents.upload'), [
            'document_type' => 'SPPKP',
            'document' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('supplier_master_documents', [
            'supplier_id' => $user->id,
            'document_type' => 'SPPKP',
            'original_filename' => 'sppkp.pdf',
        ]);

        $doc = SupplierMasterDocument::where('supplier_id', $user->id)->first();
        Storage::disk('private')->assertExists($doc->file_path);
    }

    public function test_local_supplier_cannot_see_payment_term_in_dashboard_or_invoice_form(): void
    {
        $user = $this->createLocalSupplier([
            'company_name' => 'PT Baja Unggul',
            'payment_term_days' => 45,
            'npwp' => '01.234.567.8-999.000',
        ]);

        // Dashboard
        $dashboardResponse = $this->actingAs($user)->get(route('local-supplier.dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('PT Baja Unggul');
        $dashboardResponse->assertSee('01.234.567.8-999.000');
        $dashboardResponse->assertDontSee('Ketentuan Termin');
        $dashboardResponse->assertDontSee('Net 45 Hari');

        // Invoice create form
        $formResponse = $this->actingAs($user)->get(route('local-supplier.invoices.create'));
        $formResponse->assertOk();
        $formResponse->assertDontSee('Net 45 Hari');
        $formResponse->assertDontSee('Termin Pembayaran');
        $formResponse->assertDontSee('ketentuan termin pembayaran resmi');
    }
}
