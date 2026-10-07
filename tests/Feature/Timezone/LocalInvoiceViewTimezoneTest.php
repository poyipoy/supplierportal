<?php

namespace Tests\Feature\Timezone;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function displayTimezones(): array
    {
        return [
            'system preserves UTC' => ['system', '02:00'],
            'Jakarta converts to WIB' => ['Asia/Jakarta', '09:00 WIB'],
        ];
    }

    private function setDisplayTimezone(User $user, string $timezone): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->create([...$defaults, 'timezone' => $timezone, 'locale' => 'en']);
        app()->setLocale('en');
    }

    private function nodeText(string $html, string $selector): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $nodes = (new DOMXPath($document))->query($selector);
        $this->assertCount(1, $nodes, 'The fixture timestamp must identify exactly one display node.');

        return trim(preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent));
    }

    #[DataProvider('displayTimezones')]
    public function test_invoice_table_renders_the_viewers_display_timezone(string $timezone, string $expectedTime): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $supplier = $this->createLocalSupplier();
        $this->setDisplayTimezone($supplier, $timezone);

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
        $timestampCell = "//tr[td//span[normalize-space(.)='INV-TIMEZONE-T6']]/td[3]";
        $expectedDate = $timezone === 'Asia/Jakarta' ? '14 Oct 2026 WIB' : '14 Oct 2026';
        $this->assertSame($expectedDate, $this->nodeText($response->getContent(), $timestampCell.'/span[1]'));
        $this->assertSame($expectedTime, $this->nodeText($response->getContent(), $timestampCell.'/span[2]'));

        // 2. Test direct view rendering of local-invoices.table
        $tableHtml = view('local-invoices.table', [
            'invoices' => LocalInvoice::where('id', $invoice->id)->paginate(10),
            'portal' => 'local-supplier',
        ])->render();

        $this->assertSame($expectedDate, $this->nodeText($tableHtml, $timestampCell.'/span[1]'));
        $this->assertSame($expectedTime, $this->nodeText($tableHtml, $timestampCell.'/span[2]'));
        $this->assertSame('2026-10-14 02:00:00', $invoice->fresh()->getRawOriginal('submitted_at'));
        $this->assertSame('2026-10-14', $invoice->fresh()->getRawOriginal('invoice_date'));
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

    #[DataProvider('displayTimezones')]
    public function test_purchasing_po_show_renders_the_viewers_display_timezone(string $timezone, string $expectedTime): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->setDisplayTimezone($purchasing, $timezone);
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
        $timestamp = "//*[@id='sec-timeline']//time[@datetime='".$po->created_at->toIso8601String()."']";
        $this->assertSame('14 Oct 2026, '.$expectedTime, $this->nodeText($response->getContent(), $timestamp));
        $this->assertSame('2026-10-14 02:00:00', $po->fresh()->getRawOriginal('created_at'));
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

        $employee = Employee::create([
            'name' => 'Budi Santoso',
            'department' => 'General Affairs',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Budi Santoso',
            'is_active' => true,
        ]);
        $gaUser = User::factory()->create(['role' => 'ga']);
        $claim = GaClaim::create([
            'claim_number' => 'CLM-GA-001',
            'employee_id' => $employee->id,
            'submitted_by' => $gaUser->id,
            'claim_type' => GaClaim::TYPE_UPD_GA,
            'claim_date' => '2026-10-14',
            'amount' => 500000,
            'status' => GaClaim::STATUS_SUBMITTED,
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

    #[DataProvider('displayTimezones')]
    public function test_finance_invoice_show_renders_the_viewers_display_timezone(string $timezone, string $expectedTime): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));

        $finance = User::factory()->create(['role' => 'finance']);
        $this->setDisplayTimezone($finance, $timezone);
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
        $timestamp = "//span[normalize-space(.)='".__('finance.invoice_ui.cashier_date')."']/following-sibling::strong[1]";
        $this->assertSame('14 Oct 2026 '.$expectedTime, $this->nodeText($response->getContent(), $timestamp));
        $this->assertSame('2026-10-14 02:00:00', $invoice->fresh()->getRawOriginal('cashier_received_at'));
        $this->assertSame('2026-11-13', $invoice->fresh()->getRawOriginal('due_date'));
    }
}
