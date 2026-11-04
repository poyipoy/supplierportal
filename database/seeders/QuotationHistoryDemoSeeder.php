<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use App\Models\PrItem;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Comparison demo data for Purchasing → Price Comparison (Inter-supplier, Historical PR mode,
 * Quotation Price Trend, Historical Purchase Price, vs Best Price).
 *
 * Deterministic generator (fixed RNG seed): 8 demo suppliers in USD/CNY/JPY/IDR, 14 materials
 * with several dimension variants each (thickness / outer diameter is the comparison key),
 * 18 months of history PRs (May 2025 - Oct 2026) with awards and completed POs for the cheapest
 * offer, and open bidding PRs in Oct 2026 to compare against that history. Knobs on purpose:
 * FOB / Tanjung Perak / legacy PRs (basis differs, indicative basis), JPY quotes without a rate
 * snapshot (FX unavailable), length ranges and stock widths (indicative spec), new thicknesses
 * (different specification), a duplicated quotation (ambiguous), unavailable lines.
 *
 * Isolated by marker (PR_PREFIX, PO_PREFIX, EMAIL_DOMAIN, PERIOD_PREFIX); every run removes
 * the previous demo set first. Existing exchange rates are reused, none is inserted.
 *
 *   php artisan db:seed --class=QuotationHistoryDemoSeeder
 */
class QuotationHistoryDemoSeeder extends Seeder
{
    public const PR_PREFIX = 'DEMO-HIST/';

    public const PO_PREFIX = 'DEMO-HIST-PO/';

    public const EMAIL_DOMAIN = '@demo-history.test';

    private const PERIOD_PREFIX = 'DEMO Comparison ';

    private const SEED = 20261102;

    /** Supplier key => [company, quote currency, price factor vs market]. */
    private const SUPPLIERS = [
        'A' => ['PT. Demo Baja Prima', 'USD', 1.00],
        'B' => ['Demo Sinar Steel (CN)', 'CNY', 0.93],
        'C' => ['Demo Tokai Special Steel', 'JPY', 1.06],
        'D' => ['PT. Demo Logam Nusantara', 'IDR', 1.02],
        'E' => ['Demo Duplicate Trading', 'USD', 0.98],
        'F' => ['Demo Hebei Metals', 'CNY', 0.90],
        'G' => ['Demo Daido Trading', 'USD', 1.04],
        'H' => ['PT. Demo Krakatau Niaga', 'IDR', 0.99],
    ];

    /** Material => [shape, base USD/kg, variants]. Flat [t, w, l]; Round [d, l]; Hollow [outer, inner, l]. */
    private const CATALOG = [
        'SKD11' => ['Flat', 3.60, [[20, 300, 2000], [30, 300, 2000], [30, 400, 2000], [50, 400, 2000]]],
        'DC53' => ['Flat', 5.40, [[30, 400, 1500], [50, 400, 1500]]],
        'P20' => ['Flat', 2.90, [[25, 900, 2300], [60, 610, 2300], [405, 610, 4000]]],
        'NAK80' => ['Flat', 7.00, [[40, 500, 2000], [60, 500, 2000]]],
        'SKD61' => ['Flat', 4.80, [[80, 400, 2000], [120, 400, 2000]]],
        'SUS304' => ['Flat', 4.40, [[12, 1219, 2438], [16, 1219, 2438], [20, 1219, 2438]]],
        'SS400' => ['Flat', 1.15, [[12, 1500, 6000], [16, 1500, 6000], [25, 1500, 6000]]],
        'S50C' => ['Flat', 1.60, [[20, 600, 3000], [35, 600, 3000]]],
        'S45C' => ['Round', 2.20, [[25, 2300], [40, 6000], [60, 6000]]],
        'SCM440' => ['Round', 2.60, [[50, 6000], [80, 6000]]],
        'SUJ2' => ['Round', 3.10, [[30, 4000], [45, 4000]]],
        'H13' => ['Round', 5.00, [[100, 3000]]],
        'STKM13A' => ['Hollow', 2.90, [[80, 50, 3000], [80, 60, 3000], [114, 90, 3000]]],
        'SCM415' => ['Hollow', 3.30, [[60, 40, 3000]]],
    ];

