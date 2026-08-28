<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Heads-up to the account owner that a sign-in happened from a device/IP
 * combination that had no other active session for this account. This is a
 * cheap heuristic (no separate "known devices" table): a device that hasn't
 * signed in for a while, or whose previous session already expired/was
 * evicted, will look "new" again even if it's genuinely the owner's regular
 * device. If that produces too many false positives in practice, upgrade
 * to a persisted known-devices table instead of this session-based check.
 */
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
            ->line('Your account was just signed in from a device or location we haven\'t seen recently.')
            ->line('IP address: '.($this->ipAddress !== '' ? $this->ipAddress : 'Unknown'))
            ->line('Device: '.($this->userAgent !== '' ? $this->userAgent : 'Unknown'))
            ->line('Time: '.$this->occurredAt->timezone(config('app.timezone'))->format('d M Y H:i').' ('.config('app.timezone').')')
            ->action('Review Active Sessions', route('profile.edit'))
            ->line('If this wasn\'t you, sign that device out from the Active Sessions list on your profile page and change your password right away.');
    }
}
