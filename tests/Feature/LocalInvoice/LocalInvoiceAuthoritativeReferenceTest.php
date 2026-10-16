<?php

namespace Tests\Feature\LocalInvoice;

use App\Http\Requests\LocalInvoice\StoreLocalInvoiceRequest;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

/**
 * A new local invoice binds to exactly one authoritative Local PO plus whole GRs,
 * so the PO financial ceiling and GR reservation always apply. Free-text PO/GR
 * references are refused, including the implicit fallback taken when no
 * po_source is sent.
 */
class LocalInvoiceAuthoritativeReferenceTest extends TestCase
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
            'po_number' => 'PO-LOCAL-CEILING-1',
            'supplier_id' => $this->supplier->id,
            'total_amount' => '1000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => '2026-09-01',
            'source' => 'MANUAL',
        ]);
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->po->id,
            'gr_number' => 'GR-LOCAL-CEILING-1',
            'gr_date' => '2026-09-02',
            'qty' => '10.000',
            'uom' => 'PCS',
            'description' => 'Ceiling fixture',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => 'MANUAL',
        ]);
    }

    private function submitInvoice(array $payload)
    {
        return $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge([...NativeFileFixtures::invoiceReferences(),
            'invoice_number' => 'INV-REF-001',
            'invoice_date' => '2026-09-08',
            'invoice_amount' => '5000000.00',
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'invoice' => NativeFileFixtures::upload('invoice.pdf', 100),
            'tax_invoice' => NativeFileFixtures::upload('tax.pdf'),
        ], $payload));
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, LocalInvoice::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_new_invoice_requires_an_authoritative_purchase_order(): void
    {
        // Five times the PO ceiling, citing a PO/GR reference that exists nowhere.
        $this->submitInvoice([
            'po_source' => 'MANUAL',
            'po_number' => 'PO-DOES-NOT-EXIST-9999',
            'manual_po_number' => 'PO-DOES-NOT-EXIST-9999',
            'manual_gr_reference' => 'GR-DOES-NOT-EXIST-9999',
        ])->assertSessionHasErrors('local_purchase_order_id');

        $this->assertNothingStored();
    }

    public function test_blank_po_source_does_not_fall_back_to_a_manual_reference(): void
    {
        // Either legacy GR field name; neither may resolve to a stored invoice.
        foreach (['gr_reference', 'manual_gr_reference'] as $grField) {
            $this->submitInvoice([
                'po_number' => 'PO-DOES-NOT-EXIST-9999',
                $grField => 'GR-DOES-NOT-EXIST-9999',
            ])->assertSessionHasErrors();
        }

        $this->assertNothingStored();
    }

    public function test_authoritative_invoice_above_the_po_ceiling_is_rejected(): void
    {
        $this->submitInvoice([
            'local_purchase_order_id' => $this->po->id,
            'goods_receipt_ids' => [$this->po->goodsReceipts()->value('id')],
        ])->assertSessionHasErrors('goods_receipt_ids');

        $this->assertNothingStored();
    }

    public static function requiredInvoiceFields(): array
    {
        return [
            'missing PO' => [['local_purchase_order_id' => null], ['local_purchase_order_id']],
            'missing PO and GRs' => [['local_purchase_order_id' => null, 'goods_receipt_ids' => null], ['local_purchase_order_id', 'goods_receipt_ids']],
            'empty GRs' => [['goods_receipt_ids' => []], ['goods_receipt_ids']],
            'zero DPP' => [['invoice_amount' => '0.00'], ['invoice_amount']],
        ];
    }

    #[DataProvider('requiredInvoiceFields')]
    public function test_store_request_rejects_missing_authoritative_fields_and_zero_dpp(array $overrides, array $errorFields): void
    {
        $request = StoreLocalInvoiceRequest::create('/local-supplier/invoices', 'POST', array_merge([
            'local_purchase_order_id' => $this->po->id,
            'goods_receipt_ids' => [$this->po->goodsReceipts()->value('id')],
            'po_number' => $this->po->po_number,
            'invoice_number' => 'INV-REQ-001',
            'invoice_date' => '2026-09-08',
            'invoice_amount' => '100.00',
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
        ], $overrides), [], [...NativeFileFixtures::invoiceReferences(),
            'invoice' => NativeFileFixtures::upload('invoice.pdf', 10),
            'tax_invoice' => NativeFileFixtures::upload('tax.pdf'),
        ]);
        $request->setUserResolver(fn () => $this->supplier);
        $errors = Validator::make($request->all(), $request->rules(), $request->messages())->errors();

        foreach ($errorFields as $field) {
            $this->assertTrue($errors->has($field), "Expected a request validation error for {$field}.");
        }
        $this->assertNothingStored();
    }
}
