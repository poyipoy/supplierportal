<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\QuotationHistoryService;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Golden fixture builder for the Historical quotation comparison (Gate 1 Rev 3 + E1-E3, section D).
 * Dates are business dates (Asia/Jakarta) at 09:00 and stored through BusinessTime (E2).
 * Only UserFactory exists in this repository, so every other record is created explicitly.
 */
trait QuotationHistoryFixtures
{
    protected User $purchasingUser;

    protected Period $historyPeriod;

    /** @var array<string, ExchangeRate> */
    protected array $rates = [];

    private int $prSequence = 0;

    protected function setUpQuotationHistory(): void
    {
        $this->purchasingUser = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->historyPeriod = Period::create(['name' => 'Golden', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasingUser->id]);
        foreach (['USD' => [17700, '2026-05-21'], 'JPY' => [108, '2026-05-01'], 'CNY' => [2250, '2026-05-28']] as $currency => [$rate, $validFrom]) {
            $this->rates[$currency] = ExchangeRate::create(['currency' => $currency, 'rate_to_idr' => $rate, 'valid_from' => $validFrom, 'created_by' => $this->purchasingUser->id]);
        }
        // Frozen evaluation date (E2).
        $this->travelTo($this->at('2026-10-20', '10:00'));
    }

    protected function at(string $date, string $time = '09:00'): CarbonInterface
    {
        return BusinessTime::parseDate($date)->setTimeFromTimeString($time)->utc();
    }

    protected function supplierNamed(string $company): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        Supplier::create(['user_id' => $user->id, 'company_name' => $company]);

        return $user;
    }

    /**
     * @param  list<array{material: string, shape: ?string, dims: array<string, float|int>, master?: ?int}>  $items
     */
    protected function prAt(string $created, array $items, ?string $incoterm = 'CIF', ?string $port = 'TANJUNG_PRIOK'): PurchaseRequisition
    {
        $this->travelTo($this->at($created));
        $pr = PurchaseRequisition::create([
            'period_id' => $this->historyPeriod->id,
            'created_by' => $this->purchasingUser->id,
            'pr_number' => 'REQ/G/'.(++$this->prSequence),
            'status' => 'bidding',
        ]);
        $pr->forceFill(['incoterm' => $incoterm, 'port_of_discharge' => $port])->save();

        foreach ($items as $item) {
            PrItem::create([
                'pr_id' => $pr->id,
                'material_master_id' => $item['master'] ?? null,
                'hs_code' => '7228.30.00',
                'material_name' => $item['material'],
                'quantity' => 10,
                'shape' => $item['shape'],
                ...$item['dims'],
                'weight_needed' => 5,
            ]);
        }
        $this->travelTo($this->at('2026-10-20', '10:00'));

        return $pr->fresh(['items']);
    }

    /**
     * $lines is keyed by PR item index. A line is either a price (offer = requested spec) or an array:
     * price, offered (dims; null = legacy silent offer), range [min, max], available, weight_source,
     * duplicate (bool, adds a second line with duplicate_price).
     *
     * @param  array<int, float|array<string, mixed>>  $lines
     */
    protected function quote(PurchaseRequisition $pr, User $supplier, string $submitted, string $currency, ?string $rateCurrency, array $lines, string $status = Quotation::STATUS_SUBMITTED): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => $currency,
            'exchange_rate_id' => $rateCurrency === null ? null : $this->rates[$rateCurrency]->id,
            'status' => $status,
            'payment_terms' => 'TT 30 Days',
            'estimated_delivery' => '2026-12-01',
            'validity_period' => '2026-12-31',
            'submitted_at' => $this->at($submitted),
        ]);

        foreach ($lines as $index => $line) {
            $line = is_array($line) ? $line : ['price' => $line];
            $prItem = $pr->items[$index];
            $this->line($quotation, $prItem, $line, $line['price']);
            if (! empty($line['duplicate'])) {
                $this->line($quotation, $prItem, $line, $line['duplicate_price']);
            }
        }

        // History compares against purchases only (2026-11-02): an accepted golden quotation
        // stands for one that was bought, through a legacy PO without awards (every line counts).
        if ($status === Quotation::STATUS_ACCEPTED) {
            $this->purchase($quotation);
        }

        return $quotation->fresh(['items']);
    }

    protected function purchase(Quotation $quotation, string $status = 'completed'): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $quotation->supplier_id,
            'currency' => $quotation->currency,
            'exchange_rate_id' => $quotation->exchange_rate_id,
            'po_number' => 'PO/G/'.$quotation->id,
            'status' => $status,
            'created_by' => $this->purchasingUser->id,
        ]);
        DB::table('po_quotations')->insert(['po_id' => $po->id, 'quotation_id' => $quotation->id, 'created_at' => now(), 'updated_at' => now()]);

        return $po;
    }

    private function line(Quotation $quotation, PrItem $prItem, array $line, ?float $price): void
    {
        $available = $line['available'] ?? true;
        $offered = array_key_exists('offered', $line) ? $line['offered'] : collect(PrItem::DIMENSION_FIELDS)->mapWithKeys(fn ($field) => [$field => $prItem->{$field}])->all();
        $attributes = [
            'pr_item_id' => $prItem->id,
            'is_available' => $available,
            'price_per_kg' => $available ? $price : null,
            'amount' => $available ? $price * 50 : 0,
            'available_qty' => $available ? 10 : null,
            'offered_weight_per_unit' => $available ? 5 : null,
            'offered_weight_source' => $available ? ($line['weight_source'] ?? 'auto') : null,
        ];
        foreach (PrItem::DIMENSION_FIELDS as $field) {
            $attributes['available_'.$field] = $available ? ($offered[$field] ?? null) : null;
        }
        if (isset($line['range'])) {
            $attributes['available_length'] = null;
            $attributes['available_length_min'] = $line['range'][0];
            $attributes['available_length_max'] = $line['range'][1];
        }
        $quotation->items()->create($attributes);
    }

    protected function history(PurchaseRequisition $pr): array
    {
        return app(QuotationHistoryService::class)->forPr($pr);
    }

    protected function rowFor(array $history, PurchaseRequisition $pr, int $itemIndex, User $supplier): array
    {
        $itemId = $pr->items[$itemIndex]->id;

        return collect($history['rows'])->first(fn (array $row) => $row['pr_item_id'] === $itemId && $row['supplier']['id'] === $supplier->id)
            ?? $this->fail("No row for item {$itemIndex} / supplier {$supplier->id}.");
    }

    protected static function skd(float $t = 30, float $w = 300, float $l = 2000): array
    {
        return ['material' => 'SKD11', 'shape' => 'Flat', 'dims' => ['thickness' => $t, 'width' => $w, 'length' => $l]];
    }

    protected static function round(string $material, float $d, float $l = 6000): array
    {
        return ['material' => $material, 'shape' => 'Round', 'dims' => ['d_outer' => $d, 'length' => $l]];
    }
}
