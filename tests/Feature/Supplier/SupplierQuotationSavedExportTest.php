<?php

namespace Tests\Feature\Supplier;

use App\Exports\Advanced\Definitions\QuotationDefinition;
use App\Exports\QuotationsExport;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Gate 2 GEN-03 Mode 2: the existing saved-data export (Gate 1 §3-F).
 * Scope is characterised, new columns are opt-in, number masks apply to the
 * catalog XLSX path only and survive the queued chain.
 */
class SupplierQuotationSavedExportTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_COLUMNS = ['requested_shape', 'requested_thickness', 'requested_width', 'requested_d_outer', 'requested_d_inner', 'requested_length', 'offered_thickness', 'offered_width', 'offered_d_outer', 'offered_d_inner'];

    private User $s1;

    private User $s2;

    private User $purchasing;

    private ExchangeRate $rate;

    /** @var array<string, Quotation> */
    private array $quotations = [];

    /** @var array<string, Period> */
    private array $periods = [];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->s1 = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->s2 = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => now()->subDay(), 'created_by' => $admin->id]);
        foreach (['p1' => 9, 'p2' => 10] as $key => $month) {
            $this->periods[$key] = Period::create(['name' => 'Saved '.$key, 'month' => $month, 'year' => 2026, 'status' => 'open', 'created_by' => $admin->id]);
        }

        $pr1 = $this->pr('REQ/09/2026/701', 'p1');
        $pr2 = $this->pr('REQ/10/2026/702', 'p2');
        $pr3 = $this->pr('REQ/10/2026/703', 'p2');
        $this->quotations['s1_draft'] = $this->quotation($pr1, $this->s1, Quotation::STATUS_DRAFT);
        $this->quotations['s1_submitted'] = $this->quotation($pr2, $this->s1, Quotation::STATUS_SUBMITTED);
        $this->quotations['s1_accepted'] = $this->quotation($pr3, $this->s1, Quotation::STATUS_ACCEPTED);
        $this->quotations['s2_submitted'] = $this->quotation($pr1, $this->s2, Quotation::STATUS_SUBMITTED);
        $this->quotations['s2_draft'] = $this->quotation($pr2, $this->s2, Quotation::STATUS_DRAFT);
    }

    public function test_supplier_export_scope_equals_the_hand_computed_item_sets(): void
    {
        Queue::fake();
        $cases = [
            'all' => [[], ['s1_draft', 's1_submitted', 's1_accepted']],
            'period one' => [['period_id' => $this->periods['p1']->id], ['s1_draft']],
            'period two' => [['period_id' => $this->periods['p2']->id], ['s1_submitted', 's1_accepted']],
            'draft' => [['status' => 'draft'], ['s1_draft']],
            'accepted in period two' => [['status' => 'accepted', 'period_id' => $this->periods['p2']->id], ['s1_accepted']],
            'pr number' => [['pr_number' => '702'], ['s1_submitted']],
            'unresponded' => [['status' => 'unresponded'], []],
            'tampered supplier' => [['supplier_id' => $this->s2->hash], ['s1_draft', 's1_submitted', 's1_accepted']],
        ];

        foreach ($cases as $name => [$filters, $expected]) {
            ExportJob::query()->delete();
            $this->actingAs($this->s1)
                ->postJson(route('supplier.export.quotations'), [...$filters, 'options' => ['columns' => ['pr_number'], 'format' => 'xlsx']])
                ->assertAccepted();
            $export = $this->restore(ExportJob::sole());

            $expectedIds = collect($expected)->flatMap(fn ($key) => $this->quotations[$key]->items()->pluck('id'))->sort()->values()->all();
            $this->assertSame($expectedIds, $export->query()->pluck('id')->sort()->values()->all(), $name);
            $this->assertSame(count($expectedIds), $export->query()->count(), "{$name}: one row per saved item");
        }
    }

    public function test_new_dimension_columns_are_opt_in_numeric_and_keep_defaults(): void
    {
        $definition = ExportDefinitions::forClass(QuotationsExport::class, 'supplier');
        $defaults = ExportDefinitions::defaultKeys($definition);
        foreach (self::NEW_COLUMNS as $key) {
            $this->assertNotContains($key, $defaults);
        }
        $catalog = collect(QuotationDefinition::columns())->keyBy('key');
        foreach (self::NEW_COLUMNS as $key) {
            $this->assertFalse($catalog[$key]->default, "{$key} default");
        }

        $export = $this->catalogExport(['pr_number', ...self::NEW_COLUMNS]);
        $item = $export->query()->whereKey($this->quotations['s1_submitted']->items()->firstOrFail()->id)->firstOrFail();
        $this->assertSame(['REQ/10/2026/702', 'Flat', 12.0, 1500.0, null, null, 6000.0, 12.5, 1500.0, null, null], $export->map($item));
        $headings = $export->headings();
        $this->assertCount(11, $headings);
        $this->assertNotContains('exports.headings.requested_shape', $headings);
    }

    public function test_number_masks_apply_to_the_catalog_xlsx_only_and_cells_stay_numeric(): void
    {
        $export = $this->catalogExport(['pr_number', 'price_per_kg', 'offered_quantity', 'offered_thickness', 'material']);
        $this->assertSame(['B' => '#,##0.0000', 'C' => '#,##0', 'D' => '#,##0.0000'], $export->columnFormats());

        $sheet = $this->sheet(Excel::raw($export, ExcelWriter::XLSX));
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertSame('#,##0.0000', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());

        $this->assertSame([], (new QuotationsExport([], $this->s1->id, true))->columnFormats(), 'legacy path untouched');
        $csv = new QuotationsExport([], $this->s1->id, true);
        $csv->applyOptions(new ExportOptions(['pr_number', 'price_per_kg'], 'csv', 'supplier'));
        $this->assertSame([], $csv->columnFormats(), 'CSV has no masks');
    }

    public function test_number_masks_survive_the_queued_chunked_chain(): void
    {
        Storage::fake('private');
        $this->actingAs($this->s1)
            ->postJson(route('supplier.export.quotations'), ['options' => ['columns' => ['pr_number', 'price_per_kg', 'offered_quantity'], 'format' => 'xlsx']])
            ->assertAccepted();

        $record = ExportJob::sole()->fresh();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $record->status, (string) $record->error_message);
        $sheet = $this->sheet(Storage::disk('private')->get($record->file_path));
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertSame('#,##0.0000', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
    }

    /**
     * Characterisation (not a change): the legacy no-options path compares the
     * month boundary in UTC, the catalog path in business time. The supplier
     * UI always posts options, so only crafted legacy requests see UTC.
     */
    public function test_date_filters_differ_between_legacy_and_catalog_paths(): void
    {
        $this->quotations['s1_submitted']->forceFill(['submitted_at' => '2026-09-30 18:00:00'])->save(); // 01:00 WIB on 1 Oct

        $legacy = new QuotationsExport(['date_from' => '2026-10'], $this->s1->id, true);
        $catalog = $this->catalogExport(['pr_number'], ['date_from' => '2026-10']);
        $id = $this->quotations['s1_submitted']->items()->firstOrFail()->id;

        $this->assertNotContains($id, $legacy->query()->pluck('id')->all());
        $this->assertContains($id, $catalog->query()->pluck('id')->all());
    }

    private function catalogExport(array $columns, array $filters = []): QuotationsExport
    {
        $export = new QuotationsExport($filters, $this->s1->id, true);
        $export->applyOptions(new ExportOptions($columns, 'xlsx', 'supplier'));

        return $export;
    }

    private function restore(ExportJob $record): QuotationsExport
    {
        $this->assertSame((int) $this->s1->id, (int) $record->export_args[1]);
        $export = new QuotationsExport(...$record->export_args);
        $export->applyOptions(ExportOptions::fromArray($record->export_options));

        return $export;
    }

    private function sheet(string $binary): Worksheet
    {
        Cell::setValueBinder(new DefaultValueBinder);
        $path = tempnam(sys_get_temp_dir(), 'qse').'.xlsx';
        file_put_contents($path, $binary);
        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    private function pr(string $number, string $period): PurchaseRequisition
    {
        $pr = PurchaseRequisition::create(['period_id' => $this->periods[$period]->id, 'created_by' => $this->purchasing->id, 'pr_number' => $number, 'status' => 'bidding']);
        PrItem::create(['pr_id' => $pr->id, 'hs_code' => '7208.51.00', 'material_name' => 'Plate '.$number, 'shape' => 'Flat', 'thickness' => 12, 'width' => 1500, 'length' => 6000, 'quantity' => 4, 'weight_needed' => 848.16]);
        PrItem::create(['pr_id' => $pr->id, 'hs_code' => '7214.99.00', 'material_name' => 'Bar '.$number, 'shape' => 'Round', 'd_outer' => 50, 'length' => 3000, 'quantity' => 2, 'weight_needed' => 46.2]);

        return $pr;
    }

    private function quotation(PurchaseRequisition $pr, User $supplier, string $status): Quotation
    {
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->rate->id, 'status' => $status, 'submitted_at' => $status === Quotation::STATUS_DRAFT ? null : now()]);
        foreach ($pr->items()->orderBy('id')->get() as $item) {
            QuotationItem::create([
                'quotation_id' => $quotation->id,
                'pr_item_id' => $item->id,
                'price_per_kg' => 2.5,
                'amount' => 100,
                'is_available' => true,
                'available_qty' => $item->quantity_value,
                'available_thickness' => $item->shape === 'Flat' ? 12.5 : null,
                'available_width' => $item->shape === 'Flat' ? 1500 : null,
                'available_d_outer' => $item->shape === 'Round' ? 50 : null,
                'available_length' => $item->length,
            ]);
        }

        return $quotation;
    }
}
