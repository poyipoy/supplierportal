<?php

namespace App\Services\LocalInvoice\Contracts;

use App\Models\User;

interface LocalPoProviderInterface
{
    /**
     * Look up authoritative internal PO data for a given supplier.
     * Returns null if not found or if the PO does not belong to the supplier.
     *
     * @return array{
     *     po_number: string,
     *     description: string,
     *     po_value: float,
     *     has_gr: bool,
     *     gr_reference: ?string
     * }|null
     */
    public function getPoData(User $supplierUser, string $poNumber): ?array;
}
