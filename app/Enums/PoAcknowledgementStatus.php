<?php

namespace App\Enums;

enum PoAcknowledgementStatus: string
{
    case PENDING = 'PENDING';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case CHANGE_REQUESTED = 'CHANGE_REQUESTED';
    case ACCEPTED_CHANGE = 'ACCEPTED_CHANGE';

    public function isAcknowledged(): bool
    {
        return in_array($this, [self::ACKNOWLEDGED, self::ACCEPTED_CHANGE], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::ACKNOWLEDGED, self::ACCEPTED_CHANGE => 'success',
            self::CHANGE_REQUESTED => 'warning',
            self::PENDING => 'neutral',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ACKNOWLEDGED => __('purchasing.po_ack.status.acknowledged'),
            self::CHANGE_REQUESTED => __('purchasing.po_ack.status.change_requested'),
            self::ACCEPTED_CHANGE => __('purchasing.po_ack.status.accepted_change'),
            self::PENDING => __('purchasing.po_ack.status.pending'),
        };
    }

    public static function tryFromOrDefault(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom(strtoupper(trim((string) $value))) ?? self::PENDING;
    }
}
