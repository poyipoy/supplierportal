<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Queued so the SMTP round-trip never happens inline on the forgot-password
 * request. Without this, "email exists" (send + wait) and "email doesn't
 * exist" (skip send) responded in noticeably different times, which leaked
 * account existence even though the response message is identical.
 */
class AdasiResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * Build the ADASI-branded mail message without changing the password-broker
     * token or URL-generation contract supplied by Laravel.
     */
    public function toMail($notifiable): MailMessage
    {
        $passwordBroker = config('auth.defaults.passwords');

        return (new MailMessage)
            ->subject('Reset your password | ADASI Supplier Portal')
            ->view([
                'html' => 'emails.auth.reset-password',
                'text' => 'emails.auth.reset-password-text',
            ], [
                'recipientName' => (string) ($notifiable->name ?? ''),
                'resetUrl' => $this->resetUrl($notifiable),
                'expiresInMinutes' => (int) config("auth.passwords.{$passwordBroker}.expire", 60),
            ]);
    }
}
