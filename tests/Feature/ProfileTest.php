<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk()
            ->assertSeeText('My Profile')
            ->assertSee('Account Information')
            ->assertDontSeeText('Change Password')
            ->assertDontSeeText('Two-Factor Authentication')
            ->assertDontSeeText('Active Sessions')
            ->assertDontSeeText('Log Out Other Devices')
            ->assertDontSeeText('Delete Account')
            ->assertDontSeeText('Danger Zone')
            ->assertDontSee('profile-security-title', false)
            ->assertDontSee('profile-danger-title', false)
            ->assertDontSee('confirmUserDeletionModal', false)
            ->assertDontSee('delete_account_password', false);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_account_deletion_named_route_is_absent(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('profile.destroy'));
    }

    public function test_obsolete_profile_delete_request_cannot_delete_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ])->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_obsolete_method_spoofed_profile_delete_cannot_delete_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/profile', [
            '_method' => 'DELETE',
            'password' => 'password',
        ])->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_administrator_cannot_delete_their_own_account_through_user_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error', 'You cannot delete your own account.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertAuthenticatedAs($admin);
    }
}
