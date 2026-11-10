<?php

namespace App\Enums;

use ValueError;

enum SupplierChangeRequestStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    /**
     * Resolve canonical and historical legacy synonym status values deterministically.
     */
    public static function tryFromWithLegacy(string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtoupper(trim($value));

        if ($case = self::tryFrom($normalized)) {
            return $case;
        }

        return match ($normalized) {
            'OPEN', 'SUBMITTED' => self::PENDING,
            'VERIFIED', 'COMPLETED' => self::APPROVED,
            'DECLINED' => self::REJECTED,
            'CANCELED', 'VOID', 'SUPERSEDED' => self::CANCELLED,
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

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [
                self::APPROVED,
                self::REJECTED,
                self::CANCELLED,
            ],
            self::APPROVED,
            self::REJECTED,
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        $key = 'status.supplier_contact.'.strtolower($this->value);

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::APPROVED => 'success',
            self::REJECTED => 'error',
            self::CANCELLED => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::APPROVED => 'check-circle-2',
            self::REJECTED => 'x-circle',
            self::CANCELLED => 'ban',
        };
    }
}
