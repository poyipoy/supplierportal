<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to admins when a single account has been rate-limit locked out
 * several times within an hour — a pattern more consistent with a targeted
 * credential-stuffing/brute-force attempt than a user mistyping a password.
 */
class RepeatedLockoutAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $emailAttempted,
        private readonly int $lockoutCount,
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
            ->subject('Repeated sign-in lockouts detected | ADASI Supplier Portal')
            ->greeting('Hi '.($notifiable->name ?? 'Admin').',')
            ->line('The account "'.$this->emailAttempted.'" has been rate-limited '.$this->lockoutCount.' times in the last hour due to repeated failed sign-in attempts.')
            ->line('This pattern is more consistent with a targeted attack than a user simply mistyping their password.')
            ->action('Review Auth Audit Log', route('admin.auth-audit-logs.index'))
            ->line('If the account owner did not do this, consider contacting them and reviewing whether their credentials may be compromised.');
    }
}
