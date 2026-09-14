<?php

namespace App\Services\LocalInvoice\Providers;

use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\Contracts\LocalPoProviderInterface;

class DatabaseLocalPoProvider implements LocalPoProviderInterface
{
    public function getPoData(User $supplierUser, string $poNumber): ?array
    {
        $po = LocalPurchaseOrder::where('po_number', $poNumber)
            ->where('supplier_id', $supplierUser->id)
            ->with('goodsReceipts')
            ->first();

        if (! $po) {
            return null;
        }

        $gr = $po->goodsReceipts->first();

        return [
            'po_number' => $po->po_number,
            'description' => $po->description ?? '',
            'po_value' => (float) $po->total_amount,
            'has_gr' => $gr !== null,
            'gr_reference' => $gr?->gr_number,
        ];
    }
}
