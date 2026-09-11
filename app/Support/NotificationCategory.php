<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class NotificationCategory
{
    public const ALL = 'all';

    public const CHAT = 'chat';

    public const QUOTATION = 'quotation';

    public const DOCUMENT = 'document';

    public const INVOICE = 'invoice';

    public const OTHER = 'other';

    public static function options(): array
    {
        return [
            self::ALL => [
                'label' => 'All',
                'short_label' => 'All',
                'icon' => 'bell',
                'description' => 'All notifications',
            ],
            self::CHAT => [
                'label' => 'Chat',
                'short_label' => 'Chat',
                'icon' => 'message-circle-more',
                'description' => 'Negotiation messages',
            ],
            self::QUOTATION => [
                'label' => 'Quotation',
                'short_label' => 'Quotation',
                'icon' => 'tags',
                'description' => 'PR and quotations',
            ],
            self::DOCUMENT => [
                'label' => 'PO Documents',
                'short_label' => 'Document',
                'icon' => 'file-check',
                'description' => 'Import document status',
            ],
            self::INVOICE => [
                'label' => 'Invoice',
                'short_label' => 'Invoice',
                'icon' => 'receipt',
                'description' => 'Local invoice updates',
            ],
            self::OTHER => [
                'label' => 'Other',
                'short_label' => 'Other',
                'icon' => 'layout-grid',
                'description' => 'Other system information',
            ],
        ];
    }

    /**
     * Return only the categories relevant to the given user's notification domains.
     *
     * Local operators and local-only suppliers see: All, Invoice, Other.
     * Import users (purchasing, qc, import-only suppliers) see: All, Chat, Quotation, PO Documents, Other.
     * Admin and dual-scope suppliers see all categories.
     */
    public static function optionsForUser(User $user): array
    {
        $all = self::options();
        $domains = NotificationDomain::allowedDomainsForUser($user);

        $hasImport = in_array(NotificationDomain::IMPORT, $domains, true);
        $hasLocal = in_array(NotificationDomain::LOCAL, $domains, true);

        // Admin or users with both domains see everything
        if ($hasImport && $hasLocal) {
            return $all;
        }

        // Local-only: All, Invoice, Other
        if ($hasLocal && ! $hasImport) {
            return array_intersect_key($all, array_flip([
                self::ALL, self::INVOICE, self::OTHER,
            ]));
        }

        // Import-only: All, Chat, Quotation, PO Documents, Other
        if ($hasImport && ! $hasLocal) {
            return array_intersect_key($all, array_flip([
                self::ALL, self::CHAT, self::QUOTATION, self::DOCUMENT, self::OTHER,
            ]));
        }

        // Fallback: All and Other only
        return array_intersect_key($all, array_flip([self::ALL, self::OTHER]));
    }

    public static function key(DatabaseNotification $notification): string
    {
        $data = $notification->data ?? [];
        $explicitCategory = (string) ($data['category'] ?? '');

        if ($explicitCategory !== '' && $explicitCategory !== self::ALL && self::isAllowed($explicitCategory)) {
            return $explicitCategory;
        }

        // Local invoice heuristic (for historical notifications without explicit category)
        $event = (string) ($data['event'] ?? '');
        if (
            isset($data['local_invoice_id'])
            || Str::startsWith($event, 'local_invoice.')
            || Str::startsWith($event, 'local-invoice.')
        ) {
            return self::INVOICE;
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
            Str::contains($title.' '.$message, ['document', 'bill of lading', 'packing list', 'form-e'])
            || Str::contains($icon, ['file-earmark-check', 'check2-circle'])
        ) {
            return self::DOCUMENT;
        }

        if (
            Str::contains($type, ['quotation'])
            ||
            Str::contains($url, ['/quotations', '/requisitions'])
            || Str::contains($title.' '.$message, ['quotation', 'purchase requisition', 'pr ', 'revision', 'revisi'])
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
