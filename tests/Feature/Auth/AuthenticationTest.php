<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200)
            ->assertSee('Forgot password?')
            ->assertSee(route('password.request'), false);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_with_remember_me_enabled(): void
    {
        $user = User::factory()->create([
            'remember_token' => null,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
        $response->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_login_does_not_redirect_to_polling_or_api_intended_urls(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => 'http://localhost/notifications/unread-count'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNull(session('url.intended'));
    }

    public function test_login_preserves_valid_page_intended_url(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => '/profile'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/profile');
    }

    public function test_unauthenticated_polling_endpoints_return_unauthorized_json(): void
    {
        $response = $this->get('/notifications/unread-count');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->assertNull(session('url.intended'));
    }
}
