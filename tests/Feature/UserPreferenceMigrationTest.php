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

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_migration_can_drop_and_recreate_its_table_on_the_dedicated_test_database(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $migration = require database_path('migrations/2026_09_28_000001_create_user_preferences_table.php');
        $extension = require database_path('migrations/2026_09_28_000002_extend_user_preferences_for_dashboard_customization.php');

        $regional = require database_path('migrations/2026_09_29_000001_extend_user_preferences_for_regional_preferences.php');
        $notifications = require database_path('migrations/2026_09_30_000001_add_notification_preferences_to_user_preferences_table.php');

        try {
            $notifications->down();
            $regional->down();
            $extension->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('user_preferences'));

            $migration->up();
            $this->assertTrue(Schema::hasTable('user_preferences'));
            $this->assertTrue(Schema::hasColumn('user_preferences', 'revision'));
            $this->assertTrue(Schema::hasColumn('user_preferences', 'quick_access'));

            $user = User::factory()->create(['role' => 'admin']);
            $values = ['theme' => 'dark', 'density' => 'compact', 'sidebar_state' => 'collapsed', 'page_size' => 50, 'quick_access' => json_encode(['admin.users']), 'revision' => 7];
            DB::table('user_preferences')->insert(['user_id' => $user->id, ...$values]);
            $extension->up();
            foreach (['accent', 'dashboard_preferences', 'sidebar_revision'] as $column) {
                $this->assertTrue(Schema::hasColumn('user_preferences', $column));
            }
            $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'accent' => 'brand', 'sidebar_revision' => 1, 'theme' => 'dark', 'revision' => 7]);
            $this->assertSame([], $user->fresh()->preference->dashboard_preferences);
            DB::table('user_preferences')->where('user_id', $user->id)->update(['accent' => 'slate', 'dashboard_preferences' => json_encode(['admin' => ['hidden' => ['admin.summary'], 'order' => []]])]);
            $extension->down();
            $this->assertFalse(Schema::hasColumn('user_preferences', 'accent'));
            $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'theme' => 'dark', 'revision' => 7]);
            $extension->up();
            $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'accent' => 'brand', 'sidebar_revision' => 1]);
            $this->assertSame(['admin.users'], $user->fresh()->preference->quick_access);
            $regional->up();
            $notifications->up();
            $this->assertNull($user->fresh()->preference->notification_preferences);
        } finally {
            if (! Schema::hasTable('user_preferences')) {
                $migration->up();
            }
            if (! Schema::hasColumn('user_preferences', 'accent')) {
                $extension->up();
            }
            if (! Schema::hasColumn('user_preferences', 'timezone')) {
                $regional->up();
            }
            if (! Schema::hasColumn('user_preferences', 'notification_preferences')) {
                $notifications->up();
            }
        }
    }
}
