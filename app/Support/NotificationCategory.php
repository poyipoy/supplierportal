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
                'label' => 'All',
                'short_label' => 'All',
                'icon' => 'bi-bell',
                'description' => 'All notifications',
            ],
            self::CHAT => [
                'label' => 'Chat',
                'short_label' => 'Chat',
                'icon' => 'bi-chat-dots',
                'description' => 'Negotiation messages',
            ],
            self::QUOTATION => [
                'label' => 'Quotation',
                'short_label' => 'Quotation',
                'icon' => 'bi-tags',
                'description' => 'PR and quotations',
            ],
            self::DOCUMENT => [
                'label' => 'PO Documents',
                'short_label' => 'Document',
                'icon' => 'bi-file-earmark-check',
                'description' => 'Import document status',
            ],
            self::OTHER => [
                'label' => 'Other',
                'short_label' => 'Other',
                'icon' => 'bi-grid',
                'description' => 'Other system information',
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

        if (Str::contains($type, ['chat', 'message']) || Str::contains($url, '/conversations') || Str::contains($icon, 'chat') || Str::contains($title, ['message', 'chat'])) {
            return self::CHAT;
        }

        if (
            Str::contains($type, ['po_document', 'document'])
            ||
            Str::contains($title . ' ' . $message, ['document', 'invoice', 'bill of lading', 'packing list', 'form-e'])
            || Str::contains($icon, ['file-earmark-check', 'check2-circle'])
        ) {
            return self::DOCUMENT;
        }

        if (
            Str::contains($type, ['quotation'])
            ||
            Str::contains($url, ['/quotations', '/requisitions'])
            || Str::contains($title . ' ' . $message, ['quotation', 'purchase requisition', 'pr ', 'revision', 'revisi'])
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
