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
}
