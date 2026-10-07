<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrdersExport;
use App\Models\ExportJob;
use App\Models\User;
use App\Support\ExportDispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdvancedExportConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_two_connections_cannot_claim_the_last_list_export_slot(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($actor);
        for ($i = 0; $i < 4; $i++) {
            ExportDispatcher::dispatch('Existing', PurchaseOrdersExport::class, [], 'existing.xlsx');
        }
        $start = (string) (microtime(true) + 2);
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $processes[] = new Process([PHP_BINARY, base_path('tests/Support/advanced-export-concurrency-worker.php'), (string) $actor->id, $start], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'adasi_portal_test', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '',
            ], timeout: 30);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        $this->assertEqualsCanonicalizing(['accepted', 'rejected'], array_map(fn ($p) => trim($p->getOutput()), $processes));
        $this->assertSame(5, ExportJob::where('user_id', $actor->id)->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'exports')->count());
    }
}
