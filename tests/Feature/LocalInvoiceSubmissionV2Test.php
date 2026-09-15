<?php

namespace Tests\Feature;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocalInvoiceSubmissionV2Test extends TestCase
{
    use RefreshDatabase;

    protected InvoiceSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        LocalPoReferenceService::clearMockPos();
        $this->service = app(InvoiceSubmissionService::class);
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

    private function nextWednesday(): string
    {
        $date = Carbon::now();
        while ($date->dayOfWeek !== Carbon::WEDNESDAY) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    public function test_internal_po_requires_goods_receipt_before_submission(): void
    {
        $supplier = $this->createLocalSupplier();
        $wednesday = $this->nextWednesday();

        // Register internal PO WITHOUT Goods Receipt
        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-INT-001',
            value: 10000000.0,
            grReference: null,
            description: 'Steel Pipes'
        );

        $this->expectException(ValidationException::class);

        $this->service->submit(
            $supplier,
            [
                'invoice_number' => 'INV-001',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-INT-001',
                'internal_po_reference' => 'PO-INT-001',
                'invoice_amount' => 5000000,
                'scheduled_physical_delivery_date' => $wednesday,
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );
    }

    public function test_internal_po_flags_discrepancy_when_dpp_exceeds_remaining_po_value(): void
    {
        $supplier = $this->createLocalSupplier();
        $wednesday = $this->nextWednesday();

        // Register internal PO WITH GR, value 10,000,000
        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-INT-002',
            value: 10000000.0,
            grReference: 'GR-002',
            description: 'Steel Plates'
        );

        // Submit invoice with DPP 12,000,000 (> 10,000,000 remaining)
        $invoice = $this->service->submit(
            $supplier,
            [
                'invoice_number' => 'INV-002',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-INT-002',
                'internal_po_reference' => 'PO-INT-002',
                'invoice_amount' => 12000000,
                'ppn_scheme' => '11%',
                'scheduled_physical_delivery_date' => $wednesday,
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );

        // Submission allowed, but flagged with discrepancy
        $this->assertTrue($invoice->has_po_discrepancy);
        $this->assertEquals(10000000.0, $invoice->po_value_snapshot);
        $this->assertEquals(10000000.0, $invoice->po_remaining_snapshot);
        $this->assertSame('PO-INT-002', $invoice->internal_po_reference);
        $this->assertSame('GR-002', $invoice->internal_gr_reference);
        $this->assertSame('11%', $invoice->ppn_scheme);
        $this->assertEquals(1320000.0, $invoice->tax_amount); // 11% of 12,000,000
        $this->assertNull($invoice->due_date); // Due date NOT set at submission!
    }

    public function test_manual_po_requires_manual_gr_reference(): void
    {
        $supplier = $this->createLocalSupplier();
        $wednesday = $this->nextWednesday();

        // Manual PO without manual GR should fail
        $this->expectException(ValidationException::class);

        $this->service->submit(
            $supplier,
            [
                'invoice_number' => 'INV-003',
                'invoice_date' => '2026-09-10',
                'po_source' => 'MANUAL',
                'manual_po_number' => 'MAN-PO-999',
                'manual_gr_reference' => null, // Missing!
                'invoice_amount' => 3000000,
                'scheduled_physical_delivery_date' => $wednesday,
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );
    }

    public function test_manual_po_submission_succeeds_with_manual_gr(): void
    {
        $supplier = $this->createLocalSupplier();
        $wednesday = $this->nextWednesday();

        $invoice = $this->service->submit(
            $supplier,
            [
                'invoice_number' => 'INV-004',
                'invoice_date' => '2026-09-10',
                'po_source' => 'MANUAL',
                'manual_po_number' => 'MAN-PO-999',
                'manual_gr_reference' => 'MAN-GR-888',
                'invoice_amount' => 3000000,
                'scheduled_physical_delivery_date' => $wednesday,
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );

        $this->assertSame('MANUAL', $invoice->po_source);
        $this->assertSame('MAN-PO-999', $invoice->manual_po_number);
        $this->assertSame('MAN-GR-888', $invoice->manual_gr_reference);
        $this->assertFalse($invoice->has_po_discrepancy);
    }

    public function test_conditional_documents_non_pkp_and_service_category_do_not_require_faktur_or_surat_jalan(): void
    {
        // Non-PKP and category Jasa Konsultan
        $supplier = $this->createLocalSupplier([
            'vendor_category' => 'Jasa Konsultan',
            'is_pkp' => false,
        ]);
        $wednesday = $this->nextWednesday();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-JASA-001',
            value: 5000000.0,
            grReference: 'GR-JASA-001'
        );

        // Submit with ONLY invoice document (no tax_invoice, no delivery_note)
        $invoice = $this->service->submit(
            $supplier,
            [
                'invoice_number' => 'INV-JASA-001',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-JASA-001',
                'internal_po_reference' => 'PO-JASA-001',
                'invoice_amount' => 5000000,
                'ppn_scheme' => '0%',
                'scheduled_physical_delivery_date' => $wednesday,
            ],
            [
                'invoice' => UploadedFile::fake()->create('invoice_only.pdf', 100),
            ]
        );

        $this->assertNotNull($invoice);
        $this->assertCount(1, $invoice->documents);
        $this->assertSame('invoice', $invoice->documents->first()->document_type);
    }

    public function test_wednesday_delivery_schedule_is_enforced(): void
    {
        $supplier = $this->createLocalSupplier();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-SCHED-001',
            value: 5000000.0,
            grReference: 'GR-SCHED-001'
        );

        // Pick a Tuesday or Thursday
        $thursday = Carbon::now();
        while ($thursday->dayOfWeek !== Carbon::THURSDAY) {
            $thursday->addDay();
        }

        $this->expectException(ValidationException::class);

        $this->service->submit(
            $supplier,
            [
                'invoice_number' => 'INV-THU-001',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-SCHED-001',
                'internal_po_reference' => 'PO-SCHED-001',
                'invoice_amount' => 5000000,
                'scheduled_physical_delivery_date' => $thursday->toDateString(),
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );
    }

    public function test_tax_invoice_number_is_saved_and_normalized_for_pkp_supplier(): void
    {
        $supplier = $this->createLocalSupplier();
        $wednesday = $this->nextWednesday();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-TAX-001',
            value: 5000000.0,
            grReference: 'GR-TAX-001'
        );

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-TAX-001',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'internal_po_reference' => 'PO-TAX-001',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '0100002612345678', // Raw 16 digits
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ]);

