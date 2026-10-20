<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutAllDevicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_has_marker_and_server_flags(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('data-auth-login-page', false)
            ->assertSee('data-session-cross-tab="1"', false)
            ->assertSee('window.AdasiSession = null;', false);
    }

    public function test_authenticated_shell_has_post_logout_marker_and_server_session_payload(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('method="POST" data-logout-form', false)
            ->assertSee('window.AdasiLoginUrl = ', false)
            ->assertSee('window.AdasiSession = ', false)
            ->assertDontSee('data-auth-login-page', false);
    }

    public function test_guest_polling_returns_json_401_with_sso_on_or_off(): void
    {
        foreach ([false, true] as $sso) {
            config(['sso.enabled' => $sso]);
            foreach (['notifications.unread-count', 'conversations.unread-count'] as $route) {
                $this->getJson(route($route))->assertUnauthorized()->assertHeaderMissing('Location');
            }
        }
    }
}
