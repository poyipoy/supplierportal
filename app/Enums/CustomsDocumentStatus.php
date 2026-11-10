<?php

namespace App\Enums;

use ValueError;

enum CustomsDocumentStatus: string
{
    case PENDING = 'pending';
    case ISSUED = 'issued';
    case PROCESSING = 'processing';
    case RECEIVED = 'received';
    case VERIFIED = 'verified';
    case DONE = 'done';

    /**
     * Resolve canonical and historical legacy synonym status values deterministically.
     */
    public static function tryFromWithLegacy(string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim($value));

        if ($case = self::tryFrom($normalized)) {
            return $case;
        }

        return match ($normalized) {
            'open', 'waiting' => self::PENDING,
            'uploaded', 'submitted' => self::RECEIVED,
            'in_progress' => self::PROCESSING,
            'approved' => self::VERIFIED,
            'completed' => self::DONE,
            default => throw new ValueError(sprintf('"%s" is not a valid backing value for enum %s', $value, self::class)),
        };
    }

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

    public function rank(): int
    {
        return match ($this) {
            self::PENDING => 0,
            self::ISSUED,
            self::PROCESSING => 1,
            self::RECEIVED => 2,
            self::VERIFIED,
            self::DONE => 3,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case !== $this,
        ));
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return false;
    }

    public function label(): string
    {
        $key = 'status.shipment_document.'.$this->value;

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::VERIFIED,
            self::DONE => 'success',
            self::RECEIVED,
            self::ISSUED => 'info',
            self::PENDING,
            self::PROCESSING => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::ISSUED => 'file-check',
            self::PROCESSING => 'loader',
            self::RECEIVED => 'inbox',
            self::VERIFIED,
            self::DONE => 'check-circle-2',
        };
    }
}
