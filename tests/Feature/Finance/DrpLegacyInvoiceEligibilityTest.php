<?php

namespace Tests\Feature\Finance;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalInvoiceVerification;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Payment\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrpLegacyInvoiceEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;
    private User $supplierUser;
    private LocalInvoice $approvedInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->financeUser = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
        ]);

        $this->supplierUser = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        SupplierScope::create([
            'supplier_id' => $this->supplierUser->id,
            'scope' => 'local',
        ]);

        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Legacy Vendor',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        SupplierBankAccount::create([
            'supplier_id' => $this->supplierUser->id,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Legacy Vendor',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'is_primary' => true,
            'is_active' => true,
            'verified_at' => now(),
            'verified_by' => $this->financeUser->id,
        ]);

        $po = LocalPurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'po_number' => 'PO-LEGACY-001',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'currency' => 'IDR',
            'total_amount' => 1000000,
            'po_date' => '2026-09-01',
        ]);

        $gr = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po->id,
            'gr_number' => 'GR-LEGACY-001',
            'gr_date' => '2026-09-02',
            'received_amount' => 1000000,
            'status' => LocalGoodsReceipt::STATUS_INVOICED,
        ]);

        $this->approvedInvoice = LocalInvoice::create([
            'supplier_id' => $this->supplierUser->id,
            'submission_number' => 'SUB-260903-0001',
            'invoice_number' => 'INV-LEGACY-APPROVED',
            'invoice_date' => '2026-09-03',
            'submitted_at' => now(),
            'invoice_amount' => 1000000,
            'tax_amount' => 110000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '01.01.26.99999999999',
            'status' => 'APPROVED', // Legacy status
            'po_source' => 'INTERNAL',
            'po_number' => $po->po_number,
            'local_purchase_order_id' => $po->id,
            'due_date' => now()->addDays(15),
        ]);

        LocalInvoiceVerification::create([
            'local_invoice_id' => $this->approvedInvoice->id,
            'verified_by' => $this->financeUser->id,
            'is_locked' => true,
            'dpp_amount' => 1000000,
            'ppn_amount' => 110000,
            'pph_amount' => 0,
            'status' => 'VERIFIED',
        ]);
    }

    public function test_legacy_approved_invoice_is_listed_in_drp_eligible_invoices(): void
    {
        $response = $this->actingAs($this->financeUser)->get(route('finance.drp.supplier'));

        $response->assertOk();
        $response->assertViewHas('eligibleInvoices', function ($eligibleInvoices) {
            return $eligibleInvoices->contains('id', $this->approvedInvoice->id);
        });
    }

    public function test_legacy_approved_invoice_can_be_batched_in_payment_batch_service(): void
    {
        $service = app(PaymentBatchService::class);

        $batch = $service->createSupplierBatch(
            $this->financeUser,
            [$this->approvedInvoice->id],
            'Batching legacy approved invoice'
        );

        $this->assertNotNull($batch);
        $this->assertSame(PaymentBatch::STATUS_DRAFT, $batch->status);
        $this->assertCount(1, $batch->groups);
    }
}
