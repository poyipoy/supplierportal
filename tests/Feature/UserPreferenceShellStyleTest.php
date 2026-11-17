<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferenceShellStyleTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_style_registry_defaults_to_classic(): void
    {
        $this->assertSame(['classic', 'slate'], array_keys(config('user_preferences.shell_styles')));
        $this->assertSame('classic', config('user_preferences.defaults.shell_style'));
    }

    public function test_customization_renders_shell_style_options_and_persists_choice(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);

        $this->actingAs($user)
            ->get(route('profile.customization'))
            ->assertOk()
            ->assertSee('name="shell_style"', false)
            ->assertSee('value="classic"', false)
            ->assertSee('value="slate"', false);

        foreach (['slate', 'classic'] as $style) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->validPreferences(['shell_style' => $style]))
                ->assertRedirect(route('profile.customization'))
                ->assertSessionHasNoErrors();

            $this->assertSame($style, $user->fresh()->preference->shell_style);
            $effective = app(UserPreferenceService::class)->for($user);
            $this->assertSame($style, $effective['shell_style']);
            $this->assertSame($style, app(UserPreferenceService::class)->frontendPayload($user, $effective)['shellStyle']);
        }
    }

    public function test_omitting_shell_style_on_save_preserves_existing_selection(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([
            ...config('user_preferences.defaults'),
            'shell_style' => 'slate',
        ]);

        $this->actingAs($user)
            ->patch(route('profile.customization.update'), $this->validPreferences(['theme' => 'dark']))
            ->assertSessionHasNoErrors();

        $this->assertSame('slate', $user->fresh()->preference->shell_style);
    }

    public function test_invalid_shell_style_values_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        foreach (['midnight', '#111317', 'color: black;', '<script>', 'dark-shell'] as $invalid) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->validPreferences(['shell_style' => $invalid]))
                ->assertSessionHasErrors('shell_style');
        }

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_corrupted_stored_shell_style_falls_back_to_classic_without_writing(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $stored = $user->preference()->create([
            ...config('user_preferences.defaults'),
            'shell_style' => 'legacy_shell',
        ]);

        $effective = app(UserPreferenceService::class)->for($user);
        $this->assertSame('classic', $effective['shell_style']);
        $this->assertSame('legacy_shell', $stored->fresh()->shell_style);
    }

    public function test_full_reset_restores_classic_while_dashboard_reset_preserves_slate(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([
            ...config('user_preferences.defaults'),
            'shell_style' => 'slate',
        ]);

        $this->actingAs($user)
            ->delete(route('profile.customization.reset'), ['scope' => 'dashboard'])
            ->assertRedirect(route('profile.customization'));

        $this->assertSame('slate', $user->fresh()->preference->shell_style);

        $this->actingAs($user)
            ->delete(route('profile.customization.reset'))
            ->assertRedirect(route('profile.customization'));

        $this->assertSame('classic', $user->fresh()->preference->shell_style);
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
