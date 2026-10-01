<?php

namespace App\Listeners;

use App\Contracts\UserConfigurableNotification;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyNotificationPreferences
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notifiable instanceof User || ! $event->notification instanceof UserConfigurableNotification) {
            return null;
        }

        try {
            $key = $event->notification->preferenceKey();
            $registered = $this->preferences->registry()[$key] ?? null;
            if (! is_array($registered) || ($registered['class'] ?? null) !== $event->notification::class) {
                return null;
            }

            $channel = $registered['channels'][$event->channel] ?? null;
            if (! is_array($channel) || ($channel['configurable'] ?? false) !== true) {
                return null;
            }

            return $this->preferences->enabled($event->notifiable, $key, $event->channel) ? null : false;
        } catch (Throwable $exception) {
            try {
                Log::warning('Notification preference enforcement failed; legacy delivery retained.', [
                    'recipient_id' => $event->notifiable->getKey(),
                    'notification_class' => $event->notification::class,
                    'channel' => $event->channel,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // A logging failure must not prevent legacy notification delivery either.
            }

            return null;
        }
    }
}
