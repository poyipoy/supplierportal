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

    public function test_concurrent_locale_and_notification_first_saves_preserve_both_scopes(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $start = microtime(true) + 2;
        $worker = base_path('tests/Feature/_user-preference-concurrency-worker.php');
        $processes = [
            new Process([PHP_BINARY, $worker, (string) $user->id, 'false', (string) $start, 'brand', 'notifications'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $worker, (string) $user->id, 'dark', (string) $start, 'teal', 'customization', 'id'], base_path(), ['APP_ENV' => 'testing']),
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
        $saved = $user->fresh()->preference;
        $this->assertSame('id', $saved->locale);
        $this->assertSame('teal', $saved->accent);
        $this->assertSame(['local_invoice_submitted' => false], $saved->notification_preferences);
        $this->assertSame(2, $saved->revision);
    }

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    protected function tearDown(): void
    {
        $this->beforeTruncatingDatabase();
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_notification_reset_and_customization_save_preserve_both_updates(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $preference = $user->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta']);
        $preference->forceFill(['notification_preferences' => ['local_invoice_submitted' => false], 'revision' => 3])->save();
        $start = microtime(true) + 2;
        $worker = base_path('tests/Feature/_user-preference-concurrency-worker.php');
        $processes = [
            new Process([PHP_BINARY, $worker, (string) $user->id, 'true', (string) $start, 'brand', 'notification-reset'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $worker, (string) $user->id, 'dark', (string) $start, 'slate'], base_path(), ['APP_ENV' => 'testing']),
        ];
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('saved', trim($process->getOutput()));
        }
        $saved = $preference->fresh();
        $this->assertNull($saved->notification_preferences);
        $this->assertSame('dark', $saved->theme);
        $this->assertSame('slate', $saved->accent);
        $this->assertSame('Asia/Jakarta', $saved->timezone);
        $this->assertSame(5, $saved->revision);
        $this->assertSame(1, $user->preference()->count());
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

    public function test_concurrent_notification_and_customization_first_saves_both_survive(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $start = microtime(true) + 2;
        $workerPath = base_path('tests/Feature/_user-preference-concurrency-worker.php');
        $processes = [
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'false', (string) $start, 'brand', 'notifications'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'dark', (string) $start, 'slate'], base_path(), ['APP_ENV' => 'testing']),
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
        $preference = $user->fresh()->preference;
        $this->assertSame(['local_invoice_submitted' => false], $preference->notification_preferences);
        $this->assertSame('dark', $preference->theme);
        $this->assertSame('slate', $preference->accent);
        $this->assertSame(2, $preference->revision);
        $this->assertSame(1, $preference->sidebar_revision);
    }

    public function test_concurrent_notification_reenable_preserves_customization_and_existing_regional_preferences(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $preference = $user->preference()->create([
            ...config('user_preferences.defaults'),
            'theme' => 'light',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'dmy',
            'time_format' => '12h',
            'number_format' => 'indonesian',
        ]);
        $preference->forceFill([
            'notification_preferences' => ['local_invoice_submitted' => false],
            'revision' => 7,
            'sidebar_revision' => 4,
        ])->save();
        $start = microtime(true) + 2;
        $workerPath = base_path('tests/Feature/_user-preference-concurrency-worker.php');
        $processes = [
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'true', (string) $start, 'brand', 'notifications'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'dark', (string) $start, 'slate'], base_path(), ['APP_ENV' => 'testing']),
        ];

        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('saved', trim($process->getOutput()));
        }

        $preference = $preference->fresh();
        $this->assertNull($preference->notification_preferences);
        $this->assertSame('dark', $preference->theme);
        $this->assertSame('slate', $preference->accent);
        $this->assertSame('Asia/Jakarta', $preference->timezone);
        $this->assertSame('dmy', $preference->date_format);
        $this->assertSame('12h', $preference->time_format);
        $this->assertSame('indonesian', $preference->number_format);
        $this->assertSame(9, $preference->revision);
        $this->assertSame(4, $preference->sidebar_revision);
    }

    public function test_concurrent_accent_customization_and_notification_save_preserve_both(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $preference = $user->preference()->create([
            ...config('user_preferences.defaults'),
            'theme' => 'light',
            'accent' => 'brand',
        ]);
        $preference->forceFill([
            'revision' => 2,
            'sidebar_revision' => 1,
        ])->save();

        $start = microtime(true) + 2;
        $workerPath = base_path('tests/Feature/_user-preference-concurrency-worker.php');
        $processes = [
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'false', (string) $start, 'brand', 'notifications'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $workerPath, (string) $user->id, 'dark', (string) $start, 'indigo'], base_path(), ['APP_ENV' => 'testing']),
        ];

        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('saved', trim($process->getOutput()));
        }

        $fresh = $preference->fresh();
        $this->assertSame(1, $user->preference()->count());
        $this->assertSame('indigo', $fresh->accent);
        $this->assertSame('dark', $fresh->theme);
        $this->assertSame(['local_invoice_submitted' => false], $fresh->notification_preferences);
        $this->assertSame(4, $fresh->revision);
    }
}
