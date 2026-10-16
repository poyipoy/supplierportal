<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;

/**
 * Explicit Import-procurement records for Mission 01 contract tests.
 * Only UserFactory exists in this repository, so every other record is
 * created directly with Model::create().
 */
trait ProcurementFixtures
{
    protected User $purchasing;

    protected User $supplier;

    protected ExchangeRate $usdRate;

    protected function setUpProcurementActors(): void
    {
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->usdRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay()->toDateString(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    /**
     * @param  list<array{quantity?: int, weight_needed?: float}>  $items
     */
    protected function createRequisition(array $items = [[]], string $status = 'bidding'): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'Mission 01 Period '.uniqid(),
            'month' => 10,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/10/2026/'.uniqid(),
            'status' => $status,
        ]);

        foreach (array_values($items) as $index => $item) {
            PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => 'Mission 01 Material '.($index + 1),
                'quantity' => $item['quantity'] ?? 10,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 2,
                'width' => 100,
                'length' => 200,
                'weight_needed' => $item['weight_needed'] ?? 3,
            ]);
        }

        return $pr->fresh(['items']);
    }

    /**
     * Offers default to the PR quantity and weight per unit, so the line is
     * neither a surplus nor a shortage unless the caller says otherwise.
     *
     * @param  list<array{available_qty?: int|null, offered_weight_per_unit?: float|null, price_per_kg?: float, amount?: float|null}>  $offers
     */
    protected function createQuotation(PurchaseRequisition $pr, array $offers = [], string $status = Quotation::STATUS_SUBMITTED, ?User $supplier = null): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->usdRate->id,
            'status' => $status,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        foreach ($pr->items->values() as $index => $prItem) {
            $offer = $offers[$index] ?? [];
            $availableQty = array_key_exists('available_qty', $offer) ? $offer['available_qty'] : $prItem->quantity_value;
            $weightPerUnit = array_key_exists('offered_weight_per_unit', $offer) ? $offer['offered_weight_per_unit'] : (float) $prItem->weight_needed;
            $price = $offer['price_per_kg'] ?? 2.5;
            $amount = array_key_exists('amount', $offer)
                ? $offer['amount']
                : round(($availableQty ?? $prItem->quantity_value) * ($weightPerUnit ?? (float) $prItem->weight_needed) * $price, 4);

            $quotation->items()->create([
                'pr_item_id' => $prItem->id,
                'is_available' => true,
                'price_per_kg' => $price,
                'amount' => $amount,
                'available_qty' => $availableQty,
                'offered_weight_per_unit' => $weightPerUnit,
            ]);
        }

        return $quotation->fresh(['items']);
    }
}
