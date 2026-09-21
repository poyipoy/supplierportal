<?php

namespace App\Notifications\LocalInvoice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceSubmissionReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $submissionNumber,
        public string $invoiceNumber,
        public ?string $scheduledDeliveryDate,
        public string $url
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Pengajuan Invoice Diterima: '.$this->submissionNumber)
            ->line("Pengajuan tagihan invoice nomor [{$this->invoiceNumber}] (No. Pengajuan: {$this->submissionNumber}) telah berhasil diterima oleh sistem.");

        if ($this->scheduledDeliveryDate) {
            $mail->line("Jadwal penyerahan berkas fisik asli: Rabu, {$this->scheduledDeliveryDate}. Harap membawa berkas lengkap ke loket kasir Finance ADASI.");
        }

        return $mail->line('Harap pastikan kelengkapan berkas fisik invoice, faktur pajak (bila PKP), surat jalan, dan dokumen pendukung lainnya.')
            ->action('Lihat Detail Pengajuan', $this->url);
    }
}
