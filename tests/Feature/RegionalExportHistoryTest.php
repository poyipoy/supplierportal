<?php

namespace Tests\Feature;

use App\Exports\RequisitionsExport;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegionalExportHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_formatter_matches_the_shared_explicit_timestamp_fixtures(): void
    {
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/regional-timestamp-cases.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($cases as $case) {
            $formatter = new RegionalDisplayFormatter([
                ...config('user_preferences.defaults'),
                ...$case['preferences'],
            ]);

            $this->assertSame(
                $case['expected'],
                $formatter->timestamp(new CarbonImmutable($case['value']), 'datetime'),
                $case['name'],
            );
        }
    }

    public function test_export_history_ssr_keeps_legacy_system_output_and_formats_explicit_regional_timestamps(): void
    {
        $user = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $job = $this->createJob($user, '2026-09-28T23:35:00Z', '2026-09-29T00:35:00Z', '2026-10-02T23:35:00Z');

        $system = $this->actingAs($user)->get(route('exports.index'))->assertOk()->getContent();
        $systemBody = $this->tableBody($system);
        $this->assertStringContainsString('28 Sep 2026 23:35', $systemBody);
        $this->assertStringContainsString('Completed: 29 Sep 2026 00:35', $systemBody);
        $this->assertStringContainsString('Expires: 02 Oct 2026 23:35', $systemBody);

        $user->preference()->updateOrCreate([], [
            ...config('user_preferences.defaults'),
            'number_format' => 'indonesian',
        ]);
        app()->forgetScopedInstances();
        $numberOnly = $this->actingAs($user)->get(route('exports.index'))->assertOk()->getContent();
        $numberOnlyBody = $this->tableBody($numberOnly);
        $this->assertStringContainsString('28 Sep 2026 23:35', $numberOnlyBody);
        $this->assertStringNotContainsString('WIB', $numberOnlyBody);

        $user->preference()->updateOrCreate([], [
            ...config('user_preferences.defaults'),
            'timezone' => 'Asia/Jakarta', 'date_format' => 'iso', 'time_format' => '12h',
        ]);
        app()->forgetScopedInstances();

        $this->createJob($user, '2026-09-29T00:35:00Z');

        $regional = $this->actingAs($user)->get(route('exports.index'))->assertOk()->getContent();
        $regionalBody = $this->tableBody($regional);
        $this->assertStringContainsString('2026-09-29 6:35 AM WIB', $regionalBody);
        $this->assertStringContainsString('Completed: 2026-09-29 7:35 AM WIB', $regionalBody);
        $this->assertStringContainsString('Expires: 2026-10-03 6:35 AM WIB', $regionalBody);
        $this->assertSame(1, substr_count($regionalBody, 'Completed:'));
        $this->assertSame(1, substr_count($regionalBody, 'Expires:'));
    }

    public function test_polling_keeps_raw_iso_contract_and_owner_only_lifecycle_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30T12:00:00Z'));
        Storage::fake('private');
        $owner = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $foreign = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $job = $this->createJob($owner, '2026-09-28T23:35:00Z', '2026-09-29T00:35:00Z', '2026-10-02T23:35:00Z');
        $filePath = 'exports/'.$owner->id.'/regional.xlsx';
        $job->forceFill([
            'status' => ExportJob::STATUS_COMPLETED,
            'progress_stage' => ExportJob::STAGE_COMPLETED,
            'progress' => 100,
            'processed_rows' => 3,
            'total_rows' => 3,
            'file_path' => $filePath,
        ])->save();
        Storage::disk('private')->put($filePath, 'xlsx fixture');

        $payload = $this->actingAs($owner)->getJson(route('exports.index'))->assertOk();
        $item = collect($payload->json('data'))->firstWhere('id', $job->getRouteKey());
        $this->assertSame($job->fresh()->created_at->toIso8601String(), $item['created_at']);
        $this->assertSame($job->fresh()->completed_at->toIso8601String(), $item['completed_at']);
        $this->assertSame($job->fresh()->expires_at->toIso8601String(), $item['expires_at']);
        $this->assertSame(100, $item['progress']);
        $this->assertSame(3, $item['processed_rows']);
        $this->assertSame(3, $item['total_rows']);
        $this->assertSame(route('exports.download', $job, absolute: false), $item['download_url']);

        $this->actingAs($foreign)->getJson(route('exports.status', $job))->assertForbidden();
        $this->actingAs($foreign)->get(route('exports.download', $job))->assertForbidden();
    }

    public function test_export_history_resolves_preferences_once_for_one_or_twenty_rows(): void
    {
        $user = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->createJob($user, '2026-09-28T23:35:00Z');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($user)->get(route('exports.index'))->assertOk();
        $singleRowLookups = count($queries);

        for ($index = 1; $index < 20; $index++) {
            $this->createJob($user, '2026-09-28T23:35:00Z');
        }
        $queries = [];
        app()->forgetScopedInstances();
        $this->actingAs($user)->get(route('exports.index'))->assertOk();

        $this->assertSame(1, $singleRowLookups);
        $this->assertCount(1, $queries);
    }

    private function createJob(User $owner, string $createdAt, ?string $completedAt = null, ?string $expiresAt = null): ExportJob
    {
        $job = ExportJob::create([
            'user_id' => $owner->id,
            'label' => 'Regional history export',
            'export_class' => RequisitionsExport::class,
            'export_args' => [null, null, null],
            'file_name' => 'regional.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
        ]);

        $job->forceFill([
            'created_at' => new CarbonImmutable($createdAt),
            'completed_at' => $completedAt === null ? null : new CarbonImmutable($completedAt),
            'expires_at' => $expiresAt === null ? null : new CarbonImmutable($expiresAt),
        ])->save();

        return $job->fresh();
    }

    private function tableBody(string $html): string
    {
        $this->assertSame(1, preg_match('/<tbody id="exportJobsTableBody">(.*?)<\/tbody>/s', $html, $matches));

        return $matches[1];
    }
}
