<?php

namespace Tests\Feature\Pdf;

use App\Http\Middleware\LimitOperationalRequests;
use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\GaClaimReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceReceipt;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\QcItem;
use App\Models\User;
use App\Services\Ga\GaClaimService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

/**
 * The shared print forms (PNR261178 style): QC report, payment voucher and the
 * two receipts. The PO form has its own blade and its own test.
 */
class DocumentFormStyleTest extends TestCase
{
    use RefreshDatabase;

    /** Brand colours and status fills from the previous web-styled documents. */
    private const FORBIDDEN_COLOURS = [
        '#1F5FA6', '#1a5290', '#C0392B', '#22c55e', '#ef4444',
        '#dcfce7', '#fee2e2', '#166534', '#991b1b', '#86efac', '#fca5a5',
    ];

    public function test_every_form_is_monochrome_and_ignores_the_locale(): void
    {
        foreach ($this->renderedForms() as $name => $renderer) {
            app()->setLocale('en');
            $english = $renderer();
            app()->setLocale('id');
            $indonesian = $renderer();
            app()->setLocale('en');

            $this->assertSame($indonesian, $english, $name.' must be a fixed English form.');

            foreach (self::FORBIDDEN_COLOURS as $colour) {
                $this->assertStringNotContainsStringIgnoringCase($colour, $english, $name.' must stay monochrome.');
            }

            $this->assertStringNotContainsString('border-radius', $english, $name.' must not use rounded corners.');
            $this->assertStringContainsString("font-family: 'PO Noto Sans'", $english, $name.' must use the embedded form font.');
            $this->assertStringContainsString('size: A4 portrait', $english, $name.' must be an A4 portrait form.');
            $this->assertStringContainsString('Page   1   of   1', $english, $name.' must display the PO-style page indicator.');
        }
    }

    public function test_qc_report_marks_ng_by_text_and_rules_not_by_colour(): void
    {
        $html = $this->renderQcReport();

        // WCAG 1.4.1: the failing row is identifiable without perceiving colour.
        $this->assertStringContainsString('class="row-flagged"', $html);
        $this->assertStringContainsString('class="row-number flag-value">NG<', $html);
        $this->assertStringContainsString('>OK<', $html);
        $this->assertStringNotContainsString('status-ng', $html);
        $this->assertStringNotContainsString('cell-ng', $html);
        // The KPI wall is gone; the counts live in one summary row.
        $this->assertStringNotContainsString('summary-number', $html);
        $this->assertStringContainsString('Summary', $html);
    }

    public function test_ga_receipt_masks_the_employee_account_number(): void
    {
        $html = $this->renderGaReceipt();

        $this->assertStringNotContainsString('1234567890', $html);
        $this->assertStringContainsString('******7890', $html);
    }

    public function test_receipts_head_the_number_with_a_bold_label_and_drop_the_verification_link(): void
    {
        foreach ([$this->renderInvoiceReceipt(), $this->renderGaReceipt()] as $html) {
            // PNR261178 pattern: bold "RECEIPT NO." label, plain value beside it.
            $this->assertStringContainsString('class="doc-number-label">RECEIPT NO.<', $html);
            $this->assertStringNotContainsString('/verify-receipt/', $html);
            $this->assertStringNotContainsString('Scan to verify', $html);
        }

        $invoiceReceiptHtml = $this->renderInvoiceReceipt();
        $this->assertStringContainsString('RCPT/09/2026/001', $invoiceReceiptHtml);
        $this->assertStringContainsString('Total Invoice Amount', $invoiceReceiptHtml);
        $this->assertStringContainsString('Rp 1.387.500', $invoiceReceiptHtml);
        $this->assertStringNotContainsString('Important Notes:', $invoiceReceiptHtml);
        $this->assertStringContainsString('This receipt only records your online submission', $invoiceReceiptHtml);
    }

