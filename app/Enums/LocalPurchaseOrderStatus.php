<?php

namespace App\Enums;

use ValueError;

enum LocalPurchaseOrderStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
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
            'APPROVED' => self::OPEN,
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
            self::OPEN => [
                self::CLOSED,
                self::CANCELLED,
            ],
            self::CLOSED => [],
            self::CANCELLED => [
                self::OPEN,
            ],
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
        $key = 'status.finance.'.strtolower($this->value);

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::OPEN => 'success',
            self::CLOSED => 'info',
            self::CANCELLED => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OPEN => 'check-circle-2',
            self::CLOSED => 'archive',
            self::CANCELLED => 'ban',
        };
    }
}
