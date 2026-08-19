<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_screen_is_not_available(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_public_registration_post_is_not_available(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Str0ng!Passphrase',
            'password_confirmation' => 'Str0ng!Passphrase',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
