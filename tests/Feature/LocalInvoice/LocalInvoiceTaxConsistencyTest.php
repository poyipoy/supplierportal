<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

/**
 * The PO ceiling caps DPP only, so the supplier-entered PPN must be derived from
 * DPP and the chosen scheme (1 IDR rounding tolerance) and DPP must be positive.
 */
class LocalInvoiceTaxConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private LocalPurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();

        $this->supplier = User::factory()->create();
        $this->supplier->supplierScopes()->delete();
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'Local Vendor '.$this->supplier->id,
            'address' => 'Jakarta',
            'phone' => '123',
            'npwp' => '123',
            'category' => 'Parts',
            'payment_term_days' => 30,
            'is_pkp' => true,
        ]);

        $this->po = LocalPurchaseOrder::create([
            'po_number' => 'PO-LOCAL-TAX-1',
            'supplier_id' => $this->supplier->id,
            'total_amount' => '1000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => '2026-09-01',
            'source' => 'MANUAL',
        ]);
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->po->id,
            'gr_number' => 'GR-LOCAL-TAX-1',
            'gr_date' => '2026-09-02',
            'qty' => '10.000',
            'uom' => 'PCS',
            'description' => 'Tax fixture',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => 'MANUAL',
        ]);
    }

    private function payload(array $overrides): array
    {
        return array_merge([
            'invoice_number' => 'INV-TAX-001',
            'invoice_date' => '2026-09-08',
            'local_purchase_order_id' => $this->po->id,
            'goods_receipt_ids' => [$this->po->goodsReceipts()->value('id')],
            'invoice_amount' => '100000.00',
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'invoice' => NativeFileFixtures::upload('invoice.pdf', 100),
            'tax_invoice' => NativeFileFixtures::upload('tax.pdf'),
        ], $overrides);
    }

    private function submitInvoice(array $payload): TestResponse
    {
        return $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), $this->payload($payload));
    }

    private function setPkp(bool $isPkp): void
    {
        $this->supplier->supplier()->update(['is_pkp' => $isPkp]);
        $this->supplier->unsetRelation('supplier');
    }

    private function invoiceNeedingRevision(): LocalInvoice
    {
        $this->submitInvoice(['tax_amount' => '11000.00'])->assertSessionHasNoErrors();

        return app(InvoiceVerificationService::class)->requestRevision(
            LocalInvoice::sole(), 'Correct the invoice.', User::factory()->create(['role' => 'finance'])
        );
    }

    public static function nonPkpTaxCases(): array
    {
        return [
            'standard VAT' => ['11%', '11000.00'],
            'reduced VAT' => ['1.1%', '1100.00'],
            'nonzero tax under exempt scheme' => ['0%', '0.01'],
        ];
    }

    #[DataProvider('nonPkpTaxCases')]
    public function test_non_pkp_submission_rejects_vat(string $scheme, string $tax): void
    {
        $this->setPkp(false);
        $this->submitInvoice(['ppn_scheme' => $scheme, 'tax_amount' => $tax])->assertSessionHasErrors('ppn_scheme');

        $this->assertSame(0, LocalInvoice::count());
        $this->assertSame(LocalGoodsReceipt::STATUS_AVAILABLE, $this->po->goodsReceipts()->sole()->status);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    #[DataProvider('nonPkpTaxCases')]
    public function test_non_pkp_resubmission_rejects_vat_without_changing_the_invoice(string $scheme, string $tax): void
    {
        $invoice = $this->invoiceNeedingRevision();
        $this->setPkp(false);
        $before = $invoice->getAttributes();
        $files = Storage::disk('private')->allFiles();

        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.resubmit', $invoice), $this->payload([
            'ppn_scheme' => $scheme, 'tax_amount' => $tax,
        ]))->assertSessionHasErrors('ppn_scheme');

        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame(1, $invoice->revisions()->count());
        $this->assertSame(LocalGoodsReceipt::STATUS_RESERVED, $this->po->goodsReceipts()->sole()->status);
        $this->assertSame($files, Storage::disk('private')->allFiles());
    }

    public static function acceptedTaxCases(): array
    {
        return [
            'non-PKP exempt' => [false, '0%', '0.00'],
            'PKP standard' => [true, '11%', '11000.00'],
            'PKP reduced' => [true, '1.1%', '1100.00'],
        ];
    }

    #[DataProvider('acceptedTaxCases')]
    public function test_valid_vendor_tax_is_preserved_on_submission(bool $isPkp, string $scheme, string $tax): void
    {
        $this->setPkp($isPkp);
        $this->submitInvoice(['ppn_scheme' => $scheme, 'tax_amount' => $tax])->assertSessionHasNoErrors();

        $invoice = LocalInvoice::sole();
        $this->assertSame($scheme, $invoice->ppn_scheme);
        $this->assertSame($tax, $invoice->tax_amount);
        $this->assertSame($tax, $invoice->submitted_ppn_amount);
        $this->assertSame($tax, $invoice->latestRevision->tax_amount);
    }

    #[DataProvider('acceptedTaxCases')]
    public function test_valid_vendor_tax_is_preserved_on_resubmission(bool $isPkp, string $scheme, string $tax): void
    {
        $invoice = $this->invoiceNeedingRevision();
        $this->setPkp($isPkp);
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.resubmit', $invoice), $this->payload([
            'ppn_scheme' => $scheme, 'tax_amount' => $tax,
        ]))->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(2, $invoice->revision_number);
        $this->assertSame($scheme, $invoice->ppn_scheme);
        $this->assertSame($tax, $invoice->tax_amount);
        $this->assertSame($tax, $invoice->submitted_ppn_amount);
        $this->assertSame($tax, $invoice->latestRevision->tax_amount);
    }

    public static function pkpStatuses(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('pkpStatuses')]
    public function test_invoice_forms_offer_only_vendor_eligible_tax_schemes(bool $isPkp): void
    {
        $invoice = $this->invoiceNeedingRevision();
        $this->setPkp($isPkp);

        foreach ([route('local-supplier.invoices.create'), route('local-supplier.invoices.revision', $invoice)] as $url) {
            $response = $this->actingAs($this->supplier)->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $options = $xpath->query('//select[@id="ppn-scheme"]/option');
            $schemes = [];
            foreach ($options as $option) {
                $schemes[] = $option->getAttribute('value');
            }
            $this->assertSame($isPkp ? ['11%', '1.1%', '0%'] : ['0%'], $schemes);
            if (! $isPkp) {
                $taxInput = $xpath->query('//input[@id="tax-amount"]')->item(0);
                $this->assertSame('0.00', $taxInput->getAttribute('value'));
                $this->assertTrue($taxInput->hasAttribute('readonly'));
            }
        }
    }

    public function test_submitted_tax_must_match_dpp_and_scheme(): void
    {
        $this->submitInvoice(['tax_amount' => '500000000.00'])->assertSessionHasErrors('tax_amount');

        $this->assertSame(0, LocalInvoice::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_zero_dpp_cannot_carry_the_amount_through_tax(): void
    {
        $this->submitInvoice(['invoice_amount' => '0.00', 'tax_amount' => '500000000.00'])
            ->assertSessionHasErrors('invoice_amount');

        $this->assertSame(0, LocalInvoice::count());
    }

    public function test_tax_within_one_rupiah_of_dpp_times_scheme_is_accepted(): void
    {
        // 100000.00 x 11% = 11000.00; 11000.90 is inside the rounding tolerance.
        $this->submitInvoice(['tax_amount' => '11000.90'])->assertSessionHasNoErrors();

        $this->assertSame('11000.90', LocalInvoice::sole()->tax_amount);
    }

    public function test_tax_more_than_one_rupiah_off_is_rejected(): void
    {
        $this->submitInvoice(['tax_amount' => '11001.01'])->assertSessionHasErrors('tax_amount');

        $this->assertSame(0, LocalInvoice::count());
    }

    public function test_pkp_resubmission_rejects_tax_inconsistent_with_scheme(): void
    {
        $invoice = $this->invoiceNeedingRevision();
        $before = $invoice->getAttributes();

        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.resubmit', $invoice), $this->payload([
            'tax_amount' => '500000000.00',
        ]))->assertSessionHasErrors('tax_amount');

        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame(1, $invoice->revisions()->count());
    }
}