    public function test_no_form_prints_a_rule_line_contact_row_or_generated_footer(): void
    {
        foreach ($this->renderedForms() as $name => $renderer) {
            $html = $renderer();

            $this->assertStringNotContainsString('head-rule', $html, $name.' must not draw the full-width rule.');
            $this->assertStringNotContainsString('generated automatically', $html, $name.' must not print the generated-at footer.');
            $this->assertStringNotContainsString('Website:', $html, $name.' must not print the contact line.');
            $this->assertStringNotContainsString('Phone:', $html, $name.' must not print the contact line.');
        }
    }

    public function test_qc_report_heads_the_po_number_and_shows_shape_and_arrival(): void
    {
        $html = $this->renderQcReport();

        $this->assertStringContainsString('QUALITY CONTROL INSPECTION REPORT', $html);
        $this->assertStringContainsString('class="doc-number-label">PURCHASE ORDER NO.<', $html);
        // The PO number is no longer repeated as a field beside Supplier/Period.
        $this->assertStringNotContainsString('>Purchase Order No.<', $html);
        $this->assertStringContainsString('Arrived Date', $html);
        $this->assertStringNotContainsString('>Inspector<', $html);
        // Shape is appended to the material name ("S45C · Round"); a long name
        // wraps, so assert the suffix rather than the whole composed string.
        $this->assertStringContainsString('· Round', $html);
        $this->assertStringContainsString('Round Bar SKD11', $html);
        $this->assertStringContainsString('( Inspector )', $html);
        $this->assertStringContainsString('( Production Dept. )', $html);
        $this->assertStringNotContainsString('Manager QC', $html);
    }

