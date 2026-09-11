<?php

namespace App\Notifications\LocalInvoice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RevisionRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $submission, public string $reason, public string $url)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Revision required: '.$this->submission)->line($this->reason)->line('Resubmit the invoice and deliver the updated physical documents for verification.')->action('View invoice', $this->url);
    }
}
