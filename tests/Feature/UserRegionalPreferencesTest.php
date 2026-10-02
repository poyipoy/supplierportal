<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use App\Services\UserPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRegionalPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $values = []): array
    {
        return [...['theme' => 'dark', 'density' => 'compact', 'sidebar_state' => 'collapsed', 'page_size' => 50, 'quick_access' => ['admin.users'], 'accent' => 'slate'], ...$values];
    }

    public function test_defaults_need_no_row_and_regional_values_are_resolved_once_per_request(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries++;
            }
        });
        $preferences = app(UserPreferenceService::class)->for($user);
        foreach (['timezone', 'date_format', 'time_format', 'number_format'] as $field) {
            $this->assertSame('system', $preferences[$field]);
        }
        $formatter = app(RegionalDisplayFormatter::class);
        for ($i = 0; $i < 30; $i++) {
            $formatter->date('2026-09-28');
            $formatter->number('1,250.00');
        }
        $this->assertSame(1, $queries);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_customization_request_reuses_one_preference_lookup(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries++;
            }
        });
        $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        $this->assertSame(1, $queries);
    }

    public function test_all_allowed_values_persist_and_region_only_updates_leave_sidebar_revision_unchanged(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->patch(route('profile.customization.update'), $this->base())->assertSessionHasNoErrors();
        $sidebarRevision = $user->fresh()->preference->sidebar_revision;
        foreach (['timezone' => 'timezones', 'date_format' => 'date_formats', 'time_format' => 'time_formats', 'number_format' => 'number_formats'] as $field => $registry) {
            foreach (array_keys(config('regional_display.'.$registry)) as $value) {
                $before = $user->fresh()->preference->revision;
                $this->patch(route('profile.customization.update'), $this->base([$field => $value]))->assertSessionHasNoErrors();
                $saved = $user->fresh()->preference;
                $this->assertSame($value, $saved->{$field});
                $this->assertSame($before + 1, $saved->revision);
                $this->assertSame($sidebarRevision, $saved->sidebar_revision);
                $this->assertSame('dark', $saved->theme);
                $this->assertSame('slate', $saved->accent);
                $this->assertSame(['admin.users'], $saved->quick_access);
            }
        }
    }

    public function test_legacy_payload_preserves_saved_regional_values_and_dashboard_layout(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $layout = ['admin' => ['hidden' => ['admin.summary'], 'order' => []]];
        $user->preference()->create([...$this->base(), 'timezone' => 'Asia/Jakarta', 'date_format' => 'iso', 'time_format' => '12h', 'number_format' => 'indonesian', 'dashboard_preferences' => $layout]);
        $this->actingAs($user)->patch(route('profile.customization.update'), $this->base())->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference;
        $this->assertSame('Asia/Jakarta', $saved->timezone);
        $this->assertSame('iso', $saved->date_format);
        $this->assertSame('12h', $saved->time_format);
        $this->assertSame('indonesian', $saved->number_format);
        $this->assertSame(['admin.summary'], $saved->dashboard_preferences['admin']['hidden']);
    }

    public function test_invalid_and_nested_values_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        foreach (['timezone' => ['Asia/Makassar', 'Asia/Jayapura', 'UTC', ['Asia/Jakarta']], 'date_format' => ['d/m/Y', '<script>', ['iso']], 'time_format' => ['H:i', 'locale', ['12h']], 'number_format' => ['id-ID', '.', ['indonesian']]] as $field => $invalidValues) {
            foreach ($invalidValues as $value) {
                $this->patch(route('profile.customization.update'), $this->base([$field => $value]))->assertSessionHasErrors($field);
            }
        }
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_client_cannot_change_owner_revisions_or_account_security_data(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'finance']);
        $before = $owner->fresh()->getAttributes();
        $this->actingAs($owner)->patch(route('profile.customization.update'), $this->base(['timezone' => 'Asia/Jakarta', 'user_id' => $other->id, 'revision' => 999, 'sidebar_revision' => 999, 'role' => 'supplier', 'password' => 'forged', 'account_status' => 'REJECTED', 'two_factor_secret' => 'forged']))->assertSessionHasNoErrors();
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $other->id]);
        $this->assertSame(1, $owner->fresh()->preference->revision);
        $this->assertSame(2, $owner->fresh()->preference->sidebar_revision);
    }

    public function test_reset_restores_regional_defaults_and_keeps_security_and_session_state(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now(), 'auth_session_version' => 5])->save();
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->withSession(['unrelated' => 'preserved'])->patch(route('profile.customization.update'), $this->base(['timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', 'number_format' => 'indonesian']))->assertSessionHasNoErrors();
        $revision = $user->fresh()->preference->revision;
        $sidebarRevision = $user->fresh()->preference->sidebar_revision;
        $this->delete(route('profile.customization.reset'))->assertRedirect()->assertSessionHas('unrelated', 'preserved');
        $this->delete(route('profile.customization.reset'))->assertRedirect();
        $saved = $user->fresh()->preference;
        foreach (config('user_preferences.defaults') as $field => $value) {
            if ($field !== 'sidebar_revision') {
                $this->assertSame($value, $saved->{$field});
            }
        }
        $this->assertSame($revision + 2, $saved->revision);
        $this->assertSame($sidebarRevision + 2, $saved->sidebar_revision);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_stale_stored_keys_fall_back_and_frontend_exposes_only_safe_regional_contract(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([...$this->base(), 'timezone' => 'Asia/Jayapura', 'date_format' => 'D M j', 'time_format' => 'H:i', 'number_format' => 'id-ID']);
        $this->actingAs($user);
        $service = app(UserPreferenceService::class);
        $effective = $service->for($user);
        $payload = $service->frontendPayload($user, $effective);
        $this->assertSame(['timezone' => 'system', 'date_format' => 'system', 'time_format' => 'system', 'number_format' => 'system'], $payload['regional']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('user', $payload);
        $this->assertSame(['system', 'human', 'dmy', 'iso'], $payload['regionalRegistry']['date_formats']);
    }

    public function test_additive_regional_migration_preserves_prior_preferences_through_down_and_reup(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $user = User::factory()->create(['role' => 'admin']);
        $preference = $user->preference()->create([...$this->base(), 'dashboard_preferences' => ['admin' => ['hidden' => ['admin.summary'], 'order' => []]]]);
        $preference->forceFill(['revision' => 7, 'sidebar_revision' => 4])->save();
        $migration = require database_path('migrations/2026_09_29_000001_extend_user_preferences_for_regional_preferences.php');
        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('user_preferences', 'timezone'));
            $migration->up();
            foreach (['timezone', 'date_format', 'time_format', 'number_format'] as $field) {
                $this->assertSame('system', $preference->fresh()->{$field});
            }
            $this->assertSame('dark', $preference->fresh()->theme);
            $this->assertSame('slate', $preference->fresh()->accent);
            $this->assertSame(7, $preference->fresh()->revision);
            $this->assertSame(4, $preference->fresh()->sidebar_revision);
            $this->assertSame(['admin.users'], $preference->fresh()->quick_access);
        } finally {
            if (! Schema::hasColumn('user_preferences', 'timezone')) {
                $migration->up();
            }
        }
    }
}
