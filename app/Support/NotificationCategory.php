<?php

namespace App\Support;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class NotificationCategory
{
    public const ALL = 'all';
    public const CHAT = 'chat';
    public const QUOTATION = 'quotation';
    public const DOCUMENT = 'document';
    public const OTHER = 'other';

    public static function options(): array
    {
        return [
            self::ALL => [
                'label' => 'Semua',
                'short_label' => 'Semua',
                'icon' => 'bi-bell',
                'description' => 'Seluruh notifikasi',
            ],
            self::CHAT => [
                'label' => 'Chat',
                'short_label' => 'Chat',
                'icon' => 'bi-chat-dots',
                'description' => 'Pesan negosiasi',
            ],
            self::QUOTATION => [
                'label' => 'Penawaran',
                'short_label' => 'Penawaran',
                'icon' => 'bi-tags',
                'description' => 'PR dan quotation',
            ],
            self::DOCUMENT => [
                'label' => 'Dokumen PO',
                'short_label' => 'Dokumen',
                'icon' => 'bi-file-earmark-check',
                'description' => 'Status dokumen impor',
            ],
            self::OTHER => [
                'label' => 'Lainnya',
                'short_label' => 'Lainnya',
                'icon' => 'bi-grid',
                'description' => 'Info sistem lain',
            ],
        ];
    }

    public static function key(DatabaseNotification $notification): string
    {
        $data = $notification->data ?? [];
        $explicitCategory = (string) ($data['category'] ?? '');

        if ($explicitCategory !== '' && $explicitCategory !== self::ALL && self::isAllowed($explicitCategory)) {
            return $explicitCategory;
        }

        $title = Str::lower((string) ($data['title'] ?? ''));
        $message = Str::lower((string) ($data['message'] ?? ''));
        $url = Str::lower((string) ($data['url'] ?? ''));
        $icon = Str::lower((string) ($data['icon'] ?? ''));
        $type = Str::lower((string) ($data['type'] ?? ''));

        if (Str::contains($type, ['chat', 'message']) || Str::contains($url, '/conversations') || Str::contains($icon, 'chat') || Str::contains($title, ['pesan', 'chat'])) {
            return self::CHAT;
        }

        if (
            Str::contains($type, ['po_document', 'document'])
            ||
            Str::contains($title . ' ' . $message, ['dokumen', 'invoice', 'bill of lading', 'packing list', 'form-e'])
            || Str::contains($icon, ['file-earmark-check', 'check2-circle'])
        ) {
            return self::DOCUMENT;
        }

        if (
            Str::contains($type, ['quotation', 'penawaran'])
            ||
            Str::contains($url, ['/quotations', '/requirements'])
            || Str::contains($title . ' ' . $message, ['penawaran', 'quotation', 'permintaan material', 'pr ', 'revisi'])
        ) {
            return self::QUOTATION;
        }

        return self::OTHER;
    }

    public static function isAllowed(?string $category): bool
    {
        return array_key_exists($category, self::options());
    }
}