    /** Sizes that only appear on the open PRs: no supplier has quoted this key dimension before. */
    private const NEW_VARIANTS = [
        'SKD11' => [[80, 400, 2000]],
        'SUS304' => [[25, 1219, 2438]],
        'S45C' => [[90, 6000]],
        'NAK80' => [[25, 500, 2000]],
    ];

    private int $purchasingId;

    /** @var array<int, int> year => period id */
    private array $periods = [];

    /** @var array<string, array{id: ?int, rate: ?float}> */
    private array $rates = [];

    /** @var array<string, int> supplier key => users.id */
    private array $users = [];

    /** @var array<string, list<string>> supplier key => materials it trades */
    private array $coverage = [];

    /** @var array<string, float> variant key => fixed price factor */
    private array $variantFactor = [];

    private int $poSequence = 0;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('QuotationHistoryDemoSeeder must not run in production.');
        }

        mt_srand(self::SEED);

        DB::transaction(function (): void {
            $this->cleanPreviousDemoData();
            $this->prepareActors();
            $stats = $this->seedHistory();
            $open = $this->seedOpenPrs();

            $this->command?->info(sprintf(
                'Comparison demo: %d history PRs, %d POs, %d open PRs (%s), %d quotations.',
                $stats['prs'], $stats['pos'], count($open), implode(', ', $open),
                DB::table('quotations')->whereIn('pr_id', DB::table('purchase_requisitions')->where('pr_number', 'like', self::PR_PREFIX.'%')->pluck('id'))->count(),
            ));
        });
    }

    // ─── History: 18 months, 2 PRs a month, cheapest offer awarded into a PO ──

    /**
     * @return array{prs: int, pos: int}
     */
    private function seedHistory(): array
    {
        $variants = $this->variants(self::CATALOG);
        $prs = 0;
        $pos = 0;
        $month = BusinessTime::parseDate('2025-05-01');

        for ($m = 0; $m < 18; $m++, $month = $month->copy()->addMonth()) {
            foreach ([4, 18] as $index => $day) {
                $prDate = $month->copy()->day($day);
                $sequence = $m * 2 + $index;
                // Basis knobs: a few FOB, Tanjung Perak and legacy (no terms) PRs.
                [$incoterm, $port] = match (true) {
                    in_array($sequence, [7, 22, 31], true) => ['FOB', 'TANJUNG_PRIOK'],
                    in_array($sequence, [11, 27], true) => ['CIF', 'TANJUNG_PERAK'],
                    in_array($sequence, [3, 15], true) => [null, null],
                    default => ['CIF', 'TANJUNG_PRIOK'],
                };
                $picked = $this->pick($variants, mt_rand(6, 11));
                $number = sprintf('%s%s-%d', self::PR_PREFIX, $prDate->format('Y/m'), $index + 1);
                $pr = $this->pr($number, $prDate, $picked, $incoterm, $port, 'completed');

                $quotes = [];
                foreach ($this->invite($picked, mt_rand(4, 6)) as $supplier) {
                    $quoteDate = $prDate->copy()->addDays(mt_rand(3, 10))->setTime(mt_rand(8, 16), mt_rand(0, 59));
                    // C sometimes quotes in JPY without a rate snapshot (FX unavailable later).
                    $snapshot = ! ($supplier === 'C' && mt_rand(1, 100) <= 25);
                    $quotes[] = $this->quote($pr, $supplier, $quoteDate, $m, 'submitted', $snapshot);
                }
                $pos += $this->awardCheapest($pr, $quotes, $prDate->copy()->addDays(14), $m >= 16 ? 'active' : 'completed');
                $prs++;
            }
        }

        return ['prs' => $prs, 'pos' => $pos];
    }

    // ─── Open PRs to compare: Oct 2026, bidding ─────────────────────────────

    /**
     * @return list<string>
     */
    private function seedOpenPrs(): array
    {
        $variants = $this->variants(self::CATALOG);
        $newVariants = $this->variants($this->newCatalog());
        $numbers = [];

        foreach ([[6, 10], [10, 12], [15, 14], [20, 9], [24, 16], [28, 12]] as $index => [$day, $size]) {
            $prDate = BusinessTime::parseDate('2026-10-01')->day($day);
            $picked = $this->pick($variants, $size - 1) + $this->pick($newVariants, 1);
            $number = sprintf('%s2026/10-OPEN-%d', self::PR_PREFIX, $index + 1);
            $pr = $this->pr($number, $prDate, $picked, 'CIF', 'TANJUNG_PRIOK', 'bidding');

            foreach ($this->invite($picked, mt_rand(5, 7)) as $supplier) {
                $quoteDate = $prDate->copy()->addDays(mt_rand(2, 6))->setTime(mt_rand(8, 16), mt_rand(0, 59));
                $quoteDate = $quoteDate->min(BusinessTime::parseDate('2026-11-01')->setTime(15, 0));
                // Tokai switched from JPY to USD; every supplier sometimes quotes a material it never sold before.
                $this->quote($pr, $supplier, $quoteDate, 17, 'submitted', true, $supplier === 'C' ? 'USD' : null, 40);
            }
            // One supplier sent two quotations on the third PR → Ambiguous rows.
            if ($index === 2) {
                $this->quote($pr, 'E', $prDate->copy()->addDays(3)->setTime(10, 0), 17, 'submitted', true);
                $this->quote($pr, 'E', $prDate->copy()->addDays(5)->setTime(14, 0), 17, 'submitted', true);
            }
            $numbers[] = $number;
        }

        return $numbers;
    }

    // ─── Builders ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $variants
     */
    private function pr(string $number, CarbonInterface $date, array $variants, ?string $incoterm, ?string $port, string $status): array
    {
        $at = $date->copy()->setTime(8, 0)->utc();
        $prId = DB::table('purchase_requisitions')->insertGetId([
            'period_id' => $this->periods[(int) $date->year],
            'created_by' => $this->purchasingId,
            'pr_number' => $number,
            'notes' => 'Demo data for price comparison.',
            'status' => $status,
            'incoterm' => $incoterm,
            'port_of_discharge' => $port,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $items = [];
        foreach ($variants as $key => $variant) {
            $quantity = mt_rand(2, 12);
            $id = DB::table('pr_items')->insertGetId([
                'pr_id' => $prId,
                'hs_code' => $variant['shape'] === 'Hollow' ? '7304.51.00' : '7228.30.00',
                'material_name' => $variant['material'],
                'quantity' => $quantity,
                'shape' => $variant['shape'],
                ...$variant['dims'],
                'weight_needed' => self::weight($variant['shape'], $variant['dims']),
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $items[$key] = $variant + ['id' => $id, 'quantity' => $quantity];
        }

        return ['id' => $prId, 'number' => $number, 'items' => $items];
    }

    /**
     * One quotation of one supplier on one PR; returns its offered lines for awarding.
     */
    private function quote(array $pr, string $supplier, CarbonInterface $at, int $monthIndex, string $status, bool $snapshot, ?string $currencyOverride = null, int $newMaterialChance = 0): array
    {
        [, $currency, $factor] = self::SUPPLIERS[$supplier];
        $currency = $currencyOverride ?? $currency;
        $rate = $this->rates[$currency];
        $at = $at->copy()->utc();

        $quotationId = DB::table('quotations')->insertGetId([
            'pr_id' => $pr['id'],
            'supplier_id' => $this->users[$supplier],
            'currency' => $currency,
            'exchange_rate_id' => $snapshot ? $rate['id'] : null,
            'status' => $status,
            'payment_terms' => ['TT 30 Days', 'TT 45 Days', 'LC at sight'][mt_rand(0, 2)],
            'estimated_delivery' => $at->copy()->addDays(mt_rand(45, 90))->toDateString(),
            'validity_period' => $at->copy()->addDays(30)->toDateString(),
            'submitted_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        DB::table('purchase_requisition_suppliers')->updateOrInsert(
            ['pr_id' => $pr['id'], 'supplier_id' => $this->users[$supplier]],
            ['invited_at' => $at, 'created_at' => $at, 'updated_at' => $at],
        );

        // Market index: slow rise with a seasonal wave; per-quote noise of about ±3%.
        $market = 1 + 0.012 * $monthIndex + 0.03 * sin($monthIndex / 3);
        $lines = [];
        foreach ($pr['items'] as $key => $item) {
            if (! in_array($item['material'], $this->coverage[$supplier], true) && mt_rand(1, 100) > $newMaterialChance) {
                continue;
            }
            $roll = mt_rand(1, 100);
            $available = $roll > 5;
            $offered = $item['dims'];
            $range = null;
            if ($available && $roll > 92 && $item['shape'] !== 'Hollow') {
                $range = [$offered['length'] - 200, $offered['length'] + 200];
            } elseif ($available && $roll > 86 && $item['shape'] === 'Flat') {
                $offered['width'] = $offered['width'] + 100; // stock width, same thickness
            } elseif ($available && $roll > 82 && $item['shape'] === 'Hollow') {
                $offered['d_inner'] = $offered['d_inner'] - 5;
            }

            $usd = self::CATALOG[$item['material']][1] * $factor * $this->variantFactor[$key] * $market * (1 + (mt_rand(-30, 30) / 1000));
            $price = $available ? $this->toCurrency($usd, $currency) : null;
            $weight = self::weight($item['shape'], $offered);
            $quantity = $item['quantity'];

            $row = [
                'quotation_id' => $quotationId,
                'pr_item_id' => $item['id'],
                'is_available' => $available,
                'price_per_kg' => $price,
                'amount' => $available ? round($price * $weight * $quantity, 4) : 0,
                'available_qty' => $available ? $quantity : null,
                'offered_weight_per_unit' => $available ? $weight : null,
                'offered_weight_source' => $available ? 'auto' : null,
                'created_at' => $at,
                'updated_at' => $at,
            ];
            foreach (PrItem::DIMENSION_FIELDS as $field) {
                $row['available_'.$field] = $available ? ($offered[$field] ?? null) : null;
            }
            if ($range !== null) {
                $row['available_length'] = null;
                $row['available_length_min'] = $range[0];
                $row['available_length_max'] = $range[1];
            }
            $lineId = DB::table('quotation_items')->insertGetId($row);

            if ($available) {
                $lines[] = [
                    'quotation_id' => $quotationId, 'line_id' => $lineId, 'item' => $item, 'supplier' => $supplier,
                    'price' => $price, 'weight' => $weight, 'quantity' => $quantity, 'currency' => $currency,
                    'rate_id' => $snapshot ? $rate['id'] : null,
                    'idr' => $snapshot && $rate['rate'] !== null ? $price * $rate['rate'] : null,
                ];
            }
        }

        return ['id' => $quotationId, 'supplier' => $supplier, 'lines' => $lines, 'snapshot' => $snapshot];
    }

    /**
     * Cheapest IDR offer per item wins; one PO per winning supplier (po_quotations.quotation_id is unique).
     */
    private function awardCheapest(array $pr, array $quotes, CarbonInterface $poDate, string $poStatus): int
    {
        // Cheapest offer wins about 55% of the time; otherwise one of the three cheapest (lead
        // time, stock), so purchases spread over suppliers like a real buying history.
        $offers = [];
        foreach ($quotes as $quote) {
            foreach ($quote['lines'] as $line) {
                if ($line['idr'] !== null) {
                    $offers[$line['item']['id']][] = $line;
                }
            }
        }
        $winners = [];
        foreach ($offers as $itemId => $lines) {
            usort($lines, fn (array $a, array $b) => $a['idr'] <=> $b['idr']);
            $winners[$itemId] = mt_rand(1, 100) <= 55 ? $lines[0] : $lines[mt_rand(0, min(2, count($lines) - 1))];
        }

        $prRow = DB::table('purchase_requisitions')->where('id', $pr['id'])->first(['incoterm', 'port_of_discharge']);
        $at = $poDate->copy()->setTime(10, 0)->utc();
        $byQuotation = collect($winners)->groupBy('quotation_id');

        foreach ($quotes as $quote) {
            $won = $byQuotation->has($quote['id']);
            DB::table('quotations')->where('id', $quote['id'])->update([
                'status' => $won ? 'accepted' : (mt_rand(1, 100) <= 70 ? 'rejected' : 'submitted'),
                'reviewed_at' => $at,
                'reviewed_by' => $this->purchasingId,
            ]);
        }

        foreach ($byQuotation as $quotationId => $lines) {
            $first = $lines->first();
            $poId = DB::table('purchase_orders')->insertGetId([
                'supplier_id' => $this->users[$first['supplier']],
                'currency' => $first['currency'],
                'exchange_rate_id' => $first['rate_id'],
                'po_number' => sprintf('%s%s/%03d', self::PO_PREFIX, $poDate->format('Y/m'), ++$this->poSequence),
                'status' => $poStatus,
                'created_by' => $this->purchasingId,
                'estimated_arrival' => $poDate->copy()->addDays(60)->toDateString(),
                'actual_arrival' => $poStatus === 'completed' ? $poDate->copy()->addDays(mt_rand(50, 75))->toDateString() : null,
                'incoterm' => $prRow->incoterm,
                'port_of_discharge' => $prRow->port_of_discharge,
                'notes' => 'Demo data for price comparison.',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            DB::table('po_quotations')->insert(['po_id' => $poId, 'quotation_id' => $quotationId, 'created_at' => $at, 'updated_at' => $at]);

            foreach ($lines as $line) {
                $total = round($line['weight'] * $line['quantity'], 4);
                DB::table('pr_item_awards')->insert([
                    'pr_id' => $pr['id'],
                    'pr_item_id' => $line['item']['id'],
                    'quotation_id' => $quotationId,
                    'quotation_item_id' => $line['line_id'],
                    'supplier_id' => $this->users[$line['supplier']],
                    'purchase_order_id' => $poId,
                    'awarded_by' => $this->purchasingId,
                    'awarded_at' => $at,
                    'ordered_qty' => $line['quantity'],
                    'ordered_weight_per_unit' => $line['weight'],
                    'ordered_total_weight_kg' => $total,
                    'ordered_price_per_kg' => $line['price'],
                    'ordered_amount' => round($total * $line['price'], 4),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }

        return $byQuotation->count();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{material: string, shape: string, dims: array<string, float>}>
     */
    private function variants(array $catalog): array
    {
        $variants = [];
        foreach ($catalog as $material => [$shape, , $sizes]) {
            foreach ($sizes as $size) {
                $dims = match ($shape) {
                    'Flat' => ['thickness' => $size[0], 'width' => $size[1], 'length' => $size[2]],
                    'Round' => ['d_outer' => $size[0], 'length' => $size[1]],
                    'Hollow' => ['d_outer' => $size[0], 'd_inner' => $size[1], 'length' => $size[2]],
                };
                $key = $material.'|'.implode('x', $size);
                $variants[$key] = ['material' => $material, 'shape' => $shape, 'dims' => $dims];
                // Thinner / smaller sizes cost a little more per KG.
                $this->variantFactor[$key] ??= round(1 + max(0, 60 - $size[0]) / 1000 + mt_rand(-20, 20) / 1000, 4);
            }
        }

        return $variants;
    }

    private function newCatalog(): array
    {
        $catalog = [];
        foreach (self::NEW_VARIANTS as $material => $sizes) {
            $catalog[$material] = [self::CATALOG[$material][0], self::CATALOG[$material][1], $sizes];
        }

        return $catalog;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pick(array $variants, int $count): array
    {
        $keys = array_keys($variants);
        $picked = [];
        while (count($picked) < min($count, count($keys))) {
            $key = $keys[mt_rand(0, count($keys) - 1)];
            $picked[$key] = $variants[$key];
        }

        return $picked;
    }

    /**
     * Suppliers that trade at least one of the PR's materials, randomly limited to $count.
     *
     * @return list<string>
     */
    private function invite(array $variants, int $count): array
    {
        $materials = array_unique(array_column($variants, 'material'));
        $eligible = array_values(array_filter(array_keys(self::SUPPLIERS), fn (string $supplier) => array_intersect($materials, $this->coverage[$supplier]) !== []));
        usort($eligible, fn () => mt_rand(-1, 1));

        return array_slice($eligible, 0, $count);
    }

    private function toCurrency(float $usd, string $currency): float
    {
        $usdRate = $this->rates['USD']['rate'] ?? 17700;
        $rate = $this->rates[$currency]['rate'] ?? ['CNY' => 2250, 'JPY' => 110, 'IDR' => 1][$currency] ?? $usdRate;
        $value = $usd * $usdRate / $rate;

        return round($value, match ($currency) {
            'IDR' => -1,
            'JPY' => 1,
            default => 2,
        });
    }

    /** Steel weight per piece in kg (density 7.85). */
    private static function weight(string $shape, array $d): float
    {
        $mm3 = match ($shape) {
            'Flat' => $d['thickness'] * $d['width'] * $d['length'],
            'Round' => M_PI / 4 * $d['d_outer'] ** 2 * $d['length'],
            'Hollow' => M_PI / 4 * ($d['d_outer'] ** 2 - $d['d_inner'] ** 2) * $d['length'],
        };

        return round($mm3 * 7.85 / 1_000_000, 2);
    }

    // ─── Actors and cleanup ──────────────────────────────────────────────────

    private function prepareActors(): void
    {
        $purchasing = User::query()->where('role', 'purchasing')->orderByRaw("email = 'purchasing@adasi.com' desc")->orderBy('id')->first()
            ?? throw new RuntimeException('No purchasing user found; seed the base accounts first.');
        $this->purchasingId = $purchasing->id;

        foreach (['USD', 'JPY', 'CNY', 'IDR'] as $currency) {
            $rate = ExchangeRate::query()->where('currency', $currency)->orderByDesc('valid_from')->orderByDesc('id')->first();
            $this->rates[$currency] = ['id' => $rate?->id, 'rate' => $rate === null ? null : (float) $rate->rate_to_idr];
        }

        foreach ([2025, 2026] as $year) {
            $this->periods[$year] = DB::table('periods')->insertGetId([
                'name' => self::PERIOD_PREFIX.$year, 'month' => null, 'year' => $year, 'status' => 'open',
                'created_by' => $this->purchasingId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $materials = array_keys(self::CATALOG);
        foreach (self::SUPPLIERS as $key => [$company]) {
            $user = User::create([
                'name' => $company,
                'email' => 'supplier.'.strtolower($key).self::EMAIL_DOMAIN,
                'password' => Hash::make(Str::random(40)),
                'role' => 'supplier',
                'account_status' => User::ACCOUNT_STATUS_ACTIVE,
                'is_active' => true,
            ]);
            DB::table('suppliers')->insert(['user_id' => $user->id, 'company_name' => $company, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('supplier_scopes')->insert(['supplier_id' => $user->id, 'scope' => 'import', 'created_at' => now(), 'updated_at' => now()]);
            $this->users[$key] = $user->id;

            // Each supplier trades about 70% of the catalog, so some rows have no history.
            $shuffled = $materials;
            usort($shuffled, fn () => mt_rand(-1, 1));
            $this->coverage[$key] = array_slice($shuffled, 0, 10);
        }
    }

    private function cleanPreviousDemoData(): void
    {
        $prIds = DB::table('purchase_requisitions')->where('pr_number', 'like', self::PR_PREFIX.'%')->pluck('id');
        $poIds = DB::table('purchase_orders')->where('po_number', 'like', self::PO_PREFIX.'%')->pluck('id');
        $quotationIds = DB::table('quotations')->whereIn('pr_id', $prIds)->pluck('id');
        $userIds = DB::table('users')->where('email', 'like', '%'.self::EMAIL_DOMAIN)->pluck('id');

        DB::table('pr_item_awards')->whereIn('pr_id', $prIds)->delete();
        DB::table('po_quotations')->whereIn('po_id', $poIds)->delete();
        DB::table('po_documents')->whereIn('po_id', $poIds)->delete();
        DB::table('purchase_orders')->whereIn('id', $poIds)->delete();
        DB::table('quotation_items')->whereIn('quotation_id', $quotationIds)->delete();
        DB::table('quotations')->whereIn('id', $quotationIds)->delete();
        DB::table('pr_items')->whereIn('pr_id', $prIds)->delete();
        DB::table('purchase_requisition_suppliers')->whereIn('pr_id', $prIds)->delete();
        DB::table('purchase_requisitions')->whereIn('id', $prIds)->delete();
        DB::table('periods')->where('name', 'like', self::PERIOD_PREFIX.'%')->orWhere('name', 'DEMO Quotation History')->delete();
        DB::table('supplier_scopes')->whereIn('supplier_id', $userIds)->delete();
        DB::table('suppliers')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
}
