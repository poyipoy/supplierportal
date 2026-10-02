<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_automated_password_reset_named_routes_are_absent(): void
    {
        foreach (['password.email', 'password.reset', 'password.store'] as $name) {
            $this->assertFalse(Route::has($name));
        }
    }

    public function test_obsolete_recovery_endpoints_cannot_issue_tokens_send_mail_or_change_credentials(): void
    {
        Mail::fake();
        Notification::fake();
        $user = User::factory()->create();
        $password = $user->password;
        $version = $user->auth_session_version;
        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(405);
        $this->get('/reset-password/obsolete-token')->assertNotFound();
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => 'obsolete-token',
            'password' => 'Str0ng!Passphrase', 'password_confirmation' => 'Str0ng!Passphrase',
        ])->assertNotFound();
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame($version, $user->fresh()->auth_session_version);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200)
            ->assertSee('ADASI Supplier Portal')
            ->assertSee('Password Assistance')
            ->assertSee('Copy Email Template');
    }
}
