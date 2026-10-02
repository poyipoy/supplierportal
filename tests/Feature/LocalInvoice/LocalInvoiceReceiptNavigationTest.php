<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceReceipt;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalInvoiceReceiptNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierUser;
    private LocalInvoice $invoice;
    private LocalInvoiceReceipt $receipt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierUser = User::factory()->create([
            'role' => 'supplier',
            'name' => 'PT Baja Utama',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
        SupplierScope::create([
            'supplier_id' => $this->supplierUser->id,
            'scope' => 'local',
        ]);
        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Baja Utama',
            'address' => 'Jl. Industri No. 12',
            'phone' => '021-555555',
            'npwp' => '01.234.567.8-111.000',
            'category' => 'Local Material',
            'payment_term_days' => 30,
        ]);

        $this->invoice = LocalInvoice::create([
            'supplier_id' => $this->supplierUser->id,
            'created_by' => $this->supplierUser->id,
            'invoice_number' => 'INV-TEST-001',
            'submission_number' => 'SUB-TEST-001',
            'po_number' => 'PO-LOC-001',
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'submitted_at' => now(),
        ]);

        $this->receipt = LocalInvoiceReceipt::create([
            'local_invoice_id' => $this->invoice->id,
            'receipt_number' => 'TT-2026-00001',
            'issued_at' => now(),
            'received_by' => 'Kasir Finance',
        ]);
    }

    public function test_finance_can_view_receipt_and_sees_finance_back_links_without_403(): void
    {
        $finance = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($finance)->get(route('finance.invoices.receipt', $this->invoice));
        $response->assertOk();
        $response->assertSee(route('finance.invoices.show', $this->invoice));
        $response->assertSee(route('finance.invoices.index'));
        $response->assertDontSee(route('local-supplier.invoices.show', $this->invoice));

        // Verifikasi tautan kembali dapat diakses oleh finance tanpa 403 Forbidden
        $backResponse = $this->actingAs($finance)->get(route('finance.invoices.show', $this->invoice));
        $backResponse->assertOk();
    }

    public function test_supplier_can_view_receipt_and_sees_supplier_back_links(): void
    {
        $response = $this->actingAs($this->supplierUser)
            ->withSession(['supplier_context' => 'local'])
            ->get(route('local-supplier.invoices.receipt', $this->invoice));

        $response->assertOk();
        $response->assertSee(route('local-supplier.invoices.show', $this->invoice));
        $response->assertSee(route('local-supplier.invoices.index'));

        // Verifikasi tautan kembali dapat diakses oleh supplier
        $backResponse = $this->actingAs($this->supplierUser)
            ->withSession(['supplier_context' => 'local'])
            ->get(route('local-supplier.invoices.show', $this->invoice));
        $backResponse->assertOk();
    }

    public function test_accounting_can_view_receipt_and_sees_accounting_back_links(): void
    {
        $accounting = User::factory()->create([
            'role' => 'accounting',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($accounting)->get(route('accounting.invoices.receipt', $this->invoice));
        $response->assertOk();
        $response->assertSee(route('accounting.invoices.show', $this->invoice));
        $response->assertSee(route('accounting.invoices.index'));
        $response->assertDontSee(route('local-supplier.invoices.show', $this->invoice));

        $backResponse = $this->actingAs($accounting)->get(route('accounting.invoices.show', $this->invoice));
        $backResponse->assertOk();
    }

    public function test_purchasing_can_view_receipt_and_sees_purchasing_back_links(): void
    {
        $purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($purchasing)->get(route('finance.invoices.receipt', $this->invoice));
        $response->assertOk();
        $response->assertSee(route('purchasing.local-invoices.show', $this->invoice));
        $response->assertSee(route('purchasing.local-vendors.index'));
        $response->assertDontSee(route('local-supplier.invoices.show', $this->invoice));

        $backResponse = $this->actingAs($purchasing)->get(route('purchasing.local-invoices.show', $this->invoice));
        $backResponse->assertOk();
    }
}
