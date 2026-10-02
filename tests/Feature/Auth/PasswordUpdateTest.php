<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile/security')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'Str0ng!Passphrase',
                'password_confirmation' => 'Str0ng!Passphrase',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/security')
            ->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('Str0ng!Passphrase', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile/security')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'Str0ng!Passphrase',
                'password_confirmation' => 'Str0ng!Passphrase',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile/security');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_confirmation_mismatch_rejects_update_without_changing_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from('/profile/security')->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'Str0ng!Passphrase',
            'password_confirmation' => 'Different!Passphrase123',
        ])->assertRedirect('/profile/security')->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_invalid_password_rule_rejects_update_without_changing_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from('/profile/security')->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertRedirect('/profile/security')->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_password_update_requires_a_valid_csrf_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $user = User::factory()->create();
        $version = $user->auth_session_version;
        $payload = ['current_password' => 'password', 'password' => 'Str0ng!Passphrase', 'password_confirmation' => 'Str0ng!Passphrase'];
        $this->actingAs($user)->withSession(['_token' => 'expected-token'])
            ->from('/profile/security')->put(route('password.update'), $payload)->assertStatus(419);
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertSame($version, $user->auth_session_version);

        $this->put(route('password.update'), [...$payload, '_token' => 'expected-token'])
            ->assertRedirect('/profile/security')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Str0ng!Passphrase', $user->refresh()->password));
    }
}
