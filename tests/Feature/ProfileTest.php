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
            ->assertSeeText('Profile and Security')
            ->assertSee('Account Information')
            ->assertSee('Two-Factor Authentication')
            ->assertSee('Other Devices')
            ->assertSee('Danger Zone');
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

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_user_with_quotation_cannot_delete_account_and_session_is_preserved(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        \App\Models\Supplier::create([
            'user_id' => $supplierUser->id,
            'company_name' => 'Supplier Co',
        ]);

        $period = \App\Models\Period::create([
            'name' => 'Period 2026',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $purchasing->id,
        ]);

        $pr = \App\Models\PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/09/2026/001',
            'status' => 'submitted',
        ]);

        \App\Models\Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplierUser->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this
            ->actingAs($supplierUser)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        // User row is preserved in database
        $this->assertNotNull($supplierUser->fresh());

        // User remains authenticated (session was not destroyed)
        $this->assertAuthenticatedAs($supplierUser);
    }

    public function test_user_with_purchase_requisition_cannot_delete_account_and_session_is_preserved(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $period = \App\Models\Period::create([
            'name' => 'Period PR Test',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $purchasing->id,
        ]);

        \App\Models\PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/09/2026/002',
            'status' => 'submitted',
        ]);

        $response = $this
            ->actingAs($purchasing)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($purchasing->fresh());
        $this->assertAuthenticatedAs($purchasing);
    }

    public function test_database_exception_fallback_preserves_authentication_and_returns_controlled_error(): void
    {
        $user = User::factory()->create();

        // Simulate a database-level delete exception by hooking deleting event
        User::deleting(function () {
            throw new \Illuminate\Database\QueryException(
                'mysql',
                'DELETE FROM users WHERE id = ?',
                [],
                new \Exception('Integrity constraint violation')
            );
        });

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }
}
