<?php

namespace App\Enums;

use ValueError;

enum QcInspectionStatus: string
{
    case OK = 'ok';
    case NG = 'ng';

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
            'pass', 'passed', 'approved' => self::OK,
            'pending', 'fail', 'failed', 'rejected' => self::NG,
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
            self::NG => [
                self::OK,
            ],
            self::OK => [],
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
        return strtoupper($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::OK => 'success',
            self::NG => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OK => 'check-circle-2',
            self::NG => 'x-circle',
        };
    }
}
