<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UserPreferenceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_first_saves_create_one_row_and_increment_revision(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $start = microtime(true) + 2;
        $workerPath = base_path('tests/Feature/_user-preference-concurrency-worker.php');
        $processes = [
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'dark', (string) $start, 'slate'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'light', (string) $start, 'brand'], base_path(), ['APP_ENV' => 'testing']),
        ];

        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('saved', trim($process->getOutput()));
        }

        $this->assertSame(1, $user->preference()->count());
        $this->assertSame(2, $user->fresh()->preference->revision);
        $this->assertContains($user->fresh()->preference->theme, ['dark', 'light']);
        $this->assertContains($user->fresh()->preference->accent, ['brand', 'slate']);
        $this->assertSame(1, $user->fresh()->preference->sidebar_revision);
        $this->assertSame(['admin.notifications'], $user->fresh()->preference->dashboard_preferences['admin']['hidden']);
    }
}
