<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Services\UserPreferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    use Queueable;

    protected $title;

    protected $message;

    protected $url;

    protected $icon;

    protected $data;

    /**
     * Create a new notification instance.
     */
    public function __construct($title, $message, $url = '#', $icon = 'bell', array $data = [], array $replace = [], string $locale = 'en', array $localizedReplace = [])
    {
        $locale = UserPreferenceService::normalizeLocale($locale);
        $translator = app('translator');
        foreach ($localizedReplace as $name => $key) {
            if (is_string($name) && is_string($key)) {
                $replace[$name] = self::localizedReplacement($key, $locale, $translator);
            }
        }
        // Freeze recipient copy before database/broadcast delivery; no global locale mutation.
        $this->title = $translator->get($title, $replace, $locale);
        $this->message = $translator->get($message, $replace, $locale);
        $this->icon = $icon;
        $this->data = $data;

        // Force the URL to be relative to avoid cross-domain 403 errors when testing on multiple domains
        if (str_starts_with($url, 'http')) {
            $parsedUrl = parse_url($url);
            $this->url = ($parsedUrl['path'] ?? '/').(isset($parsedUrl['query']) ? '?'.$parsedUrl['query'] : '');
        } else {
            $this->url = $url;
        }
    }

    private static function localizedReplacement(string $key, string $locale, object $translator): string
    {
        if (str_starts_with($key, '@date:')) {
            $value = substr($key, 6);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

            return $date && $date->format('Y-m-d') === $value
                ? $date->locale($locale)->translatedFormat('d M Y')
                : $value;
        }

        return (string) $translator->get($key, [], $locale);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'icon' => $this->icon,
        ], $this->data);

        if ($notifiable instanceof User) {
            try {
                $prefService = app(NotificationPreferenceService::class);
                $key = $prefService->keyFor($this);
                if ($key !== null && $prefService->deliveryFor($notifiable, $key, $this->data()) === 'silent') {
                    $payload['silent'] = true;
                } else {
                    unset($payload['silent']);
                }
            } catch (\Throwable) {
                unset($payload['silent']);
            }
        } else {
            unset($payload['silent']);
        }

        return $payload;
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'icon' => $this->icon,
        ], $this->data));
    }

    public function data(): array
    {
        return is_array($this->data) ? $this->data : [];
    }

    public function event(): ?string
    {
        $event = $this->data['event'] ?? null;

        return is_string($event) && $event !== '' ? $event : null;
    }

    public function eventKey(): ?string
    {
        $eventKey = $this->data['event_key'] ?? null;

        return is_string($eventKey) && $eventKey !== '' ? $eventKey : null;
    }
}
