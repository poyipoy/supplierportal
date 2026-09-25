<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceDeliveryScheduleValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierUser;

    private LocalPurchaseOrder $po;

    private LocalGoodsReceipt $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        LocalPoReferenceService::clearMockPos();

        $this->supplierUser = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        SupplierScope::create([
            'supplier_id' => $this->supplierUser->id,
            'scope' => 'local',
        ]);

        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Mitra Sejahtera',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $masters = app(LocalProcurementMasterService::class);
        $this->po = $masters->createPurchaseOrder($finance, [
            'supplier_id' => $this->supplierUser->id,
            'po_number' => 'PO-TEST-001',
            'po_date' => '2026-09-10',
            'total_amount' => '1000000.00',
        ]);
        $this->gr = $masters->createGoodsReceipt($finance, $this->po, [
            'gr_number' => 'GR-TEST-001',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);
    }

    public function test_request_validation_rejects_past_wednesday_delivery_date(): void
    {
        $pastWednesday = Carbon::now()->subWeek();
        while ($pastWednesday->dayOfWeek !== Carbon::WEDNESDAY) {
            $pastWednesday->subDay();
        }

        $response = $this->actingAs($this->supplierUser)
            ->post(route('local-supplier.invoices.store'), [
                'local_purchase_order_id' => $this->po->id,
                'goods_receipt_ids' => [$this->gr->id],
                'invoice_number' => 'INV-TEST-PAST',
                'invoice_date' => now()->toDateString(),
                'invoice_amount' => 1000000,
                'ppn_scheme' => '11%',
                'tax_amount' => 110000,
                'tax_invoice_number' => '01.01.26.12345678901',
                'scheduled_physical_delivery_date' => $pastWednesday->toDateString(),
                'invoice' => [UploadedFile::fake()->create('inv.pdf', 100, 'application/pdf')],
                'tax_invoice' => [UploadedFile::fake()->create('tax.pdf', 100, 'application/pdf')],
                'delivery_note' => [UploadedFile::fake()->create('sj.pdf', 100, 'application/pdf')],
            ]);

        $response->assertSessionHasErrors('scheduled_physical_delivery_date');
    }

    public function test_service_validation_rejects_past_wednesday_delivery_date(): void
    {
        $pastWednesday = Carbon::now()->subWeek();
        while ($pastWednesday->dayOfWeek !== Carbon::WEDNESDAY) {
            $pastWednesday->subDay();
        }

        $service = app(InvoiceSubmissionService::class);

        $this->expectException(ValidationException::class);

        $service->submit(
            $this->supplierUser,
            [
                'local_purchase_order_id' => $this->po->id,
                'goods_receipt_ids' => [$this->gr->id],
                'invoice_number' => 'INV-TEST-SERVICE-PAST',
                'invoice_date' => now()->toDateString(),
                'invoice_amount' => 1000000,
                'ppn_scheme' => '11%',
                'tax_amount' => 110000,
                'tax_invoice_number' => '01.01.26.12345678901',
                'scheduled_physical_delivery_date' => $pastWednesday->toDateString(),
            ],
            [
                'invoice' => [UploadedFile::fake()->create('inv.pdf', 100, 'application/pdf')],
                'tax_invoice' => [UploadedFile::fake()->create('tax.pdf', 100, 'application/pdf')],
                'delivery_note' => [UploadedFile::fake()->create('sj.pdf', 100, 'application/pdf')],
            ]
        );
    }

    public function test_future_wednesday_delivery_date_is_accepted(): void
    {
        $futureWednesday = Carbon::now()->addWeek();
        while ($futureWednesday->dayOfWeek !== Carbon::WEDNESDAY) {
            $futureWednesday->addDay();
        }

        $service = app(InvoiceSubmissionService::class);

        $invoice = $service->submit(
            $this->supplierUser,
            [
                'local_purchase_order_id' => $this->po->id,
                'goods_receipt_ids' => [$this->gr->id],
                'invoice_number' => 'INV-TEST-SERVICE-FUTURE',
                'invoice_date' => now()->toDateString(),
                'invoice_amount' => 1000000,
                'ppn_scheme' => '11%',
                'tax_amount' => 110000,
                'tax_invoice_number' => '01.01.26.12345678901',
                'scheduled_physical_delivery_date' => $futureWednesday->toDateString(),
            ],
            [
                'invoice' => [UploadedFile::fake()->create('inv.pdf', 100, 'application/pdf')],
                'tax_invoice' => [UploadedFile::fake()->create('tax.pdf', 100, 'application/pdf')],
                'delivery_note' => [UploadedFile::fake()->create('sj.pdf', 100, 'application/pdf')],
            ]
        );

        $this->assertNotNull($invoice);
        $this->assertSame($futureWednesday->toDateString(), $invoice->scheduled_physical_delivery_date?->toDateString());
    }
}
