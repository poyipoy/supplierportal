<?php

namespace App\Enums;

use ValueError;

enum SupplierRegistrationAttemptStatus: string
{
    case PENDING = 'PENDING';
    case REVISION = 'REVISION';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

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
            'SUBMITTED', 'UNDER_REVIEW', 'OPEN' => self::PENDING,
            'REVISION_REQUESTED', 'NEEDS_REVISION', 'NEED_REVISION' => self::REVISION,
            'ACTIVE', 'VERIFIED', 'COMPLETED' => self::APPROVED,
            'DECLINED', 'CANCELLED' => self::REJECTED,
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
                self::REVISION,
                self::APPROVED,
                self::REJECTED,
            ],
            self::REVISION => [
                self::REJECTED,
            ],
            self::APPROVED,
            self::REJECTED => [],
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
        $key = 'status.registration.'.strtolower($this->value);

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::APPROVED => 'success',
            self::PENDING => 'warning',
            self::REVISION => 'info',
            self::REJECTED => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::REVISION => 'file-warning',
            self::APPROVED => 'check-circle-2',
            self::REJECTED => 'x-circle',
        };
    }
}
