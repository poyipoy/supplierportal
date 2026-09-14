<?php

namespace App\Notifications\LocalInvoice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PhysicalDeliveryReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $submissionNumber,
        public string $invoiceNumber,
        public string $scheduledDate,
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
        return (new MailMessage)
            ->subject('Pengingat Pengiriman Berkas Fisik: '.$this->submissionNumber)
            ->line("Mengingatkan bahwa jadwal penyerahan dokumen fisik invoice [{$this->invoiceNumber}] dijadwalkan pada hari Rabu, {$this->scheduledDate}.")
            ->line('Harap pastikan berkas fisik invoice asli, faktur pajak, surat jalan, dan tanda terima dibawa ke loket kasir Finance PT Astra Daido Steel Indonesia.')
            ->line('Keterlambatan penyerahan berkas fisik hingga 2 kali jadwal Rabu berturut-turut akan menyebabkan pengajuan kedaluwarsa (Expired).')
            ->action('Lihat Detail Pengajuan', $this->url);
    }
}
