<?php

namespace Tests\Feature;

use App\Listeners\ApplyNotificationPreferences;
use App\Listeners\LogAuthenticationEvent;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\UserPreferenceService;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NotificationPreferenceDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'mail.mailers.array' => ['transport' => 'array'],
            'mail.from.address' => 'portal@example.test',
            'mail.from.name' => 'ADASI Portal',
            'queue.default' => 'sync',
            'broadcasting.default' => 'log',
        ]);
        Mail::purge('array');
        Storage::fake('private');
    }

    public function test_characterization_no_preference_record_delivers_actual_acknowledgement_mail(): void
    {
        $supplier = $this->localSupplier();

        $supplier->notify($this->acknowledgement());

        $this->assertCount(0, $this->mailTransport()->messages());
        $this->assertSame('local_invoice.submitted', $supplier->notifications()->sole()->data['event']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $supplier->id]);
    }

    public function test_characterization_mandatory_notifications_use_the_actual_mail_sender(): void
    {
        $supplier = $this->localSupplier();
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ($this->mandatoryNotifications() as $notification) {
            $supplier->notifyNow($notification);
        }
        $admin->notifyNow(new SystemNotification('Lockouts', 'Lockouts', '#', 'bell', ['event' => 'repeated_lockouts_detected']));
        $this->assertCount(0, $this->mailTransport()->messages());
        $this->assertSame(4, $supplier->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_characterization_unregistered_system_notification_preserves_both_channels(): void
    {
        $supplier = $this->localSupplier();
        $notification = new SystemNotification('Required workflow', 'Legacy delivery', '#');

        $this->assertSame(['database', 'broadcast'], $notification->via($supplier));
        $supplier->notify($notification);

        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertSame('Required workflow', $supplier->notifications()->sole()->data['title']);
    }

    public function test_characterization_submission_preserves_finance_database_delivery_and_supplier_mail(): void
    {
        $supplier = $this->localSupplier();
        $finance = User::factory()->create(['role' => 'finance']);

        $invoice = $this->submitInvoice($supplier, $finance);

        $this->assertSame(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, $invoice->status);
        $this->assertNotNull($invoice->receipt);
        $this->assertSame(1, $invoice->revisions()->count());
        $this->assertSame(LocalGoodsReceipt::STATUS_RESERVED, $invoice->activeGoodsReceiptHistories()->sole()->goodsReceipt->status);
        $this->assertSame('local_invoice.submitted', $finance->notifications()->sole()->data['event']);
        $this->assertCount(0, $this->mailTransport()->messages());
        $this->assertSame('local_invoice.submitted', $supplier->notifications()->sole()->data['event']);
    }

    public function test_characterization_acknowledgement_is_queued_only_after_commit(): void
    {
        $supplier = $this->localSupplier();
        DB::transaction(function () use ($supplier): void {
            app(NotificationService::class)->send($supplier, 'local_invoice.submitted', 'commit:one', 'Submitted', 'Submitted');
            $this->assertSame(0, $supplier->notifications()->count());
        });
        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_characterization_rolled_back_acknowledgement_never_enters_the_queue(): void
    {
        $supplier = $this->localSupplier();
        try {
            DB::transaction(function () use ($supplier): void {
                app(NotificationService::class)->send($supplier, 'local_invoice.submitted', 'rollback:one', 'Submitted', 'Submitted');
                throw new RuntimeException('Intentional rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Intentional rollback', $exception->getMessage());
        }
        $this->assertSame(0, $supplier->notifications()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_false_override_suppresses_only_the_actual_supplier_acknowledgement_mail(): void
    {
        $supplier = $this->localSupplier();
        $finance = User::factory()->create(['role' => 'finance']);
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);

        $invoice = $this->submitInvoice($supplier, $finance);

        $this->assertCount(0, $this->mailTransport()->messages());
        $this->assertSame(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, $invoice->status);
        $this->assertNotNull($invoice->receipt);
        $this->assertSame(1, $invoice->revisions()->count());
        $this->assertSame(LocalGoodsReceipt::STATUS_RESERVED, $invoice->activeGoodsReceiptHistories()->sole()->goodsReceipt->status);
        $this->assertSame('local_invoice.submitted', $finance->notifications()->sole()->data['event']);
    }

    public function test_reenabling_removes_the_override_and_delivers_mail_again(): void
    {
        $supplier = $this->localSupplier();
        $service = app(UserPreferenceService::class);
        $service->saveNotificationPreferences($supplier, ['local_invoice_submitted' => false]);
        $supplier->notifyNow($this->acknowledgement());
        $this->assertSame(0, $supplier->notifications()->count());
        $service->saveNotificationPreferences($supplier, ['local_invoice_submitted' => true]);
        $supplier->notifyNow($this->acknowledgement());
        $this->assertNull($supplier->preference()->sole()->notification_preferences);
        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public static function legacyOverrides(): array
    {
        return [
            'null' => [null],
            'empty' => [[]],
            'stale event' => [['retired_event' => ['mail' => false]]],
            'missing channel' => [['local_invoice_submission_received' => []]],
            'stale channel' => [['local_invoice_submission_received' => ['sms' => false]]],
            'string false' => [['local_invoice_submission_received' => ['mail' => 'false']]],
            'integer zero' => [['local_invoice_submission_received' => ['mail' => 0]]],
            'null value' => [['local_invoice_submission_received' => ['mail' => null]]],
            'malformed event' => [['local_invoice_submission_received' => false]],
            'true' => [['local_invoice_submission_received' => ['mail' => true]]],
        ];
    }

    #[DataProvider('legacyOverrides')]
    public function test_missing_or_malformed_stored_overrides_preserve_legacy_mail(?array $overrides): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, $overrides);

        $supplier->notifyNow($this->acknowledgement());

        $this->assertCount(0, $this->mailTransport()->messages());
        $this->assertSame(1, $supplier->notifications()->count());
    }

    public function test_mandatory_notifications_cannot_be_suppressed_by_stored_optional_or_forged_overrides(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, [SystemNotification::class => ['database' => false], 'local_invoice_submitted' => ['mail' => false]]);
        $supplier->notifyNow($this->acknowledgement());
        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
        app(UserPreferenceService::class)->saveNotificationPreferences($supplier, ['local_invoice_submitted' => false]);
        $supplier->notifyNow($this->acknowledgement());
        $this->assertSame(1, $supplier->notifications()->count());
    }

    public function test_exact_class_mismatch_cannot_claim_the_registered_preference_key(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);
        $notification = new class('Spoof', 'Spoof', '#', 'bell', ['event' => 'local_invoice.submitted']) extends SystemNotification {};
        $supplier->notifyNow($notification, ['database']);
        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_anonymous_recipient_preserves_legacy_mail(): void
    {
        $recipient = new AnonymousNotifiable;
        $result = app(ApplyNotificationPreferences::class)->handle(new NotificationSending($recipient, $this->acknowledgement(), 'database'));
        $this->assertNull($result);
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_unknown_delivery_channel_is_not_suppressed(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, ['local_invoice_submission_received' => ['mail' => false, 'test_unknown' => false]]);
        $result = app(ApplyNotificationPreferences::class)->handle(new NotificationSending($supplier, $this->acknowledgement(), 'test_unknown'));

        $this->assertNull($result);
    }

    public function test_unregistered_key_preserves_actual_mail_delivery(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);
        config(['notification_preferences' => []]);
        $supplier->notifyNow($this->acknowledgement());
        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_channel_marked_nonconfigurable_preserves_actual_mail_delivery(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);
        $listener = app(ApplyNotificationPreferences::class);
        foreach (['mail', 'unknown'] as $channel) {
            $this->assertNull($listener->handle(new NotificationSending($supplier, $this->acknowledgement(), $channel)));
        }
    }

    public function test_ineligible_recipient_ignores_stored_opt_out_and_sender_context(): void
    {
        foreach ([['role' => 'supplier', 'scopes' => ['import']], ['role' => 'supplier', 'scopes' => ['local'], 'is_active' => false], ['role' => 'supplier', 'scopes' => ['local'], 'account_status' => User::ACCOUNT_STATUS_PENDING], ['role' => 'admin', 'scopes' => []]] as $attributes) {
            $scopes = $attributes['scopes'];
            unset($attributes['scopes']);
            $recipient = $this->localSupplier($scopes);
            $recipient->forceFill($attributes)->save();
            $this->storeOverrides($recipient, ['local_invoice_submitted' => false]);
            session(['supplier_context' => 'local']);
            $recipient->notifyNow($this->acknowledgement());
            $this->assertSame(1, $recipient->notifications()->count());
        }
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_dual_scope_recipient_uses_one_durable_opt_out_in_either_sender_context(): void
    {
        $supplier = $this->localSupplier(['local', 'import']);
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);

        foreach (['import', 'local'] as $context) {
            session(['supplier_context' => $context]);
            $supplier->notifyNow($this->acknowledgement());
        }

        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_queued_delivery_reads_a_preference_changed_after_dispatch(): void
    {
        $supplier = $this->localSupplier();
        dispatch((new SendQueuedNotifications($supplier, $this->acknowledgement(), ['database', 'broadcast']))->onConnection('database')->onQueue('phase5-notification-test'));
        $this->assertSame(1, DB::table('jobs')->count());
        app(UserPreferenceService::class)->saveNotificationPreferences($supplier, ['local_invoice_submitted' => false]);
        $this->app->forgetScopedInstances();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'phase5-notification-test', '--once' => true, '--sleep' => 0, '--tries' => 1]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, $supplier->notifications()->count());
    }

    public function test_queued_delivery_reads_reenable_after_dispatch(): void
    {
        $supplier = $this->localSupplier();
        app(UserPreferenceService::class)->saveNotificationPreferences($supplier, ['local_invoice_submitted' => false]);
        dispatch((new SendQueuedNotifications($supplier, $this->acknowledgement(), ['database', 'broadcast']))->onConnection('database')->onQueue('phase5-notification-test'));
        app(UserPreferenceService::class)->saveNotificationPreferences($supplier, ['local_invoice_submitted' => true]);
        $this->app->forgetScopedInstances();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'phase5-notification-test', '--once' => true, '--sleep' => 0, '--tries' => 1]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_queued_recipient_losing_local_eligibility_keeps_legacy_delivery(): void
    {
        $supplier = $this->localSupplier();
        app(UserPreferenceService::class)->saveNotificationPreferences($supplier, ['local_invoice_submitted' => false]);
        dispatch((new SendQueuedNotifications($supplier, $this->acknowledgement(), ['database', 'broadcast']))->onConnection('database')->onQueue('phase5-notification-test'));
        $supplier->supplierScopes()->delete();
        $this->app->forgetScopedInstances();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'phase5-notification-test', '--once' => true, '--sleep' => 0, '--tries' => 1]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $supplier->notifications()->count());
    }

    public function test_recipient_caches_do_not_leak_and_worker_scopes_read_fresh_values(): void
    {
        $recipientA = $this->localSupplier();
        $recipientB = $this->localSupplier();
        $this->storeOverrides($recipientA, ['local_invoice_submitted' => false]);
        $scopeOne = app(NotificationPreferenceService::class);
        $recipientA->notifyNow($this->acknowledgement());
        $recipientB->notifyNow($this->acknowledgement());
        $this->assertSame(0, $recipientA->notifications()->count());
        $this->assertSame(1, $recipientB->notifications()->count());
        DB::table('user_preferences')->where('user_id', $recipientA->id)->update(['notification_preferences' => null]);
        $this->app->forgetScopedInstances();
        $this->assertNotSame($scopeOne, app(NotificationPreferenceService::class));
        $recipientA->notifyNow($this->acknowledgement());
        $this->assertSame(1, $recipientA->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_repeated_callbacks_measure_one_preference_and_eligibility_read_per_recipient(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);
        $reads = ['user_preferences' => 0, 'supplier_scopes' => 0];
        DB::listen(function ($query) use (&$reads): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            foreach (array_keys($reads) as $table) {
                if (str_contains(strtolower($query->sql), $table)) {
                    $reads[$table]++;
                }
            }
        });

        for ($iteration = 0; $iteration < 10; $iteration++) {
            app(NotificationService::class)->send(
                User::findOrFail($supplier->id), 'local_invoice.submitted', 'query-measure:'.$iteration, 'Submitted', 'Submitted'
            );
        }

        $this->assertSame(['user_preferences' => 1, 'supplier_scopes' => 1], $reads);
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_mandatory_and_unregistered_notifications_short_circuit_without_preference_reads(): void
    {
        $supplier = $this->localSupplier();
        $reads = ['user_preferences' => 0, 'supplier_scopes' => 0];
        DB::listen(function ($query) use (&$reads): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                foreach (array_keys($reads) as $table) {
                    if (str_contains(strtolower($query->sql), $table)) {
                        $reads[$table]++;
                    }
                }
            }
        });
        $supplier->notify(new SystemNotification('Legacy workflow', 'Legacy delivery', '#'));
        $this->assertSame(['user_preferences' => 0, 'supplier_scopes' => 0], $reads);
        $this->assertCount(0, $this->mailTransport()->messages());
        $this->assertSame(1, $supplier->notifications()->count());
    }

    public function test_preference_lookup_failure_fails_open_to_actual_mail_delivery(): void
    {
        $supplier = $this->localSupplier();
        $this->mock(NotificationPreferenceService::class, function ($mock): void {
            $mock->shouldReceive('keyFor')->andReturn('local_invoice_submitted');
            $mock->shouldReceive('enabled')->andThrow(new RuntimeException('Preference database unavailable'));
            $mock->shouldReceive('hasSupplierScope')->andThrow(new RuntimeException('Preference scope cache unavailable'));
        });
        $supplier->notifyNow($this->acknowledgement());
        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
        DB::transaction(function () use ($supplier): void {
            app(NotificationService::class)->send(
                $supplier, 'local_invoice.submitted', 'preference-failure-business', 'Submitted', 'Submitted'
            );
            $this->assertSame(1, $supplier->notifications()->count());
        });
        $this->assertSame(2, $supplier->notifications()->count());
    }

    public function test_cached_eligibility_does_not_suppress_an_inactive_or_rejected_recipient(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);
        $supplier->notifyNow($this->acknowledgement());
        $this->assertSame(0, $supplier->notifications()->count());
        $supplier->forceFill(['is_active' => false])->save();
        $supplier->fresh()->notifyNow($this->acknowledgement());
        $this->assertSame(1, $supplier->notifications()->count());
        $supplier->forceFill(['is_active' => true, 'account_status' => User::ACCOUNT_STATUS_REJECTED])->save();
        $supplier->fresh()->notifyNow($this->acknowledgement());
        $this->assertSame(2, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_real_preference_lookup_failure_logs_and_preserves_actual_mail_delivery(): void
    {
        $supplier = $this->localSupplier();
        request()->setUserResolver(fn () => $supplier);
        request()->setRouteResolver(fn () => new Route('GET', '/profile/notifications', fn () => null));
        $this->mock(UserPreferenceService::class, function ($mock) use ($supplier): void {
            $mock->shouldReceive('for')->once()->withArgs(
                fn (User $recipient): bool => $recipient->id === $supplier->id
            )->andThrow(new RuntimeException('Preference database unavailable'));
        });
        Log::spy();

        $supplier->notifyNow($this->acknowledgement());

        $this->assertCount(0, $this->mailTransport()->messages());
        $this->assertSame(1, $supplier->notifications()->count());
        Log::shouldHaveReceived('warning')->once()->with(
            'Notification preference lookup failed; legacy delivery retained.',
            \Mockery::on(fn (array $context): bool => $context['recipient_id'] === $supplier->id
                && $context['event_key'] === 'local_invoice_submitted'
                && $context['exception_class'] === RuntimeException::class),
        );
    }

    public function test_worker_ignores_a_stale_request_preference_snapshot_after_raw_database_reenable(): void
    {
        $supplier = $this->localSupplier();
        $this->storeOverrides($supplier, ['local_invoice_submitted' => false]);
        $snapshot = app(UserPreferenceService::class)->for($supplier);
        $this->assertSame(['local_invoice_submitted' => false], $snapshot['notification_preferences']);
        $scopeOne = app(NotificationPreferenceService::class);
        $this->assertFalse($scopeOne->enabled($supplier, 'local_invoice_submitted'));
        dispatch((new SendQueuedNotifications($supplier, $this->acknowledgement(), ['database', 'broadcast']))->onConnection('database')->onQueue('phase5-notification-test'));
        DB::table('user_preferences')->where('user_id', $supplier->id)->update(['notification_preferences' => null]);
        request()->setUserResolver(fn () => $supplier);
        request()->setRouteResolver(fn () => null);
        $this->app->forgetScopedInstances();
        $this->assertNotSame($scopeOne, app(NotificationPreferenceService::class));
        $this->assertSame(['local_invoice_submitted' => false], request()->attributes->get(UserPreferenceService::class.'.'.$supplier->id)['preferences']['notification_preferences']);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'phase5-notification-test', '--once' => true, '--sleep' => 0, '--tries' => 1]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $supplier->notifications()->count());
    }

    private function mailTransport(): ArrayTransport
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    public function test_generic_events_are_independent_and_disabled_channels_are_not_created(): void
    {
        Queue::fake();
        $supplier = $this->localSupplier(['import']);
        $this->storeOverrides($supplier, ['quotation_rejected' => false]);
        $supplier->notify(new SystemNotification('Same title', 'Same text', '#', 'bell', ['event' => 'quotation.rejected']));
        $this->assertSame(0, $supplier->notifications()->count());
        Queue::assertNothingPushed();
        $supplier->notify(new SystemNotification('Same title', 'Same text', '#', 'bell', ['event' => 'quotation.accepted']));
        $this->assertSame('quotation.accepted', $supplier->notifications()->sole()->data['event']);
        Queue::assertPushed(BroadcastEvent::class, 1);
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    public function test_security_opt_out_preserves_detection_and_audit_with_obsolete_mail_config_enabled(): void
    {
        config(['auth_security.notifications.mail_enabled' => true]);
        $user = User::factory()->create(['role' => 'finance']);
        $admin = User::factory()->create(['role' => 'admin']);
        app(UserPreferenceService::class)->saveNotificationPreferences($user, ['new_device_login' => false]);
        app(UserPreferenceService::class)->saveNotificationPreferences($admin, ['repeated_lockouts_detected' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('auth_known_devices', ['user_id' => $user->id]);
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'new_device_login']);
        $this->assertSame(0, $user->notifications()->count());
        $request = Request::create('/login', 'POST', ['email' => 'lockout-target@example.test']);
        for ($i = 0; $i < 3; $i++) {
            app(LogAuthenticationEvent::class)->handleLockout(new Lockout($request));
        }
        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'repeated_lockouts_detected']);
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    private function acknowledgement(): SystemNotification
    {
        return new SystemNotification('Submission received', 'SUB-TEST-001', '#', 'receipt', ['event' => 'local_invoice.submitted']);
    }

    private function mandatoryNotifications(): array
    {
        return array_map(fn ($event) => new SystemNotification('Workflow', 'Workflow', '#', 'bell', ['event' => $event]), [
            'new_device_login', 'local_invoice.revision_requested', 'local_invoice.paid', 'local_invoice.physical_delivery_reminder',
        ]);
    }

    public function test_disabled_reminder_is_consumed_without_replaying_when_reenabled(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 01:00:00', 'UTC'));
        $supplier = $this->localSupplier();
        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id, 'submission_number' => 'REMINDER-OFF-001',
            'invoice_number' => 'INV-REMINDER-OFF-001', 'invoice_date' => '2026-10-07',
            'po_number' => 'PO-REMINDER-OFF', 'invoice_amount' => '100000.00', 'tax_amount' => '11000.00',
            'submitted_at' => now(), 'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'scheduled_physical_delivery_date' => '2026-10-07',
        ]);
        app(UserPreferenceService::class)->saveNotificationPreferences($supplier, ['local_invoice_physical_delivery_reminder' => false]);
        $this->artisan('local-invoices:send-delivery-reminders')->assertSuccessful();
        $this->assertSame(0, $supplier->notifications()->count());
        $this->assertNotNull($invoice->fresh()->delivery_reminder_sent_at);
        $this->assertSame(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, $invoice->fresh()->status);
        $this->assertSame('2026-10-07', $invoice->fresh()->scheduled_physical_delivery_date->toDateString());
        app(UserPreferenceService::class)->saveNotificationPreferences($supplier, ['local_invoice_physical_delivery_reminder' => true]);
        $this->artisan('local-invoices:send-delivery-reminders')->assertSuccessful();
        $this->assertSame(0, $supplier->notifications()->count());
        $this->assertCount(0, $this->mailTransport()->messages());
    }

    private function localSupplier(array $scopes = ['local']): User
    {
        $supplier = User::factory()->create(['role' => 'supplier']);
        $supplier->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            $supplier->supplierScopes()->create(['scope' => $scope]);
        }

        return $supplier;
    }

    private function storeOverrides(User $user, ?array $overrides): void
    {
        $preference = $user->preference()->create(config('user_preferences.defaults'));
        DB::table('user_preferences')->where('id', $preference->id)->update([
            'notification_preferences' => $overrides === null ? null : json_encode($overrides, JSON_THROW_ON_ERROR),
        ]);
    }

    private function submitInvoice(User $supplier, User $finance): LocalInvoice
    {
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'Notification Vendor',
            'category' => 'Jasa',
            'vendor_category' => 'Jasa',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);
        $po = LocalPurchaseOrder::create([
            'po_number' => 'PO-ACK-001',
            'supplier_id' => $supplier->id,
            'total_amount' => '100000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
        ]);
        $gr = $po->goodsReceipts()->create([
            'gr_number' => 'GR-ACK-001',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '1.0000',
            'description' => 'Completed service',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => 'MANUAL',
            'created_by' => $finance->id,
        ]);

        return app(InvoiceSubmissionService::class)->submit($supplier, [
            'invoice_number' => 'ACK-001',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'invoice_amount' => '100000.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'scheduled_physical_delivery_date' => BusinessTime::today()->next(3)->toDateString(),
        ], [
            'invoice' => UploadedFile::fake()->createWithContent('ACK-001.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
        ]);
    }
}
