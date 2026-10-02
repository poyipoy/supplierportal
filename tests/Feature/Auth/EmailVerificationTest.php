<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_named_routes_and_profile_resend_ui_are_absent(): void
    {
        foreach (['verification.notice', 'verification.verify', 'verification.send'] as $name) {
            $this->assertFalse(Route::has($name));
        }
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/profile')->assertOk()
            ->assertDontSee('send-verification')->assertDontSee('Send verification email')
            ->assertDontSee('Your email address has not been verified.');
    }

    public function test_obsolete_verification_endpoints_cannot_send_mail_or_update_metadata(): void
    {
        Mail::fake();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/verify-email')->assertNotFound();
        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        $this->post('/email/verification-notification')->assertNotFound();
        $this->assertNull($user->fresh()->email_verified_at);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_unverified_metadata_does_not_bypass_existing_role_authorization(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'qc']);
        $this->actingAs($user)->get(route('qc.dashboard'))->assertOk();
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
