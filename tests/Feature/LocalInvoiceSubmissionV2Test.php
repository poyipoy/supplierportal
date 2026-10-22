<?php

namespace Tests\Feature;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class LocalInvoiceSubmissionV2Test extends TestCase
{
    use RefreshDatabase;

    protected InvoiceSubmissionService $service;

    private User $operator;

    private static int $authSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        LocalPoReferenceService::clearMockPos();
        $this->service = app(InvoiceSubmissionService::class);
        $this->operator = User::factory()->create(['role' => 'finance']);
    }

    private function createLocalSupplier(array $supplierData = []): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);

        Supplier::create(array_merge([
            'user_id' => $user->id,
            'company_name' => 'PT Local Vendor',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ], $supplierData));

        return $user;
    }

    /**
     * Creates an authoritative Local PO with one whole GR for the owner and
     * returns the payload keys a new submission must carry.
     */
    private function authoritative(User $owner): array
    {
        $suffix = str_pad((string) ++self::$authSeq, 3, '0', STR_PAD_LEFT);
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->operator, [
            'supplier_id' => $owner->id,
            'po_number' => 'PO-V2-'.$suffix,
            'po_date' => '2026-09-01',
            'total_amount' => '999999999999999999.99',
        ]);
        $gr = $masters->createGoodsReceipt($this->operator, $po, [
            'gr_number' => 'GR-V2-'.$suffix,
            'gr_date' => '2026-09-02',
            'uom' => 'pcs',
            'qty' => 10.0,
        ]);

        return ['local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$gr->id]];
    }

    private function files(): array
    {
        return [...NativeFileFixtures::invoiceReferences(),
            'invoice' => NativeFileFixtures::upload('inv.pdf', 100),
            'tax_invoice' => NativeFileFixtures::upload('tax.pdf', 100),
            'delivery_note' => NativeFileFixtures::upload('sj.pdf', 100),
        ];
    }

    private function nextWednesday(): string
    {
        $date = Carbon::now();
        while ($date->dayOfWeek !== Carbon::WEDNESDAY) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    /**
     * A submission that does not name an authoritative Local PO must be refused
     * on local_purchase_order_id, leaving no invoice and no stored file behind.
     */
    private function assertRejectedAsNonAuthoritative(User $supplier, array $data): void
    {
        try {
            $this->service->submit($supplier, $data, $this->files());
            $this->fail('A submission without an authoritative Local PO was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('local_purchase_order_id', $exception->errors());
        }

        $this->assertSame(0, LocalInvoice::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_legacy_internal_po_reference_without_goods_receipt_is_rejected(): void
    {
        $supplier = $this->createLocalSupplier();
        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-INT-001',
            value: 10000000.0,
            grReference: null,
            description: 'Steel Pipes'
        );

        $this->assertRejectedAsNonAuthoritative($supplier, [
            'invoice_number' => 'INV-001',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'po_number' => 'PO-INT-001',
            'internal_po_reference' => 'PO-INT-001',
            'invoice_amount' => 5000000,
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ]);
    }

    public function test_legacy_internal_po_reference_is_rejected_even_with_goods_receipt(): void
    {
        $supplier = $this->createLocalSupplier();
        // A provider-resolvable PO with a GR used to be billable with only a
        // discrepancy flag when DPP exceeded its value; it now has no path in.
        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-INT-002',
            value: 10000000.0,
            grReference: 'GR-002',
            description: 'Steel Plates'
        );

        $this->assertRejectedAsNonAuthoritative($supplier, [
            'invoice_number' => 'INV-002',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'po_number' => 'PO-INT-002',
            'internal_po_reference' => 'PO-INT-002',
            'invoice_amount' => 12000000,
            'ppn_scheme' => '11%',
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ]);
    }

    public function test_manual_po_without_gr_reference_is_rejected(): void
    {
        $supplier = $this->createLocalSupplier();

        $this->assertRejectedAsNonAuthoritative($supplier, [
            'invoice_number' => 'INV-003',
            'invoice_date' => '2026-09-10',
            'po_source' => 'MANUAL',
            'manual_po_number' => 'MAN-PO-999',
            'manual_gr_reference' => null,
            'invoice_amount' => 3000000,
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ]);
    }

    public function test_manual_po_with_manual_gr_reference_is_rejected(): void
    {
        $supplier = $this->createLocalSupplier();

        $this->assertRejectedAsNonAuthoritative($supplier, [
            'invoice_number' => 'INV-004',
            'invoice_date' => '2026-09-10',
            'po_source' => 'MANUAL',
            'manual_po_number' => 'MAN-PO-999',
            'manual_gr_reference' => 'MAN-GR-888',
            'invoice_amount' => 3000000,
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ]);
    }

    public function test_conditional_documents_non_pkp_and_service_category_do_not_require_faktur_or_surat_jalan(): void
    {
        // Non-PKP and category Jasa Konsultan
        $supplier = $this->createLocalSupplier([
            'vendor_category' => 'Jasa Konsultan',
            'is_pkp' => false,
        ]);

        // Invoice, PO and GR remain mandatory; tax and delivery note are optional.
        $invoice = $this->service->submit(
            $supplier,
            array_merge([
                'invoice_number' => 'INV-JASA-001',
                'invoice_date' => '2026-09-10',
                'invoice_amount' => 5000000,
                'ppn_scheme' => '0%',
                'scheduled_physical_delivery_date' => $this->nextWednesday(),
            ], $this->authoritative($supplier)),
            [...NativeFileFixtures::invoiceReferences(),
                'invoice' => NativeFileFixtures::upload('invoice_only.pdf', 100),
            ]
        );

        $this->assertNotNull($invoice);
        $this->assertCount(3, $invoice->documents);
        $this->assertSame('invoice', $invoice->documents->first()->document_type);
    }

    public function test_service_vendor_may_attach_an_optional_surat_jalan(): void
    {
        $supplier = $this->createLocalSupplier([
            'vendor_category' => 'Jasa',
            'is_pkp' => false,
        ]);

        $invoice = $this->service->submit(
            $supplier,
            array_merge([
                'invoice_number' => 'INV-JASA-DN-001',
                'invoice_date' => '2026-09-10',
                'invoice_amount' => 5000000,
                'ppn_scheme' => '0%',
                'scheduled_physical_delivery_date' => $this->nextWednesday(),
            ], $this->authoritative($supplier)),
            [...NativeFileFixtures::invoiceReferences(),
                'invoice' => NativeFileFixtures::upload('invoice_only.pdf', 100),
                'delivery_note' => [NativeFileFixtures::upload('optional-sj.pdf', 100)],
            ]
        );

        $this->assertSame(1, $invoice->documents()->where('document_type', 'delivery_note')->count());
    }

    public function test_wednesday_delivery_schedule_is_enforced(): void
    {
        $supplier = $this->createLocalSupplier();

        // Pick a Tuesday or Thursday
        $thursday = Carbon::now();
        while ($thursday->dayOfWeek !== Carbon::THURSDAY) {
            $thursday->addDay();
        }

        try {
            $this->service->submit(
                $supplier,
                array_merge([
                    'invoice_number' => 'INV-THU-001',
                    'invoice_date' => '2026-09-10',
                    'invoice_amount' => 5000000,
                    'scheduled_physical_delivery_date' => $thursday->toDateString(),
                ], $this->authoritative($supplier)),
                $this->files()
            );
            $this->fail('A Thursday physical delivery date was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_physical_delivery_date', $exception->errors());
        }
    }

    public function test_tax_invoice_number_is_saved_and_normalized_for_pkp_supplier(): void
    {
        $supplier = $this->createLocalSupplier();

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([
            'invoice_number' => 'INV-TAX-001',
            'invoice_date' => '2026-09-10',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '0100002612345678', // Raw 16 digits
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ], $this->authoritative($supplier), $this->files()));

        $response->assertSessionHasNoErrors();
        $invoice = LocalInvoice::where('invoice_number', 'INV-TAX-001')->firstOrFail();
        $response->assertRedirect(route('local-supplier.invoices.receipt', $invoice));
        $this->assertSame('010.000-26.12345678', $invoice->tax_invoice_number);

        $revision = $invoice->revisions()->firstOrFail();
        $this->assertSame('010.000-26.12345678', $revision->tax_invoice_number);
    }

    public function test_async_invoice_submit_returns_json_redirect_and_flashes_success(): void
    {
        $supplier = $this->createLocalSupplier();

        $response = $this->actingAs($supplier)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('local-supplier.invoices.store'), array_merge([
                'invoice_number' => 'INV-ASYNC-001',
                'invoice_date' => '2026-09-10',
                'invoice_amount' => 5000000,
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '0100002612345678',
                'scheduled_physical_delivery_date' => $this->nextWednesday(),
            ], $this->authoritative($supplier), $this->files()));

        $invoice = LocalInvoice::where('invoice_number', 'INV-ASYNC-001')->firstOrFail();
        $response->assertOk()->assertExactJson(['redirect' => route('local-supplier.invoices.receipt', $invoice)]);
        $this->assertSame(__('local_invoice.feedback.submitted'), session('success'));
    }

    public function test_async_invoice_submit_with_invalid_data_returns_422_and_creates_nothing(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);

        $response = $this->actingAs($supplier)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('local-supplier.invoices.store'), array_merge([
                'invoice_number' => 'INV-ASYNC-002',
                'invoice_date' => '2026-09-10',
                'invoice_amount' => 5000000,
                'ppn_scheme' => '11%',
                // tax_invoice_number omitted for a PKP supplier
                'scheduled_physical_delivery_date' => $this->nextWednesday(),
            ], $this->authoritative($supplier), $this->files()));

        $response->assertUnprocessable()->assertJsonValidationErrors(['tax_invoice_number']);
        $this->assertSame(0, LocalInvoice::where('invoice_number', 'INV-ASYNC-002')->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_tax_invoice_number_supports_coretax_17_digit_format(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([
            'invoice_number' => 'INV-TAX-CORETAX',
            'invoice_date' => '2026-09-10',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '01002600000000001', // Raw 17 digits Coretax
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ], $this->authoritative($supplier), $this->files()));

        $response->assertSessionHasNoErrors();
        $invoice = LocalInvoice::where('invoice_number', 'INV-TAX-CORETAX')->firstOrFail();
        $this->assertSame('01.00.26.00000000001', $invoice->tax_invoice_number);
    }

    public function test_tax_invoice_number_is_required_for_pkp_supplier(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([
            'invoice_number' => 'INV-TAX-002',
            'invoice_date' => '2026-09-10',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            // tax_invoice_number omitted
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ], $this->authoritative($supplier), $this->files()));

        $response->assertSessionHasErrors('tax_invoice_number');
    }

    public function test_tax_invoice_number_rejects_invalid_format(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([
            'invoice_number' => 'INV-TAX-003',
            'invoice_date' => '2026-09-10',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => 'INVALID-123',
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ], $this->authoritative($supplier), $this->files()));

        $response->assertSessionHasErrors('tax_invoice_number');
    }

    public function test_duplicate_tax_invoice_number_is_prevented_for_same_supplier(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);
        $wednesday = $this->nextWednesday();

        // First invoice succeeds
        $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([
            'invoice_number' => 'INV-TAX-004A',
            'invoice_date' => '2026-09-10',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.99999999',
            'scheduled_physical_delivery_date' => $wednesday,
        ], $this->authoritative($supplier), $this->files()))->assertSessionHasNoErrors();

        // Second invoice with duplicate NSFP fails
        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([
            'invoice_number' => 'INV-TAX-004B',
            'invoice_date' => '2026-09-10',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.99999999',
            'scheduled_physical_delivery_date' => $wednesday,
        ], $this->authoritative($supplier), $this->files()));

        $response->assertSessionHasErrors('tax_invoice_number');
    }

    public function test_non_pkp_supplier_can_omit_tax_invoice_number(): void
    {
        $supplier = $this->createLocalSupplier([
            'is_pkp' => false,
            'vendor_category' => 'Jasa',
            'category' => 'Jasa',
        ]);

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([...NativeFileFixtures::invoiceReferences(),
            'invoice_number' => 'INV-TAX-005',
            'invoice_date' => '2026-09-10',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '0%',
            // tax_invoice_number omitted
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
            'invoice' => NativeFileFixtures::upload('inv.pdf', 100),
        ], $this->authoritative($supplier)));

        $response->assertSessionHasNoErrors();
        $invoice = LocalInvoice::where('invoice_number', 'INV-TAX-005')->firstOrFail();
        $this->assertNull($invoice->tax_invoice_number);
    }

    public function test_supplier_can_search_internal_purchase_orders_with_isolation(): void
    {
        $supplierA = $this->createLocalSupplier();
        $supplierB = $this->createLocalSupplier();

        LocalPoReferenceService::registerInternalPo(
            $supplierA->id,
            'PO-SEARCH-A1',
            value: 15000000.0,
            grReference: 'GR-A1',
            description: 'Order A1'
        );
        LocalPoReferenceService::registerInternalPo(
            $supplierA->id,
            'PO-SEARCH-A2',
            value: 20000000.0,
            grReference: null, // No GR
            description: 'Order A2 without GR'
        );
        LocalPoReferenceService::registerInternalPo(
            $supplierB->id,
            'PO-SEARCH-B1',
            value: 50000000.0,
            grReference: 'GR-B1',
            description: 'Order B1'
        );

        // Search as Supplier A
        $response = $this->actingAs($supplierA)->getJson(route('local-supplier.purchase-orders.search', ['q' => 'SEARCH']));
        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertEquals('PO-SEARCH-A1', $data[0]['po_number']);
        $this->assertTrue($data[0]['has_gr']);
        $this->assertEquals('GR-A1', $data[0]['gr_reference']);
        $this->assertEquals(15000000.0, $data[0]['total_amount']);

        $this->assertEquals('PO-SEARCH-A2', $data[1]['po_number']);
        $this->assertFalse($data[1]['has_gr']);
        $this->assertNull($data[1]['gr_reference']);

        // Filter specifically for A1
        $filtered = $this->actingAs($supplierA)->getJson(route('local-supplier.purchase-orders.search', ['q' => 'A1']));
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertEquals('PO-SEARCH-A1', $filtered->json('data.0.po_number'));
    }

    public function test_unified_po_number_resolving_to_internal_po_is_rejected(): void
    {
        $supplier = $this->createLocalSupplier();
        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-UNIFIED-INT',
            value: 10000000.0,
            grReference: 'GR-UNIFIED-01'
        );

        // Only po_number is sent; the resolver would auto-detect INTERNAL.
        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), array_merge([
            'invoice_number' => 'INV-UNIFIED-001',
            'invoice_date' => '2026-09-10',
            'po_number' => 'PO-UNIFIED-INT',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ], $this->files()));

        $response->assertSessionHasErrors('local_purchase_order_id');
        $this->assertSame(0, LocalInvoice::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_unified_po_number_with_blank_po_source_does_not_fall_back_to_manual(): void
    {
        $supplier = $this->createLocalSupplier();
        $base = [
            'invoice_number' => 'INV-UNIFIED-002',
            'invoice_date' => '2026-09-10',
            'po_number' => 'PO-CUSTOM-MANUAL',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'scheduled_physical_delivery_date' => $this->nextWednesday(),
        ];

        // An unresolvable PO number with no po_source used to default to MANUAL,
        // with or without a free-text GR reference. Both variants are refused.
        foreach ([[], ['manual_gr_reference' => 'SJ-CUSTOM-999']] as $variant) {
            $response = $this->actingAs($supplier)->post(
                route('local-supplier.invoices.store'),
                array_merge($base, $variant, $this->files())
            );

            $response->assertSessionHasErrors('local_purchase_order_id');
        }

        $this->assertSame(0, LocalInvoice::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
