<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_customization(): void
    {
        $this->get(route('profile.customization'))->assertRedirect(route('login'));
    }

    public function test_authenticated_roles_can_view_defaults_without_creating_a_row(): void
    {
        foreach (['admin', 'purchasing', 'supplier', 'finance', 'accounting', 'qc', 'ga'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get(route('profile.customization'))
                ->assertOk()
                ->assertViewHas('preferences', [
                    'theme' => 'system',
                    'density' => 'comfortable',
                    'sidebar_state' => 'expanded',
                    'page_size' => 25,
                    'quick_access' => [],
                    'revision' => 0,
                    'accent' => 'brand',
                    'dashboard_preferences' => [],
                    'sidebar_revision' => 1,
                    'timezone' => 'system',
                    'date_format' => 'system',
                    'time_format' => 'system',
                    'number_format' => 'system',
                    'notification_preferences' => [],
                    'locale' => 'en',
                ]);

            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        }
    }

    public function test_layout_resolves_the_preference_relation_once_per_request(): void
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

    public function test_valid_preferences_are_persisted_for_the_current_user_only(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'finance']);

        $this->actingAs($user)
            ->patch(route('profile.customization.update'), $this->validPreferences([
                'theme' => 'dark',
                'density' => 'compact',
                'sidebar_state' => 'collapsed',
                'page_size' => 100,
                'quick_access' => ['admin.users'],
                'user_id' => $other->id,
                'role' => 'admin',
                'password' => 'ChangedPassword123!',
                'revision' => 500,
            ]))
            ->assertRedirect(route('profile.customization'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme' => 'dark',
            'density' => 'compact',
            'sidebar_state' => 'collapsed',
            'page_size' => 100,
            'revision' => 1,
        ]);
        $this->assertSame(['admin.users'], $user->fresh()->preference->quick_access);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $other->id]);
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertNotSame('ChangedPassword123!', $user->fresh()->password);
    }

    public function test_each_supported_theme_density_sidebar_and_page_size_can_be_saved(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $options = [
            'theme' => config('user_preferences.themes'),
            'density' => config('user_preferences.densities'),
            'sidebar_state' => config('user_preferences.sidebar_states'),
            'page_size' => config('user_preferences.page_sizes'),
        ];

        foreach ($options as $field => $values) {
            foreach ($values as $value) {
                $this->actingAs($user)
                    ->patch(route('profile.customization.update'), $this->validPreferences([$field => $value]))
                    ->assertSessionHasNoErrors();
                $this->assertSame($value, $user->fresh()->preference->{$field});
            }
        }
    }

    public function test_invalid_preference_values_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $cases = [
            ['field' => 'theme', 'value' => 'ultraviolet'],
            ['field' => 'density', 'value' => 'tiny'],
            ['field' => 'sidebar_state', 'value' => 'hidden'],
            ['field' => 'page_size', 'value' => 'many'],
            ['field' => 'page_size', 'value' => 20],
        ];

        foreach ($cases as ['field' => $field, 'value' => $value]) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->validPreferences([$field => $value]))
                ->assertSessionHasErrors($field);
        }

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_reset_restores_defaults_and_preserves_account_security_data(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'name' => 'Customization Owner',
            'password' => 'OriginalPassword123!',
        ]);
        $user->forceFill([
            'two_factor_secret' => 'two-factor-secret',
            'two_factor_confirmed_at' => now(),
            'auth_session_version' => 8,
        ])->save();

        $preference = $user->preference()->create([
            'theme' => 'dark',
            'density' => 'compact',
            'sidebar_state' => 'collapsed',
            'page_size' => 100,
            'quick_access' => ['admin.users'],
        ]);
        $preference->forceFill(['revision' => 4])->save();

        $this->actingAs($user)
            ->delete(route('profile.customization.reset'))
            ->assertRedirect(route('profile.customization'));
        $this->delete(route('profile.customization.reset'))->assertRedirect(route('profile.customization'));

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme' => 'system',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'revision' => 6,
        ]);
        $this->assertSame([], $user->fresh()->preference->quick_access);
        $this->assertSame('Customization Owner', $user->fresh()->name);
        $this->assertSame($user->password, $user->fresh()->password);
        $this->assertSame(8, $user->fresh()->auth_session_version);
        $this->assertTrue($user->fresh()->hasTwoFactorAuthentication());
    }

    public function test_deleting_a_user_cascades_its_preference(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([
            'theme' => 'dark',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => [],
            'revision' => 1,
        ]);

        $user->delete();

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_customization_save_and_reset_preserve_notification_overrides(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $overrides = ['local_invoice_submitted' => false];
        $preference = $user->preference()->create([
            'theme' => 'dark', 'density' => 'compact', 'sidebar_state' => 'collapsed',
            'page_size' => 50, 'quick_access' => [],
        ]);
        $preference->forceFill(['notification_preferences' => $overrides])->save();

        $this->actingAs($user)->patch(route('profile.customization.update'), $this->validPreferences())
            ->assertSessionHasNoErrors();
        $this->assertSame($overrides, $user->fresh()->preference->notification_preferences);
        $this->delete(route('profile.customization.reset'))->assertRedirect(route('profile.customization'));
        $this->assertSame($overrides, $user->fresh()->preference->notification_preferences);
    }

    public function test_accent_brand_and_slate_can_be_saved(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        foreach (['brand', 'slate'] as $accent) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->validPreferences(['accent' => $accent]))
                ->assertSessionHasNoErrors();
            $this->assertSame($accent, $user->fresh()->preference->accent);
        }
    }

    public function test_all_five_approved_accents_can_be_saved(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        foreach (['brand', 'slate', 'indigo', 'teal', 'violet'] as $accent) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->validPreferences(['accent' => $accent]))
                ->assertSessionHasNoErrors();
            $this->assertSame($accent, $user->fresh()->preference->accent);

            $effective = app(UserPreferenceService::class)->for($user);
            $this->assertSame($accent, $effective['accent']);
        }
    }

    public function test_invalid_accent_values_raw_hex_and_css_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $invalidAccents = [
            '#1F5FA6', 'rgb(31, 95, 166)', 'hsl(210, 68%, 39%)',
            'color: red;', '<script>', 'neon-pink',
            'amber', 'yellow', 'rose', 'red', 'custom',
        ];

        foreach ($invalidAccents as $invalid) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->validPreferences(['accent' => $invalid]))
                ->assertSessionHasErrors('accent');
        }

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_invalid_persisted_accent_falls_back_to_brand_on_read(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $preference = $user->preference()->create([
            'theme' => 'light',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => [],
            'accent' => 'corrupted_legacy_val',
        ]);

        $effective = app(UserPreferenceService::class)->for($user);
        $this->assertSame('brand', $effective['accent']);

        // Saving unrelated preferences also normalizes the corrupted accent safely to default
        $this->actingAs($user)
            ->patch(route('profile.customization.update'), $this->validPreferences(['theme' => 'dark']))
            ->assertSessionHasNoErrors();
        $this->assertSame('brand', $user->fresh()->preference->accent);
    }

    public function test_reset_restores_accent_to_brand(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([
            'theme' => 'dark',
            'density' => 'compact',
            'sidebar_state' => 'collapsed',
            'page_size' => 100,
            'quick_access' => [],
            'accent' => 'slate',
        ]);

        $this->actingAs($user)
            ->delete(route('profile.customization.reset'))
            ->assertRedirect(route('profile.customization'));

        $this->assertSame('brand', $user->fresh()->preference->accent);
    }

    public function test_density_registry_has_exactly_five_ordered_strings_and_keeps_comfortable_default(): void
    {
        $this->assertSame(['spacious', 'comfortable', 'balanced', 'compact', 'dense'], config('user_preferences.densities'));
        $this->assertSame('comfortable', config('user_preferences.defaults.density'));
    }

    public function test_all_five_density_levels_save_only_for_the_current_account(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'finance']);

        foreach (['spacious', 'comfortable', 'balanced', 'compact', 'dense'] as $index => $density) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->validPreferences([
                    'density' => $density,
                    'user_id' => $other->id,
                ]))
                ->assertRedirect(route('profile.customization'))
                ->assertSessionHasNoErrors();

            $saved = $user->fresh()->preference;
            $this->assertSame($density, $saved->density);
            $this->assertSame($index + 1, $saved->revision);
            $this->assertSame(25, $saved->page_size);
            $this->assertSame('expanded', $saved->sidebar_state);
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $other->id]);
        }

        $this->delete(route('profile.customization.reset'))->assertRedirect(route('profile.customization'));
        $this->assertSame('comfortable', $user->fresh()->preference->density);
    }

    public function test_invalid_density_values_never_overwrite_the_saved_value(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $stored = $user->preference()->create([
            'theme' => 'dark', 'density' => 'compact', 'sidebar_state' => 'collapsed',
            'page_size' => 50, 'quick_access' => [],
        ]);
        $stored->forceFill(['revision' => 7])->save();

        foreach (['tiny', '2', 1, 2, 5, null, [], ['dense'], false] as $density) {
            $this->actingAs($user)
                ->patchJson(route('profile.customization.update'), $this->validPreferences(['density' => $density]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('density');

            $saved = $user->fresh()->preference;
            $this->assertSame('compact', $saved->density);
            $this->assertSame('dark', $saved->theme);
            $this->assertSame('collapsed', $saved->sidebar_state);
            $this->assertSame(50, $saved->page_size);
            $this->assertSame(7, $saved->revision);
        }
    }

    public function test_unknown_stored_density_falls_back_without_writing_the_record(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $stored = $user->preference()->create([
            'theme' => 'light', 'density' => 'legacy_density', 'sidebar_state' => 'expanded',
            'page_size' => 25, 'quick_access' => [],
        ]);
        $revision = $stored->fresh()->revision;

        $this->actingAs($user)->get(route('profile.customization'))
            ->assertOk()
            ->assertViewHas('preferences', fn (array $preferences): bool => $preferences['density'] === 'comfortable');

        $this->assertSame('legacy_density', $stored->fresh()->density);
        $this->assertSame($revision, $stored->fresh()->revision);
    }

    public function test_valid_old_density_is_preview_metadata_without_changing_the_saved_baseline(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)
            ->withSession(['_old_input' => ['density' => 'dense']])
            ->get(route('profile.customization'))
            ->assertOk()
            ->assertViewHas('densityPreview', 'dense')
            ->assertViewHas('preferences', fn (array $preferences): bool => $preferences['density'] === 'comfortable');

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_invalid_old_density_is_not_bootstrapped_as_a_preview(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        foreach (['tiny', '2', ['dense']] as $density) {
            $this->actingAs($user)
                ->withSession(['_old_input' => ['density' => $density]])
                ->get(route('profile.customization'))
                ->assertOk()
                ->assertViewHas('densityPreview', null);
        }
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_density_slider_submits_one_canonical_select_and_keeps_numeric_position_unnamed(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'name="density"'));
        $this->assertMatchesRegularExpression('/<select[^>]*name="density"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="range"[^>]*min="1"[^>]*max="5"[^>]*step="1"/', $html);
        preg_match('/<input[^>]*data-density-slider[^>]*>/', $html, $slider);
        $this->assertNotEmpty($slider);
        $this->assertStringNotContainsString('name=', $slider[0]);
        $this->assertStringContainsString('hidden disabled', $slider[0]);
        $this->assertStringContainsString('aria-valuetext="Comfortable, 2 of 5"', $slider[0]);
        foreach (['spacious', 'comfortable', 'balanced', 'compact', 'dense'] as $density) {
            $this->assertStringContainsString('value="'.$density.'"', $html);
        }
        $response->assertSee('data-density-label', false)->assertSee('ui-density-preview-frame', false);
    }

    private function validPreferences(array $overrides = []): array
    {
        return array_merge([
            'theme' => 'system',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => [],
            'supplier_context' => '',
        ], $overrides);
    }
}
