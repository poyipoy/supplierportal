<?php

namespace App\Enums;

use ValueError;

enum SupplierAuditStatus: string
{
    case ASSIGNED = 'ASSIGNED';
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case REVISION_REQUESTED = 'REVISION_REQUESTED';
    case RESULT_PUBLISHED = 'RESULT_PUBLISHED';
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
            'PUBLISHED', 'OPEN', 'PENDING' => self::ASSIGNED,
            'IN_PROGRESS' => self::DRAFT,
            'UNDER_REVIEW' => self::SUBMITTED,
            'NEEDS_REVISION', 'REVISION' => self::REVISION_REQUESTED,
            'COMPLETED', 'DONE', 'APPROVED' => self::RESULT_PUBLISHED,
            'VOID', 'CANCELED' => self::CANCELLED,
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
            self::ASSIGNED => [
                self::DRAFT,
                self::SUBMITTED,
                self::CANCELLED,
            ],
            self::DRAFT => [
                self::SUBMITTED,
                self::CANCELLED,
            ],
            self::SUBMITTED => [
                self::REVISION_REQUESTED,
                self::RESULT_PUBLISHED,
                self::CANCELLED,
            ],
            self::REVISION_REQUESTED => [
                self::SUBMITTED,
                self::CANCELLED,
            ],
            self::RESULT_PUBLISHED,
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
        $key = 'status.supplier_audit.'.strtolower($this->value);

        return app('translator')->has($key)
            ? __($key)
            : __('status.meta.unknown_value', ['value' => $this->value]);
    }

    public function tone(): string
    {
        return match ($this) {
            self::ASSIGNED,
            self::SUBMITTED => 'info',
            self::REVISION_REQUESTED => 'warning',
            self::RESULT_PUBLISHED => 'success',
            self::DRAFT,
            self::CANCELLED => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ASSIGNED => 'clipboard-list',
            self::DRAFT => 'file-edit',
            self::SUBMITTED => 'send',
            self::REVISION_REQUESTED => 'alert-circle',
            self::RESULT_PUBLISHED => 'check-circle-2',
            self::CANCELLED => 'ban',
        };
    }
}
