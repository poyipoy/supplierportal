<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class NotificationDomain
{
    public const GLOBAL = 'global';

    public const IMPORT = 'import';

    public const LOCAL = 'local';

    /**
     * Resolve the domain for a notification based on its event name and payload data.
     */
    public static function resolveDomain(?string $event, array $data = []): string
    {
        // Explicit domain takes priority
        $explicit = $data['domain'] ?? null;
        if (is_string($explicit) && in_array($explicit, [self::GLOBAL, self::IMPORT, self::LOCAL], true)) {
            return $explicit;
        }

        // Local invoice indicators
        if (isset($data['local_invoice_id'])) {
            return self::LOCAL;
        }

        $event = (string) ($event ?? '');

        if (Str::startsWith($event, 'local_invoice.') || Str::startsWith($event, 'local-invoice.')) {
            return self::LOCAL;
        }

        // Global indicators (system-wide events)
        $globalPrefixes = ['export.', 'announcement.', 'system.', 'auth.', 'supplier_registration.'];
        foreach ($globalPrefixes as $prefix) {
            if (Str::startsWith($event, $prefix)) {
                return self::GLOBAL;
            }
        }

        // Import procurement events
        $importPrefixes = [
            'pr.', 'quotation.', 'po.', 'po_document.', 'claim.',
            'chat.', 'conversation.', 'shipment.', 'qc.', 'inspection.',
        ];
        foreach ($importPrefixes as $prefix) {
            if (Str::startsWith($event, $prefix)) {
                return self::IMPORT;
            }
        }

        // Fallback: check URL patterns in data
        $url = Str::lower((string) ($data['url'] ?? ''));
        if (Str::contains($url, ['/local-supplier/', '/accounting/', '/finance/', '/ga/'])) {
            return self::LOCAL;
        }

        // Default to import for existing/unclassified notifications
        return self::IMPORT;
    }

    /**
     * Determine the domain for an existing database notification (including historical).
     */
    public static function forNotification(DatabaseNotification $notification): string
    {
        $data = $notification->data ?? [];
        $event = (string) ($data['event'] ?? '');

        return self::resolveDomain($event, $data);
    }

    /**
     * Get the allowed notification domains for a user based on role and context.
     */
    public static function allowedDomainsForUser(User $user): array
    {
        if ($user->isAdmin()) {
            return [self::GLOBAL, self::IMPORT, self::LOCAL];
        }

        if ($user->isLocalOperator()) {
            return [self::GLOBAL, self::LOCAL];
        }

        if ($user->isPurchasing() || $user->isQc()) {
            return [self::GLOBAL, self::IMPORT];
        }

        if ($user->isSupplier()) {
            $hasLocal = $user->hasSupplierScope('local');
            $hasImport = $user->hasSupplierScope('import');

            if ($hasLocal && $hasImport) {
                // Dual-scope supplier: domain depends on active context
                return PortalContext::isLocal($user)
                    ? [self::GLOBAL, self::LOCAL]
                    : [self::GLOBAL, self::IMPORT];
            }

            if ($hasLocal) {
                return [self::GLOBAL, self::LOCAL];
            }

            if ($hasImport) {
                return [self::GLOBAL, self::IMPORT];
            }

            // No scopes — safety fallback
            return [self::GLOBAL];
        }

        return [self::GLOBAL];
    }

    /**
     * Check if a user is eligible for a specific notification domain.
     */
    public static function isUserEligibleForDomain(User $user, string $domain): bool
    {
        return in_array($domain, self::allowedDomainsForUser($user), true);
    }

    /**
     * Check if a specific notification is allowed for a user.
     */
    public static function isNotificationAllowed(DatabaseNotification $notification, User $user): bool
    {
        $domain = self::forNotification($notification);

        return self::isUserEligibleForDomain($user, $domain);
    }
}
