<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\NotificationCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

class NotificationService
{
    /**
     * @param  User|iterable<User>|null  $recipients
     */
    public function send(
        User|iterable|null $recipients,
        string $event,
        string $eventKey,
        string $title,
        string $message,
        string $url = '#',
        string $icon = 'bi-bell',
        array $data = [],
        array $replace = [],
    ): void {
        $recipientList = $recipients instanceof User
            ? collect([$recipients])
            : collect($recipients ?? []);

        $deliver = function () use ($recipientList, $event, $eventKey, $title, $message, $url, $icon, $data, $replace): void {
            $recipientList
                ->filter(fn ($recipient) => $recipient instanceof User && (bool) $recipient->is_active)
                ->unique('id')
                ->each(function (User $recipient) use ($event, $eventKey, $title, $message, $url, $icon, $data, $replace): void {
                    $notificationId = Uuid::uuid5(
                        Uuid::NAMESPACE_URL,
                        User::class.':'.$recipient->getKey().':'.$eventKey,
                    )->toString();

                    $category = $data['category'] ?? NotificationCategory::OTHER;
                    if (! is_string($category) || $category === NotificationCategory::ALL || ! NotificationCategory::isAllowed($category)) {
                        $category = NotificationCategory::OTHER;
                    }

                    $notification = new SystemNotification(
                        $title,
                        $message,
                        $url,
                        $icon,
                        array_merge($data, [
                            'event' => $event,
                            'event_key' => $eventKey,
                            'category' => $category,
                        ]),
                        $replace,
                    );
                    $notification->id = $notificationId;

                    try {
                        if ($recipient->notifications()->whereKey($notificationId)->exists()) {
                            return;
                        }

                        $recipient->notify($notification);
                    } catch (QueryException $exception) {
                        try {
                            $alreadyDelivered = $recipient->notifications()->whereKey($notificationId)->exists();
                        } catch (Throwable) {
                            $alreadyDelivered = false;
                        }

                        if (! $alreadyDelivered) {
                            $this->logFailure($eventKey, $recipient, $exception);
                        }
                    } catch (Throwable $exception) {
                        $this->logFailure($eventKey, $recipient, $exception);
                    }
                });
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($deliver);

            return;
        }

        $deliver();
    }

    private function logFailure(string $eventKey, User $recipient, Throwable $exception): void
    {
        Log::warning('Notification delivery failed.', [
            'event_key' => $eventKey,
            'recipient_id' => $recipient->getKey(),
            'recipient_role' => $recipient->role,
            'channel' => 'notification',
            'queue' => config('queue.default'),
            'exception_class' => $exception::class,
        ]);
    }
}
