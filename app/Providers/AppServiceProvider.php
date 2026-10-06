<?php

namespace App\Providers;

use App\Listeners\ApplyNotificationPreferences;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\LocalInvoice\Contracts\LocalPoProviderInterface;
use App\Services\LocalInvoice\Providers\DatabaseLocalPoProvider;
use App\Services\NotificationPreferenceService;
use App\Services\QuickAccessService;
use App\Services\RegionalDisplayFormatter;
use App\Services\UserPreferenceService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(NotificationPreferenceService::class);

        $this->app->scoped(RegionalDisplayFormatter::class, function (): RegionalDisplayFormatter {
            $user = auth()->user();

            return new RegionalDisplayFormatter($user instanceof User ? app(UserPreferenceService::class)->for($user) : []);
        });

        $this->app->bind(
            LocalPoProviderInterface::class,
            DatabaseLocalPoProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('bizdt', fn ($expr) => "<?php echo e(\\App\\Support\\BusinessTime::format($expr)); ?>");

        Event::listen(NotificationSending::class, ApplyNotificationPreferences::class);

        // Enforce safety: testing environment (--env=testing or APP_ENV=testing) MUST NEVER touch adasi_portal
        if ($this->app->environment('testing') || (isset($_SERVER['argv']) && in_array('--env=testing', $_SERVER['argv'], true))) {
            if ((string) config('database.connections.mysql.database') === 'adasi_portal') {
                config(['database.connections.mysql.database' => 'adasi_portal_test']);
            }
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = $event->command;
            if (in_array($command, ['migrate', 'migrate:fresh', 'migrate:reset', 'migrate:rollback', 'db:wipe', 'db:seed'], true)) {
                $isTestingEnv = app()->environment('testing') || (isset($_SERVER['argv']) && in_array('--env=testing', $_SERVER['argv'], true));
                $activeDb = (string) config('database.connections.mysql.database');
                if ($isTestingEnv && ($activeDb === 'adasi_portal' || (! str_ends_with($activeDb, '_test') && ! str_ends_with($activeDb, '_testing')))) {
                    throw new \RuntimeException("CRITICAL SAFETY GUARD: Command '{$command}' was invoked under testing mode (--env=testing or APP_ENV=testing), but active database is '{$activeDb}'. Testing migrations MUST target a test database (e.g. 'adasi_portal_test'). Aborting immediately to prevent data loss on local database.");
                }
            }
        });
        View::composer([
            'admin.dashboard', 'purchasing.dashboard', 'finance.dashboard', 'accounting.dashboard',
            'qc.dashboard', 'ga.dashboard', 'supplier.dashboard', 'local-supplier.dashboard',
        ], function ($view): void {
            $view->with('regionalFormatter', app(RegionalDisplayFormatter::class));
        });

        View::composer([
            'purchasing.shipments.index', 'supplier.shipments.index',
            'purchasing.quotations.index', 'local-invoices.table',
        ], function ($view): void {
            $view->with('regionalFormatter', app(RegionalDisplayFormatter::class));
        });

        View::composer([
            'purchasing.po.show', 'supplier.po.show',
            'purchasing.quotations.show', 'supplier.quotations.show',
            'purchasing.shipments.show', 'supplier.shipments.show',
            'ga.claims.index', 'ga.claims.show',
            'finance.ga-claims.index', 'finance.ga-claims.show',
            'purchasing.comparison.inter-supplier',
            'purchasing.comparison.historical',
            'purchasing.comparison.vs-best',
        ], function ($view): void {
            $view->with('regionalFormatter', app(RegionalDisplayFormatter::class));
        });

        View::composer(['purchasing.claims.show', 'supplier.claims.show'], function ($view): void {
            $view->with('regionalFormatter', app(RegionalDisplayFormatter::class));
        });

        View::composer([
            'local-invoices.detail', 'finance.invoices.show', 'purchasing.local-vendors.invoice-show',
            'supplier-registrations.index', 'supplier-registrations.show',
        ], function ($view): void {
            $view->with('regionalFormatter', app(RegionalDisplayFormatter::class));
        });

        View::composer('exports.index', function ($view): void {
            $view->with('regionalFormatter', app(RegionalDisplayFormatter::class));
        });

        View::composer([
            'admin.announcements.edit', 'admin.announcements.index', 'admin.exchange-rates.index',
            'admin.requisitions.show', 'admin.users.edit',
            'finance.drp._paid_table_content', 'finance.drp.ga', 'finance.drp.show', 'finance.drp.supplier',
            'finance.local-procurement.index', 'finance.local-procurement.show', 'finance.overpayments.index',
            'finance.vouchers.print', 'finance.vouchers.show', 'finance.vendors.index', 'finance.vendors.show',
            'ga.claims.receipt', 'ga.drp.draft',
            'local-supplier.purchase-orders.index',
            'local-supplier.purchase-orders.show', 'local-supplier.vendor-profile.show',
            'purchasing.comparison._historical_content',
            'purchasing.comparison._inter_supplier_content', 'purchasing.comparison._vs_best_content',
            'purchasing.drp._paid_table_content', 'purchasing.drp.ga', 'purchasing.drp.show', 'purchasing.drp.supplier',
            'purchasing.local-vendors.index', 'purchasing.local-vendors.show', 'purchasing.pr.show',
            'qc.inspections.create', 'qc.inspections.show',
            'supplier.announcements.index', 'supplier.announcements.show', 'supplier.claims.show',
        ], function ($view): void {
            $view->with('regionalFormatter', app(RegionalDisplayFormatter::class));
        });

        View::composer('layouts.app', function ($view): void {
            $user = auth()->user();
            if (! $user instanceof User) {
                return;
            }

            $preferenceService = app(UserPreferenceService::class);
            $preferences = $preferenceService->for($user);
            $quickAccess = app(QuickAccessService::class);

            $view->with('userPreferences', $preferences)
                ->with('preferenceFrontendPayload', $preferenceService->frontendPayload($user, $preferences))
                ->with('quickAccessItems', $quickAccess->selectedFor(
                    $user,
                    $preferences['quick_access'],
                    $quickAccess->contextFor($user),
                ));
        });

        View::composer(['accounting.invoices.index', 'accounting.reports.index'], function ($view) {
            $view->with('suppliers', User::where('role', 'supplier')
                ->where(function ($query) {
                    $query->whereHas('supplierScopes', fn ($q) => $q->where('scope', 'local'))
                        ->orWhereExists(function ($sub) {
                            $sub->selectRaw(1)
                                ->from('local_invoices')
                                ->whereColumn('local_invoices.supplier_id', 'users.id');
                        });
                })
                ->with('supplier')
                ->orderBy('name')
                ->get());
        });
        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            $notification = $event->notification;

            Log::warning('Notification channel failed.', [
                'event_key' => $notification instanceof SystemNotification ? $notification->eventKey() : null,
                'recipient_id' => $event->notifiable?->getKey(),
                'recipient_role' => $event->notifiable?->role,
                'channel' => $event->channel,
                'queue' => config('queue.default'),
                'exception_class' => isset($event->data['exception']) && $event->data['exception'] instanceof \Throwable
                    ? $event->data['exception']::class
                    : null,
            ]);
        });

        Queue::failing(function (JobFailed $event): void {
            $jobName = $event->job->resolveName();
            if (! str_contains($jobName, 'BroadcastNotificationCreated') && ! str_contains($jobName, 'BroadcastEvent')) {
                return;
            }

            $eventKey = null;
            $recipientId = null;
            $recipientRole = null;

            try {
                $serializedCommand = $event->job->payload()['data']['command'] ?? null;
                $command = is_string($serializedCommand)
                    ? unserialize($serializedCommand, ['allowed_classes' => true])
                    : null;
                $notificationEvent = $command instanceof BroadcastEvent ? $command->event : null;

                if ($notificationEvent instanceof BroadcastNotificationCreated) {
                    $eventKey = $notificationEvent->notification instanceof SystemNotification
                        ? $notificationEvent->notification->eventKey()
                        : null;
                    $recipientId = $notificationEvent->notifiable?->getKey();
                    $recipientRole = $notificationEvent->notifiable?->role;
                }
            } catch (\Throwable) {
                // Failure logging must never interfere with the queue failure lifecycle.
            }

            Log::warning('Queued notification broadcast failed.', [
                'event_key' => $eventKey,
                'recipient_id' => $recipientId,
                'recipient_role' => $recipientRole,
                'channel' => 'broadcast',
                'queue' => $event->job->getQueue(),
                'exception_class' => $event->exception::class,
            ]);
        });
    }
}
