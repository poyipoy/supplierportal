<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\AdasiResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200)
            ->assertSee('ADASI Supplier Portal')
            ->assertSee('Forgot your password?')
            ->assertSee('Send Reset Link');
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, AdasiResetPasswordNotification::class, function (AdasiResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            $this->assertSame('Reset your password | ADASI Supplier Portal', $mail->subject);
            $this->assertSame([
                'html' => 'emails.auth.reset-password',
                'text' => 'emails.auth.reset-password-text',
            ], $mail->view);
            $this->assertSame($user->name, $mail->viewData['recipientName']);
            $this->assertStringContainsString('/reset-password/'.$notification->token, $mail->viewData['resetUrl']);

            return true;
        });
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, AdasiResetPasswordNotification::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200)
                ->assertSee('ADASI Supplier Portal')
                ->assertSee('Create a new password')
                ->assertSee('at least 12 characters');

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, AdasiResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Str0ng!Passphrase',
                'password_confirmation' => 'Str0ng!Passphrase',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_no_notification_is_sent_for_an_unregistered_email(): void
    {
        // Locks in the core requirement as an explicit, tested guarantee
        // instead of an implicit side effect of Laravel's default password
        // broker: this must keep holding even if the broker/provider setup
        // changes later.
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'not-in-the-database@example.test']);

        Notification::assertNothingSent();
    }

    public function test_no_notification_is_sent_for_a_deactivated_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['is_active' => false]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_email_is_normalized_before_lookup(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'normalized@example.test']);

        $this->post('/forgot-password', ['email' => '  NORMALIZED@EXAMPLE.TEST ']);

        Notification::assertSentTo($user, AdasiResetPasswordNotification::class);
    }

    public function test_deactivated_account_cannot_complete_a_password_reset(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $originalPasswordHash = $user->password;
        $token = Password::broker()->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Reset!Password123',
            'password_confirmation' => 'Reset!Password123',
        ]);

        // Reported identically to an invalid/expired token - the response
        // must not reveal that the account exists but is deactivated.
        $response->assertSessionHasErrors('email');
        $this->assertSame('Password reset token is invalid.', $response->getSession()->get('errors')->first('email'));
        $this->assertSame($originalPasswordHash, $user->fresh()->password);
    }
}
