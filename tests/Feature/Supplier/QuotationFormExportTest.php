<?php

namespace Tests\Feature\Supplier;

use App\Exports\QuotationImportTemplateExport;
use App\Models\ExportJob;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Gate 2 GEN-03 Mode 1: Export Form writes the entered (unsaved) quotation
 * form to XLSX without any database write. Gate 1 §3-F.
 */
class QuotationFormExportTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private PurchaseRequisition $pr;

    private PrItem $flat;

    private PrItem $round;

    private PrItem $hollow;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $period = Period::create(['name' => 'Export Form Period', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $admin->id]);
        $this->pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/10/2026/900',
            'status' => 'submitted',
        ]);
        $this->flat = $this->item('SS400 Plate', PrItem::SHAPE_FLAT, ['thickness' => 12, 'width' => 1500, 'length' => 6000], 4);
        $this->round = $this->item('S45C Bar', PrItem::SHAPE_ROUND, ['d_outer' => 50, 'length' => 3000], 10);
        $this->hollow = $this->item('STKM Pipe', PrItem::SHAPE_HOLLOW, ['d_outer' => 60, 'd_inner' => 40, 'length' => 4000], 3);
    }

    private function validItems(): array
    {
        return [
            [
                'pr_item_id' => $this->flat->id,
                'is_available' => '1',
                'material_name' => 'FORGED MATERIAL',
                'available_qty' => '4',
                'available_thickness' => '12.5',
                'available_width' => '1500',
                'available_length_input' => '2300-2500',
                'offered_weight_per_unit' => '',
                'price_per_kg' => '1234567.1234',
                'notes' => '=1+1',
            ],
            [
                'pr_item_id' => $this->round->id,
                'is_available' => '1',
                'available_qty' => '8',
                'available_d_outer' => '50',
                'available_length_input' => '3000',
                'offered_weight_per_unit' => '46.2',
                'price_per_kg' => '2.5',
                'notes' => '',
            ],
            [
                'pr_item_id' => $this->hollow->id,
                'is_available' => '0',
            ],
        ];
    }

    public function test_valid_form_exports_entered_rows_with_typed_cells_and_no_database_write(): void
    {
        $counts = $this->counts();

        $response = $this->export(['items' => $this->validItems(), 'currency' => 'USD'])->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($counts, $this->counts());

        $sheet = $this->sheet($response);
        $this->assertSame(QuotationImportTemplateExport::CANONICAL_HEADINGS, $sheet->rangeToArray('A1:M1')[0]);

        // One row per PR item, in PR item order; material always from the database.
        $this->assertSame([$this->flat->id, $this->round->id, $this->hollow->id], array_map('intval', array_column($sheet->rangeToArray('A2:A4'), 0)));
        $this->assertSame('SS400 Plate', $sheet->getCell('B2')->getValue());

        // Flat: numeric qty, dims and price; range as text; blank weight stays blank.
        $this->assertCell($sheet, 'D2', DataType::TYPE_NUMERIC, 4);
        $this->assertCell($sheet, 'E2', DataType::TYPE_NUMERIC, 12.5);
        $this->assertCell($sheet, 'F2', DataType::TYPE_NUMERIC, 1500);
        $this->assertNull($sheet->getCell('G2')->getValue());
        $this->assertCell($sheet, 'I2', DataType::TYPE_STRING, '2300 - 2500');
        $this->assertNull($sheet->getCell('J2')->getValue());
        $this->assertCell($sheet, 'K2', DataType::TYPE_NUMERIC, 1234567.1234);
        $this->assertCell($sheet, 'L2', DataType::TYPE_STRING, "'=1+1");
        $this->assertSame('Available', $sheet->getCell('M2')->getValue());
        $this->assertSame('#,##0.0000', $sheet->getStyle('K2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());

        // Round: exact length is numeric; blank notes stay blank.
        $this->assertCell($sheet, 'I3', DataType::TYPE_NUMERIC, 3000);
        $this->assertCell($sheet, 'J3', DataType::TYPE_NUMERIC, 46.2);
        $this->assertNull($sheet->getCell('L3')->getValue());

        // Unavailable: offer cells blank, availability reads Not Available.
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $column) {
            $this->assertNull($sheet->getCell($column.'4')->getValue(), "{$column}4 must be blank");
        }
        $this->assertSame('Not Available', $sheet->getCell('M4')->getValue());
    }

    public function test_exported_file_round_trips_through_import_preview(): void
    {
        $binary = $this->binary($this->export(['items' => $this->validItems()])->assertOk());
        $upload = UploadedFile::fake()->createWithContent('form.xlsx', $binary);

        $rows = collect($this->actingAs($this->supplier)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('rows'))->keyBy('pr_item_id');

        $flat = $rows[$this->flat->id];
        $this->assertTrue($flat['is_available']);
        $this->assertSame(4, $flat['available_qty']);
        $this->assertEquals(12.5, $flat['available_thickness']);
        $this->assertEquals(1500, $flat['available_width']);
        $this->assertEquals(2300, $flat['available_length_min']);
        $this->assertEquals(2500, $flat['available_length_max']);
        $this->assertEquals(1234567.1234, $flat['price_per_kg']);
        // Documented limitation: the sanitiser's leading quote survives re-import.
        $this->assertSame("'=1+1", $flat['notes']);

        $round = $rows[$this->round->id];
        $this->assertSame(8, $round['available_qty']);
        $this->assertEquals(50, $round['available_d_outer']);
        $this->assertEquals(3000, $round['available_length']);
        $this->assertEquals(46.2, $round['offered_weight_per_unit']);
        $this->assertEquals(2.5, $round['price_per_kg']);
        $this->assertNull($round['notes']);

        $this->assertFalse($rows[$this->hollow->id]['is_available']);
    }

    public function test_invalid_entries_return_a_controlled_422_naming_the_field_and_no_file(): void
    {
        $cases = [
            'foreign pr item' => [fn (array $items) => [...array_slice($items, 0, 2), ['pr_item_id' => 999999, 'is_available' => '0']], 'items.2.pr_item_id'],
            'duplicate pr item' => [fn (array $items) => [$items[0], $items[0], $items[2]], 'items.1.pr_item_id'],
            'missing pr item' => [fn (array $items) => array_slice($items, 0, 2), 'items'],
            'invalid length text' => [fn (array $items) => $this->with($items, 0, 'available_length_input', 'abc'), 'items.0.available_length_input'],
            'zero length' => [fn (array $items) => $this->with($items, 1, 'available_length_input', '0'), 'items.1.available_length_input'],
            'non numeric price' => [fn (array $items) => $this->with($items, 1, 'price_per_kg', '12,5x'), 'items.1.price_per_kg'],
            'negative price' => [fn (array $items) => $this->with($items, 1, 'price_per_kg', '-1'), 'items.1.price_per_kg'],
            'irrelevant dimension' => [fn (array $items) => $this->with($items, 1, 'available_width', '100'), 'items.1.available_width'],
            'quantity above request' => [fn (array $items) => $this->with($items, 1, 'available_qty', '11'), 'items.1.available_qty'],
            'notes not text' => [fn (array $items) => $this->with($items, 0, 'notes', ['x']), 'items.0.notes'],
        ];

        foreach ($cases as $name => [$mutate, $field]) {
            $counts = $this->counts();
            $response = $this->export(['items' => $mutate($this->validItems())]);
            $response->assertStatus(422)->assertJsonValidationErrors([$field]);
            $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), $name);
            $this->assertSame($counts, $this->counts(), $name);
        }
    }

    public function test_uninvited_or_closed_requisitions_are_refused(): void
    {
        $other = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->pr->invitedSuppliers()->attach($other->id, ['invited_at' => now()]);
        $this->export(['items' => $this->validItems()])->assertForbidden();

        $this->pr->invitedSuppliers()->detach();
        $this->pr->update(['status' => 'completed']);
        $this->export(['items' => $this->validItems()])->assertForbidden();

        $this->pr->update(['status' => 'submitted']);
        $this->actingAs($this->supplier)
            ->postJson(route('supplier.quotations.export-form', ['pr_id' => $this->pr->id]), ['items' => $this->validItems()])
            ->assertNotFound();
    }

    public function test_formula_payloads_in_notes_are_neutralised(): void
    {
        foreach (['=1+1', '+x', '-x', '@x', '  =x'] as $index => $payload) {
            $items = $this->with($this->validItems(), 0, 'notes', $payload);
            $sheet = $this->sheet($this->export(['items' => $items])->assertOk());
            $cell = $sheet->getCell('L2');
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "payload {$index}");
            $this->assertStringStartsWith("'", (string) $cell->getValue(), "payload {$index}");
        }
    }

    private function export(array $payload): TestResponse
    {
        return $this->actingAs($this->supplier)
            ->postJson(route('supplier.quotations.export-form', $this->pr), $payload);
    }

    private function with(array $items, int $index, string $field, mixed $value): array
    {
        $items[$index][$field] = $value;

        return $items;
    }

    private function counts(): array
    {
        return [Quotation::withTrashed()->count(), QuotationItem::count(), ExportJob::count()];
    }

    private function binary(TestResponse $response): string
    {
        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    private function sheet(TestResponse $response): Worksheet
    {
        // Read with the default binder, as any later process would.
        Cell::setValueBinder(new DefaultValueBinder);
        $path = tempnam(sys_get_temp_dir(), 'qfe').'.xlsx';
        file_put_contents($path, $this->binary($response));
        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    private function assertCell(Worksheet $sheet, string $coordinate, string $type, mixed $value): void
    {
        $cell = $sheet->getCell($coordinate);
        $this->assertSame($type, $cell->getDataType(), "{$coordinate} type");
        $type === DataType::TYPE_NUMERIC
            ? $this->assertEqualsWithDelta($value, $cell->getValue(), 0.00001, "{$coordinate} value")
            : $this->assertSame($value, $cell->getValue(), "{$coordinate} value");
    }

    private function item(string $name, string $shape, array $dimensions, int $quantity): PrItem
    {
        return PrItem::create([
            'pr_id' => $this->pr->id,
            'hs_code' => '7208.51.00',
            'material_name' => $name,
            'shape' => $shape,
            'quantity' => $quantity,
            'weight_needed' => 100,
            ...$dimensions,
        ]);
    }
}
