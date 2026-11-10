<?php

namespace App\Enums;

use ValueError;

enum LocalInvoiceStatus: string
{
    case WAITING_PHYSICAL_DOCUMENT = 'WAITING_PHYSICAL_DOCUMENT';
    case UNDER_VERIFICATION = 'UNDER_VERIFICATION';
    case NEED_REVISION = 'NEED_REVISION';
    case READY_TO_PAY = 'READY_TO_PAY';
    case PAID = 'PAID';
    case EXPIRED = 'EXPIRED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    /**
     * Historical/legacy database status strings mapped to their canonical enum case.
     *
     * @var array<string, self>
     */
    private const LEGACY_SYNONYMS = [
        'SUBMITTED' => self::WAITING_PHYSICAL_DOCUMENT,
        'UNDER_REVIEW' => self::UNDER_VERIFICATION,
        'APPROVED' => self::READY_TO_PAY,
        'PAYMENT_SCHEDULED' => self::READY_TO_PAY,
        'COMPLETED' => self::PAID,
    ];

    /**
     * Resolve both canonical status values and historical legacy synonyms.
     *
     * @throws ValueError
     */
    public static function tryFromWithLegacy(string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtoupper(trim($value));

        $direct = self::tryFrom($normalized);
        if ($direct !== null) {
            return $direct;
        }

        if (isset(self::LEGACY_SYNONYMS[$normalized])) {
            return self::LEGACY_SYNONYMS[$normalized];
        }

        throw new ValueError(sprintf('Unknown LocalInvoiceStatus value "%s".', $value));
    }

    /**
     * Safe nullable variant of tryFromWithLegacy.
     */
    public static function tryFromWithLegacyOrNull(string|self|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return self::tryFromWithLegacy($value);
        } catch (ValueError) {
            return null;
        }
    }

    /**
     * Formal transition topology S -> S' for LocalInvoice.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::WAITING_PHYSICAL_DOCUMENT => [
                self::UNDER_VERIFICATION,
                self::NEED_REVISION,
                self::REJECTED,
                self::CANCELLED,
                self::EXPIRED,
            ],
            self::UNDER_VERIFICATION => [
                self::UNDER_VERIFICATION,
                self::READY_TO_PAY,
                self::NEED_REVISION,
                self::REJECTED,
            ],
            self::NEED_REVISION => [
                self::WAITING_PHYSICAL_DOCUMENT,
                self::REJECTED,
                self::CANCELLED,
                self::EXPIRED,
            ],
            self::CANCELLED => [
                self::WAITING_PHYSICAL_DOCUMENT,
            ],
            self::READY_TO_PAY => [
                self::PAID,
            ],
            self::PAID,
            self::EXPIRED,
            self::REJECTED => [],
        };
    }

    /**
     * Check whether transitioning from the current state to $target is structurally valid.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Check whether the status is a terminal sink with no outgoing transitions.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Localized human-readable label integrating with lang/status.php.
     */
    public function label(): string
    {
        $key = 'status.local_invoice.'.strtolower($this->value);
        if (app('translator')->has($key)) {
            return __($key);
        }

        return __('status.meta.unknown_value', ['value' => $this->value]);
    }

    /**
     * Semantic badge tone integrating with App\Support\StatusHelper.
     */
    public function tone(): string
    {
        return match ($this) {
            self::WAITING_PHYSICAL_DOCUMENT,
            self::NEED_REVISION => 'warning',
            self::UNDER_VERIFICATION => 'info',
            self::READY_TO_PAY,
            self::PAID => 'success',
            self::EXPIRED,
            self::REJECTED,
            self::CANCELLED => 'error',
        };
    }

    /**
     * Lucide icon name for timeline and status chip affordances.
     */
    public function icon(): string
    {
        return match ($this) {
            self::WAITING_PHYSICAL_DOCUMENT => 'file-clock',
            self::UNDER_VERIFICATION => 'file-search',
            self::NEED_REVISION => 'file-warning',
            self::READY_TO_PAY => 'badge-check',
            self::PAID => 'check-circle-2',
            self::EXPIRED => 'clock-alert',
            self::REJECTED => 'x-circle',
            self::CANCELLED => 'ban',
        };
    }
}
