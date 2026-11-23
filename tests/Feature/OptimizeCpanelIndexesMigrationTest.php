<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('schema-migration')]
class OptimizeCpanelIndexesMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_11_22_000001_optimize_cpanel_indexes.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
            throw new RuntimeException('Migration verification requires adasi_portal_test.');
        }

        $this->artisan('migrate')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_index_migration_up_and_down_are_idempotent_and_notifications_explain_uses_composite_index(): void
    {
        $migration = require database_path(self::MIGRATION);

        try {
            $migration->up();
            $migration->up();

            $this->assertTrue($this->hasIndex('notifications', 'notifications_notifiable_read_created_idx'));
            $this->assertTrue($this->hasIndex('jobs', 'jobs_queue_reserved_available_idx'));
            $this->assertFalse($this->hasIndex('quotation_items', 'quotation_items_pr_item_quotation_index'));
            $this->assertTrue($this->hasIndex('quotation_items', 'quotation_items_pr_item_id_quotation_id_index'));
            $this->assertFalse($this->hasIndex('payment_items', 'idx_payment_items_reservation'));
            $this->assertTrue($this->hasIndex('payment_items', 'payment_items_payable_type_payable_id_status_index'));

            $migration->down();
            $migration->down();

            $this->assertFalse($this->hasIndex('notifications', 'notifications_notifiable_read_created_idx'));
            $this->assertFalse($this->hasIndex('jobs', 'jobs_queue_reserved_available_idx'));
            $this->assertTrue($this->hasIndex('quotation_items', 'quotation_items_pr_item_quotation_index'));
            $this->assertTrue($this->hasIndex('payment_items', 'idx_payment_items_reservation'));
        } finally {
            $migration->up();
        }

        $plan = DB::select(
            'EXPLAIN SELECT id, data, read_at FROM notifications WHERE notifiable_type = ? AND notifiable_id = ? AND read_at IS NULL ORDER BY created_at DESC LIMIT 30',
            [User::class, 1],
        );

        $this->assertNotEmpty($plan);
        $possibleKeys = (string) ($plan[0]->possible_keys ?? '');
        $extra = (string) ($plan[0]->Extra ?? '');
        $this->assertStringContainsString('notifications_notifiable_read_created_idx', $possibleKeys);
        $this->assertStringNotContainsString('Using filesort', $extra);
    }

    public function test_cpanel_cron_queue_worker_schedule_is_registered_and_gated_by_config(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            fn ($event) => str_contains((string) $event->command, 'queue:work --queue=exports,imports,default --stop-when-empty --max-time=50 --tries=3')
        );

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        config(['queue.cpanel_cron_worker' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['queue.cpanel_cron_worker' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
}
