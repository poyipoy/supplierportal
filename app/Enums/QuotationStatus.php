<?php

namespace App\Enums;

use ValueError;

enum QuotationStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case REVISION_REQUESTED = 'revision_requested';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case ALL_UNAVAILABLE = 'all_unavailable';

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
            'approved' => self::ACCEPTED,
            'needs_revision' => self::REVISION_REQUESTED,
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
                self::ALL_UNAVAILABLE,
            ],
            self::SUBMITTED => [
                self::REVISION_REQUESTED,
                self::ACCEPTED,
                self::REJECTED,
                self::ALL_UNAVAILABLE,
            ],
            self::REVISION_REQUESTED => [
                self::SUBMITTED,
                self::ALL_UNAVAILABLE,
                self::REJECTED,
            ],
            self::ALL_UNAVAILABLE => [
                self::REVISION_REQUESTED,
                self::SUBMITTED,
                self::REJECTED,
            ],
            self::ACCEPTED,
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
        $key = 'status.quotation.'.$this->value;

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT,
            self::ALL_UNAVAILABLE => 'neutral',
            self::SUBMITTED => 'info',
            self::REVISION_REQUESTED => 'warning',
            self::ACCEPTED => 'success',
            self::REJECTED => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'file-edit',
            self::SUBMITTED => 'send',
            self::REVISION_REQUESTED => 'rotate-ccw',
            self::ACCEPTED => 'check-circle-2',
            self::REJECTED => 'x-circle',
            self::ALL_UNAVAILABLE => 'slash',
        };
    }
}
