<?php

namespace App\Enums;

use ValueError;

enum GaClaimStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case BASIC_VERIFIED = 'BASIC_VERIFIED';
    case UNDER_VERIFICATION = 'UNDER_VERIFICATION';
    case NEED_REVISION = 'NEED_REVISION';
    case READY_TO_PAY = 'READY_TO_PAY';
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
            'UNDER_REVIEW' => self::UNDER_VERIFICATION,
            'APPROVED' => self::READY_TO_PAY,
            'COMPLETED' => self::PAID,
            'REJECTED' => self::CANCELLED,
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
            self::SUBMITTED => [
                self::BASIC_VERIFIED,
                self::CANCELLED,
            ],
            self::BASIC_VERIFIED => [
                self::UNDER_VERIFICATION,
                self::READY_TO_PAY,
                self::NEED_REVISION,
                self::CANCELLED,
            ],
            self::UNDER_VERIFICATION => [
                self::READY_TO_PAY,
                self::NEED_REVISION,
                self::CANCELLED,
            ],
            self::NEED_REVISION => [
                self::SUBMITTED,
                self::CANCELLED,
            ],
            self::READY_TO_PAY => [
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
        $key = 'status.ga.'.strtolower($this->value);

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::PAID,
            self::READY_TO_PAY => 'success',
            self::SUBMITTED,
            self::BASIC_VERIFIED,
            self::UNDER_VERIFICATION => 'info',
            self::NEED_REVISION => 'warning',
            self::CANCELLED => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SUBMITTED => 'send',
            self::BASIC_VERIFIED => 'shield-check',
            self::UNDER_VERIFICATION => 'file-search',
            self::NEED_REVISION => 'alert-triangle',
            self::READY_TO_PAY => 'check-circle',
            self::PAID => 'check-circle-2',
            self::CANCELLED => 'ban',
        };
    }
}
