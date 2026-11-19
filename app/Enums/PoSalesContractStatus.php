<?php

namespace App\Enums;

enum PoSalesContractStatus: string
{
    case PENDING_SUPPLIER = 'pending_supplier';
    case SUPPLIER_UPLOADED = 'supplier_uploaded';
    case REVISION_REQUESTED = 'revision_requested';
    case PURCHASING_SIGNED = 'purchasing_signed';

    public static function tryFromOrNull(self|string|null $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function isSignedByPurchasing(): bool
    {
        return $this === self::PURCHASING_SIGNED;
    }
}
