<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;


class SystemNotification extends Notification implements ShouldBroadcast
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
    public function __construct($title, $message, $url = '#', $icon = 'bi-bell', array $data = [], array $replace = [])
    {
        $this->title = __($title, $replace);
        $this->message = __($message, $replace);
        $this->url = $url;
        $this->icon = $icon;
        $this->data = $data;
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
        return array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'icon' => $this->icon,
        ], $this->data);
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
}
