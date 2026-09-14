<?php

namespace App\Notifications\LocalInvoice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $submissionNumber,
        public string $invoiceNumber,
        public float $amount,
        public ?string $paymentReference,
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
        $formattedAmount = 'Rp '.number_format($this->amount, 0, ',', '.');

        $mail = (new MailMessage)
            ->subject('Konfirmasi Pembayaran Invoice: '.$this->invoiceNumber)
            ->line("Pembayaran untuk tagihan invoice nomor [{$this->invoiceNumber}] (No. Pengajuan: {$this->submissionNumber}) telah berhasil diproses.")
            ->line("Total Nilai Tagihan: {$formattedAmount}");

        if ($this->paymentReference) {
            $mail->line("Nomor Referensi Transfer / Bank: {$this->paymentReference}");
        }

        return $mail->line('Dana telah dikirimkan ke rekening bank terdaftar Anda. Silakan memeriksa mutasi rekening tujuan.')
            ->action('Lihat Detail Invoice', $this->url);
    }
}
