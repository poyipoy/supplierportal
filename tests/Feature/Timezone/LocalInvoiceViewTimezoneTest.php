<?php

namespace Tests\Feature\Timezone;

use App\Models\LocalInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalInvoiceViewTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function createLocalSupplier(array $supplierData = []): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);

        Supplier::create(array_merge([
            'user_id' => $user->id,
            'company_name' => 'PT Test Vendor',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ], $supplierData));

        return $user;
    }

    /**
     * T6: At 2026-10-14 02:00 UTC (09:00 WIB), invoice submitted at this instant
     * must render "09:00 WIB" and not the UTC time "02:00 WIB".
     */
    public function test_t6_invoice_table_renders_business_time_and_dynamic_timezone_label(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $supplier = $this->createLocalSupplier();

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-TIMEZONE-T6',
            'submission_number' => 'SUB-2026-00001',
            'invoice_date' => '2026-10-14',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-01',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'currency' => 'IDR',
            'invoice_amount' => 1000000,
            'tax_amount' => 110000,
            'submitted_at' => now(),
            'due_date' => '2026-11-13',
            'scheduled_physical_delivery_date' => '2026-10-14',
        ]);

        // 1. Test via HTTP route local-supplier.invoices.index
        $response = $this->actingAs($supplier)->get(route('local-supplier.invoices.index'));
        $response->assertOk();
        $response->assertSee('09:00 WIB');
        $response->assertDontSee('02:00 WIB');
        $response->assertSee('14 Oct 2026');

        // 2. Test direct view rendering of local-invoices.table
        $tableHtml = view('local-invoices.table', [
            'invoices' => LocalInvoice::where('id', $invoice->id)->paginate(10),
            'portal' => 'local-supplier',
        ])->render();

        $this->assertStringContainsString('09:00 WIB', $tableHtml);
        $this->assertStringNotContainsString('02:00 WIB', $tableHtml);
        $this->assertStringContainsString('14 Oct 2026', $tableHtml);
    }

    public function test_receipt_view_renders_business_time_with_bizdt(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $supplier = $this->createLocalSupplier();

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-RECEIPT-T6',
            'submission_number' => 'SUB-2026-00002',
            'invoice_date' => '2026-10-14',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-02',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'currency' => 'IDR',
            'invoice_amount' => 2000000,
            'tax_amount' => 220000,
            'submitted_at' => now(),
        ]);

        $invoice->receipt()->create([
            'receipt_number' => 'REC-2026-00001',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($supplier)->get(route('local-supplier.invoices.receipt', $invoice));
        $response->assertOk();
        $response->assertSee('14 Oct 2026 09:00 WIB');
        $response->assertDontSee('02:00');
    }

    public function test_purchasing_po_show_renders_business_time_and_not_hardcoded_wib(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);

        $po = PurchaseOrder::create([
            'po_number' => 'PO/10/2026/001',
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($purchasing)->get(route('purchasing.purchase-orders.show', $po));
        $response->assertOk();
        $response->assertSee('09:00 WIB');
        $response->assertDontSee('02:00 WIB');
    }

    public function test_receipt_verify_supplier_and_ga_views_render_business_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $supplier = $this->createLocalSupplier();
        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-VERIFY-T6',
            'submission_number' => 'SUB-2026-00003',
            'invoice_date' => '2026-10-14',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-03',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'currency' => 'IDR',
            'invoice_amount' => 1500000,
            'tax_amount' => 165000,
            'submitted_at' => now(),
        ]);
        $receipt = $invoice->receipt()->create([
            'receipt_number' => 'REC-SUPPLIER-T6',
            'issued_at' => now(),
        ]);

        $supplierVerifyHtml = view('receipts.verify-supplier', [
            'receipt' => $receipt,
            'invoice' => $invoice->load('supplier.supplier'),
        ])->render();

        $this->assertStringContainsString('14/10/2026 09:00 WIB', $supplierVerifyHtml);
        $this->assertStringNotContainsString('02:00', $supplierVerifyHtml);

        $employee = \App\Models\Employee::create([
            'name' => 'Budi Santoso',
            'department' => 'General Affairs',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Budi Santoso',
            'is_active' => true,
        ]);
        $gaUser = User::factory()->create(['role' => 'ga']);
        $claim = \App\Models\GaClaim::create([
            'claim_number' => 'CLM-GA-001',
            'employee_id' => $employee->id,
            'submitted_by' => $gaUser->id,
            'claim_type' => \App\Models\GaClaim::TYPE_UPD_GA,
            'claim_date' => '2026-10-14',
            'amount' => 500000,
            'status' => \App\Models\GaClaim::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'created_at' => now(),
        ]);
        $gaReceipt = $claim->receipt()->create([
            'receipt_number' => 'REC-GA-T6',
            'issued_at' => now(),
        ]);

        $gaVerifyHtml = view('receipts.verify-ga', [
            'receipt' => $gaReceipt,
            'claim' => $claim->load('employee'),
        ])->render();

        $this->assertStringContainsString('14/10/2026 09:00 WIB', $gaVerifyHtml);
        $this->assertStringNotContainsString('02:00', $gaVerifyHtml);
    }

    public function test_finance_invoice_show_renders_business_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $finance = User::factory()->create(['role' => 'finance']);
        $supplier = $this->createLocalSupplier();

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-FINANCE-T6',
            'submission_number' => 'SUB-2026-00004',
            'invoice_date' => '2026-10-14',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-04',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'currency' => 'IDR',
            'invoice_amount' => 1000000,
            'tax_amount' => 110000,
            'submitted_at' => now(),
            'cashier_received_at' => now(),
            'payment_term_days_snapshot' => 30,
            'due_date' => '2026-11-13',
            'scheduled_physical_delivery_date' => '2026-10-14',
        ]);

        $response = $this->actingAs($finance)->get(route('finance.invoices.show', $invoice));
        $response->assertOk();
        $response->assertSee('14 Oct 2026 09:00 WIB');
        $response->assertDontSee('02:00 WIB');
    }
}
