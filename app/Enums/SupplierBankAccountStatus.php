<?php

namespace App\Enums;

use ValueError;

enum SupplierBankAccountStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case INACTIVE = 'INACTIVE';

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
            'UNVERIFIED', 'OPEN' => self::PENDING,
            'ACTIVE', 'APPROVED' => self::VERIFIED,
            'DECLINED' => self::REJECTED,
            'DEACTIVATED', 'DISABLED', 'ARCHIVED' => self::INACTIVE,
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
                self::VERIFIED,
                self::REJECTED,
                self::INACTIVE,
            ],
            self::VERIFIED => [
                self::INACTIVE,
            ],
            self::REJECTED,
            self::INACTIVE => [],
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
            self::VERIFIED => 'success',
            self::PENDING => 'warning',
            self::REJECTED => 'error',
            self::INACTIVE => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::VERIFIED => 'shield-check',
            self::REJECTED => 'x-circle',
            self::INACTIVE => 'archive',
        };
    }
}
