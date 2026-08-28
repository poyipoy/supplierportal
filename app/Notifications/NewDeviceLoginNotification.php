<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class NewDeviceLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $ipAddress,
        private readonly string $userAgent,
        private readonly Carbon $occurredAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your account | ADASI Supplier Portal')
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('Your account was signed in on a device that has not been used with this account before.')
            ->line('IP address: '.($this->ipAddress !== '' ? $this->ipAddress : 'Unknown'))
            ->line('Device: '.($this->userAgent !== '' ? $this->userAgent : 'Unknown'))
            ->line('Time: '.$this->occurredAt->timezone(config('app.timezone'))->format('d M Y H:i').' ('.config('app.timezone').')')
            ->action('Review Active Sessions', route('profile.edit').'#active-sessions')
            ->line('If this wasn\'t you, sign that device out from the Active Sessions list on your profile page and change your password right away.');
    }
}
