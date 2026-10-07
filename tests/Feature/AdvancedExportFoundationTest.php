<?php

namespace Tests\Feature;

use App\Exports\InspectionsExport;
use App\Exports\PaymentBatchDrpExport;
use App\Exports\PaymentBatchTransferExport;
use App\Exports\RequisitionsExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\ExportProgressService;
use App\Support\Export\ExportOptions;
use App\Support\ExportDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdvancedExportFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_are_scalar_serializable_and_preserve_order(): void
    {
        $options = new ExportOptions(['status', 'po_number'], 'csv', 'supplier');
        $restored = unserialize(serialize($options));
        $this->assertSame($options->toArray(), $restored->toArray());
        $this->assertSame($options->toArray(), ExportOptions::fromArray($options->toArray())->toArray());
        $this->assertSame(100000, config('exports.max_rows'));
        $this->assertSame(3, config('exports.max_concurrent_per_user'));
        $this->assertSame(20, config('exports.max_presets_per_key'));
    }

    public static function invalidOptions(): array
    {
        return [
            'format' => [['status'], 'pdf', 'purchasing'],
            'audience' => [['status'], 'csv', 'guest'],
            'sql key' => [['status;drop'], 'xlsx', 'purchasing'],
            'duplicates' => [['status', 'status'], 'xlsx', 'purchasing'],
            'non scalar' => [[new \stdClass], 'xlsx', 'purchasing'],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_are_rejected(array $columns, string $format, string $audience): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExportOptions($columns, $format, $audience);
    }

    public function test_dispatch_defaults_stay_xlsx_and_csv_options_are_stored_separately(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($owner);
        $legacy = ExportDispatcher::dispatch('Legacy', RequisitionsExport::class, [null, null, null], 'legacy.xlsx');
        $this->assertSame('xlsx', $legacy->format);
        $this->assertNull($legacy->export_options);
        $this->assertSame('legacy.xlsx', $legacy->file_name);
        $options = new ExportOptions(['pr_number'], 'csv');
        $csv = ExportDispatcher::dispatch('CSV', RequisitionsExport::class, [null, null, null], '../../report.xlsx', $options);
        $this->assertSame('csv', $csv->format);
        $this->assertSame('report.csv', $csv->file_name);
        $this->assertSame($options->toArray(), $csv->export_options);
        $this->assertSame([null, null, null], $csv->export_args);
        Queue::assertPushed(ProcessExportJob::class, 2);
    }

    public function test_fixed_workbooks_reject_csv_without_creating_a_record(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        foreach ([PaymentBatchDrpExport::class, PaymentBatchTransferExport::class] as $class) {
            try {
                ExportDispatcher::dispatch('Fixed', $class, [[]], 'fixed.xlsx', new ExportOptions([], 'csv', 'finance'));
                $this->fail('Fixed workbooks must not accept CSV.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Fixed workbooks', $exception->getMessage());
            }
        }
        $this->assertSame(0, ExportJob::count());
        Queue::assertNothingPushed();
    }

    public function test_worker_fails_closed_for_export_without_options_contract(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'purchasing']));
        $record = ExportDispatcher::dispatch('CSV', InspectionsExport::class, [], 'report.csv', new ExportOptions(['po_number'], 'csv', 'qc'));
        app()->setLocale('en');
        try {
            (new ProcessExportJob($record->id, 'id'))->handle(app(ExportProgressService::class));
            $this->fail('An export without the options contract must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The export does not accept these options.', $exception->getMessage());
        }
        $this->assertSame('en', app()->getLocale());
        $this->assertSame(ExportJob::STATUS_QUEUED, $record->fresh()->status);
        $this->assertNull($record->fresh()->file_path);
    }

    public function test_csv_download_headers_owner_isolation_history_and_cleanup(): void
    {
        Queue::fake();
        Storage::fake('private');
        $owner = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($owner);
        $record = ExportDispatcher::dispatch('CSV', RequisitionsExport::class, [], 'report.csv', new ExportOptions(['pr_number'], 'csv'));
        $path = 'exports/'.$owner->id.'/'.$record->id.'/report.csv';
        $record->forceFill(['status' => 'completed', 'file_path' => $path, 'completed_at' => now(), 'expires_at' => now()->addDay()])->save();
        Storage::disk('private')->put($path, "\xEF\xBB\xBFPR Number\nREQ/08/2026/001\n");
        $this->get(route('exports.download', $record))->assertOk()->assertDownload('report.csv')
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('exports.index'))->assertOk()->assertSeeText('CSV');
        $this->getJson(route('exports.index'))->assertJsonFragment(['format' => 'csv', 'file_name' => 'report.csv']);
        $this->actingAs(User::factory()->create(['role' => 'purchasing']))->get(route('exports.download', $record))->assertForbidden();
        $record->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->artisan('exports:cleanup')->assertSuccessful();
        $this->assertDatabaseMissing('export_jobs', ['id' => $record->id]);
        Storage::disk('private')->assertMissing($path);
    }

    public function test_rollback_guard_preserves_csv_metadata(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'purchasing']));
        ExportDispatcher::dispatch('CSV', RequisitionsExport::class, [], 'report.csv', new ExportOptions(['pr_number'], 'csv'));
        $migration = require database_path('migrations/2026_10_07_000001_add_advanced_options_to_export_jobs.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove advanced export metadata');
        $migration->down();
    }
}
