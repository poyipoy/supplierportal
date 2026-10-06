<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserLocalePreferenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_locale_extension_defaults_existing_rows_and_rolls_back_only_locale(): void
    {
        $path = database_path('migrations/2026_10_04_000001_add_locale_to_user_preferences_table.php');
        $this->assertFileExists($path);
        $migration = require $path;
        $user = User::factory()->create(['role' => 'admin']);
        $preference = $user->preference()->create([
            'theme' => 'dark', 'density' => 'compact', 'sidebar_state' => 'collapsed',
            'page_size' => 50, 'quick_access' => ['admin.users'], 'accent' => 'teal',
            'timezone' => 'Asia/Jakarta', 'date_format' => 'iso',
        ]);
        $preference->forceFill(['revision' => 9, 'notification_preferences' => ['new_device_login' => false]])->save();
        $before = DB::table('user_preferences')->where('id', $preference->id)->first();
        $columns = Schema::getColumnListing('user_preferences');

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('user_preferences', 'locale'));
            $expected = (array) $before;
            unset($expected['locale']);
            $this->assertEquals($expected, (array) DB::table('user_preferences')->where('id', $preference->id)->first());

            $migration->up();
            $column = DB::selectOne("SELECT DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS length, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_preferences' AND COLUMN_NAME = 'locale'");
            $this->assertSame('varchar', $column->type);
            $this->assertSame(2, (int) $column->length);
            $this->assertSame('NO', $column->nullable);
            $this->assertSame('en', trim((string) $column->default_value, "'"));
            $this->assertSame('en', $preference->fresh()->locale);
            $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing('user_preferences'));
            $this->assertDatabaseHas('user_preferences', ['id' => $preference->id, 'revision' => 9, 'theme' => 'dark', 'accent' => 'teal']);
            $this->assertSame(['admin.users'], $preference->fresh()->quick_access);
        } finally {
            if (! Schema::hasColumn('user_preferences', 'locale')) {
                $migration->up();
            }
        }
    }
}
