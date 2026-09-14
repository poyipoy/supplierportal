<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $response->assertSee('Net 45 Hari');
        $response->assertSee('1234567890');
        $response->assertSee('NIB_Baja_Unggul.pdf');
    }

    public function test_supplier_submits_change_request(): void
    {
        $user = $this->createLocalSupplier();

        $response = $this->actingAs($user)->post(route('local-supplier.vendor-profile.change-requests.store'), [
            'company_name' => 'PT Baja Unggul Mandiri',
            'phone' => '021-222222',
            'bank_name' => 'Mandiri',
            'account_number' => '987654321',
            'account_holder_name' => 'PT Baja Unggul Mandiri',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('supplier_change_requests', [
            'supplier_id' => $user->id,
            'status' => SupplierChangeRequest::STATUS_PENDING,
        ]);

        $cr = SupplierChangeRequest::where('supplier_id', $user->id)->first();
        $this->assertSame('PT Baja Unggul Mandiri', $cr->proposed_data['company_name']);
        $this->assertSame('PT Baja Unggul', $cr->current_data_snapshot['company_name']);
    }

    public function test_supplier_uploads_master_document(): void
    {
        Storage::fake('private');

        $user = $this->createLocalSupplier();

        $file = UploadedFile::fake()->create('sppkp.pdf', 500, 'application/pdf');

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
}
