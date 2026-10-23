<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementStreamingReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InforExportFixtures;
use Tests\TestCase;

/**
 * Optional check against real Infor LN exports. They contain company data and
 * are never committed (.gitignore: /*.xlsx), so the default suite runs on
 * InforExportFixtures instead. To run this check, point INFOR_SAMPLE_DIR at a
 * folder holding the two exports below:
 *
 *   INFOR_SAMPLE_DIR=/path/to/samples php artisan test --group=infor-real-samples
 *
 * Besides the reader and preview contract, it asserts that the real files
 * still produce the same reader profile as the synthetic fixtures, so a format
 * change in Infor shows up as a fixture gap rather than a silent false pass.
 */
#[Group('infor-real-samples')]
class InforRealExportSampleTest extends TestCase
{
    use RefreshDatabase;

    private const RECEIPTS = 'whinh3512m600_0520_20260924-134847_116644.xlsx';

    private const PURCHASE_ORDERS = 'tdpur4100m000_0520_20260924-133401_101112.xlsx';

    protected function tearDown(): void
    {
        InforExportFixtures::cleanup();
        parent::tearDown();
    }

    public function test_real_exports_match_the_synthetic_fixture_profile(): void
    {
        [$receipts, $orders] = $this->samples();
        $reader = app(LocalProcurementStreamingReader::class);

        $this->assertSame(
            $this->profile($reader, InforExportFixtures::goodsReceipts(), InforExportFixtures::purchaseOrders()),
            $this->profile($reader, $receipts, $orders),
        );
    }

    public function test_real_receipt_export_previews_after_its_purchase_orders_exist(): void
    {
        [$receipts] = $this->samples();
        Storage::fake('private');
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Sample Supplier', 'category' => 'Parts']);
        $rows = iterator_to_array(app(LocalProcurementStreamingReader::class)->rows($receipts, 'GR'));
        foreach (collect($rows)->pluck('po_number')->unique()->filter() as $poNumber) {
            LocalPurchaseOrder::firstOrCreate(['po_number' => $poNumber], [
                'supplier_id' => $supplier->id, 'po_date' => '2026-09-01', 'total_amount' => '100000000.00',
                'currency' => 'IDR', 'status' => LocalPurchaseOrder::STATUS_OPEN, 'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            ]);
        }

        $response = $this->actingAs(User::factory()->create(['role' => 'finance']))
            ->post(route('finance.local-procurement.import.gr.preview'), [
                'import_file' => new UploadedFile($receipts, 'receipts.xlsx', null, null, true),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);
        $preview = $this->getJson($response->json('status_url'))->assertOk()->json('preview');

        $this->assertTrue($preview['success'], json_encode($preview));
        $this->assertSame(count($rows), $preview['source_row_count']);
        $this->assertGreaterThan(0, $preview['summary']['new_gr']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function samples(): array
    {
        $directory = getenv('INFOR_SAMPLE_DIR') ?: '';
        $receipts = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.self::RECEIPTS;
        $orders = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.self::PURCHASE_ORDERS;
        if ($directory === '' || ! is_file($receipts) || ! is_file($orders)) {
            $this->markTestSkipped('Optional: set INFOR_SAMPLE_DIR to a folder with '.self::RECEIPTS.' and '.self::PURCHASE_ORDERS.'.');
        }

        return [$receipts, $orders];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(LocalProcurementStreamingReader $reader, string $receipts, string $orders): array
    {
        $gr = collect(iterator_to_array($reader->rows($receipts, 'GR')));
        $grWarnings = array_column($reader->warnings, 'column');
        $po = collect(iterator_to_array($reader->rows($orders, 'PO')));
        $poWarnings = array_column($reader->warnings, 'column');

        return [
            'gr_rows' => $gr->count(),
            'gr_first_row' => $gr->first()['_row'],
            'gr_receipts' => $gr->pluck('gr_number')->unique()->count(),
            'gr_purchase_orders' => $gr->pluck('po_number')->unique()->count(),
            'gr_max_lines_per_receipt' => $gr->groupBy('gr_number')->map->count()->max(),
            'gr_uom' => $gr->pluck('uom')->unique()->values()->all(),
            'gr_qty_types' => $gr->map(fn (array $row) => get_debug_type($row['qty']))->unique()->values()->all(),
            'gr_dates_iso' => $gr->every(fn (array $row) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $row['gr_date']) === 1),
            'gr_descriptions' => $gr->every(fn (array $row) => filled($row['description'])),
            'gr_warnings' => $grWarnings,
            'po_rows' => $po->count(),
            'po_suppliers' => $po->pluck('supplier_name')->unique()->count(),
            'po_amount_types' => $po->map(fn (array $row) => get_debug_type($row['po_amount']))->unique()->values()->all(),
            'po_dates_iso' => $po->every(fn (array $row) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $row['po_date']) === 1),
            'po_pr_header' => $po->pluck('_pr_header_valid')->unique()->values()->all(),
            'po_warnings' => $poWarnings,
        ];
    }
}
