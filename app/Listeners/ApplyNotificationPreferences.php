<?php

namespace App\Listeners;

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
        if (! $event->notifiable instanceof User || ! in_array($event->channel, ['database', 'broadcast'], true)) {
            return null;
        }

        try {
            $key = $this->preferences->keyFor($event->notification);
            if ($key === null) {
                return null;
            }

            return $this->preferences->enabled($event->notifiable, $key) ? null : false;
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
