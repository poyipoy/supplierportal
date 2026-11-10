<?php

namespace Tests\Feature\Maintenance;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('schema-migration')]
class MaintenanceMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_11_06_000002_create_maintenance_records_tables.php';

    protected function setUp(): void
    {
        parent::setUp();
        // DDL commits implicitly: keep this test outside RefreshDatabase's transaction.
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
            throw new RuntimeException('Migration verification requires adasi_portal_test.');
        }
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_migration_creates_tables_rolls_back_when_empty_and_refuses_to_drop_history(): void
    {
        $this->assertContains(DB::getDriverName(), ['mysql', 'mariadb']);
        $this->assertTrue(Schema::hasColumns('maintenance_records', [
            'type', 'status', 'title', 'description', 'starts_at', 'estimated_end_at', 'completed_at',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('maintenance_record_modules', ['maintenance_record_id', 'module_key', 'deactivated_at']));
        $this->assertSame("enum('global','module')", DB::selectOne("SHOW COLUMNS FROM maintenance_records LIKE 'type'")->Type);

        $migration = require database_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasTable('maintenance_record_modules'));
        $this->assertFalse(Schema::hasTable('maintenance_records'));

        $migration->up();
        $id = DB::table('maintenance_records')->insertGetId([
            'type' => 'module', 'status' => 'draft', 'title' => 'History', 'description' => 'Kept.',
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
        ]);
        DB::table('maintenance_record_modules')->insert([
            'maintenance_record_id' => $id, 'module_key' => 'import.chat', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('Rollback must refuse while maintenance history exists.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('maintenance history', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('maintenance_records')->count());

        try {
            DB::table('maintenance_records')->where('id', $id)->delete();
            $this->fail('A module row must keep its parent record from being hard-deleted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('maintenance_records')->count());
        }

        DB::table('maintenance_record_modules')->delete();
        DB::table('maintenance_records')->delete();
    }
}
