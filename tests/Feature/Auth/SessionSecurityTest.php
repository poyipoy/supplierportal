<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'An0ther!StrongPassword',
                'password_confirmation' => 'An0ther!StrongPassword',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertGreaterThan($originalVersion, $user->fresh()->auth_session_version);
        $this->assertSame($user->fresh()->auth_session_version, session('auth_session_version'));
    }

    public function test_password_reset_rotates_session_version_and_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'existing-remember-token']);
        $originalVersion = $user->auth_session_version;
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Reset!Password123',
            'password_confirmation' => 'Reset!Password123',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertGreaterThan($originalVersion, $user->auth_session_version);
        $this->assertNotSame('existing-remember-token', $user->remember_token);
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

    public function test_concurrent_session_cap_evicts_the_oldest_session_on_new_login(): void
    {
        // The concurrent-session cap works directly against the `sessions`
        // table, which the array driver used by the rest of this suite
        // never writes to. Switch to the database driver for this test only.
        config()->set('session.driver', 'database');
        config()->set('auth_security.session.max_concurrent_sessions', 2);
        Notification::fake();

        $user = User::factory()->create();

        // Two pre-existing "other device" sessions, oldest first.
        DB::table('sessions')->insert([
            ['id' => 'other-session-oldest', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'DeviceA', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes(30)->timestamp],
            ['id' => 'other-session-newest', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'DeviceB', 'payload' => base64_encode(''), 'last_activity' => now()->subMinutes(10)->timestamp],
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        // Limit is 2 (including the session that was just created), so only
        // the single most-recently-active OTHER session may survive.
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-oldest']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session-newest']);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'concurrent_session_limit_enforced',
        ]);
    }

    public function test_login_from_a_known_active_session_does_not_trigger_new_device_alert(): void
    {
        config()->set('session.driver', 'database');
        Notification::fake();

        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'already-active-elsewhere',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'payload' => base64_encode(''),
            'last_activity' => now()->subMinutes(1)->timestamp,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Symfony'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNotSentTo($user, \App\Notifications\NewDeviceLoginNotification::class);
    }

    public function test_login_from_an_unrecognized_ip_and_user_agent_triggers_new_device_alert(): void
    {
        config()->set('session.driver', 'database');
        Notification::fake();

        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Never-Seen-Before-Browser'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        Notification::assertSentTo($user, \App\Notifications\NewDeviceLoginNotification::class);
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'new_device_login']);
    }

    public function test_user_can_revoke_a_single_other_session_without_affecting_the_current_one(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'someone-elses-device',
            'user_id' => $user->id,
            'ip_address' => '10.1.1.1',
            'user_agent' => 'OtherDevice',
            'payload' => base64_encode(''),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)
            ->delete(route('profile.sessions.revoke', 'someone-elses-device'))
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'someone-elses-device']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_revoke_their_own_current_session_through_the_revoke_endpoint(): void
    {
        config()->set('session.driver', 'database');

        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard');
        $currentSessionId = session()->getId();

        $this->delete(route('profile.sessions.revoke', $currentSessionId))
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }
}
