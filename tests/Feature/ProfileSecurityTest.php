<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_security(): void
    {
        $this->get('/profile/security')->assertRedirect(route('login'));
    }

    public function test_all_account_roles_can_view_security_without_creating_preferences(): void
    {
        foreach (['admin', 'purchasing', 'supplier', 'qc', 'accounting', 'finance', 'ga'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('profile.security'))
                ->assertOk()
                ->assertViewIs('profile.security')
                ->assertViewHas('user', fn (User $current) => $current->is($user));

            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        }
    }

    public function test_security_route_is_current_user_only_and_not_stored(): void
    {
        $route = app('router')->getRoutes()->getByName('profile.security');
        $this->assertNotNull($route);
        $this->assertSame('profile/security', $route->uri());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('no-store', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());

        $response = $this->actingAs(User::factory()->create())->get(route('profile.security'))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_security_renders_existing_controls_without_identity_or_deletion_forms(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('profile.security'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//h1'));
        $this->assertSame('Security', trim($xpath->query('//h1')->item(0)->textContent));

        foreach (['password.update', 'profile.two-factor.start', 'profile.logout-other-devices'] as $routeName) {
            $this->assertCount(1, $xpath->query('//form[@action="'.route($routeName).'"]'));
        }
        $this->assertCount(0, $xpath->query('//form[@action="'.route('profile.update').'"]'));
        $this->assertCount(0, $xpath->query('//*[@id="profile-security-title" or @id="profile-danger-title" or @id="confirmUserDeletionModal"]'));
        $response->assertDontSeeText('Delete Account')->assertDontSeeText('Danger Zone');

        $destination = $xpath->query('//*[@id="active-sessions"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $destination);
        $this->assertSame('-1', $destination->getAttribute('tabindex'));
        $label = $destination->getAttribute('aria-labelledby');
        $this->assertNotSame('', $label);
        $this->assertCount(1, $xpath->query('//*[@id="'.$label.'"]'));
    }

    public function test_password_fields_have_labels_autocomplete_and_no_values(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('profile.security'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        foreach (['current_password' => 'current-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'] as $name => $autocomplete) {
            $field = $xpath->query('//form[@action="'.route('password.update').'"]//input[@name="'.$name.'"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $field);
            $this->assertSame('password', $field->getAttribute('type'));
            $this->assertSame($autocomplete, $field->getAttribute('autocomplete'));
            $this->assertSame('', $field->getAttribute('value'));
            $this->assertCount(1, $xpath->query('//label[@for="'.$field->getAttribute('id').'"]'));
            foreach (preg_split('/\s+/', trim($field->getAttribute('aria-describedby'))) as $id) {
                if ($id !== '') {
                    $this->assertCount(1, $xpath->query('//*[@id="'.$id.'"]'));
                }
            }
        }
    }

    public function test_security_does_not_change_existing_preferences(): void
    {
        $user = User::factory()->create();
        $preference = $user->preference()->create([
            'theme' => 'dark',
            'density' => 'compact',
            'sidebar_state' => 'expanded',
            'page_size' => 50,
            'quick_access' => [],
        ]);
        $before = $preference->fresh()->getRawOriginal();

        $this->actingAs($user)->get(route('profile.security'))->assertOk();

        $this->assertSame($before, $preference->fresh()->getRawOriginal());
    }

    public function test_profile_exposes_only_fixed_legacy_active_sessions_bridge_and_security_link(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertOk();
        $response->assertSee((string) Js::from(route('profile.security').'#active-sessions'), false)
            ->assertDontSee('profile-security-title', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertGreaterThanOrEqual(1, $xpath->query('//a[@href="'.route('profile.security').'"]')->count());
        $this->assertCount(0, $xpath->query('//*[@id="active-sessions"]'));
    }

    public function test_security_validation_errors_are_associated_with_fields_and_secrets_are_not_repopulated(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['PRIVATE-RECOVERY-CODE'],
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();
        $errors = (new ViewErrorBag)
            ->put('updatePassword', new MessageBag(['current_password' => 'Current password is incorrect.', 'password' => 'Password confirmation does not match.']))
            ->put('logoutOtherDevices', new MessageBag(['password' => 'Password is incorrect.']))
            ->put('default', new MessageBag(['code' => 'The authentication code is invalid.']));
        $response = $this->actingAs($user)->withSession([
            'errors' => $errors,
            '_old_input' => ['current_password' => 'PRIVATE-OLD-PASSWORD', 'password' => 'PRIVATE-NEW-PASSWORD', 'password_confirmation' => 'PRIVATE-NEW-PASSWORD', 'code' => 'PRIVATE-CODE'],
        ])->get(route('profile.security'))->assertOk();
        $response->assertDontSee('PRIVATE-OLD-PASSWORD', false)->assertDontSee('PRIVATE-NEW-PASSWORD', false)
            ->assertDontSee('PRIVATE-CODE', false)->assertDontSee('PRIVATE-RECOVERY-CODE', false)
            ->assertDontSee('JBSWY3DPEHPK3PXP', false);
        $xpath = $this->xpath($response->getContent());
        foreach ([
            'update_password_current_password' => 'update_password_current_password_error',
            'update_password_password' => 'update_password_password_error',
            'update_password_password_confirmation' => 'update_password_password_error',
            'disable_two_factor_code' => 'disable_two_factor_code_error',
            'logout_other_devices_password' => 'logout_other_devices_password_error',
        ] as $fieldId => $errorId) {
            $field = $xpath->query('//input[@id="'.$fieldId.'"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $field);
            $this->assertSame('true', $field->getAttribute('aria-invalid'));
            $this->assertSame('', $field->getAttribute('value'));
            $this->assertCount(1, $xpath->query('//label[@for="'.$fieldId.'"]'));
            $descriptionIds = preg_split('/\s+/', trim($field->getAttribute('aria-describedby')));
            $this->assertContains($errorId, $descriptionIds);
            foreach ($descriptionIds as $id) {
                $this->assertCount(1, $xpath->query('//*[@id="'.$id.'"]'));
            }
            $this->assertCount(1, $xpath->query('//*[@id="'.$errorId.'" and @role="alert"]'));
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
