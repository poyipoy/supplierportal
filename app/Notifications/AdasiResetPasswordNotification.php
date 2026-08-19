<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AdasiResetPasswordNotification extends ResetPassword
{
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
