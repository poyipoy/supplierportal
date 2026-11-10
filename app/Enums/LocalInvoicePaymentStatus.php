<?php

namespace App\Enums;

use ValueError;

enum LocalInvoicePaymentStatus: string
{
    case OPEN = 'OPEN';
    case CORRECTION_REQUIRED = 'CORRECTION_REQUIRED';
    case FINALIZED = 'FINALIZED';

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
            'SETTLED', 'PAID' => self::FINALIZED,
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
                self::CORRECTION_REQUIRED,
                self::FINALIZED,
            ],
            self::CORRECTION_REQUIRED => [
                self::FINALIZED,
            ],
            self::FINALIZED => [],
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
            self::OPEN,
            self::FINALIZED => 'success',
            self::CORRECTION_REQUIRED => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OPEN => 'circle-dot',
            self::CORRECTION_REQUIRED => 'alert-triangle',
            self::FINALIZED => 'badge-check',
        };
    }
}
