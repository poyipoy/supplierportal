<?php

namespace App\Enums;

use ValueError;

enum MaterialClaimStatus: string
{
    case PENDING = 'pending';
    case RESPONDED = 'responded';
    case RESOLVED = 'resolved';
    case ESCALATED = 'escalated';

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
            'open', 'in_progress' => self::PENDING,
            'completed', 'closed' => self::RESOLVED,
            'rejected' => self::ESCALATED,
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
                self::RESPONDED,
                self::ESCALATED,
                self::RESOLVED,
            ],
            self::RESPONDED => [
                self::RESOLVED,
                self::ESCALATED,
            ],
            self::ESCALATED => [
                self::RESPONDED,
                self::RESOLVED,
            ],
            self::RESOLVED => [],
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
        $key = 'status.claim.'.$this->value;

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::RESPONDED => 'info',
            self::RESOLVED => 'success',
            self::ESCALATED => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::RESPONDED => 'message-square-reply',
            self::RESOLVED => 'check-circle-2',
            self::ESCALATED => 'alert-triangle',
        };
    }
}