    public function test_receipt_pdf_routes_use_the_pdf_rate_limit_budget(): void
    {
        $policy = new ReflectionMethod(LimitOperationalRequests::class, 'policy');
        $policy->setAccessible(true);
        $middleware = new LimitOperationalRequests;

        $names = [
            'local-supplier.invoices.receipt.print',
            'accounting.invoices.receipt.print',
            'finance.invoices.receipt.print',
            'ga.claims.receipt.print',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' must be registered.');

            $request = Request::create('/'.$route->uri(), 'GET');
            $request->setRouteResolver(fn () => $route);

            $this->assertSame('pdf', $policy->invoke($middleware, $request), $name.' must share the pdf budget.');
        }
    }

    public function test_ga_claim_receipt_and_pdf_refuse_another_ga_users_claim(): void
    {
        Storage::fake('private');

        $owner = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $otherGa = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $employee = Employee::create([
            'name' => 'Budi GA',
            'department' => 'Operations',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Budi GA',
            'is_active' => true,
        ]);

        $claim = app(GaClaimService::class)->submitClaim($owner, [
            'employee_id' => $employee->id,
            'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
            'claim_date' => '2026-09-20',
            'amount' => 500000,
            'description' => 'Operasional Test',
        ], ['supporting' => NativeFileFixtures::upload('note.pdf', 100)]);

        foreach (['ga.claims.receipt', 'ga.claims.receipt.print'] as $name) {
            $this->actingAs($otherGa)->get(route($name, $claim))->assertForbidden();
            $this->actingAs($owner)->get(route($name, $claim))->assertOk();
            $this->actingAs($finance)->get(route($name, $claim))->assertOk();
        }
    }

    /** @return array<string, callable(): string> */
    private function renderedForms(): array
    {
        return [
            'QC inspection report' => fn () => $this->renderQcReport(),
            'Payment voucher' => fn () => $this->renderVoucher(),
            'Invoice submission receipt' => fn () => $this->renderInvoiceReceipt(),
            'GA claim receipt' => fn () => $this->renderGaReceipt(),
        ];
    }

    private function renderQcReport(): string
    {
        $po = new PurchaseOrder(['po_number' => 'PO/09/2026/001', 'currency' => 'USD']);
        $po->setRelation('supplier', new User(['name' => 'PT Contoh Supplier']));
        $po->setRelation('quotations', collect());

        $inspection = new QcInspection(['status' => 'ng']);
        $inspection->forceFill(['inspected_at' => Carbon::parse('2026-10-25 04:30:00', 'UTC')]);
        $inspection->setRelation('purchaseOrder', $po);
        $inspection->setRelation('inspector', new User(['name' => 'QC Officer']));
        $inspection->setRelation('items', collect([
            $this->qcItem('ok', 'Round Bar SKD11'),
            $this->qcItem('ng', 'Flat Bar SKD61'),
        ]));

        return view('pdf.qc-inspection-pdf', ['inspection' => $inspection])->render();
    }

    private function qcItem(string $status, string $material): QcItem
    {
        $item = new QcItem(['status' => $status]);
        $item->forceFill([
            'actual_thickness' => 12.5,
            'actual_width' => 100,
            'actual_length' => 2000,
            'actual_weight' => 250,
        ]);
        $item->setRelation('prItem', new PrItem([
            'material_name' => $material,
            'shape' => 'round',
            'thickness' => 12.0,
            'width' => 100,
            'length' => 2000,
            'quantity' => 3,
            'weight_needed' => 80,
        ]));

        return $item;
    }

    private function renderVoucher(): string
    {
        $voucher = (object) [
            'voucher_number' => 'VB/09/2026/001',
            'batch' => (object) [
                'batch_number' => 'DRP/09/2026/001',
                'finalized_at' => Carbon::parse('2026-09-28T23:35:00Z'),
                'created_at' => Carbon::parse('2026-09-20T01:00:00Z'),
            ],
            'payment_method' => 'BANK',
            'supplier_name_snapshot' => 'Fixed Supplier',
            'npwp_snapshot' => '012345',
            'bank_name_snapshot' => 'BCA',
            'bank_account_snapshot' => '1234',
            'bank_account_holder_snapshot' => 'Fixed Supplier',
            'invoice_number_snapshot' => 'INV-001',
            'po_number_snapshot' => 'PO-001',
            'gr_references_snapshot' => 'GR-001',
            'dpp_snapshot' => '1250000.50',
            'ppn_snapshot' => '137500.06',
            'pph_snapshot' => '25000.01',
            'amount' => '1362500.55',
            'terbilang_snapshot' => 'Authoritative words',
            'remarks_snapshot' => null,
        ];

        return view('finance.vouchers.print', compact('voucher'))->render();
    }

    private function renderInvoiceReceipt(): string
    {
        $supplier = new User(['name' => 'PT Lokal Jaya']);
        $supplier->setRelation('supplier', null);

        $invoice = new LocalInvoice([
            'invoice_number' => 'INV/2026/0007',
            'po_number' => 'LPO/2026/0003',
            'invoice_amount' => '1250000',
            'tax_amount' => '137500',
        ]);
        $invoice->forceFill([
            'submission_number' => 'SUB/09/2026/001',
            'revision_number' => 0,
            'submitted_at' => Carbon::parse('2026-09-20T01:00:00Z'),
        ]);
        $invoice->setRelation('supplier', $supplier);
        $invoice->setRelation('receipt', new LocalInvoiceReceipt(['receipt_number' => 'RCPT/09/2026/001']));

        return view('pdf.local-invoice-receipt', [
            'invoice' => $invoice,
            'qrCode' => 'data:image/svg+xml;base64,PHN2Zy8+',
            'signedUrl' => url('/verify-receipt/supplier/RCPT-09-2026-001?signature=test'),
        ])->render();
    }

    private function renderGaReceipt(): string
    {
        $claim = new GaClaim([
            'claim_number' => 'GAC/09/2026/001',
            'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
            'amount' => '500000',
        ]);
        $claim->forceFill([
            'claim_date' => Carbon::parse('2026-09-20'),
            'created_at' => Carbon::parse('2026-09-20T01:00:00Z'),
        ]);
        $claim->setRelation('employee', new Employee([
            'name' => 'Budi GA',
            'department' => 'Operations',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Budi GA',
        ]));
        $claim->setRelation('receipt', new GaClaimReceipt(['receipt_number' => 'GARC/09/2026/001']));

        return view('pdf.ga-claim-receipt', [
            'claim' => $claim,
            'qrCode' => 'data:image/svg+xml;base64,PHN2Zy8+',
            'signedUrl' => url('/verify-receipt/ga/GARC-09-2026-001?signature=test'),
        ])->render();
    }
}
