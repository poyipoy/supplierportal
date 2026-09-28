<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPreferenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_can_drop_and_recreate_its_table_on_the_dedicated_test_database(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
        $migration = require database_path('migrations/2026_09_28_000001_create_user_preferences_table.php');

        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('user_preferences'));

            $migration->up();
            $this->assertTrue(Schema::hasTable('user_preferences'));
            $this->assertTrue(Schema::hasColumn('user_preferences', 'revision'));
            $this->assertTrue(Schema::hasColumn('user_preferences', 'quick_access'));
        } finally {
            if (! Schema::hasTable('user_preferences')) {
                $migration->up();
            }
        }
    }
}
