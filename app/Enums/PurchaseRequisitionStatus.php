<?php

namespace App\Enums;

use ValueError;

enum PurchaseRequisitionStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case BIDDING = 'bidding';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';

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
            'open' => self::SUBMITTED,
            'closed' => self::COMPLETED,
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
                self::SUBMITTED,
                self::REJECTED,
            ],
            self::SUBMITTED => [
                self::BIDDING,
                self::COMPLETED,
                self::REJECTED,
            ],
            self::BIDDING => [
                self::COMPLETED,
                self::REJECTED,
            ],
            self::COMPLETED,
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
        $key = 'status.pr.'.$this->value;

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => 'neutral',
            self::SUBMITTED => 'info',
            self::BIDDING => 'warning',
            self::COMPLETED => 'success',
            self::REJECTED => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'file-edit',
            self::SUBMITTED => 'send',
            self::BIDDING => 'gavel',
            self::COMPLETED => 'check-circle-2',
            self::REJECTED => 'x-circle',
        };
    }
}
