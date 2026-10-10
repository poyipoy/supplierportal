<?php

use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;

/** No database writes: shared by PDF regression tests and visual samples. */
return function (int $count = 1, string $currency = 'IDR'): PurchaseOrder {
    $supplier = new User(['name' => 'Supplier Account']);
    $supplier->setRelation('supplier', new Supplier([
        'company_name' => 'PT SURYA UTAMA TEKNOLOGI',
        'address' => "Pergudangan Bizpoint Blok R6 No 9, Sukamulya\nCikupa, Tangerang, Banten 15710\nIndonesia",
        'phone' => '85280172118',
    ]));
    $quotations = collect();
    for ($group = 0; $group < ($count > 1 ? 2 : 1); $group++) {
        $period = new Period(['name' => 'September 2026', 'month' => 9, 'year' => 2026]);
        $pr = (new PurchaseRequisition(['pr_number' => 'REQ/09/2026/00'.($group + 1)]))->setRelation('period', $period);
        $pr->id = $group + 1;
        $quotation = new Quotation(['currency' => $currency, 'payment_terms' => $group === 0 ? '0 days' : '30 days']);
        $quotation->id = $group + 1;
        $quotation->setRelation('purchaseRequisition', $pr);
        $items = collect();
        for ($i = $group; $i < $count; $i += ($count > 1 ? 2 : 1)) {
            $prItem = new PrItem(['material_name' => $count === 1 ? 'Sticker Label Barcode Thermal 100 x 50mm' : sprintf('Ordered material %03d', $i + 1), 'shape' => 'Flat', 'quantity' => 6, 'weight_needed' => 1, 'thickness' => 1, 'width' => 100, 'length' => 48, 'hs_code' => '4821.10.00']);
            $item = new QuotationItem(['quotation_id' => $quotation->id, 'amount' => 633000, 'price_per_kg' => 105500, 'available_qty' => 6, 'offered_weight_per_unit' => 1, 'available_thickness' => 1, 'available_width' => 100, 'available_length' => 48]);
            $item->id = $i + 1;
            $item->setRelation('prItem', $prItem);
            $items->push($item);
        }
        $quotation->setRelation('items', $items);
        $quotations->push($quotation);
    }
    $po = new PurchaseOrder(['po_number' => $count === 1 ? 'PNR261178' : 'PO/09/2026/001', 'currency' => $currency, 'status' => 'active', 'estimated_arrival' => '2026-09-16']);
    $po->created_at = Carbon::parse('2026-09-16T00:00:00Z');
    $po->setRelation('supplier', $supplier)->setRelation('creator', new User(['name' => 'Purchasing Sample']))->setRelation('quotations', $quotations)->setRelation('awards', collect());

    return $po;
};
