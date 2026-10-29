<?php

namespace Tests\Feature;

use App\Exports\PrImportTemplateExport;
use App\Exports\QuotationImportTemplateExport;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class MissionFiveImportTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private Period $period;

    private PurchaseRequisition $pr;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MaterialHsCodeMasterSeeder::class);

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);
        $this->supplierA = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);
        $this->supplierB = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);
        $this->period = Period::create([
            'name' => 'Mission Five August 2026',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $this->pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/08/2026/501',
            'status' => 'submitted',
            'notes' => 'Mission Five import test',
        ]);
        $this->pr->items()->create([
            'hs_code' => '00112233',
            'material_name' => '=Requested Flat Material',
            'shape' => PrItem::SHAPE_FLAT,
            'quantity' => 2,
            'thickness' => 1.5,
            'width' => 100,
            'length' => 200,
            'weight_needed' => 10,
            'remark' => 'Requested flat material',
        ]);
        $this->pr->items()->create([
            'hs_code' => '44556677',
            'material_name' => 'Requested Round Material',
            'shape' => PrItem::SHAPE_ROUND,
            'quantity' => 3,
            'd_outer' => 25,
            'length' => 600,
            'weight_needed' => 12.5,
        ]);
        $this->pr->invitedSuppliers()->sync([$this->supplierA->id]);
        $this->pr = $this->pr->fresh(['items', 'invitedSuppliers']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_pr_and_quotation_templates_have_the_required_contract_and_authorization(): void
    {
        $prTemplate = new PrImportTemplateExport;
        // Q5: technical keys unchanged; dimensions Thickness, Width, OD, ID, Length.
        $this->assertSame([
            'material_name', 'shape', 'quantity', 'thickness', 'width',
            'd_outer', 'd_inner', 'length', 'remark',
        ], $prTemplate->headings());
        $this->assertCount(1, $prTemplate->array());

        $quotationTemplate = new QuotationImportTemplateExport($this->pr->id);
        // Q5: same technical keys; dimensions Thickness, Width, OD, ID, Length.
        // Gate 2 GEN-02 (Confirmation 2): order now follows the quotation form (Qty, dimensions,
        // KG/Unit, Price/kg, Notes, Availability); the set of keys is unchanged.
        $this->assertSame([
            'pr_item_id', 'material_name', 'requested_dimension', 'available_qty',
            'available_thickness', 'available_width', 'available_d_outer',
            'available_d_inner', 'available_length', 'offered_weight_per_unit',
            'price_per_kg', 'notes', 'availability',
        ], $quotationTemplate->headings());
        $this->assertSame(QuotationImportTemplateExport::CANONICAL_HEADINGS, $quotationTemplate->headings());
        $this->assertCount(13, $quotationTemplate->collection()->first());
        $sample = array_combine($quotationTemplate->headings(), $quotationTemplate->collection()->first());
        $this->assertSame('Available', $sample['availability']);
        $this->assertNull($sample['price_per_kg']);
        $this->assertSame(
            $this->pr->items->pluck('id')->all(),
            $quotationTemplate->collection()->pluck(0)->all()
        );
        $this->assertSame("'=Requested Flat Material", $quotationTemplate->collection()->first()[1]);

        $templatePath = tempnam(sys_get_temp_dir(), 'mission-five-template-').'.xlsx';
        file_put_contents($templatePath, Excel::raw($prTemplate, ExcelFormat::XLSX));
        $this->temporaryFiles[] = $templatePath;
        $templateSheet = IOFactory::load($templatePath)->getActiveSheet();
        $this->assertSame('material_name', $templateSheet->getCell('A1')->getValue());
        $this->assertSame('shape', $templateSheet->getCell('B1')->getValue());
        $this->assertSame('Round', $templateSheet->getCell('B2')->getValue());

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), [
                'import_file' => new UploadedFile(
                    $templatePath,
                    'template_import_pr.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('rows.0.material_name', 'SCM440');

        Excel::fake();

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.import-template'))
            ->assertOk();
        Excel::assertDownloaded('template_import_pr.xlsx');

        $this->actingAs($this->supplierA)
            ->get(route('supplier.quotations.import-template', $this->pr))
            ->assertOk();
        Excel::assertDownloaded('template_import_quotation_REQ_08_2026_501.xlsx');

        $this->actingAs($this->supplierB)
            ->get(route('supplier.quotations.import-template', $this->pr))
            ->assertForbidden();
    }

    public function test_pr_preview_maps_canonical_dimensions_for_all_shapes_and_never_writes_database(): void
    {
        $before = $this->databaseSnapshot();
        // Old/arbitrary column order still parses: the import is heading-based.
        $upload = $this->spreadsheetUpload([
            'quantity', 'material_name', 'shape', 'd_inner', 'width',
            'd_outer', 'thickness', 'length', 'remark',
        ], [
            [2, 'SCM440', 'Flat', null, 120, null, 1.2, 240, 'Imported flat'],
            [3, 'SCM440', 'Round', null, null, 20, null, 300, 'Imported round'],
            [4, 'SCM440', 'Hollow', 10, null, 20, null, 400, 'Imported hollow'],
        ]);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), [
                'import_file' => $upload,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.valid', 3)
            ->assertJsonPath('summary.invalid', 0);

        $flat = $response->json('rows.0');
        $this->assertSame(PrItem::SHAPE_FLAT, $flat['shape']);
        $this->assertSame(2, $flat['quantity']);
        $this->assertEquals(1.2, $flat['thickness']);
        $this->assertNull($flat['d_inner']);
        $this->assertNull($flat['d_outer']);
        $this->assertEquals(120.0, $flat['width']);
        $this->assertEquals(240.0, $flat['length']);

        $round = $response->json('rows.1');
        $this->assertSame(PrItem::SHAPE_ROUND, $round['shape']);
        $this->assertSame(3, $round['quantity']);
        $this->assertNull($round['thickness']);
        $this->assertNull($round['d_inner']);
        $this->assertEquals(20.0, $round['d_outer']);
        $this->assertNull($round['width']);
        $this->assertEquals(300.0, $round['length']);

        $hollow = $response->json('rows.2');
        $this->assertSame(PrItem::SHAPE_HOLLOW, $hollow['shape']);
        $this->assertSame(4, $hollow['quantity']);
        $this->assertNull($hollow['thickness']);
        $this->assertEquals(10.0, $hollow['d_inner']);
        $this->assertEquals(20.0, $hollow['d_outer']);
        $this->assertNull($hollow['width']);
        $this->assertEquals(400.0, $hollow['length']);

        $this->assertSame($before, $this->databaseSnapshot());
    }

    /** PUR-01/PUR-02: per-row Total KG plus Grand Total Qty/KG, computed server-side with BCMath. */
    public function test_pr_preview_reports_row_total_weight_and_grand_totals(): void
    {
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload(
            ['material_name', 'shape', 'quantity', 'thickness', 'width', 'd_outer', 'd_inner', 'length'],
            [
                ['SCM440', 'Flat', 3, 1.2, 120, null, null, 240],
                ['SCM440', 'Hollow', 7, null, null, 20, 10, 400],
            ]
        );

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true);

        $rows = $response->json('rows');
        $expectedKg = '0';
        $expectedQty = 0;
        foreach ($rows as $row) {
            // Independent oracle: unit KG × quantity at the 4 dp storage scale.
            $rowKg = bcmul(sprintf('%.4F', $row['weight_needed']), (string) $row['quantity'], 4);
            $this->assertSame($rowKg, $row['total_weight']);
            $expectedKg = bcadd($expectedKg, $rowKg, 4);
            $expectedQty += $row['quantity'];
        }
        $this->assertSame(10, $response->json('summary.total_quantity'));
        $this->assertSame($expectedQty, $response->json('summary.total_quantity'));
        $this->assertSame($expectedKg, $response->json('summary.total_weight_kg'));
        $this->assertSame(1, bccomp($expectedKg, '0', 4), 'Fixture must produce a calculated weight.');
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_pr_preview_rejects_a_shape_irrelevant_dimension_with_row_column_and_value(): void
    {
        $upload = $this->spreadsheetUpload(
            ['material_name', 'shape', 'quantity', 'thickness', 'width', 'd_outer', 'd_inner', 'length'],
            [
                ['SCM440', 'Flat', 2, 1.2, 120, null, null, 240],
                ['SCM440', 'Flat', 2, 1.2, 120, 25, null, 240],
            ]
        );

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $upload])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('rows', []);

        $error = collect($response->json('errors'))->firstWhere('column', 'd_outer');
        $this->assertNotNull($error);
        $this->assertSame(3, $error['row']);
        $this->assertSame('25', (string) $error['value']);
        $this->assertStringContainsString('25', $error['message']);
    }

    public function test_pr_preview_is_all_or_nothing_for_95_valid_and_5_invalid_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['SCM440', 'Round', $i % 20 === 0 ? 0 : 2, null, null, 20, null, 300];
        }
        $upload = $this->spreadsheetUpload(
            ['material_name', 'shape', 'quantity', 'thickness', 'width', 'd_outer', 'd_inner', 'length'],
            $rows
        );

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $upload])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('rows', [])
            ->assertJsonPath('summary.total', 100)
            ->assertJsonPath('summary.invalid', 5);

        $this->assertGreaterThanOrEqual(5, count($response->json('errors')));
    }

    public function test_pr_preview_reports_all_row_errors_without_returning_partial_success(): void
    {
        $upload = $this->spreadsheetUpload([
            'material_name', 'hs_code', 'shape', 'quantity', 'weight_needed', 'remark',
        ], [
            ['SCM440', '1111', 'Round', 2, 10, 'valid'],
            ['SCM440', '2222', 'Round', 0, 10, 'invalid'],
            ['=DANGEROUS()', '3333', 'Round', 2, 12, 'formula'],
        ]);

        // D05: any row error makes the whole preview non-importable.
        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $upload])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.invalid', 2);

        $this->assertSame([], $response->json('rows'));
        $this->assertTrue(collect($response->json('errors'))->contains(
            fn (array $error) => $error['row'] === 3 && $error['column'] === 'quantity'
        ));
        $this->assertTrue(collect($response->json('errors'))->contains(
            fn (array $error) => $error['row'] === 4 && $error['column'] === 'material_name'
        ));
    }

    public function test_file_level_pr_errors_are_structured_and_unprocessable(): void
    {
        $missingHeader = $this->spreadsheetUpload(
            ['material_name', 'shape'],
            [['SCM440', 'Round']]
        );

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $missingHeader])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.row', 1)
            ->assertJsonPath('errors.0.column', 'quantity');

        $empty = $this->spreadsheetUpload(
            ['material_name', 'quantity'],
            []
        );

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $empty])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_draft_pr_edit_keeps_import_controls_and_preview_available_without_writes(): void
    {
        $draftPr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'draft',
            'notes' => 'Draft import test',
        ]);
        $draftPr->items()->create([
            'material_name' => 'Existing draft material',
            'hs_code' => '220011',
            'shape' => PrItem::SHAPE_FLAT,
            'quantity' => 1,
            'thickness' => 1,
            'width' => 100,
            'length' => 200,
            'weight_needed' => 12.5,
        ]);
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload(
            ['material_name', 'quantity'],
            [['SCM440', 2]]
        );

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.edit', $draftPr))
            ->assertOk()
            ->assertSee('Download Template')
            ->assertSee('Import Excel')
            ->assertSee('Replace Current Rows')
            ->assertSee('Append to Current Rows')
            ->assertSee('Removes the material rows currently shown in this form and replaces them with the validated rows from the spreadsheet.')
            ->assertSee('Keeps the material rows currently shown and adds validated spreadsheet rows below them.')
            ->assertSee('dimension-input', false)
            ->assertSee('Thickness (mm)')
            ->assertSee('Outer D. (mm)')
            ->assertSee('Inner D. (mm)')
            ->assertSee('Width (mm)')
            ->assertSee('Length (mm)')
            ->assertSee('data-dimension-field="thickness"', false)
            ->assertDontSee('data-dimension-slot=', false)
            ->assertDontSee('data-dimension-canonical-field=', false)
            ->assertDontSee('Dimension 1')
            ->assertSee('prImportPreviewUrl', false);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('rows.0.material_name', 'SCM440');

        $this->assertSame('draft', $draftPr->fresh()->status);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_rejected_and_final_pr_edit_forms_do_not_expose_import_controls(): void
    {
        $rejectedPr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'rejected',
        ]);
        $rejectedPr->items()->create([
            'material_name' => 'Rejected material',
            'hs_code' => '440033',
            'quantity' => 1,
            'weight_needed' => 10,
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.edit', $rejectedPr))
            ->assertOk()
            ->assertDontSee('Download Template')
            ->assertDontSee('Import Excel')
            ->assertDontSee('prImportPreviewUrl', false);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.edit', $this->pr))
            ->assertRedirect();
    }

    public function test_quotation_preview_maps_only_editable_fields_by_pr_item_id(): void
    {
        $target = $this->pr->items->first();
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload([
            'notes', 'available_width', 'price_per_kg', 'material_name', 'pr_item_id',
            'available_qty', 'requested_dimension',
        ], [[
            'Imported item note', 105, 8.75, 'Spoofed material', $target->id, 2, 'Spoofed dimensions',
        ]]);

        $response = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.valid', 1);

        $row = $response->json('rows.0');
        $this->assertSame($target->id, $row['pr_item_id']);
        $this->assertSame(8.75, $row['price_per_kg']);
        $this->assertSame(2, $row['available_qty']);
        $this->assertEquals(105.0, $row['available_width']);
        $this->assertIsNumeric($row['available_width']);
        $this->assertNull($row['available_d_outer']);
        $this->assertArrayNotHasKey('material_name', $row);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_quotation_preview_rejects_duplicate_cross_pr_items_and_locked_quotations(): void
    {
        $otherPr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/08/2026/502',
            'status' => 'submitted',
        ]);
        $foreignItem = $otherPr->items()->create([
            'material_name' => 'Foreign item',
            'hs_code' => '9999',
            'quantity' => 1,
            'weight_needed' => 10,
        ]);
        $ownItem = $this->pr->items->first();
        $upload = $this->spreadsheetUpload(
            ['pr_item_id', 'price_per_kg'],
            [[$ownItem->id, 5], [$ownItem->id, 6], [$foreignItem->id, 7]]
        );

        $response = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('rows', []);

        $this->assertTrue(collect($response->json('errors'))->contains(
            fn (array $error) => $error['column'] === 'pr_item_id' && str_contains($error['message'], 'duplicate')
        ));
        $this->assertTrue(collect($response->json('errors'))->contains(
            fn (array $error) => $error['column'] === 'pr_item_id' && str_contains($error['message'], 'current PR')
        ));

        $this->actingAs($this->supplierB)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertForbidden();

        $this->supplierA->update(['is_active' => false]);
        $inactiveUpload = $this->spreadsheetUpload(
            ['pr_item_id', 'price_per_kg'],
            [[$ownItem->id, 5]]
        );
        $this->actingAs($this->supplierA->fresh())
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $inactiveUpload])
            ->assertRedirect(route('login'));
        $this->supplierA->update(['is_active' => true]);

        Quotation::create([
            'pr_id' => $this->pr->id,
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14),
            'validity_period' => now()->addDays(30),
            'payment_terms' => 'TT 30 Days',
        ]);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.quotations.create', $this->pr))
            ->assertRedirect();

        $lockedUpload = $this->spreadsheetUpload(
            ['pr_item_id', 'price_per_kg'],
            [[$ownItem->id, 5]]
        );
        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $lockedUpload])
            ->assertForbidden();
    }

    public function test_quotation_preview_normalizes_availability_range_and_offer_weight(): void
    {
        $item = $this->pr->items->firstOrFail();
        $upload = $this->spreadsheetUpload(
            [
                'pr_item_id', 'availability', 'price_per_kg', 'available_qty',
                'available_thickness', 'available_width', 'available_length',
                'offered_weight_per_unit', 'notes',
            ],
            [[$item->id, 'Available', 100, 2, 1.5, 100, '1800-2200', 2.4, 'Range offer']],
        );

        $response = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('rows.0.is_available', true)
            ->assertJsonPath('rows.0.available_length', null)
            ->assertJsonPath('rows.0.available_length_min', 1800)
            ->assertJsonPath('rows.0.available_length_max', 2200)
            ->assertJsonPath('rows.0.offered_weight_per_unit', 2.4);

        $this->assertSame('Available', $response->json('rows.0.availability'));
    }

    public function test_quotation_preview_rejects_invalid_hollow_diameter_pair_without_writes(): void
    {
        $item = $this->pr->items()->create([
            'material_name' => 'Requested Hollow Material',
            'shape' => PrItem::SHAPE_HOLLOW,
            'quantity' => 1,
            'd_inner' => 10,
            'd_outer' => 20,
            'length' => 400,
            'weight_needed' => 2,
        ]);
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload(
            [
                'pr_item_id', 'availability', 'price_per_kg', 'available_qty',
                'available_d_inner', 'available_d_outer', 'available_length',
            ],
            [[$item->id, 'Available', 100, 1, 20, 20, 400]],
        );

        $response = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('summary.valid', 0)
            ->assertJsonPath('summary.invalid', 1);

        $this->assertTrue(collect($response->json('errors'))->contains(
            fn (array $error) => $error['row'] === 2
                && $error['column'] === 'available_d_inner'
                && $error['message'] === 'Inner diameter must be smaller than outer diameter for a Hollow item.'
        ));
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_quotation_preview_rejects_quantity_above_requested_while_surplus_is_disabled(): void
    {
        $item = $this->pr->items->firstOrFail();
        $upload = $this->spreadsheetUpload(
            ['pr_item_id', 'availability', 'price_per_kg', 'available_qty'],
            [[$item->id, 'Available', 100, 3]],
        );

        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('rows', [])
            ->assertJsonPath('summary.invalid', 1);
    }

    public function test_quotation_preview_accepts_surplus_quantity_only_when_both_flags_are_on(): void
    {
        $item = $this->pr->items->firstOrFail();
        $rows = [[$item->id, 'Available', 100, 3]];
        $headings = ['pr_item_id', 'availability', 'price_per_kg', 'available_qty'];

        // Surplus flag alone is inconsistent and fails closed.
        config(['procurement.allow_offer_surplus' => true, 'procurement.ordered_quantity_write_enabled' => false]);
        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $this->spreadsheetUpload($headings, $rows)])
            ->assertUnprocessable();

        config(['procurement.ordered_quantity_write_enabled' => true]);
        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $this->spreadsheetUpload($headings, $rows)])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('rows.0.available_qty', 3);
    }

    public function test_quotation_preview_rejects_a_shape_irrelevant_dimension_with_row_column_and_value(): void
    {
        $flat = $this->pr->items->firstOrFail();
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload(
            ['pr_item_id', 'availability', 'price_per_kg', 'available_qty', 'available_thickness', 'available_d_outer'],
            [[$flat->id, 'Available', 100, 2, 1.5, 25]],
        );

        $response = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('rows', []);

        $this->assertTrue(collect($response->json('errors'))->contains(
            fn (array $error) => $error['row'] === 2
                && $error['column'] === 'available_d_outer'
                && (string) $error['value'] === '25'
        ));
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_quotation_preview_is_all_or_nothing_when_one_row_is_invalid(): void
    {
        [$flat, $round] = $this->pr->items->values()->all();
        $upload = $this->spreadsheetUpload(
            ['pr_item_id', 'availability', 'price_per_kg', 'available_qty'],
            [[$flat->id, 'Available', 100, 2], [$round->id, 'Available', -5, 3]],
        );

        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertUnprocessable()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.invalid', 1);
    }

    public function test_quotation_preview_parses_an_old_column_order_file_identically(): void
    {
        $flat = $this->pr->items->firstOrFail();
        $old = $this->spreadsheetUpload(
            ['pr_item_id', 'price_per_kg', 'available_qty', 'available_thickness', 'available_d_inner', 'available_d_outer', 'available_width', 'available_length'],
            [[$flat->id, 100, 2, 1.5, null, null, 100, 200]],
        );
        $new = $this->spreadsheetUpload(
            ['pr_item_id', 'price_per_kg', 'available_qty', 'available_thickness', 'available_width', 'available_d_outer', 'available_d_inner', 'available_length'],
            [[$flat->id, 100, 2, 1.5, 100, null, null, 200]],
        );

        $oldRows = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $old])
            ->assertOk()->json('rows');
        $newRows = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $new])
            ->assertOk()->json('rows');

        $this->assertSame($newRows, $oldRows);
        $this->assertEquals(100.0, $oldRows[0]['available_width']);
    }

    public function test_not_available_import_warns_and_clears_numeric_offer_values(): void
    {
        $item = $this->pr->items->firstOrFail();
        $upload = $this->spreadsheetUpload(
            [
                'pr_item_id', 'availability', 'price_per_kg', 'available_qty',
                'available_length', 'offered_weight_per_unit', 'notes',
            ],
            [[$item->id, 'Not Available', 100, 2, '2000', 2.4, 'No stock']],
        );

        $response = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('rows.0.is_available', false)
            ->assertJsonPath('rows.0.price_per_kg', null)
            ->assertJsonPath('rows.0.available_qty', null)
            ->assertJsonPath('rows.0.offered_weight_per_unit', null);

        $this->assertNotEmpty($response->json('warnings'));
        $this->assertSame('Not Available', $response->json('rows.0.availability'));
    }

    public function test_first_sheet_only_row_limit_and_extra_sheet_warning_are_enforced(): void
    {
        $extraSheetUpload = $this->spreadsheetUpload(
            ['material_name', 'quantity'],
            [['SCM440', 1]],
            ExcelFormat::XLSX,
            [[
                'title' => 'Ignored',
                'headings' => ['material_name', 'quantity'],
                'rows' => [['S45C', 1]],
            ]]
        );

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $extraSheetUpload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'rows');
        $this->assertTrue(collect($response->json('warnings'))->contains(
            fn (array $warning) => str_contains($warning['message'], 'worksheet')
        ));

        $rows = [];
        for ($index = 1; $index <= 1001; $index++) {
            $rows[] = ['SCM440', 1];
        }
        $tooManyRows = $this->spreadsheetUpload(
            ['material_name', 'quantity'],
            $rows,
            ExcelFormat::CSV
        );

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $tooManyRows])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_xls_is_supported_and_file_type_and_size_guards_are_structured(): void
    {
        $xls = $this->spreadsheetUpload(
            ['material_name', 'quantity'],
            [['SCM440', 2]],
            ExcelFormat::XLS
        );

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $xls])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('rows.0.material_name', 'SCM440');

        $invalidType = UploadedFile::fake()->create('not-a-spreadsheet.pdf', 10, 'application/pdf');
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $invalidType])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.column', 'import_file');

        $oversized = UploadedFile::fake()->create(
            'too-large.xlsx',
            10241,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $oversized])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.column', 'import_file');
    }

    public function test_draft_quotation_import_is_available_and_preview_remains_read_only(): void
    {
        $quotation = Quotation::create([
            'pr_id' => $this->pr->id,
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_DRAFT,
            'estimated_delivery' => now()->addDays(14),
            'validity_period' => now()->addDays(30),
            'payment_terms' => 'TT 30 Days',
        ]);
        $quotationItem = $quotation->items()->create([
            'pr_item_id' => $this->pr->items->first()->id,
            'price_per_kg' => 4.25,
            'amount' => 85,
        ]);
        $attachment = $quotationItem->attachments()->create([
            'file_path' => 'attachments/2026/08/draft-mtc.pdf',
            'file_name' => 'draft-mtc.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $this->supplierA->id,
        ]);
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload(
            ['pr_item_id', 'price_per_kg', 'notes'],
            [[$this->pr->items->first()->id, 9.5, 'Draft preview only']]
        );

        $this->actingAs($this->supplierA)
            ->get(route('supplier.quotations.create', $this->pr))
            ->assertOk()
            ->assertSee('Download Template')
            ->assertSee('Import Excel')
            ->assertSee('Import Mode')
            ->assertSee('Fill Empty Fields Only')
            ->assertSee('Replace Imported Fields')
            ->assertSee('Only fills offer fields that are still empty. Existing values entered in the form are preserved.')
            ->assertSee('Replaces the matching offer fields for the same PR items using values from the spreadsheet. It does not create additional quotation items.')
            ->assertSee('quotationImportPreviewUrl', false);

        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('rows.0.price_per_kg', 9.5);

        $this->assertSame(Quotation::STATUS_DRAFT, $quotation->fresh()->status);
        $this->assertSame('4.2500', $quotationItem->fresh()->price_per_kg);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_revision_requested_preview_preserves_quotation_items_and_mtc_attachment(): void
    {
        $quotation = Quotation::create([
            'pr_id' => $this->pr->id,
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_REVISION_REQUESTED,
            'estimated_delivery' => now()->addDays(14),
            'validity_period' => now()->addDays(30),
            'payment_terms' => 'TT 30 Days',
        ]);
        $quotationItem = $quotation->items()->create([
            'pr_item_id' => $this->pr->items->first()->id,
            'price_per_kg' => 4.25,
            'amount' => 85,
        ]);
        $attachment = $quotationItem->attachments()->create([
            'file_path' => 'attachments/2026/08/existing-mtc.pdf',
            'file_name' => 'existing-mtc.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $this->supplierA->id,
        ]);
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload(
            ['pr_item_id', 'price_per_kg', 'notes'],
            [[$this->pr->items->first()->id, 9.5, 'Preview only']]
        );

        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('rows.0.price_per_kg', 9.5);

        $this->assertSame(Quotation::STATUS_REVISION_REQUESTED, $quotation->fresh()->status);
        $this->assertSame('4.2500', $quotationItem->fresh()->price_per_kg);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_laravel_excel_temporary_files_are_cleaned_after_preview(): void
    {
        $before = $this->excelTemporaryFiles();
        $upload = $this->spreadsheetUpload(
            ['material_name', 'quantity'],
            [['SCM440', 1]]
        );

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $upload])
            ->assertOk();

        clearstatcache();
        $this->assertSame($before, $this->excelTemporaryFiles());
    }

    /** GEN-02: a downloaded template, filled in its own column order, previews back field for field. */
    public function test_quotation_template_round_trips_through_import_preview_in_canonical_order(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gen02-template-').'.xlsx';
        $this->temporaryFiles[] = $path;
        file_put_contents($path, Excel::raw(new QuotationImportTemplateExport($this->pr->id), ExcelFormat::XLSX));

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $columns = [];
        foreach ($sheet->getRowIterator(1, 1)->current()->getCellIterator() as $cell) {
            $columns[(string) $cell->getValue()] = $cell->getColumn();
        }
        $this->assertSame(QuotationImportTemplateExport::CANONICAL_HEADINGS, array_keys($columns));

        $values = [
            2 => ['available_qty' => 2, 'available_thickness' => 1.5, 'available_width' => 100, 'available_length' => 200, 'offered_weight_per_unit' => 10, 'price_per_kg' => 8.75, 'notes' => 'Flat offer'],
            3 => ['available_qty' => 3, 'available_d_outer' => 25, 'available_length' => '590-610', 'offered_weight_per_unit' => 12.5, 'price_per_kg' => 9.5],
        ];
        foreach ($values as $row => $fields) {
            foreach ($fields as $field => $value) {
                $sheet->setCellValue($columns[$field].$row, $value);
            }
        }
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        $before = $this->databaseSnapshot();
        $response = $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), [
                'import_file' => new UploadedFile($path, 'filled.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.valid', 2);

        [$flat, $round] = $response->json('rows');
        $this->assertSame($this->pr->items[0]->id, $flat['pr_item_id']);
        $this->assertEquals(8.75, $flat['price_per_kg']);
        $this->assertSame(2, $flat['available_qty']);
        $this->assertEquals(1.5, $flat['available_thickness']);
        $this->assertEquals(100, $flat['available_width']);
        $this->assertEquals(200, $flat['available_length']);
        $this->assertEquals(10, $flat['offered_weight_per_unit']);
        $this->assertSame('Flat offer', $flat['notes']);
        $this->assertSame($this->pr->items[1]->id, $round['pr_item_id']);
        $this->assertEquals(25, $round['available_d_outer']);
        $this->assertEquals(590, $round['available_length_min']);
        $this->assertEquals(610, $round['available_length_max']);
        $this->assertEquals(9.5, $round['price_per_kg']);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    /** GEN-02: the form's editable fields, the import preview and the template share one order. */
    public function test_quotation_form_preview_and_template_share_the_canonical_order(): void
    {
        $html = $this->actingAs($this->supplierA)->get(route('supplier.quotations.create', $this->pr))->assertOk()->getContent();
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        // Form: first item's editable inputs in DOM order, mapped to template keys.
        $formOrder = [];
        foreach ($xpath->query('//*[starts-with(@name, "items[0][")]') as $input) {
            $field = preg_replace(['/^items\[0\]\[|\]$/', '/_input$/'], '', $input->getAttribute('name'));
            if (in_array($field, QuotationImportTemplateExport::CANONICAL_HEADINGS, true) && ! in_array($field, $formOrder, true)) {
                $formOrder[] = $field;
            }
        }
        // Flat item: pr_item_id (hidden), qty, thickness, width, length, KG/Unit, price, notes.
        $this->assertCount(8, $formOrder, implode(',', $formOrder));
        $templateEditable = array_values(array_intersect(QuotationImportTemplateExport::CANONICAL_HEADINGS, $formOrder));
        $this->assertSame($templateEditable, $formOrder, 'Form inputs follow the template order.');

        $previewHeads = array_map(fn ($th) => trim($th->textContent), iterator_to_array(
            $xpath->query('//tbody[@id="quotationImportPreviewBody"]/ancestor::table[1]//thead//th')
        ));
        $expected = [
            __('supplier.copy.pr_item_id'), __('supplier.copy.material'), __('supplier.copy.offer_qty'),
            ...array_map(fn ($field) => PrItem::dimensionLabel($field), PrItem::FIXED_DIMENSION_ORDER),
            __('supplier.copy.offer_kg_unit'), __('supplier.copy.price_kg'), __('supplier.copy.notes'), __('supplier.copy.availability'),
        ];
        $this->assertSame($expected, $previewHeads);
    }

    /** PUR-01/PUR-02: five dimension columns plus Total KG; header and footer columns stay aligned. */
    public function test_pr_form_and_import_preview_show_dimensions_and_total_kg_columns(): void
    {
        $html = $this->actingAs($this->purchasing)->get(route('purchasing.requisitions.create'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $dimensions = array_map(fn (string $field) => PrItem::dimensionLabel($field), PrItem::FIXED_DIMENSION_ORDER);

        $preview = $xpath->query('//tbody[@id="prImportPreviewBody"]/ancestor::table[1]')->item(0);
        $form = $xpath->query('//tfoot[@id="prFormTotals"]/ancestor::table[1]')->item(0);
        $this->assertNotNull($preview);
        $this->assertNotNull($form);

        $previewHeads = array_map(fn ($th) => trim($th->textContent), iterator_to_array($xpath->query('.//thead//th', $preview)));
        $this->assertSame([...$dimensions, 'KG/Unit', __('procurement.totals.total_kg')], array_slice($previewHeads, 5, 7));

        foreach ([$preview, $form] as $table) {
            $heads = $xpath->query('.//thead//th', $table)->length;
            $footer = 0;
            foreach ($xpath->query('.//tfoot/tr[1]/*', $table) as $cell) {
                $footer += max(1, (int) $cell->getAttribute('colspan'));
            }
            $this->assertSame($heads, $footer, 'Footer cells must span exactly the header columns.');
        }
        $formHeads = array_map(fn ($th) => trim($th->textContent), iterator_to_array($xpath->query('.//thead//th', $form)));
        $this->assertContains(__('procurement.totals.total_kg'), $formHeads);
        $this->assertStringContainsString('data-pr-row-total-kg', $html, 'Row template carries the per-row Total KG cell.');
    }

    public function test_import_routes_and_views_obey_role_guards_and_expose_safe_modes(): void
    {
        $this->get(route('purchasing.requisitions.import-template'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->supplierA)
            ->get(route('purchasing.requisitions.import-template'))
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.create'))
            ->assertOk()
            ->assertSee('Download Template')
            ->assertSee('Import Excel')
            ->assertSee('Replace Current Rows')
            ->assertSee('Append to Current Rows')
            ->assertSee('prImportPreviewUrl', false);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.quotations.create', $this->pr))
            ->assertOk()
            ->assertSee('Import Data')
            ->assertSee('Download Template')
            ->assertSee('Import Excel')
            ->assertSee('dropdown-toggle', false)
            ->assertSee(route('supplier.quotations.import-template', $this->pr), false)
            ->assertSee('Fill Empty Fields Only')
            ->assertSee('Replace Imported Fields')
            ->assertSee('quotationImportPreviewUrl', false)
            ->assertSee('autoCalculateOfferWeight($formRow)', false)
            ->assertSee('field === \'offered_weight_per_unit\'', false);
    }

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, array{title: string, headings: array<int, string>, rows: array<int, array<int, mixed>>}>  $extraSheets
     */
    private function spreadsheetUpload(
        array $headings,
        array $rows,
        string $format = ExcelFormat::XLSX,
        array $extraSheets = []
    ): UploadedFile {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($headings, null, 'A1');
        if ($rows !== []) {
            $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A2');
        }

        foreach ($extraSheets as $extraSheet) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($extraSheet['title']);
            $sheet->fromArray($extraSheet['headings'], null, 'A1');
            if ($extraSheet['rows'] !== []) {
                $sheet->fromArray($extraSheet['rows'], null, 'A2');
            }
        }

        [$writerType, $extension, $mime] = match ($format) {
            ExcelFormat::CSV => ['Csv', 'csv', 'text/csv'],
            ExcelFormat::XLS => ['Xls', 'xls', 'application/vnd.ms-excel'],
            default => ['Xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        };
        $path = tempnam(sys_get_temp_dir(), 'mission-five-import-').'.'.$extension;
        IOFactory::createWriter($spreadsheet, $writerType)->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, 'mission-five-import.'.$extension, $mime, null, true);
    }

    /** @return array<string, int> */
    private function databaseSnapshot(): array
    {
        return [
            'purchase_requisitions' => DB::table('purchase_requisitions')->count(),
            'pr_items' => DB::table('pr_items')->count(),
            'quotations' => DB::table('quotations')->count(),
            'quotation_items' => DB::table('quotation_items')->count(),
            'attachments' => DB::table('attachments')->count(),
            'notifications' => DB::table('notifications')->count(),
        ];
    }

    /** @return array<int, string> */
    private function excelTemporaryFiles(): array
    {
        $files = array_merge(
            glob(storage_path('framework/cache/laravel-excel/laravel-excel-*')) ?: [],
            glob(storage_path('framework/cache/import-previews/import-*')) ?: []
        );
        sort($files);

        return array_values($files);
    }
}