        $response->assertSessionHasNoErrors();
        $invoice = LocalInvoice::where('invoice_number', 'INV-TAX-001')->firstOrFail();
        $response->assertRedirect(route('local-supplier.invoices.receipt', $invoice));
        $this->assertSame('010.000-26.12345678', $invoice->tax_invoice_number);

        $revision = $invoice->revisions()->firstOrFail();
        $this->assertSame('010.000-26.12345678', $revision->tax_invoice_number);
    }

    public function test_tax_invoice_number_is_required_for_pkp_supplier(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);
        $wednesday = $this->nextWednesday();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-TAX-002',
            value: 5000000.0,
            grReference: 'GR-TAX-002'
        );

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-TAX-002',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'internal_po_reference' => 'PO-TAX-002',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            // tax_invoice_number omitted
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ]);

        $response->assertSessionHasErrors('tax_invoice_number');
    }

    public function test_tax_invoice_number_rejects_invalid_format(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);
        $wednesday = $this->nextWednesday();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-TAX-003',
            value: 5000000.0,
            grReference: 'GR-TAX-003'
        );

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-TAX-003',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'internal_po_reference' => 'PO-TAX-003',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => 'INVALID-123',
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ]);

        $response->assertSessionHasErrors('tax_invoice_number');
    }

    public function test_duplicate_tax_invoice_number_is_prevented_for_same_supplier(): void
    {
        $supplier = $this->createLocalSupplier(['is_pkp' => true]);
        $wednesday = $this->nextWednesday();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-TAX-004A',
            value: 5000000.0,
            grReference: 'GR-TAX-004A'
        );

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-TAX-004B',
            value: 5000000.0,
            grReference: 'GR-TAX-004B'
        );

        // First invoice succeeds
        $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-TAX-004A',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'internal_po_reference' => 'PO-TAX-004A',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.99999999',
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ])->assertSessionHasNoErrors();

        // Second invoice with duplicate NSFP fails
        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-TAX-004B',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'internal_po_reference' => 'PO-TAX-004B',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.99999999',
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ]);

        $response->assertSessionHasErrors('tax_invoice_number');
    }

    public function test_non_pkp_supplier_can_omit_tax_invoice_number(): void
    {
        $supplier = $this->createLocalSupplier([
            'is_pkp' => false,
            'vendor_category' => 'Jasa',
            'category' => 'Jasa',
        ]);
        $wednesday = $this->nextWednesday();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-TAX-005',
            value: 5000000.0,
            grReference: 'GR-TAX-005'
        );

        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-TAX-005',
            'invoice_date' => '2026-09-10',
            'po_source' => 'INTERNAL',
            'internal_po_reference' => 'PO-TAX-005',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '0%',
            // tax_invoice_number omitted
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
        ]);

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

    public function test_invoice_submission_with_unified_po_number_auto_detects_internal(): void
    {
        $supplier = $this->createLocalSupplier();
        $wednesday = $this->nextWednesday();

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-UNIFIED-INT',
            value: 10000000.0,
            grReference: 'GR-UNIFIED-01'
        );

        // Form only sends po_number without po_source or internal_po_reference
        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-UNIFIED-001',
            'invoice_date' => '2026-09-10',
            'po_number' => 'PO-UNIFIED-INT',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ]);

        $response->assertSessionHasNoErrors();
        $invoice = LocalInvoice::where('invoice_number', 'INV-UNIFIED-001')->firstOrFail();
        $this->assertEquals('INTERNAL', $invoice->po_source);
        $this->assertEquals('PO-UNIFIED-INT', $invoice->po_number);
        $this->assertEquals('PO-UNIFIED-INT', $invoice->internal_po_reference);
        $this->assertEquals('GR-UNIFIED-01', $invoice->internal_gr_reference);
    }

    public function test_invoice_submission_with_unified_po_number_auto_detects_manual_and_requires_gr(): void
    {
        $supplier = $this->createLocalSupplier();
        $wednesday = $this->nextWednesday();

        // 1. Without manual_gr_reference -> fails validation
        $response = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-UNIFIED-002',
            'invoice_date' => '2026-09-10',
            'po_number' => 'PO-CUSTOM-MANUAL',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ]);

        $response->assertSessionHasErrors('manual_gr_reference');

        // 2. With manual_gr_reference -> succeeds
        $responseOk = $this->actingAs($supplier)->post(route('local-supplier.invoices.store'), [
            'invoice_number' => 'INV-UNIFIED-002',
            'invoice_date' => '2026-09-10',
            'po_number' => 'PO-CUSTOM-MANUAL',
            'manual_gr_reference' => 'SJ-CUSTOM-999',
            'invoice_amount' => 5000000,
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'scheduled_physical_delivery_date' => $wednesday,
            'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
            'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
            'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
        ]);

        $responseOk->assertSessionHasNoErrors();
        $invoice = LocalInvoice::where('invoice_number', 'INV-UNIFIED-002')->firstOrFail();
        $responseOk->assertRedirect(route('local-supplier.invoices.receipt', $invoice));
        $this->assertEquals('MANUAL', $invoice->po_source);
        $this->assertEquals('PO-CUSTOM-MANUAL', $invoice->po_number);
        $this->assertEquals('SJ-CUSTOM-999', $invoice->manual_gr_reference);
    }
}
