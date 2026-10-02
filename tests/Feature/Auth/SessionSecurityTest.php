<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\SessionInventoryService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_is_removed_from_shared_authenticated_routes(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'account_deactivated',
        ]);
    }

    public function test_session_version_revokes_a_session_with_array_driver(): void
    {
        $user = User::factory()->create(['auth_session_version' => 2]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => 1, 'auth_absolute_started_at' => now()->timestamp])
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_revoked_json_session_receives_401(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'auth_session_version' => 2]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => 1, 'auth_absolute_started_at' => now()->timestamp])
            ->getJson(route('notifications.unread-count'))
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_absolute_session_timeout_is_enforced_at_eight_hours(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 1,
                'auth_absolute_started_at' => now()->subHours(8)->timestamp,
            ])
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'session_timeout']);
    }

    public function test_predeployment_session_is_initialized_instead_of_rejected(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('supplier.dashboard'))
            ->assertSessionHas('auth_session_version', 1)
            ->assertSessionHas('auth_absolute_started_at');

        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_other_devices_rotates_version_but_preserves_current_session(): void
    {
        $user = User::factory()->create();
        $originalVersion = $user->auth_session_version;

        $this->actingAs($user)
            ->post(route('profile.logout-other-devices'), ['password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertGreaterThan($originalVersion, $user->fresh()->auth_session_version);
        $this->assertSame($user->fresh()->auth_session_version, session('auth_session_version'));
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'other_device_logout']);
    }

    public function test_password_update_revokes_other_sessions_and_preserves_current_session(): void
    {
        $user = User::factory()->create();
        $originalVersion = $user->auth_session_version;

        $this->actingAs($user)
            ->from('/profile/security')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'An0ther!StrongPassword',
                'password_confirmation' => 'An0ther!StrongPassword',
            ])
            ->assertRedirect('/profile/security')
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertGreaterThan($originalVersion, $user->fresh()->auth_session_version);
        $this->assertSame($user->fresh()->auth_session_version, session('auth_session_version'));
    }

    public function test_admin_deactivation_revokes_existing_target_session(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $oldVersion = $target->auth_session_version;

        $this->actingAs($admin)->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'purchasing',
        ])->assertRedirect(route('admin.users.index'));

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertGreaterThan($oldVersion, $target->auth_session_version);

        $this->actingAs($target)
            ->withSession([
                'auth_session_version' => $oldVersion,
                'auth_absolute_started_at' => now()->timestamp,
            ])
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_admin_role_and_password_changes_rotate_session_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'purchasing']);
        $oldVersion = $target->auth_session_version;

        $this->actingAs($admin)->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'qc',
            'is_active' => '1',
            'password' => 'Adm1n!ChangedPassword',
            'password_confirmation' => 'Adm1n!ChangedPassword',
        ])->assertRedirect(route('admin.users.index'));

        $target->refresh();
        $this->assertSame('qc', $target->role);
        $this->assertGreaterThan($oldVersion, $target->auth_session_version);
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $target->id, 'event' => 'role_changed']);
    }

    public function test_concurrent_session_cap_of_three_evicts_only_the_oldest_active_session(): void
    {
        // The concurrent-session cap works directly against the `sessions`
        // table, which the array driver used by the rest of this suite
        // never writes to. Switch to the database driver for this test only.
        config()->set('session.driver', 'database');
        config()->set('auth_security.session.max_concurrent_sessions', 3);
        Notification::fake();

        $user = User::factory()->create();

        // Three pre-existing "other device" sessions, oldest first.
        DB::table('sessions')->insert([
            ['id' => 'other-session-oldest', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'DeviceA', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes(30)->timestamp],
            ['id' => 'other-session-middle', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'DeviceB', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes(20)->timestamp],
            ['id' => 'other-session-newest', 'user_id' => $user->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'DeviceC', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes(10)->timestamp],
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        // Limit is 3 (including the session that was just created), so the
        // two most-recently-active OTHER sessions survive.
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-oldest']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session-middle']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session-newest']);
        $this->assertSame(3, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'concurrent_session_limit_enforced',
        ]);
    }

    public function test_idle_expired_session_does_not_consume_a_concurrent_session_slot(): void
    {
        config()->set('session.driver', 'database');
        config()->set('auth_security.session.max_concurrent_sessions', 3);
        Notification::fake();
        $user = User::factory()->create();
        $expiredAt = now()->subMinutes((int) config('session.lifetime') + 1)->timestamp;

        DB::table('sessions')->insert([
            ['id' => 'active-session-one', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'ActiveA', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes(10)->timestamp],
            ['id' => 'active-session-two', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'ActiveB', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes(5)->timestamp],
            ['id' => 'idle-expired-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'Expired', 'payload' => base64_encode(''), 'last_activity' => $expiredAt],
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('sessions', ['id' => 'active-session-one']);
        $this->assertDatabaseHas('sessions', ['id' => 'active-session-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'idle-expired-session']);
        $this->assertDatabaseMissing('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'concurrent_session_limit_enforced',
        ]);
    }

    public function test_active_sessions_security_page_hides_idle_expired_rows(): void
    {
        config()->set('session.driver', 'database');
        $user = User::factory()->create();

        DB::table('sessions')->insert([
            ['id' => 'visible-active-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.4', 'user_agent' => 'VisibleActiveDevice', 'payload' => base64_encode(''), 'last_activity' => now()->subMinute()->timestamp],
            ['id' => 'hidden-expired-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.5', 'user_agent' => 'HiddenExpiredDevice', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes((int) config('session.lifetime') + 1)->timestamp],
        ]);

        $this->actingAs($user)
            ->get(route('profile.security'))
            ->assertOk()
            ->assertSee('VisibleActiveDevice')
            ->assertDontSee('HiddenExpiredDevice');
    }

    public function test_user_can_revoke_a_single_other_session_without_affecting_the_current_one(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create();
        $originalVersion = $user->auth_session_version;

        DB::table('sessions')->insert([
            'id' => 'someone-elses-device',
            'user_id' => $user->id,
            'ip_address' => '10.1.1.1',
            'user_agent' => 'OtherDevice',
            'payload' => base64_encode(''),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)
            ->from('/profile/security')
            ->delete(route('profile.sessions.revoke'), ['session_token' => Crypt::encryptString('someone-elses-device')])
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'someone-elses-device']);
        $this->assertAuthenticatedAs($user);
        $this->assertSame($originalVersion, $user->fresh()->auth_session_version);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'session_revoked',
        ]);
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        config()->set('session.driver', 'database');
        $actor = User::factory()->create();
        $otherUser = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'other-users-session',
            'user_id' => $otherUser->id,
            'ip_address' => '10.1.1.2',
            'user_agent' => 'OtherUserDevice',
            'payload' => base64_encode(''),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($actor)
            ->delete(route('profile.sessions.revoke'), ['session_token' => Crypt::encryptString('other-users-session')])
            ->assertRedirect()
            ->assertSessionHas('status', 'session-not-found');

        $this->assertDatabaseHas('sessions', [
            'id' => 'other-users-session',
            'user_id' => $otherUser->id,
        ]);
        $this->assertDatabaseMissing('auth_audit_logs', [
            'user_id' => $actor->id,
            'event' => 'session_revoked',
        ]);
    }

    public function test_user_cannot_revoke_their_own_current_session_through_the_revoke_endpoint(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create();

        $start = $this->actingAs($user)->get('/dashboard');
        $currentSessionId = $start->getCookie(config('session.cookie'))->getValue();

        $this->withCookie(config('session.cookie'), $currentSessionId)
            ->delete(route('profile.sessions.revoke'), ['session_token' => Crypt::encryptString($currentSessionId)])
            ->assertRedirect()
            ->assertSessionHas('warning', 'Use the sign-out button to end your current session.');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'session_revoked',
        ]);
    }

    public function test_security_session_forms_transport_only_opaque_tokens(): void
    {
        $user = User::factory()->create();
        $rawId = 'private-session-identifier-for-other-device';
        $this->insertSession($user, $rawId);
        $response = $this->actingAs($user)->get(route('profile.security'))->assertOk();
        $response->assertDontSee($rawId, false)->assertDontSee('private-payload', false);

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form[@action="'.route('profile.sessions.revoke').'"]');
        $this->assertCount(1, $forms);
        $form = $forms->item(0);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertCount(1, $xpath->query('.//input[@name="_token"]', $form));
        $this->assertCount(1, $xpath->query('.//input[@name="_method" and @value="DELETE"]', $form));
        $token = $xpath->query('.//input[@name="session_token"]', $form)->item(0);
        $this->assertNotNull($token);
        $this->assertSame($rawId, Crypt::decryptString($token->getAttribute('value')));
        $this->assertSame('profile/sessions', app('router')->getRoutes()->getByName('profile.sessions.revoke')->uri());
    }

    public function test_session_inventory_projection_omits_raw_ids_and_current_session_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->insertSession($user, 'current-private-id');
        $this->insertSession($user, 'other-private-id');
        $this->insertSession($other, 'another-users-private-id');
        $sessions = app(SessionInventoryService::class)->activeSessionsFor($user, 'current-private-id');
        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $this->assertObjectNotHasProperty('id', $session);
            $this->assertObjectNotHasProperty('payload', $session);
            if ($session->is_current) {
                $this->assertEmpty($session->revocation_token ?? null);
            } else {
                $this->assertSame('other-private-id', Crypt::decryptString($session->revocation_token));
            }
        }
    }

    public function test_tampered_session_token_fails_without_deleting_rows_or_logging_secrets(): void
    {
        $user = User::factory()->create();
        $rawId = 'session-to-preserve-after-tampering';
        $this->insertSession($user, $rawId);
        $token = Crypt::encryptString($rawId);
        $tampered = substr($token, 0, -8).'tampered';

        $this->actingAs($user)->from('/profile/security')
            ->delete(route('profile.sessions.revoke'), ['session_token' => $tampered])
            ->assertRedirect('/profile/security')->assertSessionHas('status', 'session-not-found');
        $this->assertDatabaseHas('sessions', ['id' => $rawId, 'user_id' => $user->id]);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('auth_audit_logs', ['user_id' => $user->id, 'event' => 'session_revoked']);
        $this->get(route('profile.security'))->assertOk()->assertDontSee($tampered, false)
            ->assertDontSee('DecryptException')->assertDontSee('Stack trace');
    }

    public function test_missing_or_unbounded_session_token_is_rejected_without_deletion(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, 'session-preserved-by-validation');
        foreach ([[], ['session_token' => ['invalid']], ['session_token' => str_repeat('x', 2049)]] as $payload) {
            $this->actingAs($user)->from('/profile/security')->delete(route('profile.sessions.revoke'), $payload)
                ->assertRedirect('/profile/security')->assertSessionHasErrors('session_token');
            $this->assertDatabaseHas('sessions', ['id' => 'session-preserved-by-validation', 'user_id' => $user->id]);
        }
    }

    public function test_plaintext_token_and_obsolete_raw_id_endpoint_cannot_revoke_a_session(): void
    {
        $user = User::factory()->create();
        $rawId = 'raw-session-endpoint-must-be-unavailable';
        $this->insertSession($user, $rawId);
        $this->actingAs($user)->from('/profile/security')
            ->delete(route('profile.sessions.revoke'), ['session_token' => $rawId])
            ->assertRedirect('/profile/security')->assertSessionHas('status', 'session-not-found');
        $this->delete('/profile/sessions/'.$rawId)->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => $rawId, 'user_id' => $user->id]);
    }

    public function test_opaque_session_revocation_requires_a_valid_csrf_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $user = User::factory()->create();
        $rawId = 'session-preserved-without-csrf-token';
        $this->insertSession($user, $rawId);
        $payload = ['session_token' => Crypt::encryptString($rawId)];
        $this->actingAs($user)->withSession(['_token' => 'expected-token'])
            ->from('/profile/security')->delete(route('profile.sessions.revoke'), $payload)->assertStatus(419);
        $this->assertDatabaseHas('sessions', ['id' => $rawId, 'user_id' => $user->id]);

        $this->delete(route('profile.sessions.revoke'), [...$payload, '_token' => 'expected-token'])
            ->assertRedirect('/profile/security')->assertSessionHas('status', 'session-revoked');
        $this->assertDatabaseMissing('sessions', ['id' => $rawId]);
        $this->assertAuthenticatedAs($user);
    }

    private function insertSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.1.1.9',
            'user_agent' => 'ExistingDevice',
            'payload' => base64_encode('private-payload'),
            'last_activity' => now()->timestamp,
        ]);
    }
}
