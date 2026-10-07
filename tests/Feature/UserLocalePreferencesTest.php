<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserLocalePreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    private function values(array $overrides = []): array
    {
        return array_replace(['theme' => 'dark', 'density' => 'compact', 'sidebar_state' => 'collapsed', 'page_size' => 50, 'quick_access' => []], $overrides);
    }

    public function test_all_roles_default_to_english_without_creating_preferences(): void
    {
        foreach (['admin', 'purchasing', 'supplier', 'finance', 'accounting', 'qc', 'ga'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertSame('en', app(UserPreferenceService::class)->for($user)['locale']);
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        }
    }

    public function test_save_switches_locale_feedback_and_preserves_scoped_preferences(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'finance']);
        $preference = $user->preference()->create([...config('user_preferences.defaults'), 'theme' => 'dark', 'accent' => 'teal', 'timezone' => 'Asia/Jakarta', 'date_format' => 'iso', 'number_format' => 'indonesian']);
        $preference->forceFill(['notification_preferences' => ['local_invoice_submitted' => false], 'dashboard_preferences' => ['admin' => ['hidden' => ['admin.notifications'], 'order' => ['admin.rates']]]])->save();
        $this->actingAs($user)->patch(route('profile.customization.update'), $this->values(['locale' => 'id', 'user_id' => $other->id]))
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.customization'))
            ->assertSessionHas('success', 'Kustomisasi disimpan.');
        $saved = $preference->fresh();
        $this->assertSame('id', $saved->locale);
        $this->assertSame('teal', $saved->accent);
        $this->assertSame('Asia/Jakarta', $saved->timezone);
        $this->assertSame('iso', $saved->date_format);
        $this->assertSame('indonesian', $saved->number_format);
        $this->assertSame(['local_invoice_submitted' => false], $saved->notification_preferences);
        $this->assertSame(['admin.notifications'], $saved->dashboard_preferences['admin']['hidden']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $other->id]);

        $this->patch(route('profile.customization.update'), $this->values())->assertSessionHasNoErrors();
        $this->assertSame('id', $preference->fresh()->locale);
        $this->patch(route('profile.customization.update'), $this->values(['locale' => 'en']))->assertSessionHas('success', 'Customization saved.');
        $this->assertSame('en', $preference->fresh()->locale);
    }

    public function test_invalid_locale_inputs_are_rejected_without_writes(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        foreach (['en-US', 'id-ID', '../../id', '<script>alert(1)</script>', '', null, ['id']] as $locale) {
            $this->actingAs($user)->patch(route('profile.customization.update'), $this->values(['locale' => $locale]))->assertSessionHasErrors('locale');
        }
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_stale_locale_falls_back_and_reset_contracts_preserve_scoped_state(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $preference = $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'xx']);
        $preference->refresh()->forceFill(['notification_preferences' => ['local_invoice_submitted' => false]])->save();
        $this->assertSame('en', app(UserPreferenceService::class)->for($user)['locale']);
        $this->actingAs($user)->patch(route('profile.customization.update'), $this->values(['locale' => 'id']))->assertSessionHasNoErrors();
        $this->delete(route('profile.customization.reset'), ['scope' => 'dashboard'])->assertRedirect();
        $this->assertSame('id', $preference->fresh()->locale);
        app(UserPreferenceService::class)->resetNotificationPreferences($user);
        $this->assertSame('id', $preference->fresh()->locale);
        $preference->refresh()->forceFill(['notification_preferences' => ['local_invoice_submitted' => false]])->save();
        $this->delete(route('profile.customization.reset'))->assertSessionHas('success', 'Customization reset to defaults.');
        $this->assertSame('en', $preference->fresh()->locale);
        $this->assertSame(['local_invoice_submitted' => false], $preference->fresh()->notification_preferences);
    }
}
