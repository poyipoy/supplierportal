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

    public function test_pending_bank_changes_link_only_the_selected_owned_confirmation_document(): void
    {
        [$user, , , , $request] = $this->createLocalSupplierWithFullMaster();
        $documents = [];
        foreach (['selected-bank.pdf', 'other-bank.pdf'] as $name) {
            $documents[] = SupplierMasterDocument::create([
                'supplier_id' => $user->id, 'document_type' => SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING,
                'file_path' => 'bank/'.$name, 'original_filename' => $name, 'mime_type' => 'application/pdf',
                'file_size' => 100, 'uploaded_by' => $user->id,
            ]);
        }
        $request->update(['proposed_data' => ['bank_name' => 'BCA', 'account_number' => '77778888',
            'account_holder_name' => 'New Bank Holder', 'bank_confirmation_document_id' => $documents[0]->id]]);

        foreach (['finance' => 'finance.vendor-master.index', 'purchasing' => 'purchasing.local-vendors.index'] as $role => $route) {
            session()->forget('password_hash_web');
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route($route))->assertOk()
                ->assertSee('77778888')->assertSee('New Bank Holder')->assertSee('selected-bank.pdf')
                ->assertSee(route('supplier-master-documents.show', $documents[0]), false)->assertDontSee('other-bank.pdf');
        }
    }

    public function test_purchasing_vendor_detail_requires_active_local_eligibility(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        foreach ([
            User::factory()->create(),
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['is_active' => false]),
            User::factory()->create(['account_status' => User::ACCOUNT_STATUS_PENDING]),
        ] as $vendor) {
            $this->actingAs($purchasing)->get(route('purchasing.local-vendors.show', $vendor))->assertNotFound();
        }
    }

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

    public function test_finance_cannot_access_import_only_supplier_in_vendor_master(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $importSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $response = $this->actingAs($finance)->get(route('finance.vendor-master.show', $importSupplier));
        $response->assertNotFound();
    }

    public function test_finance_cannot_access_inactive_local_supplier_in_vendor_master(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $inactiveSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => false]);
        $inactiveSupplier->supplierScopes()->delete();
        $inactiveSupplier->supplierScopes()->create(['scope' => 'local']);

        $response = $this->actingAs($finance)->get(route('finance.vendor-master.show', $inactiveSupplier));
        $response->assertNotFound();
    }

    public function test_non_authorized_roles_remain_blocked_from_finance_vendor_master(): void
    {
        [$supplierUser] = $this->createLocalSupplierWithFullMaster();
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $this->actingAs($supplier)->get(route('finance.vendor-master.index'))->assertForbidden();
        $this->actingAs($supplier)->get(route('finance.vendor-master.show', $supplierUser))->assertForbidden();

        $this->actingAs($qc)->get(route('finance.vendor-master.index'))->assertForbidden();
        $this->actingAs($qc)->get(route('finance.vendor-master.show', $supplierUser))->assertForbidden();
    }

    public function test_admin_can_access_active_local_supplier_but_not_import_or_inactive(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$activeSupplier] = $this->createLocalSupplierWithFullMaster();

        $importSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $inactiveSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => false]);
        $inactiveSupplier->supplierScopes()->delete();
        $inactiveSupplier->supplierScopes()->create(['scope' => 'local']);

        // Admin can access active local supplier
        $this->actingAs($admin)->get(route('finance.vendor-master.show', $activeSupplier))->assertOk();

        // Admin also blocked by domain eligibility (404) for non-local or inactive suppliers
        $this->actingAs($admin)->get(route('finance.vendor-master.show', $importSupplier))->assertNotFound();
        $this->actingAs($admin)->get(route('finance.vendor-master.show', $inactiveSupplier))->assertNotFound();
    }
}
