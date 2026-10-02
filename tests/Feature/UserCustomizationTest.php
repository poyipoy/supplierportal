<?php

namespace Tests\Feature;

use App\Models\User;
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
