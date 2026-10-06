<?php

namespace Tests\Feature;

use App\Exports\RequisitionsExport;
use App\Jobs\GenerateWorkbookJob;
use App\Jobs\ProcessExportJob;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Jobs\Middleware\LocalizeJob;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LocalizationExportTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_generated_xlsx_headings_follow_locale_without_changing_column_contract(): void
    {
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            $export = new RequisitionsExport;
            $export->setExportLocale($locale);
            $path = tempnam(sys_get_temp_dir(), 'phase7-xlsx-');
            try {
                file_put_contents($path, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
                $workbook = IOFactory::load($path);
                $sheet = $workbook->getActiveSheet();
                $this->assertSame(__('exports.headings.pr_number', [], $locale), $sheet->getCell('A1')->getValue());
                $this->assertSame(__('exports.headings.weight_unit', [], $locale), $sheet->getCell('F1')->getValue());
                $this->assertSame('K', $sheet->getHighestColumn());
                $this->assertSame(1, $sheet->getHighestRow());
                $workbook->disconnectWorksheets();
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_export_locale_survives_serialization_and_legacy_jobs_default_to_english(): void
    {
        $job = unserialize(serialize(new ProcessExportJob(123, 'id')));
        $this->assertSame('id', $job->locale);
        $this->assertSame('en', (new ProcessExportJob(123))->locale);
        $this->assertSame('id', unserialize(serialize(new GenerateWorkbookJob(123, 'id')))->locale);
        $export = new RequisitionsExport;
        $this->assertInstanceOf(HasLocalePreference::class, $export);
        $export->setExportLocale('id');
        $this->assertSame('id', unserialize(serialize($export))->preferredLocale());
        $export->setExportLocale('../../id');
        $this->assertSame('en', $export->preferredLocale());
    }

    public function test_native_excel_localization_restores_locale_after_failed_chunk(): void
    {
        $export = new RequisitionsExport;
        $export->setExportLocale('id');
        app()->setLocale('en');
        try {
            (new LocalizeJob($export))->handle(new \stdClass, function () {
                $this->assertSame('id', app()->getLocale());
                throw new \RuntimeException('Simulated chunk failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated chunk failure', $exception->getMessage());
        }
        $this->assertSame('en', app()->getLocale());
    }
}
