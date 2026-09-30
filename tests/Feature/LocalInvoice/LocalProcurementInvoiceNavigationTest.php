<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalProcurementInvoiceNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $finance;

    private User $purchasing;

    private LocalPurchaseOrder $po;

    private LocalGoodsReceipt $gr;

    private LocalInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Test Supplier',
            'category' => 'Raw Material',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $this->po = LocalPurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-LOCAL-NAV-01',
            'po_date' => '2026-09-20',
            'total_amount' => 10000000,
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'created_by' => $this->purchasing->id,
        ]);

        $this->gr = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->po->id,
            'gr_number' => 'GR-LOCAL-NAV-01',
            'gr_date' => '2026-09-21',
            'qty' => 10,
            'description' => 'Test Item',
            'status' => LocalGoodsReceipt::STATUS_INVOICED,
            'created_by' => $this->purchasing->id,
        ]);

        $this->invoice = LocalInvoice::create([
            'supplier_id' => $this->supplier->id,
            'submission_number' => 'SUB/09/2026/NAV01',
            'invoice_number' => 'INV-LOCAL-NAV-01',
            'invoice_date' => '2026-09-22',
            'local_purchase_order_id' => $this->po->id,
            'po_number' => $this->po->po_number,
            'po_source' => 'INTERNAL',
            'invoice_amount' => 5000000,
            'tax_amount' => 550000,
            'payment_term_days_snapshot' => 30,
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'submitted_at' => now(),
            'revision_number' => 1,
        ]);

        $this->gr->update(['current_invoice_id' => $this->invoice->id]);
    }

    public function test_purchasing_sees_purchasing_invoice_show_link_on_local_procurement_po(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.local-procurement.show', $this->po));

        $response->assertOk();
        $response->assertSee(route('purchasing.local-invoices.show', $this->invoice));
        $response->assertDontSee(route('finance.invoices.show', $this->invoice));

        // Ensure following the link as Purchasing does not yield 403 Forbidden
        $followResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.local-invoices.show', $this->invoice));

        $followResponse->assertOk();
    }

    public function test_finance_sees_finance_invoice_show_link_on_local_procurement_po(): void
    {
        $response = $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.show', $this->po));

        $response->assertOk();
        $response->assertSee(route('finance.invoices.show', $this->invoice));
    }
}
