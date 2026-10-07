<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\User;
use App\Support\JsTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierRegistrationLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_switch_locale_via_route(): void
    {
        $response = $this->get(route('locale.switch', ['locale' => 'id']));

        $response->assertRedirect();
        $response->assertSessionHas('locale', 'id');
    }

    public function test_locale_switch_sanitizes_return_to_against_open_redirect(): void
    {
        // Malicious external redirect attempt should fall back to back/home
        $response = $this->get('/locale/en?return_to=https://evil.com/phishing');
        $response->assertRedirect();
        $this->assertStringNotContainsString('evil.com', (string) $response->headers->get('Location'));

        foreach (['//evil.com/phishing', '/\\evil.com/phishing'] as $returnTo) {
            $unsafeResponse = $this->get('/locale/en?return_to='.urlencode($returnTo));
            $unsafeResponse->assertRedirect();
            $this->assertStringNotContainsString('evil.com', (string) $unsafeResponse->headers->get('Location'));
        }

        // Safe relative internal redirect
        $safeResponse = $this->get('/locale/id?return_to='.urlencode('/supplier/register'));
        $safeResponse->assertRedirect('/supplier/register');
        $safeResponse->assertSessionHas('locale', 'id');
    }

    public function test_supplier_registration_form_renders_in_selected_session_locale(): void
    {
        // 1. Visit in Indonesian
        $responseId = $this->withSession(['locale' => 'id'])->get(route('supplier.register'));
        $responseId->assertOk();
        $responseId->assertSee('lang="id"', false);
        $responseId->assertSee(__('registration.account_profile', [], 'id'));
        $responseId->assertSee(__('registration.legal_pic_bank', [], 'id'));
        $responseId->assertSee('aria-label="'.__('registration.language_selector', [], 'id').'"', false);
        $responseId->assertSee('Umum', false)
            ->assertSee('Status PKP', false)
            ->assertSee('Status Non-PKP', false)
            ->assertSee('js.validation.digit_progress', false);
        $this->assertSame(':count/:max digit', app(JsTranslations::class)->payload()['messages']['js.validation.digit_progress']);
        $responseId->assertSee("currentLocale: 'id'", false);

        // 2. Visit in English
        $responseEn = $this->withSession(['locale' => 'en'])->get(route('supplier.register'));
        $responseEn->assertOk();
        $responseEn->assertSee('lang="en"', false);
        $responseEn->assertSee(__('registration.account_profile', [], 'en'));
        $responseEn->assertSee(__('registration.legal_pic_bank', [], 'en'));
        $responseEn->assertSee('aria-label="'.__('registration.language_selector', [], 'en').'"', false);
        $responseEn->assertSee('General', false)
            ->assertSee('PKP status', false)
            ->assertSee('Non-PKP status', false)
            ->assertSee('js.validation.digit_progress', false);
        $this->assertSame(':count/:max digits', app(JsTranslations::class)->payload()['messages']['js.validation.digit_progress']);
        $responseEn->assertSee("currentLocale: 'en'", false);
    }

    public function test_guest_registration_uses_safe_autocomplete_and_search_control_identifiers(): void
    {
        $response = $this->get(route('supplier.register'))->assertOk();

        $response->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('autocomplete="organization"', false)
            ->assertSee('autocomplete="street-address"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('autocomplete="name"', false)
            ->assertSee('id="bank_select-search"', false)
            ->assertDontSee('name="bank_select-search"', false)
            ->assertSee('min-width: 0;', false);
    }

    public function test_authenticated_user_persists_locale_preference_when_switching(): void
    {
        $user = User::factory()->create(['role' => 'supplier', 'account_status' => User::ACCOUNT_STATUS_ACTIVE, 'is_active' => true]);
        $preference = $user->preference()->create([
            ...config('user_preferences.defaults'),
            'theme' => 'dark',
            'page_size' => 50,
            'locale' => 'en',
        ]);
        $startingRevision = (int) $preference->fresh()->revision;

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'id']));

        $response->assertRedirect();
        $saved = $user->fresh()->preference;
        $this->assertSame('id', $saved?->locale);
        $this->assertSame('dark', $saved?->theme);
        $this->assertSame(50, $saved?->page_size);
        $this->assertSame($startingRevision + 1, $saved?->revision);
        $response->assertSessionHas('locale', 'id');
    }

    public function test_authenticated_get_only_changes_session_and_invalid_locale_input_falls_back_to_english(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $preference = $user->preference()->create([
            ...config('user_preferences.defaults'),
            'locale' => 'id',
        ]);

        $this->actingAs($user)->get(route('locale.switch', ['locale' => 'en']))->assertRedirect();
        $this->assertSame('id', $preference->fresh()->locale);

        $response = $this->post('/locale', ['locale' => ['id']]);
        $response->assertRedirect()->assertSessionHas('locale', 'en');
        $this->assertSame('en', $preference->fresh()->locale);
    }
}
