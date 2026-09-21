<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierChangeRequest;
use App\Models\User;
use App\Services\VendorMaster\VendorChangeRequestService;
use App\Services\VendorMaster\VendorMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class VendorMasterV2Test extends TestCase
{
    use RefreshDatabase;

    protected VendorMasterService $vendorMasterService;
    protected VendorChangeRequestService $changeRequestService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendorMasterService = app(VendorMasterService::class);
        $this->changeRequestService = app(VendorChangeRequestService::class);
    }

    public function test_roles_and_helpers(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $ga = User::factory()->create(['role' => 'ga']);
        $supplier = User::factory()->create(['role' => 'supplier']);

        $this->assertTrue($finance->isFinance());
        $this->assertTrue($finance->isLocalOperator());
        $this->assertFalse($finance->isGa());

        $this->assertTrue($ga->isGa());
        $this->assertFalse($ga->isFinance());

        $this->assertTrue($supplier->isSupplier());
    }

    public function test_vendor_category_and_pkp_rules(): void
    {
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        $supplier = Supplier::create([
            'user_id' => $supplierUser->id,
            'company_name' => 'PT Baja Mulia',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
        ]);

        $this->assertTrue($this->vendorMasterService->requiresSuratJalan($supplier));
        $this->assertTrue($this->vendorMasterService->requiresFakturPajak($supplier));

        $supplier->update([
            'vendor_category' => 'Jasa Konsultan',
            'is_pkp' => false,
        ]);

        $this->assertFalse($this->vendorMasterService->requiresSuratJalan($supplier));
        $this->assertFalse($this->vendorMasterService->requiresFakturPajak($supplier));
    }

    public function test_bank_account_versioning_preserves_historical_records(): void
    {
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        $finance = User::factory()->create(['role' => 'finance']);

        // Account 1
        $account1 = $this->vendorMasterService->addBankAccount($supplierUser, [
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Baja Mulia',
        ], $finance, autoVerify: true);

        $this->assertSame(SupplierBankAccount::STATUS_VERIFIED, $account1->status);
        $this->assertTrue($account1->isBca());
        $this->assertSame($account1->id, $supplierUser->fresh()->activeSupplierBankAccount->id);

        // Account 2
        $account2 = $this->vendorMasterService->addBankAccount($supplierUser, [
            'bank_name' => 'Mandiri',
            'account_number' => '9876543210',
            'account_holder_name' => 'PT Baja Mulia Utama',
        ], $finance, autoVerify: true);

        // Verify account 1 is inactive but NOT deleted or overwritten
        $account1->refresh();
        $this->assertSame(SupplierBankAccount::STATUS_INACTIVE, $account1->status);
        $this->assertNotNull($account1->deactivated_at);

        // Verify account 2 is active
        $this->assertSame(SupplierBankAccount::STATUS_VERIFIED, $account2->status);
        $this->assertFalse($account2->isBca());
        $this->assertSame($account2->id, $supplierUser->fresh()->activeSupplierBankAccount->id);

        // Both accounts exist in database
        $this->assertCount(2, $supplierUser->supplierBankAccounts);
    }

    public function test_supplier_creates_change_request_and_purchasing_approves(): void
    {
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        $supplier = Supplier::create([
            'user_id' => $supplierUser->id,
            'company_name' => 'PT Baja Mulia Old',
            'vendor_category' => 'Barang',
            'phone' => '021-111111',
            'is_pkp' => false,
        ]);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $req = $this->changeRequestService->submitChangeRequest($supplierUser, [
            'company_name' => 'PT Baja Mulia New',
            'phone' => '021-999999',
            'is_pkp' => true,
            'bank_name' => 'BCA',
            'account_number' => '5555555555',
            'account_holder_name' => 'PT Baja Mulia New',
        ]);

        $this->assertSame(SupplierChangeRequest::STATUS_PENDING, $req->status);
        $this->assertSame('PT Baja Mulia Old', $req->current_data_snapshot['company_name']);

        // Profile has not changed yet
        $this->assertSame('PT Baja Mulia Old', $supplier->fresh()->company_name);

        // Purchasing approves
        $this->changeRequestService->approve($req, $purchasing, 'Approved by Purchasing');

        $req->refresh();
        $this->assertSame(SupplierChangeRequest::STATUS_APPROVED, $req->status);
        $this->assertSame($purchasing->id, $req->reviewed_by);

        // Master profile updated
        $supplier->refresh();
        $this->assertSame('PT Baja Mulia New', $supplier->company_name);
        $this->assertSame('021-999999', $supplier->phone);
        $this->assertTrue($supplier->is_pkp);

        // Bank account created and verified
        $activeBank = $supplierUser->fresh()->activeSupplierBankAccount;
        $this->assertNotNull($activeBank);
        $this->assertSame('5555555555', $activeBank->account_number);
        $this->assertSame(SupplierBankAccount::STATUS_VERIFIED, $activeBank->status);
    }

    public function test_finance_can_approve_change_request(): void
    {
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        Supplier::create([
            'user_id' => $supplierUser->id,
            'company_name' => 'PT Logam Jaya',
            'vendor_category' => 'Lainnya',
        ]);

        $finance = User::factory()->create(['role' => 'finance']);

        $req = $this->changeRequestService->submitChangeRequest($supplierUser, [
            'company_name' => 'PT Logam Jaya Abadi',
        ]);

        $this->changeRequestService->approve($req, $finance, 'Approved by Finance AP');

        $req->refresh();
        $this->assertSame(SupplierChangeRequest::STATUS_APPROVED, $req->status);
        $this->assertSame($finance->id, $req->reviewed_by);
        $this->assertSame('PT Logam Jaya Abadi', $supplierUser->supplier->fresh()->company_name);
    }

    public function test_supplier_cannot_approve_own_change_request(): void
    {
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        Supplier::create([
            'user_id' => $supplierUser->id,
            'company_name' => 'PT Test',
        ]);

        $req = $this->changeRequestService->submitChangeRequest($supplierUser, [
            'company_name' => 'PT Test Hacked',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->changeRequestService->approve($req, $supplierUser);
    }

    public function test_change_request_rejection_requires_notes_and_does_not_mutate_master(): void
    {
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        $supplier = Supplier::create([
            'user_id' => $supplierUser->id,
            'company_name' => 'PT Original',
        ]);

        $finance = User::factory()->create(['role' => 'finance']);

        $req = $this->changeRequestService->submitChangeRequest($supplierUser, [
            'company_name' => 'PT Changed',
        ]);

        $this->changeRequestService->reject($req, $finance, 'Dokumen pendukung tidak lengkap.');

        $req->refresh();
        $this->assertSame(SupplierChangeRequest::STATUS_REJECTED, $req->status);
        $this->assertSame('Dokumen pendukung tidak lengkap.', $req->review_notes);

        // Master remains unchanged
        $this->assertSame('PT Original', $supplier->fresh()->company_name);
    }
}
