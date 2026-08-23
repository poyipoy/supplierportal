<?php

namespace App\Providers;

use App\Notifications\SystemNotification;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
