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
        $this->assertSame([
            'material_name', 'shape', 'quantity', 'thickness', 'd_inner',
            'd_outer', 'width', 'length', 'remark',
        ], $prTemplate->headings());
        $this->assertCount(1, $prTemplate->array());

        $quotationTemplate = new QuotationImportTemplateExport($this->pr->id);
        $this->assertSame([
            'pr_item_id', 'material_name', 'requested_dimension', 'price_per_kg',
            'available_qty', 'available_thickness', 'available_d_inner',
            'available_d_outer', 'available_width', 'available_length', 'notes',
            'availability', 'offered_weight_per_unit',
        ], $quotationTemplate->headings());
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

    public function test_pr_preview_parses_reordered_columns_sanitizes_shape_and_never_writes_database(): void
    {
        $before = $this->databaseSnapshot();
        $upload = $this->spreadsheetUpload([
            'quantity', 'material_name', 'weight_needed', 'shape', 'hs_code',
            'thickness', 'd_outer', 'length', 'remark',
        ], [[
            4, 'SCM440', 15.75, 'Round', '00990011', 9.9, 30, 500, 'Imported remark',
        ]]);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), [
                'import_file' => $upload,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.invalid', 0);

        $row = $response->json('rows.0');
        $this->assertSame(4, $row['quantity']);
        $this->assertEquals(2.7752, $row['weight_needed']);
        $this->assertNull($row['thickness']);
        $this->assertEquals(30.0, $row['d_outer']);
        $this->assertIsNumeric($row['d_outer']);
        $this->assertSame('7228.30.10', $row['hs_code']);
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertSame($before, $this->databaseSnapshot());
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

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.import-preview'), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.invalid', 2);

        $this->assertCount(1, $response->json('rows'));
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
            ->assertSee('data-dimension-slot="1"', false)
            ->assertSee('data-dimension-canonical-field="thickness"', false)
            ->assertDontSee('dimension-source-input', false)
            ->assertDontSee('data-active-dimension-field', false)
            ->assertSee('Dimension 1')
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
            'available_qty', 'available_d_outer', 'requested_dimension',
        ], [[
            'Imported item note', 105, 8.75, 'Spoofed material', $target->id, 2, 999, 'Spoofed dimensions',
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
            ->assertOk()
            ->assertJsonPath('success', false);

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

    public function test_quotation_preview_rejects_explicit_quantity_above_requested(): void
    {
        $item = $this->pr->items->firstOrFail();
        $upload = $this->spreadsheetUpload(
            ['pr_item_id', 'availability', 'price_per_kg', 'available_qty'],
            [[$item->id, 'Available', 100, 3]],
        );

        $this->actingAs($this->supplierA)
            ->post(route('supplier.quotations.import-preview', $this->pr), ['import_file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('summary.invalid', 1);
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
            ->assertSee('quotationImportPreviewUrl', false);
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
