<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserNotificationPreferenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_notification_migration_is_additive_nullable_and_preserves_existing_preferences(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $migrationPath = database_path('migrations/2026_09_30_000001_add_notification_preferences_to_user_preferences_table.php');
        $this->assertFileExists($migrationPath);
        $migration = require $migrationPath;
        $user = User::factory()->create(['role' => 'admin']);
        $withoutPreferences = User::factory()->create(['role' => 'admin']);
        $preference = $user->preference()->create([
            ...config('user_preferences.defaults'),
            'theme' => 'dark',
            'accent' => 'slate',
            'density' => 'compact',
            'sidebar_state' => 'collapsed',
            'page_size' => 50,
            'quick_access' => ['admin.users'],
            'dashboard_preferences' => ['admin' => ['hidden' => ['admin.notifications'], 'order' => ['admin.summary']]],
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'dmy',
            'time_format' => '12h',
            'number_format' => 'indonesian',
        ]);
        $preference->forceFill(['revision' => 7, 'sidebar_revision' => 4])->save();
        $before = (array) DB::table('user_preferences')->where('id', $preference->id)->first();
        unset($before['notification_preferences']);
        $columns = Schema::getColumnListing('user_preferences');
        $indexes = Schema::getIndexes('user_preferences');
        $foreignKeys = Schema::getForeignKeys('user_preferences');
        $rowCount = DB::table('user_preferences')->count();

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('user_preferences', 'notification_preferences'));
            $this->assertEqualsCanonicalizing(
                array_values(array_diff($columns, ['notification_preferences'])),
                Schema::getColumnListing('user_preferences'),
            );
            $this->assertSame($before, (array) DB::table('user_preferences')->where('id', $preference->id)->first());

            $migration->up();
            $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing('user_preferences'));
            $this->assertSame($indexes, Schema::getIndexes('user_preferences'));
            $this->assertSame($foreignKeys, Schema::getForeignKeys('user_preferences'));
            $column = DB::selectOne(
                'SELECT DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['adasi_portal_test', 'user_preferences', 'notification_preferences'],
            );
            $this->assertNotNull($column);
            $this->assertSame('YES', $column->is_nullable);
            $this->assertContains(strtolower($column->data_type), ['json', 'longtext']);
            if (strtolower($column->data_type) === 'longtext') {
                $definition = (array) DB::selectOne('SHOW CREATE TABLE user_preferences');
                $this->assertMatchesRegularExpression('/json_valid\s*\(\s*`notification_preferences`\s*\)/i', (string) array_values($definition)[1]);
            }
            $after = (array) DB::table('user_preferences')->where('id', $preference->id)->first();
            $this->assertNull($after['notification_preferences']);
            unset($after['notification_preferences']);
            $this->assertSame($before, $after);
            $this->assertSame($rowCount, DB::table('user_preferences')->count());
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $withoutPreferences->id]);
        } finally {
            if (! Schema::hasColumn('user_preferences', 'notification_preferences')) {
                $migration->up();
            }
            // MySQL DDL commits the test transaction; remove only this test's fixtures.
            User::query()->whereKey([$user->id, $withoutPreferences->id])->delete();
        }
    }

    public function test_notification_overrides_are_array_cast_without_expanding_mass_assignment(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $preference = $user->preference()->create(config('user_preferences.defaults'));
        $overrides = ['local_invoice_submission_received' => ['mail' => false]];
        $preference->notification_preferences = $overrides;
        $preference->save();

        $this->assertSame($overrides, $preference->fresh()->notification_preferences);
        $this->assertFalse((new UserPreference)->isFillable('notification_preferences'));
        $this->assertSame($overrides, json_decode(DB::table('user_preferences')->where('id', $preference->id)->value('notification_preferences'), true, 512, JSON_THROW_ON_ERROR));
    }
}
