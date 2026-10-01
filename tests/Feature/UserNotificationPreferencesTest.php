<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Services\UserPreferenceService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'local_invoice_submission_received';

    public function test_guests_cannot_view_or_save_notifications(): void
    {
        $this->get(route('profile.notifications'))->assertRedirect(route('login'));
        $this->patch(route('profile.notifications.update'), $this->payload(false))->assertRedirect(route('login'));
    }

    public function test_all_account_roles_can_open_the_page_without_a_preference_write(): void
    {
        foreach (['admin', 'purchasing', 'supplier', 'qc', 'accounting', 'finance', 'ga'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('profile.notifications'))->assertOk()
                ->assertSeeText('Notifications')->assertSeeText('Manage how you receive optional notifications.')
                ->assertSeeText('Required notifications')->assertDontSee('type="checkbox"', false);
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        }
    }

    public function test_local_supplier_sees_only_the_registered_email_control_with_accessible_form_semantics(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk()
            ->assertSeeText('Local invoices')->assertSeeText('Invoice submission confirmations')
            ->assertSeeText('Email')->assertSeeText('physical-document obligations');
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//h1[normalize-space(.)="Notifications"]'));
        $this->assertCount(1, $xpath->query('//fieldset/legend[normalize-space(.)="Invoice submission confirmations"]'));
        $name = 'notification_preferences['.self::KEY.'][mail]';
        $checkbox = $xpath->query('//input[@type="checkbox"]')->item(0);
        $this->assertSame($name, $checkbox->getAttribute('name'));
        $this->assertSame('1', $checkbox->getAttribute('value'));
        $this->assertTrue($checkbox->hasAttribute('checked'));
        $this->assertNotSame('', $checkbox->getAttribute('aria-describedby'));
        $this->assertCount(1, $xpath->query('//label[@for="'.$checkbox->getAttribute('id').'"]'));
        $this->assertCount(1, $xpath->query('//input[@type="hidden" and @name="'.$name.'" and @value="0"]'));
        $this->assertCount(1, $xpath->query('//form[@action="'.route('profile.notifications.update').'"]//input[@name="_token"]'));
        $this->assertCount(1, $xpath->query('//form//input[@name="_method" and @value="PATCH"]'));
        $this->assertCount(1, $xpath->query('//input[@type="checkbox"]'));
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_notification_save_and_reload_persists_false_and_reenable_removes_the_override(): void
    {
        $user = $this->localSupplier();
        $this->actingAs($user)->patch(route('profile.notifications.update'), $this->payload('0'))
            ->assertRedirect(route('profile.notifications'))->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference;
        $this->assertSame([self::KEY => ['mail' => false]], $saved->notification_preferences);
        $this->assertSame(1, $saved->revision);
        $this->assertSame(1, $saved->sidebar_revision);
        $this->get(route('profile.notifications'))->assertOk()->assertViewHas('effectivePreferences', [self::KEY => ['mail' => false]]);
        $this->patch(route('profile.notifications.update'), $this->payload('1'))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->preference->notification_preferences);
        $this->assertSame(2, $user->fresh()->preference->revision);
    }

    public function test_ineligible_users_cannot_save_even_an_empty_preference_map(): void
    {
        foreach (['admin', 'purchasing', 'finance', 'accounting', 'qc', 'ga', 'supplier'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->patch(route('profile.notifications.update'), $this->payload(false))->assertForbidden();
            $this->patch(route('profile.notifications.update'), ['notification_preferences' => []])->assertForbidden();
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        }
    }

    public function test_inactive_or_non_active_accounts_have_no_editable_events(): void
    {
        $service = app(NotificationPreferenceService::class);
        foreach ([['is_active' => false], ['account_status' => User::ACCOUNT_STATUS_PENDING], ['account_status' => User::ACCOUNT_STATUS_REVISION], ['account_status' => User::ACCOUNT_STATUS_REJECTED]] as $state) {
            $user = $this->localSupplier($state);
            $this->assertSame([], $service->eventsFor($user));
        }
    }

    public function test_dual_scope_preference_is_account_wide_regardless_of_active_portal(): void
    {
        $user = $this->localSupplier();
        $this->actingAs($user)->withSession(['supplier_context' => 'import'])
            ->patch(route('profile.notifications.update'), $this->payload(false))->assertSessionHasNoErrors();
        foreach (['import', 'local'] as $context) {
            $this->withSession(['supplier_context' => $context])->get(route('profile.notifications'))
                ->assertOk()->assertViewHas('effectivePreferences', [self::KEY => ['mail' => false]]);
        }
        $this->assertSame(1, $user->preference()->count());
    }

    public function test_forged_events_channels_nested_metadata_and_invalid_booleans_are_rejected(): void
    {
        $user = $this->localSupplier();
        $cases = [
            ['unknown' => ['mail' => false]],
            ['invoice_paid' => ['mail' => false]],
            [self::KEY => ['database' => false]],
            [self::KEY => ['broadcast' => false]],
            [self::KEY => ['sms' => false]],
            [self::KEY => ['whatsapp' => false]],
            [self::KEY => ['telegram' => false]],
            [self::KEY => ['push' => false]],
            [self::KEY => ['mail' => false, 'class' => User::class]],
            [self::KEY => ['mail' => ['enabled' => false]]],
            [self::KEY => ['mail' => 'false']],
            [self::KEY => ['mail' => null]],
            [self::KEY => ['mail' => 2]],
        ];
        $this->actingAs($user);
        foreach ($cases as $values) {
            $this->patchJson(route('profile.notifications.update'), ['notification_preferences' => $values])->assertUnprocessable();
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        }
    }

    public function test_unsupported_root_fields_cannot_modify_current_or_other_accounts(): void
    {
        $user = $this->localSupplier();
        $other = $this->localSupplier();
        $before = $user->fresh()->getAttributes();
        foreach (['user_id' => $other->id, 'recipient' => $other->id, 'email' => $other->email, 'class' => User::class, 'route' => 'profile.edit', 'theme' => 'dark', 'role' => 'admin', 'revision' => 99, 'supplier_context' => 'local'] as $field => $value) {
            $this->actingAs($user)->patchJson(route('profile.notifications.update'), [...$this->payload(false), $field => $value])
                ->assertUnprocessable();
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $other->id]);
    }

    public function test_save_preserves_all_customization_fields_and_sidebar_revision(): void
    {
        $user = $this->localSupplier();
        $values = ['theme' => 'dark', 'accent' => 'slate', 'density' => 'compact', 'sidebar_state' => 'collapsed', 'page_size' => 50, 'quick_access' => [], 'dashboard_preferences' => ['supplier.local' => ['hidden' => [], 'order' => []]], 'timezone' => 'Asia/Jakarta', 'date_format' => 'iso', 'time_format' => '12h', 'number_format' => 'indonesian'];
        $preference = $user->preference()->create($values);
        $preference->forceFill(['revision' => 9, 'sidebar_revision' => 5])->save();
        $before = $preference->fresh();
        $this->actingAs($user)->patch(route('profile.notifications.update'), $this->payload(false))->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference;
        foreach ($values as $field => $value) {
            $this->assertSame($before->{$field}, $saved->{$field});
        }
        $this->assertSame(10, $saved->revision);
        $this->assertSame(5, $saved->sidebar_revision);
    }

    public function test_effective_defaults_ignore_stale_or_malformed_stored_overrides(): void
    {
        $user = $this->localSupplier();
        app(UserPreferenceService::class)->saveNotificationPreferences($user, []);
        foreach ([null, [], ['stale' => ['mail' => false]], [self::KEY => ['mail' => '0']], [self::KEY => ['mail' => null]]] as $stored) {
            DB::table('user_preferences')->where('user_id', $user->id)->update(['notification_preferences' => $stored === null ? null : json_encode($stored)]);
            app(NotificationPreferenceService::class)->forget($user);
            $this->actingAs($user)->get(route('profile.notifications'))->assertOk()
                ->assertViewHas('effectivePreferences', [self::KEY => ['mail' => true]]);
        }
    }

    public function test_notifications_and_layout_reuse_one_preference_lookup(): void
    {
        $user = $this->localSupplier();
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries++;
            }
        });
        $this->actingAs($user)->get(route('profile.notifications'))->assertOk();
        $this->assertSame(1, $queries);
    }

    public function test_notification_update_requires_a_valid_csrf_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $user = $this->localSupplier();
        $this->actingAs($user)->withSession(['_token' => 'expected-token'])
            ->patch(route('profile.notifications.update'), $this->payload(false))->assertStatus(419);
        $this->patch(route('profile.notifications.update'), [...$this->payload(false), '_token' => 'expected-token'])
            ->assertRedirect(route('profile.notifications'))->assertSessionHasNoErrors();
    }

    private function localSupplier(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'supplier', ...$attributes]);
        $user->supplierScopes()->firstOrCreate(['scope' => 'local']);

        return $user;
    }

    private function payload(mixed $value): array
    {
        return ['notification_preferences' => [self::KEY => ['mail' => $value]]];
    }
}
