<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorMasterViewsTest extends TestCase
{
    use RefreshDatabase;

    private function createLocalSupplierWithFullMaster(): array
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);

        $supplier = Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Baja Unggul V2',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'npwp' => '01.234.567.8-123.000',
            'phone' => '021-888888',
            'address' => 'Jl. Industri No. 10',
            'payment_term_days' => 30,
            'pic_name' => 'Budi PIC',
            'pic_email' => 'budi@unggul.com',
            'pic_phone' => '0812345678',
        ]);

        $bank = SupplierBankAccount::create([
            'supplier_id' => $user->id,
            'bank_name' => 'BCA',
            'account_number' => '9988776655',
            'account_holder_name' => 'PT Baja Unggul V2',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        $doc = SupplierMasterDocument::create([
            'supplier_id' => $user->id,
            'document_type' => 'NIB',
            'file_path' => 'supplier-documents/'.$user->id.'/nib.pdf',
            'original_filename' => 'NIB_Baja_Unggul.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
            'uploaded_by' => $user->id,
        ]);

        $cr = SupplierChangeRequest::create([
            'supplier_id' => $user->id,
            'change_type' => 'profile',
            'current_data_snapshot' => ['company_name' => 'PT Baja Unggul V2'],
            'proposed_data' => ['company_name' => 'PT Baja Unggul Mega'],
            'status' => SupplierChangeRequest::STATUS_PENDING,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        return [$user, $supplier, $bank, $doc, $cr];
    }

    public function test_finance_can_view_vendor_master_index_and_show(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        [$supplierUser] = $this->createLocalSupplierWithFullMaster();

        // Index
        $response = $this->actingAs($finance)->get(route('finance.vendor-master.index'));
        $response->assertOk();
        $response->assertSee('PT Baja Unggul V2');
        $response->assertSee('9988776655');
        $response->assertSee('PT Baja Unggul Mega');

        // Show
        $responseShow = $this->actingAs($finance)->get(route('finance.vendor-master.show', $supplierUser));
        $responseShow->assertOk();
        $responseShow->assertSee('PT Baja Unggul V2');
        $responseShow->assertSee('NIB_Baja_Unggul.pdf');
        $responseShow->assertSee(route('supplier-master-documents.show', SupplierMasterDocument::first()));
    }

    public function test_purchasing_can_view_local_vendor_index_and_show(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        [$supplierUser] = $this->createLocalSupplierWithFullMaster();

        // Index
        $response = $this->actingAs($purchasing)->get(route('purchasing.local-vendors.index'));
        $response->assertOk();
        $response->assertSee('PT Baja Unggul V2');

        // Show
        $responseShow = $this->actingAs($purchasing)->get(route('purchasing.local-vendors.show', $supplierUser));
        $responseShow->assertOk();
        $responseShow->assertSee('PT Baja Unggul V2');
        $responseShow->assertSee('NIB_Baja_Unggul.pdf');
        $responseShow->assertSee(route('supplier-master-documents.show', SupplierMasterDocument::first()));
    }
}
