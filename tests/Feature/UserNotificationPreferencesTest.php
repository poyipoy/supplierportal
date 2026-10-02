<?php

namespace Tests\Feature;

use App\Exports\InspectionsExport;
use App\Models\ExportJob;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\SystemNotification;
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

    private const KEY = 'local_invoice_submitted';

    public function test_phase_five_registry_and_flat_event_form(): void
    {
        $registry = config('notification_preferences');
        $this->assertCount(40, $registry);
        $expectedKeys = [
            'pr_submitted', 'quotation_submitted', 'quotation_revised', 'quotation_accepted', 'quotation_rejected',
            'quotation_revision_requested', 'quotation_negotiation_message', 'conversation_message_created',
            'po_issued', 'po_item_progress_updated', 'document_status_updated', 'document_all_completed',
            'shipment_submitted', 'po_material_arrived', 'qc_inspection_ok', 'qc_inspection_ng',
            'claim_created', 'claim_responded', 'claim_resolved', 'export_completed', 'export_failed',
            'new_device_login', 'repeated_lockouts_detected', 'supplier_registration_submitted',
            'supplier_registration_resubmitted', 'supplier_registration_revision_requested',
            'supplier_registration_rejected', 'supplier_registration_approved', 'local_invoice_submitted',
            'local_invoice_resubmitted', 'local_invoice_cancelled', 'local_invoice_physical_received',
            'local_invoice_approved', 'local_invoice_revision_requested', 'local_invoice_rejected',
            'local_invoice_partial_payment', 'local_invoice_paid', 'local_invoice_overpaid',
            'local_invoice_refund_settled', 'local_invoice_physical_delivery_reminder',
        ];
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($registry));
        $this->assertCount(40, array_unique(array_column($registry, 'source_event')));
        foreach ($registry as $entry) {
            $this->assertSame(SystemNotification::class, $entry['class']);
            $this->assertTrue($entry['default']);
            $this->assertNotEmpty($entry['source_event']);
            $this->assertArrayNotHasKey('channels', $entry);
        }
        $this->actingAs($this->localSupplier())->get(route('profile.notifications'))->assertOk()
            ->assertSeeText('Choose which in-app notifications you want to receive.')
            ->assertSee('name="notification_preferences[local_invoice_paid]"', false)
            ->assertSeeText('Reset to defaults')->assertDontSeeText('Required notifications');
    }

    public function test_phase_five_flat_save_reset_and_stale_mail_compatibility(): void
    {
        $user = $this->localSupplier();
        $row = $user->preference()->create([...config('user_preferences.defaults'), 'theme' => 'dark']);
        $stale = ['local_invoice_submission_received' => ['mail' => false], 'local_invoice_paid' => false];
        $row->forceFill(['notification_preferences' => $stale])->save();
        $this->actingAs($user)->get(route('profile.notifications'))
            ->assertViewHas('effectivePreferences', fn ($values) => $values['local_invoice_submitted'] === true && $values['local_invoice_paid'] === false);
        $this->assertEquals($stale, $row->fresh()->notification_preferences);
        $this->patch(route('profile.notifications.update'), ['notification_preferences' => ['local_invoice_submitted' => '0']])->assertSessionHasNoErrors();
        $this->assertSame(['local_invoice_paid' => false, 'local_invoice_submitted' => false], $row->fresh()->notification_preferences);
        $this->delete('/profile/notifications')->assertRedirect(route('profile.notifications'))
            ->assertSessionHas('success', 'Notification preferences reset to defaults.');
        $this->assertNull($row->fresh()->notification_preferences);
        $this->assertSame('dark', $row->fresh()->theme);
        $this->assertSame(3, $row->fresh()->revision);
    }

    public function test_guests_cannot_view_or_save_notifications(): void
    {
        $this->get(route('profile.notifications'))->assertRedirect(route('login'));
        $this->patch(route('profile.notifications.update'), $this->payload(false))->assertRedirect(route('login'));
        $this->delete(route('profile.notifications.reset'))->assertRedirect(route('login'));
    }

    public function test_all_account_roles_can_open_the_page_without_a_preference_write(): void
    {
        foreach (['admin', 'purchasing', 'supplier', 'qc', 'accounting', 'finance', 'ga'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('profile.notifications'))->assertOk()
                ->assertSeeText('Notifications')->assertSeeText('Choose which in-app notifications you want to receive.')
                ->assertDontSeeText('Required notifications')
                ->assertViewHas('events', function (array $events): bool {
                    $order = [
                        'Purchase requisitions', 'Quotations', 'Conversations', 'Purchase orders', 'Documents',
                        'Shipments and QC', 'Material claims', 'Local invoices', 'Supplier registration', 'Exports', 'Security',
                    ];
                    $actual = array_values(array_unique(array_column($events, 'category')));

                    return $actual === array_values(array_intersect($order, $actual));
                });
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        }
    }

    public function test_local_supplier_sees_only_the_registered_email_control_with_accessible_form_semantics(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk()
            ->assertSeeText('Local invoices')->assertSeeText('Invoice diajukan')
            ->assertDontSeeText('Required notifications');
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
        $this->assertCount(1, $xpath->query('//fieldset/legend[normalize-space(.)="Invoice diajukan"]'));
        $name = 'notification_preferences['.self::KEY.']';
        $checkbox = $xpath->query('//input[@type="checkbox" and @name="'.$name.'"]')->item(0);
        $this->assertSame($name, $checkbox->getAttribute('name'));
        $this->assertSame('1', $checkbox->getAttribute('value'));
        $this->assertTrue($checkbox->hasAttribute('checked'));
        $this->assertNotSame('', $checkbox->getAttribute('aria-describedby'));
        $this->assertCount(1, $xpath->query('//label[@for="'.$checkbox->getAttribute('id').'"]'));
        $this->assertCount(1, $xpath->query('//input[@type="hidden" and @name="'.$name.'" and @value="0"]'));
        $this->assertCount(1, $xpath->query('//form[@action="'.route('profile.notifications.update').'" and input[@name="_method" and @value="PATCH"]]//input[@name="_token"]'));
        $this->assertCount(1, $xpath->query('//form//input[@name="_method" and @value="PATCH"]'));
        $this->assertCount(13, $xpath->query('//input[@type="checkbox"]'));
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_notification_save_and_reload_persists_false_and_reenable_removes_the_override(): void
    {
        $user = $this->localSupplier();
        $this->actingAs($user)->patch(route('profile.notifications.update'), $this->payload('0'))
            ->assertRedirect(route('profile.notifications'))->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference;
        $this->assertSame([self::KEY => false], $saved->notification_preferences);
        $this->assertSame(1, $saved->revision);
        $this->assertSame(1, $saved->sidebar_revision);
        $this->get(route('profile.notifications'))->assertOk()->assertViewHas('effectivePreferences', fn ($values) => $values[self::KEY] === false);
        $this->patch(route('profile.notifications.update'), $this->payload('1'))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->preference->notification_preferences);
        $this->assertSame(2, $user->fresh()->preference->revision);
    }

    public function test_ineligible_roles_cannot_save_local_invoice_preferences(): void
    {
        foreach (['admin', 'purchasing', 'qc', 'ga'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->patchJson(route('profile.notifications.update'), $this->payload(false))->assertUnprocessable();
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
        $user->supplierScopes()->firstOrCreate(['scope' => 'import']);
        $this->actingAs($user)->withSession(['supplier_context' => 'import'])
            ->patch(route('profile.notifications.update'), $this->payload(false))->assertSessionHasNoErrors();
        foreach (['import', 'local'] as $context) {
            $this->withSession(['supplier_context' => $context])->get(route('profile.notifications'))
                ->assertOk()->assertViewHas('effectivePreferences', fn ($values) => $values[self::KEY] === false);
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
                ->assertViewHas('effectivePreferences', fn ($values) => $values[self::KEY] === true);
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
        $this->delete(route('profile.notifications.reset'))->assertStatus(419);
        $this->delete(route('profile.notifications.reset'), ['_token' => 'expected-token'])
            ->assertRedirect(route('profile.notifications'))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->preference->notification_preferences);
    }

    public function test_role_scope_audiences_have_only_actual_event_options(): void
    {
        $service = app(NotificationPreferenceService::class);
        foreach ([
            'admin' => ['pr_submitted', 'supplier_registration_submitted', 'repeated_lockouts_detected', 'export_completed'],
            'purchasing' => ['quotation_submitted', 'document_status_updated', 'qc_inspection_ok', 'supplier_registration_approved'],
            'finance' => ['local_invoice_submitted', 'supplier_registration_submitted', 'export_completed'],
            'accounting' => ['local_invoice_submitted', 'export_completed'],
            'qc' => ['po_material_arrived', 'export_completed'],
            'ga' => [],
        ] as $role => $required) {
            $user = User::factory()->create(['role' => $role]);
            $keys = array_keys($service->eventsFor($user));
            foreach ([...$required, 'new_device_login'] as $key) {
                $this->assertContains($key, $keys);
            }
            $this->assertNotContains('local_invoice_paid', $keys);
            $this->assertNotContains('quotation_accepted', $keys);
            if ($role === 'ga') {
                $this->assertSame(['new_device_login'], $keys);
            }
            if ($role === 'accounting') {
                $this->assertNotContains('supplier_registration_submitted', $keys);
            }
        }
        $supplier = $this->localSupplier();
        $local = array_keys($service->eventsFor($supplier));
        $this->assertContains('local_invoice_paid', $local);
        $this->assertNotContains('quotation_accepted', $local);
        $supplier->supplierScopes()->create(['scope' => 'import']);
        $service->forget($supplier);
        foreach (['import', 'local'] as $context) {
            session(['supplier_context' => $context]);
            $both = array_keys($service->eventsFor($supplier));
            $this->assertContains('local_invoice_paid', $both);
            $this->assertContains('quotation_accepted', $both);
        }
    }

    public function test_owner_derived_events_and_cached_exists_queries(): void
    {
        $creator = User::factory()->create(['role' => 'qc']);
        $supplier = $this->localSupplier();
        $po = PurchaseOrder::create([
            'created_by' => $creator->id, 'supplier_id' => $supplier->id,
            'currency' => 'IDR', 'po_number' => 'PO-OWNER-PREFERENCE', 'status' => 'active',
        ]);
        $ga = User::factory()->create(['role' => 'ga']);
        ExportJob::create([
            'user_id' => $ga->id, 'label' => 'Existing owner export', 'export_class' => InspectionsExport::class,
            'export_args' => [], 'file_name' => 'owner.xlsx', 'disk' => 'private', 'status' => 'queued',
        ]);
        $reads = ['purchase_orders' => 0, 'export_jobs' => 0];
        DB::listen(function ($query) use (&$reads): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                foreach (array_keys($reads) as $table) {
                    if (str_contains(strtolower($query->sql), $table)) {
                        $reads[$table]++;
                    }
                }
            }
        });
        $service = app(NotificationPreferenceService::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertArrayHasKey('document_status_updated', $service->eventsFor($creator));
            $this->assertArrayHasKey('document_all_completed', $service->eventsFor($creator));
            $this->assertArrayHasKey('export_completed', $service->eventsFor($ga));
            $this->assertArrayHasKey('export_failed', $service->eventsFor($ga));
        }
        $this->assertSame(['purchase_orders' => 1, 'export_jobs' => 1], $reads);
        $po->delete();
        $service->forget($creator);
        $this->assertArrayNotHasKey('document_status_updated', $service->eventsFor($creator));
    }

    private function localSupplier(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'supplier', ...$attributes]);
        $user->supplierScopes()->delete();
        $user->supplierScopes()->firstOrCreate(['scope' => 'local']);

        return $user;
    }

    private function payload(mixed $value): array
    {
        return ['notification_preferences' => [self::KEY => $value]];
    }
}
