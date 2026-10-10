<?php

namespace Tests\Unit;

use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Exports\SupplierPriceHistoryExport;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Sheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalWorkbookPresentationTest extends TestCase
{
    private const PROFILES = [
        'pr' => [[18, 16, 22, 24, 10, 14, 14, 14, 24, 18, 20], ['C', 'D', 'I', 'J']],
        'po' => [[18, 22, 22, 24, 10, 18, 18, 14, 24, 18], ['B', 'C', 'D', 'I', 'J']],
        'quotation' => [[18, 16, 22, 10, 22, 14, 12, 24, 12, 24, 18, 18, 16, 18, 24, 18, 20, 18, 16, 16, 18, 16, 18, 18], ['C', 'E', 'H', 'J', 'O', 'P']],
        'shipment' => [[18, 22, 24, 10, 10, 16, 14, 14, 14, 18, 24], ['B', 'C', 'J', 'K']],
        'qc' => [[18, 22, 22, 24, 24, 10, 14, 20], ['B', 'C', 'D', 'E', 'F', 'G']],
        'history-monthly' => [[18, 14, 18, 18, 10, 12], ['C']],
        'history-yearly' => [[10, 18, 18, 18, 10, 12], []],
    ];

    public static function workbookCases(): array
    {
        $cases = [];
        foreach (array_keys(self::PROFILES) as $kind) {
            foreach (['en', 'id'] as $locale) {
                foreach ([false, true] as $advanced) {
                    foreach ([false, true] as $empty) {
                        $cases[$kind.'-'.$locale.'-'.(int) $advanced.'-'.(int) $empty] = [$kind, $locale, $advanced, $empty];
                    }
                }
            }
        }

        return $cases;
    }

    private function export(string $kind): object
    {
        return match ($kind) {
            'pr' => new RequisitionsExport,
            'po' => new PurchaseOrdersExport,
            'quotation' => new QuotationsExport,
            'shipment' => new ShipmentsExport,
            'qc' => new InspectionsExport,
            'history-monthly', 'history-yearly' => new SupplierPriceHistoryExport(1, substr($kind, 8), 'Sample Steel', null),
        };
    }

    private function audience(string $kind): string
    {
        return $kind === 'qc' ? 'qc' : (str_starts_with($kind, 'history-') ? 'supplier' : 'purchasing');
    }

    private function render(object $export, bool $empty = false): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = new Sheet($book->getActiveSheet());
        $sheet->open($export);
        if (! $empty) {
            $values = array_fill(0, count($export->headings()), 'Long material description and specifications '.str_repeat('steel ', 16));
            $values[0] = SpreadsheetCellSanitizer::text('=Untrusted reference');
            $values[1] = 123456789012.25;
            $book->getActiveSheet()->fromArray([$values], null, 'A2', true);
        }
        $sheet->close($export);

        return $book;
    }

    #[DataProvider('workbookCases')]
    public function test_xlsx_round_trip_preserves_cells_and_applies_approved_presentation(string $kind, string $locale, bool $advanced, bool $empty): void
    {
        app()->setLocale($locale);
        $export = $this->export($kind);
        if ($advanced) {
            $definition = ExportDefinitions::forClass($export::class, $this->audience($kind));
            $export->applyOptions(new ExportOptions(ExportDefinitions::defaultKeys($definition), 'xlsx', $this->audience($kind)));
        }
        $this->assertInstanceOf(WithStyles::class, $export);
        $this->assertNotInstanceOf(ShouldAutoSize::class, $export);
        $headings = $export->headings();
        $serialized = serialize($export);
        $book = $this->render($export, $empty);
        $before = $book->getActiveSheet()->toArray();
        $path = tempnam(sys_get_temp_dir(), 'adasi-xlsx-');
        try {
            (new Xlsx($book))->save($path);
            $restored = IOFactory::load($path);
            $sheet = $restored->getActiveSheet();
            $this->assertSame($headings, $sheet->toArray()[0]);
            $this->assertSame($before, $sheet->toArray());
            $this->assertSame($empty ? 1 : 2, $sheet->getHighestDataRow());
            $this->assertEquals(24, $sheet->getRowDimension(1)->getRowHeight('px'));
            $this->assertEquals(18, $sheet->getRowDimension(1)->getRowHeight());
            if (! $empty) {
                $this->assertEquals(-1, $sheet->getRowDimension(2)->getRowHeight());
            }
            [$widths, $wrapped] = self::PROFILES[$kind];
            $this->assertEquals($widths, array_values($export->columnWidths()));
            foreach ($widths as $index => $width) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $this->assertEquals($width, $sheet->getColumnDimension($column)->getWidth());
                $this->assertFalse($sheet->getColumnDimension($column)->getAutoSize());
                $header = $sheet->getStyle($column.'1');
                $this->assertSame('9C4A0F', $header->getFill()->getStartColor()->getRGB());
                $this->assertSame('FFFFFF', $header->getFont()->getColor()->getRGB());
                $this->assertTrue($header->getFont()->getBold());
                $this->assertEquals(11, $header->getFont()->getSize());
                $this->assertSame('center', $header->getAlignment()->getHorizontal());
                $this->assertSame('center', $header->getAlignment()->getVertical());
                $this->assertFalse($header->getAlignment()->getWrapText());
                $this->assertTrue($header->getAlignment()->getShrinkToFit());
                if (! $empty) {
                    $this->assertSame(in_array($column, $wrapped, true), $sheet->getStyle($column.'2')->getAlignment()->getWrapText());
                    if (in_array($column, $wrapped, true)) {
                        $this->assertSame('top', $sheet->getStyle($column.'2')->getAlignment()->getVertical());
                    }
                }
            }
            $this->assertSame($serialized, serialize($export), 'Styling must not retain worksheet or callback state.');
            $this->assertSame($export->columnWidths(), unserialize($serialized)->columnWidths());
            $restored->disconnectWorksheets();
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public static function subsetCases(): array
    {
        return [
            ['pr', ['remark', 'pr_number', 'qty', 'material_name'], [24, 18, 10, 22], ['A', 'D']],
            ['po', ['remark', 'po_number', 'currency', 'supplier'], [24, 18, 10, 22], ['A', 'D']],
            ['quotation', ['item_notes', 'pr_number', 'currency', 'requested_dimensions'], [24, 18, 10, 24], ['A', 'D']],
            ['shipment', ['notes_remarks', 'shipment_number', 'items_count', 'supplier'], [24, 18, 10, 22], ['A', 'D']],
            ['qc', ['requested_specification', 'po_number', 'item_status', 'material'], [24, 18, 10, 22], ['A', 'C', 'D']],
        ];
    }

    #[DataProvider('subsetCases')]
    public function test_reordered_subsets_follow_column_keys_and_csv_remains_identical(string $kind, array $keys, array $widths, array $wrapped): void
    {
        $export = $this->export($kind);
        $export->applyOptions(new ExportOptions($keys, 'xlsx', $this->audience($kind)));
        $book = $this->render($export);
        $this->assertEquals($widths, array_values($export->columnWidths()));
        foreach ($widths as $index => $width) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $this->assertEquals($width, $book->getActiveSheet()->getColumnDimension($column)->getWidth());
            $this->assertSame(in_array($column, $wrapped, true), $book->getActiveSheet()->getStyle($column.'2')->getAlignment()->getWrapText());
        }
        $book->disconnectWorksheets();
        $export->applyOptions(new ExportOptions($keys, 'csv', $this->audience($kind)));
        $book = new Spreadsheet;
        $sheet = new Sheet($book->getActiveSheet());
        $sheet->open($export);
        $book->getActiveSheet()->fromArray([[SpreadsheetCellSanitizer::text('=Untrusted reference'), 123456789012.25, 'USD', 'A long note, with punctuation']], null, 'A2', true);
        $writer = new Csv($book);
        $writer->setUseBOM(true);
        $path = tempnam(sys_get_temp_dir(), 'adasi-csv-');
        try {
            $writer->save($path);
            $before = file_get_contents($path);
            $sheet->close($export);
            $writer->save($path);
            $this->assertSame($before, file_get_contents($path));
            $this->assertSame([], $export->columnWidths());
            $this->assertSame([], $export->styles($book->getActiveSheet()));
            $this->assertStringStartsWith("\xEF\xBB\xBF", $before);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_local_invoice_styling_is_outside_scope(): void
    {
        $this->assertNotInstanceOf(WithStyles::class, new LocalInvoicesExport(1));
    }
}
