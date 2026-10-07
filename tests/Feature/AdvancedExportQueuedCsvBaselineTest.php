<?php

namespace Tests\Feature;

use App\Exports\RequisitionsExport;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvancedExportQueuedCsvBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public static function locales(): array
    {
        return ['en' => ['en'], 'id' => ['id']];
    }

    #[DataProvider('locales')]
    public function test_csv_queue_keeps_one_bom_and_heading_across_three_query_chunks(string $locale): void
    {
        config(['queue.default' => 'sync', 'app.business_timezone' => 'Asia/Jakarta']);
        app()->setLocale($locale);
        Storage::fake('private');
        $user = User::factory()->create(['role' => 'purchasing']);
        $period = Period::create(['name' => 'CSV Period', 'month' => 8, 'year' => 2026, 'status' => 'open', 'created_by' => $user->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $user->id, 'pr_number' => 'REQ/08/2026/902', 'status' => 'submitted']);
        $pr->forceFill(['created_at' => '2026-08-15 18:30:00'])->save();
        for ($index = 0; $index < 1001; $index++) {
            $pr->items()->create(['material_name' => "=Acier é, \"inox\"\nline {$index}", 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000, 'weight_needed' => 100, 'remark' => '@CSV note']);
        }
        $export = new PhaseZeroCsvRequisitionsExport($period->id);
        $export->setExportLocale($locale);
        $this->assertSame(500, $export->chunkSize());
        $this->assertSame(1001, $export->querySize());
        $path = 'exports/phase-zero/'.$locale.'.csv';
        // This runs the actual serialized Laravel Excel job chain via the sync driver.
        $pending = Excel::queue($export, $path, 'private', ExcelFormat::CSV)->allOnQueue('exports');
        unset($pending);
        Storage::disk('private')->assertExists($path);
        $contents = Storage::disk('private')->get($path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertSame(1, substr_count($contents, "\xEF\xBB\xBF"));
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, substr($contents, 3));
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
            $headings = $locale === 'en'
                ? ['PR Number', 'Period', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'PR Total KG', 'Remark', 'Status', 'Date Created (WIB)']
                : ['Nomor PR', 'Periode', 'Nama Material', 'Spesifikasi', 'Jumlah', 'Berat/Unit', 'Total Berat', 'Total KG PR', 'Catatan', 'Status', 'Tanggal Dibuat (WIB)'];
            $this->assertCount(1002, $rows);
            $this->assertSame($headings, array_shift($rows));
            $this->assertNotContains($headings, $rows);
            foreach ($rows as $index => $row) {
                $this->assertSame(['REQ/08/2026/902', 'CSV Period (08/2026)', "'=Acier é, \"inox\"\nline {$index}", 'Flat | 2.5 × 1000 × 2000', '2', '100', '200', '200200', "'@CSV note", $locale === 'en' ? 'Submitted' : 'Diajukan', '2026-08-16 01:30:00'], $row, "CSV row {$index}");
            }
        } finally {
            fclose($stream);
        }
    }
}

// Test-only configuration exercises the installed writer without changing production exports.
class PhaseZeroCsvRequisitionsExport extends RequisitionsExport implements WithCustomCsvSettings
{
    public function getCsvSettings(): array
    {
        return ['delimiter' => ',', 'enclosure' => '"', 'use_bom' => true, 'input_encoding' => 'UTF-8', 'output_encoding' => 'UTF-8'];
    }
}
