<?php

namespace App\Enums;

use ValueError;

enum PaymentBatchStatus: string
{
    case DRAFT = 'DRAFT';
    case FINALIZED = 'FINALIZED';
    case PARTIALLY_PAID = 'PARTIALLY_PAID';
    case PAID = 'PAID';
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
            'CONFIRMED' => self::FINALIZED,
            'COMPLETED' => self::PAID,
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
            self::DRAFT => [
                self::FINALIZED,
                self::CANCELLED,
            ],
            self::FINALIZED => [
                self::PARTIALLY_PAID,
                self::PAID,
            ],
            self::PARTIALLY_PAID => [
                self::PAID,
            ],
            self::PAID,
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
        $key = 'status.finance.'.strtolower($this->value);

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::PAID => 'success',
            self::FINALIZED => 'info',
            self::PARTIALLY_PAID => 'warning',
            self::DRAFT => 'neutral',
            self::CANCELLED => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'file-edit',
            self::FINALIZED => 'lock',
            self::PARTIALLY_PAID => 'pie-chart',
            self::PAID => 'check-circle-2',
            self::CANCELLED => 'ban',
        };
    }
}
