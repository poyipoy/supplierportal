<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;

class SystemNotification extends Notification
{
    use Queueable;

    protected $title;
    protected $message;
    protected $url;
    protected $icon;
    protected $titleParams;
    protected $messageParams;

    /**
     * Create a new notification instance.
     */
    public function __construct($title, $message, $url = '#', $icon = 'bi-bell', array $titleParams = [], array $messageParams = [])
    {
        $this->title = $title;
        $this->message = $message;
        $this->url = $url;
        $this->icon = $icon;
        $this->titleParams = $titleParams;
        $this->messageParams = $messageParams;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database']; // only store in database
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_locale ?? App::getLocale();

        return [
            'title' => __($this->title, $this->titleParams, $locale),
            'message' => __($this->message, $this->messageParams, $locale),
            'url' => $this->url,
            'icon' => $this->icon,
        ];
    }
}
