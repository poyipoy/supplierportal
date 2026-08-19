<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\PasswordConfirmationContinuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200)
            ->assertSee('ADASI Supplier Portal')
            ->assertSee('Confirm your password')
            ->assertSee('Confirm and Continue');
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHasNoErrors();
    }

    public function test_password_confirmation_continues_mfa_setup_instead_of_returning_to_profile(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $start->assertSessionHas(PasswordConfirmationContinuationService::SESSION_KEY);

        $confirmation = $this->withCookie(config('session.cookie'), $start->getCookie(config('session.cookie'))->getValue())
            ->post(route('password.confirm'), [
                'password' => 'password',
            ]);

        $confirmation->assertOk()->assertViewIs('auth.password-confirmation-continuation');
        $confirmation->assertSessionMissing(PasswordConfirmationContinuationService::SESSION_KEY);

        $action = $confirmation->viewData('action');
        $this->assertSame('POST', $action['method']);
        $this->assertSame('/profile/two-factor/setup', parse_url($action['url'], PHP_URL_PATH));

        $this->post(parse_url($action['url'], PHP_URL_PATH), [
            ...collect($action['inputs'])->mapWithKeys(fn (array $input): array => [$input['name'] => $input['value']])->all(),
        ])->assertRedirect(route('profile.two-factor.setup'));

        $this->get(route('profile.two-factor.setup'))
            ->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false);
    }

    public function test_direct_password_confirmation_falls_back_to_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_json_password_confirmation_requirement_returns_locked_response_without_storing_action(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('profile.two-factor.start'));

        $response->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.'])
            ->assertSessionMissing(PasswordConfirmationContinuationService::SESSION_KEY);
    }

    public function test_expired_pending_action_is_not_replayed(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $payload = json_decode(
            Crypt::decryptString($start->getSession()->get(PasswordConfirmationContinuationService::SESSION_KEY)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['expires_at'] = now()->subSecond()->timestamp;
        $this->startSession();
        $payload['session_id'] = $this->app['session']->getId();
        $encryptedPayload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->withSession([PasswordConfirmationContinuationService::SESSION_KEY => $encryptedPayload])
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_pending_action_bound_to_another_user_is_not_replayed(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $payload = json_decode(
            Crypt::decryptString($start->getSession()->get(PasswordConfirmationContinuationService::SESSION_KEY)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['user_id'] = 'different-user';
        $this->startSession();
        $payload['session_id'] = $this->app['session']->getId();
        $encryptedPayload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->withSession([PasswordConfirmationContinuationService::SESSION_KEY => $encryptedPayload])
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_pending_action_bound_to_another_session_is_not_replayed(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $payload = json_decode(
            Crypt::decryptString($start->getSession()->get(PasswordConfirmationContinuationService::SESSION_KEY)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['session_id'] = 'different-session';

        $this->withSession([
            PasswordConfirmationContinuationService::SESSION_KEY => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ])
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
