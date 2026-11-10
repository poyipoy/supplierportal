<?php

namespace App\Enums;

use ValueError;

enum PurchaseOrderStatus: string
{
    case ACTIVE = 'active';
    case OVERDUE = 'overdue';
    case WAITING_QC = 'waiting_qc';
    case CLAIM_NEEDED = 'claim_needed';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

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
            'draft', 'open', 'issued' => self::ACTIVE,
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
            self::ACTIVE => [
                self::OVERDUE,
                self::WAITING_QC,
                self::CLAIM_NEEDED,
                self::COMPLETED,
                self::CANCELLED,
            ],
            self::OVERDUE => [
                self::ACTIVE,
                self::WAITING_QC,
                self::CLAIM_NEEDED,
                self::COMPLETED,
                self::CANCELLED,
            ],
            self::WAITING_QC => [
                self::ACTIVE,
                self::OVERDUE,
                self::CLAIM_NEEDED,
                self::COMPLETED,
                self::CANCELLED,
            ],
            self::CLAIM_NEEDED => [
                self::ACTIVE,
                self::OVERDUE,
                self::WAITING_QC,
                self::COMPLETED,
                self::CANCELLED,
            ],
            self::COMPLETED,
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
        $key = 'status.po.'.$this->value;

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::ACTIVE => 'info',
            self::WAITING_QC => 'warning',
            self::COMPLETED => 'success',
            self::OVERDUE,
            self::CLAIM_NEEDED => 'error',
            self::CANCELLED => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ACTIVE => 'truck',
            self::OVERDUE => 'clock-alert',
            self::WAITING_QC => 'clipboard-check',
            self::CLAIM_NEEDED => 'octagon-alert',
            self::COMPLETED => 'check-circle-2',
            self::CANCELLED => 'ban',
        };
    }
}
