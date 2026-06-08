<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductionDummySeeder extends Seeder
{
    private array $periods = [];

    private array $rates = [];

    private array $requirements = [];

    private array $quotations = [];

    private array $purchaseOrders = [];

    private array $inspections = [];

    public function run(): void
    {
        Model::unguarded(function () {
            $this->clearProductionDummyData();

            $admin = User::where('email', 'admin@adasi.com')->firstOrFail();
            $purchasing = User::where('email', 'purchasing@adasi.com')->firstOrFail();
            $qc = User::where('email', 'qc@adasi.com')->firstOrFail();
            $supplier1 = User::where('email', 'supplier1@test.com')->firstOrFail();
            $supplier2 = User::where('email', 'supplier2@test.com')->firstOrFail();

            $purchasing2 = $this->user('Budi Santoso', 'purchasing2@adasi.com', 'purchasing');
            $supplier3 = $this->user('PT. Nippon Steel Trading', 'supplier3@test.com', 'supplier');
            $supplier4 = $this->user('PT. Posco Indonesia', 'supplier4@test.com', 'supplier');
            $supplier5 = $this->user('PT. Krakatau Steel Intl', 'supplier5@test.com', 'supplier');

            $this->supplierProfile($supplier3, 'PT. Nippon Steel Trading', 'Flat Steel, Cold Rolled', '01.234.567.8-901.000');
            $this->supplierProfile($supplier4, 'PT. Posco Indonesia', 'Round Steel, Wire Rod', '02.345.678.9-012.000');
            $this->supplierProfile($supplier5, 'PT. Krakatau Steel Intl', 'Flat Steel, Hot Rolled', '03.456.789.0-123.000');

            $this->seedExchangeRates($admin);
            $this->seedPeriods($admin);
            $this->seedRequirementsAndQuotations($purchasing, $purchasing2, $supplier1, $supplier2, $supplier3, $supplier4, $supplier5);
            $this->seedPurchaseOrders($purchasing);
            $this->seedMissingCompletedPurchaseOrders($purchasing);
            $this->seedQcInspections($qc);
            $this->seedMaterialClaims($purchasing, $supplier1);
            $this->seedAnnouncements($admin);
            $this->seedNotifications($purchasing, $supplier1);
        });
    }

    private function clearProductionDummyData(): void
    {
        foreach ([
            'messages',
            'conversations',
            'notifications',
            'attachments',
            'material_claims',
            'qc_items',
            'qc_inspections',
            'po_documents',
            'purchase_orders',
            'quotation_items',
            'quotations',
            'pr_items',
            'purchase_requisitions',
            'periods',
            'announcements',
            'exchange_rates',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    private function user(string $name, string $email, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function supplierProfile(User $user, string $companyName, string $category, string $npwp): Supplier
    {
        return Supplier::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => $companyName,
                'address' => 'Kawasan Industri Jababeka, Cikarang',
                'phone' => '021-555-' . str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                'npwp' => $npwp,
                'category' => $category,
            ]
        );
    }

    private function seedExchangeRates(User $admin): void
    {
        $usdRates = [
            '2023-01-01' => 15100,
            '2023-04-01' => 14980,
            '2023-07-01' => 15320,
            '2023-10-01' => 15580,
            '2024-01-01' => 15690,
            '2024-04-01' => 15820,
            '2024-07-01' => 16100,
            '2024-10-01' => 15950,
            '2025-01-01' => 15750,
            '2025-02-01' => 15820,
            '2025-03-01' => 16050,
            '2025-04-01' => 15980,
            '2025-05-01' => 16150,
            '2025-06-01' => 16200,
            '2025-07-01' => 16250,
            '2025-10-01' => 16420,
            '2026-01-01' => 16180,
            '2026-04-01' => 16320,
            now()->toDateString() => 16350,
        ];

        $jpyRates = [
            '2023-01-01' => 114,
            '2023-04-01' => 110,
            '2023-07-01' => 107,
            '2023-10-01' => 105,
            '2024-01-01' => 103,
            '2024-04-01' => 104,
            '2024-07-01' => 107,
            '2024-10-01' => 106,
            '2025-01-01' => 102,
            '2025-02-01' => 104,
            '2025-03-01' => 107,
            '2025-04-01' => 105,
            '2025-05-01' => 108,
            '2025-06-01' => 108,
            '2025-07-01' => 109,
            '2025-10-01' => 111,
            '2026-01-01' => 109,
            '2026-04-01' => 110,
            now()->toDateString() => 110,
        ];

        $cnyRates = [
            '2023-01-01' => 2230,
            '2023-04-01' => 2195,
            '2023-07-01' => 2135,
            '2023-10-01' => 2160,
            '2024-01-01' => 2185,
            '2024-04-01' => 2200,
            '2024-07-01' => 2225,
            '2024-10-01' => 2240,
            '2025-01-01' => 2165,
            '2025-02-01' => 2180,
            '2025-03-01' => 2210,
            '2025-04-01' => 2195,
            '2025-05-01' => 2220,
            '2025-06-01' => 2235,
            '2025-07-01' => 2245,
            '2025-10-01' => 2260,
            '2026-01-01' => 2250,
            '2026-04-01' => 2265,
            now()->toDateString() => 2270,
        ];

        foreach ($usdRates as $date => $rate) {
            $this->rate(ExchangeRate::CURRENCY_USD, $rate, $date, $admin);
        }

        foreach ($jpyRates as $date => $rate) {
            $this->rate(ExchangeRate::CURRENCY_JPY, $rate, $date, $admin);
        }

        foreach (array_keys($usdRates) as $date) {
            $this->rate(ExchangeRate::CURRENCY_IDR, 1, $date, $admin);
        }

        foreach ($cnyRates as $date => $rate) {
            $this->rate(ExchangeRate::CURRENCY_CNY, $rate, $date, $admin);
        }
    }

    private function rate(string $currency, int $rate, string $validFrom, User $admin): ExchangeRate
    {
        $date = Carbon::parse($validFrom);

        $exchangeRate = ExchangeRate::create([
            'currency' => $currency,
            'rate_to_idr' => $rate,
            'valid_from' => $date->toDateString(),
            'created_by' => $admin->id,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        $this->rates[$currency][$date->format('Y-m')] = $exchangeRate;

        if ($date->isSameDay(now())) {
            $this->rates[$currency]['today'] = $exchangeRate;
        }

        return $exchangeRate;
    }

    private function seedPeriods(User $admin): void
    {
        $cursor = Carbon::create(2023, 1, 1);
        $currentMonth = today()->copy()->startOfMonth();

        while ($cursor->lte($currentMonth)) {
            $this->period(
                $cursor->year,
                $cursor->month,
                $cursor->isSameMonth($currentMonth) ? 'open' : 'closed',
                $admin
            );

            $cursor->addMonth();
        }
    }

    private function period(int $year, int $month, string $status, User $admin): Period
    {
        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $date = Carbon::create($year, $month, 1);

        $period = Period::create([
            'name' => 'Periode ' . $monthNames[$month] . ' ' . $year,
            'month' => $month,
            'year' => $year,
            'status' => $status,
            'created_by' => $admin->id,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        $this->periods[$date->format('Y-m')] = $period;

        return $period;
    }

    private function seedRequirementsAndQuotations(
        User $purchasing,
        User $purchasing2,
        User $supplier1,
        User $supplier2,
        User $supplier3,
        User $supplier4,
        User $supplier5
    ): void {
        $this->createHistoricalRequirement(
            'REQ/01/2023/001',
            '2023-01',
            $purchasing,
            'completed',
            '2023-01-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 5000),
                'WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 6.5, null, 3000),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.62, 'WR' => 1.85]],
                [$supplier2, 'rejected', ['CR' => 1.68, 'WR' => 1.89]],
            ],
            '2023-01'
        );

        $this->createHistoricalRequirement(
            'REQ/04/2023/001',
            '2023-04',
            $purchasing,
            'completed',
            '2023-04-12',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 4800),
                'HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 4.5, 1500, 3000, 7500),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.60, 'HR' => 1.28]],
                [$supplier3, 'rejected', ['CR' => 1.65, 'HR' => 1.25]],
            ],
            '2023-04'
        );

        $this->createHistoricalRequirement(
            'REQ/07/2023/001',
            '2023-07',
            $purchasing,
            'completed',
            '2023-07-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 5200),
                'CSP' => $this->flatItem('Carbon Steel Plate', '7208.51.00', 10.0, 2000, 6000, 8000),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.65]],
                [$supplier2, 'rejected', ['CR' => 1.71]],
                [$supplier3, 'rejected', ['CR' => 1.63]],
            ],
            '2023-07'
        );

        $this->createHistoricalRequirement(
            'REQ/10/2023/001',
            '2023-10',
            $purchasing,
            'completed',
            '2023-10-12',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 6000),
                'WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 8.0, null, 2500),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.70, 'WR' => 1.90]],
                [$supplier2, 'rejected', ['CR' => 1.68, 'WR' => 1.95]],
            ],
            '2023-10'
        );

        $this->createHistoricalRequirement(
            'REQ/01/2024/001',
            '2024-01',
            $purchasing,
            'completed',
            '2024-01-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 5500),
                'HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 4.5, 1500, 3000, 8500),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.72, 'HR' => 1.35]],
                [$supplier2, 'rejected', ['CR' => 1.75, 'HR' => 1.33]],
                [$supplier3, 'rejected', ['CR' => 1.70, 'HR' => 1.38]],
            ],
            '2024-01'
        );

        $this->createHistoricalRequirement(
            'REQ/04/2024/001',
            '2024-04',
            $purchasing,
            'completed',
            '2024-04-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 5000),
                'WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 6.5, null, 3500),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.75, 'WR' => 1.95]],
                [$supplier2, 'rejected', ['CR' => 1.78, 'WR' => 1.92]],
            ],
            '2024-04'
        );

        $this->createHistoricalRequirement(
            'REQ/07/2024/001',
            '2024-07',
            $purchasing,
            'completed',
            '2024-07-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 6500),
                'HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 6.0, 1500, 6000, 11000),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.80, 'HR' => 1.42]],
                [$supplier3, 'rejected', ['CR' => 1.82, 'HR' => 1.40]],
            ],
            '2024-07'
        );

        $this->createHistoricalRequirement(
            'REQ/10/2024/001',
            '2024-10',
            $purchasing,
            'completed',
            '2024-10-12',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 7000),
                'WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 8.0, null, 3000),
                'CSP' => $this->flatItem('Carbon Steel Plate', '7208.51.00', 10.0, 2000, 6000, 9000),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.82, 'WR' => 2.05, 'CSP' => 1.10]],
                [$supplier2, 'rejected', ['CR' => 1.80, 'WR' => 2.08, 'CSP' => 1.08]],
                [$supplier3, 'rejected', ['CR' => 1.85, 'WR' => 2.02, 'CSP' => 1.12]],
            ],
            '2024-10'
        );

        $this->createHistoricalRequirement(
            'REQ/01/2025/001',
            '2025-01',
            $purchasing,
            'completed',
            '2025-01-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 5000),
                'WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 6.5, null, 3000),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.85, 'WR' => 2.10]],
                [$supplier2, 'rejected', ['CR' => 1.92, 'WR' => 2.05]],
            ],
            '2025-01'
        );

        $this->createHistoricalRequirement(
            'REQ/02/2025/001',
            '2025-02',
            $purchasing,
            'completed',
            '2025-02-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 4500),
                'HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 4.5, 1500, 3000, 8000),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.88, 'HR' => 1.45]],
                [$supplier3, 'rejected', ['CR' => 1.90, 'HR' => 1.42]],
            ],
            '2025-02'
        );

        $this->createHistoricalRequirement(
            'REQ/03/2025/001',
            '2025-03',
            $purchasing,
            'completed',
            '2025-03-10',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 6000),
            ],
            [
                [$supplier1, 'accepted', ['CR' => 1.95]],
                [$supplier2, 'rejected', ['CR' => 1.91]],
                [$supplier3, 'rejected', ['CR' => 1.98]],
            ],
            '2025-03'
        );

        $this->createHistoricalRequirement(
            'REQ/04/2025/001',
            '2025-04',
            $purchasing,
            'bidding',
            '2025-04-05',
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, 7500),
                'WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 8.0, null, 2500),
                'HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 6.0, 1500, 6000, 12000),
            ],
            [
                [$supplier1, 'submitted', ['CR' => 2.02, 'WR' => 2.15, 'HR' => 1.52]],
                [$supplier2, 'submitted', ['CR' => 1.98, 'WR' => 2.20, 'HR' => 1.48]],
                [$supplier3, 'submitted', ['CR' => 2.05, 'WR' => 2.12, 'HR' => 1.55]],
            ],
            'today'
        );

        $this->requirement(
            'REQ/04/2025/002',
            '2025-04',
            $purchasing2,
            'submitted',
            '2025-04-08',
            [
                'CSP' => $this->flatItem('Carbon Steel Plate', '7208.51.00', 10.0, 2000, 6000, 9500),
            ]
        );

        $this->requirement(
            'REQ/04/2025/003',
            '2025-04',
            $purchasing,
            'draft',
            '2025-04-10',
            [
                'SSB' => $this->roundItem('Stainless Steel Bar', '7222.20.00', 25.0, 3000, 1500),
            ]
        );

        $this->seedExtendedRequirements($purchasing, $purchasing2, $supplier1, $supplier2, $supplier3, $supplier4, $supplier5);
    }

    private function seedExtendedRequirements(
        User $purchasing,
        User $purchasing2,
        User $supplier1,
        User $supplier2,
        User $supplier3,
        User $supplier4,
        User $supplier5
    ): void {
        $cursor = Carbon::create(2025, 5, 1);
        $currentMonth = today()->copy()->startOfMonth();
        $monthOffset = 0;

        while ($cursor->lte($currentMonth)) {
            $days = $cursor->isSameMonth(today())
                ? array_values(array_unique([min(5, today()->day), today()->day]))
                : [7, 21];

            foreach ($days as $index => $day) {
                $sequence = $index + 1;
                $date = $cursor->copy()->day($day);

                $requirement = $this->requirement(
                    'REQ/' . $date->format('m/Y') . '/' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                    $date->format('Y-m'),
                    ($sequence === 2 && $date->month % 2 === 0) ? $purchasing2 : $purchasing,
                    $this->extendedRequirementStatus($date, $sequence),
                    $date->toDateString(),
                    $this->extendedRequirementItems($monthOffset, $sequence)
                );

                $this->seedExtendedQuotations($requirement, [$supplier1, $supplier2, $supplier3, $supplier4, $supplier5]);
            }

            $cursor->addMonth();
            $monthOffset++;
        }
    }

    private function extendedRequirementStatus(Carbon $date, int $sequence): string
    {
        if ($date->lt(Carbon::create(2026, 3, 1))) {
            return 'completed';
        }

        if ($date->format('Y-m') === '2026-03') {
            return $sequence === 1 ? 'completed' : 'bidding';
        }

        if ($date->format('Y-m') === '2026-04') {
            return $sequence === 1 ? 'bidding' : 'submitted';
        }

        if ($date->isSameMonth(today())) {
            return $sequence === 1 ? 'submitted' : 'draft';
        }

        return 'completed';
    }

    private function seedExtendedQuotations(PurchaseRequisition $requirement, array $suppliers): void
    {
        if ($requirement->status === 'draft' || $requirement->status === 'submitted') {
            return;
        }

        if ($requirement->status === 'bidding') {
            foreach (array_slice($suppliers, 0, 4) as $supplier) {
                $this->quotation($requirement, $supplier, 'submitted', [], $this->exchangeRateKeyForDate($requirement->created_at));
            }

            return;
        }

        $this->quotation($requirement, $suppliers[0], 'accepted', [], $this->exchangeRateKeyForDate($requirement->created_at));
        $this->quotation($requirement, $suppliers[1], 'rejected', [], $this->exchangeRateKeyForDate($requirement->created_at));
        $this->quotation($requirement, $suppliers[3], 'rejected', [], $this->exchangeRateKeyForDate($requirement->created_at));
    }

    private function extendedRequirementItems(int $monthOffset, int $sequence): array
    {
        $baseWeight = 3000 + ($monthOffset * 180) + ($sequence * 250);

        $bundles = [
            [
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 2.0, 1219, 2438, $baseWeight + 1500),
                'HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 4.5, 1500, 3000, $baseWeight + 3200),
                'WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 6.5, null, $baseWeight + 800),
            ],
            [
                'CSP' => $this->flatItem('Carbon Steel Plate', '7208.51.00', 10.0, 2000, 6000, $baseWeight + 4200),
                'SSB' => $this->roundItem('Stainless Steel Bar', '7222.20.00', 25.0, 3000, $baseWeight - 900),
                'ALLOY' => $this->roundItem('Alloy Steel Round Bar', '7228.30.00', 32.0, 6000, $baseWeight + 400),
            ],
            [
                'GI' => $this->flatItem('Galvanized Steel Coil', '7210.49.00', 1.2, 1250, null, $baseWeight + 2300),
                'CR' => $this->flatItem('Cold Rolled Steel', '7209.16.00', 1.6, 1219, 2438, $baseWeight + 1200),
                'SPRING' => $this->roundItem('Spring Steel Wire', '7229.20.00', 5.5, null, $baseWeight + 600),
            ],
            [
                'TOOL' => $this->flatItem('Tool Steel SKD11', '7228.40.00', 30.0, 300, 3000, $baseWeight - 700),
                'BEARING' => $this->roundItem('Bearing Steel Round Bar', '7228.50.00', 45.0, 6000, $baseWeight + 150),
                'HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 6.0, 1500, 6000, $baseWeight + 3800),
            ],
        ];

        return $bundles[($monthOffset + $sequence - 1) % count($bundles)];
    }

    private function createHistoricalRequirement(
        string $prNumber,
        string $periodKey,
        User $creator,
        string $status,
        string $createdAt,
        array $items,
        array $quotationRows,
        string $rateKey
    ): PurchaseRequisition {
        $requirement = $this->requirement($prNumber, $periodKey, $creator, $status, $createdAt, $items);

        foreach ($quotationRows as [$supplier, $quotationStatus, $prices]) {
            $this->quotation($requirement, $supplier, $quotationStatus, $prices, $rateKey);
        }

        return $requirement;
    }

    private function requirement(
        string $prNumber,
        string $periodKey,
        User $creator,
        string $status,
        string $createdAt,
        array $items
    ): PurchaseRequisition {
        $date = Carbon::parse($createdAt);
        $items = $this->normalizeRequirementItems($items);

        $requirement = PurchaseRequisition::create([
            'period_id' => $this->periods[$periodKey]->id,
            'created_by' => $creator->id,
            'pr_number' => $prNumber,
            'notes' => 'Dummy production-like data untuk histori pengadaan material impor.',
            'status' => $status,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        $this->requirements[$prNumber] = [
            'model' => $requirement,
            'items' => [],
        ];

        foreach ($items as $key => $item) {
            $prItem = $requirement->items()->create(array_merge($item, [
                'created_at' => $date,
                'updated_at' => $date,
            ]));

            $this->requirements[$prNumber]['items'][$key] = $prItem;
        }

        return $requirement;
    }

    private function quotation(
        PurchaseRequisition $requirement,
        User $supplier,
        string $status,
        array $prices,
        string $rateKey
    ): Quotation {
        $createdAt = Carbon::parse($requirement->created_at)->addDays(4);
        $submittedAt = $status === 'draft' ? null : $createdAt->copy()->addDay();
        $currency = $this->quotationCurrencyForSupplier($supplier);
        $rate = $this->rates[$currency][$rateKey]
            ?? $this->rates[$currency]['today']
            ?? $this->rates[ExchangeRate::CURRENCY_USD][$rateKey];
        $prNumber = $requirement->pr_number;

        $quotation = Quotation::create([
            'pr_id' => $requirement->id,
            'supplier_id' => $supplier->id,
            'currency' => $currency,
            'exchange_rate_id' => $rate->id,
            'status' => $status,
            'submitted_at' => $submittedAt,
            'estimated_delivery' => $createdAt->copy()->addDays(45)->toDateString(),
            'payment_terms' => 'Net 30 days after invoice received',
            'validity_period' => $createdAt->copy()->addDays(30)->toDateString(),
            'general_notes' => 'Dummy quotation untuk pengujian histori produksi.',
            'created_at' => $createdAt,
            'updated_at' => $submittedAt ?? $createdAt,
        ]);

        foreach ($this->requirements[$prNumber]['items'] as $itemKey => $prItem) {
            $baseUsdPrice = $prices[$itemKey] ?? $this->defaultPriceForItem($prItem, $supplier);
            $price = $this->convertUsdPriceToCurrency($baseUsdPrice, $currency, $rateKey);

            $quotation->items()->create([
                'pr_item_id' => $prItem->id,
                'price_per_kg' => $price,
                'amount' => $price * $prItem->total_weight,
                'notes' => 'Harga dummy per KG.',
                'created_at' => $createdAt,
                'updated_at' => $submittedAt ?? $createdAt,
            ]);
        }

        $this->quotations[$prNumber][$supplier->email] = $quotation;

        return $quotation;
    }

    private function quotationCurrencyForSupplier(User $supplier): string
    {
        return match ($supplier->email) {
            'supplier2@test.com' => ExchangeRate::CURRENCY_CNY,
            'supplier3@test.com' => ExchangeRate::CURRENCY_JPY,
            'supplier5@test.com' => ExchangeRate::CURRENCY_IDR,
            default => ExchangeRate::CURRENCY_USD,
        };
    }

    private function convertUsdPriceToCurrency(float $priceUsd, string $currency, string $rateKey): float
    {
        if ($currency === ExchangeRate::CURRENCY_USD) {
            return round($priceUsd, 2);
        }

        $usdRate = $this->rates[ExchangeRate::CURRENCY_USD][$rateKey]
            ?? $this->rates[ExchangeRate::CURRENCY_USD]['today']
            ?? null;
        $targetRate = $this->rates[$currency][$rateKey]
            ?? $this->rates[$currency]['today']
            ?? null;

        if (! $usdRate || ! $targetRate || (float) $targetRate->rate_to_idr <= 0) {
            return round($priceUsd, 2);
        }

        return round(($priceUsd * (float) $usdRate->rate_to_idr) / (float) $targetRate->rate_to_idr, 2);
    }

    private function flatItem(
        string $materialName,
        string $hsCode,
        float $thickness,
        float $width,
        ?float $length,
        float $weight
    ): array {
        return [
            'hs_code' => $hsCode,
            'material_name' => $materialName,
            'shape' => 'Flat',
            'thickness' => $thickness,
            'd_inner' => null,
            'd_outer' => null,
            'width' => $width,
            'length' => $length,
            'weight_needed' => $weight,
        ];
    }

    private function normalizeRequirementItems(array $items): array
    {
        if (count($items) >= 3) {
            return $items;
        }

        foreach ($this->supplementalItemPool() as $key => $item) {
            $materialNames = array_column($items, 'material_name');

            if (in_array($item['material_name'], $materialNames, true)) {
                continue;
            }

            $items[$key] = $item;

            if (count($items) >= 3) {
                break;
            }
        }

        return $items;
    }

    private function supplementalItemPool(): array
    {
        return [
            'SUP_HR' => $this->flatItem('Hot Rolled Steel', '7208.37.00', 4.5, 1500, 3000, 7200),
            'SUP_CSP' => $this->flatItem('Carbon Steel Plate', '7208.51.00', 10.0, 2000, 6000, 7800),
            'SUP_WR' => $this->roundItem('Steel Wire Rod', '7213.91.00', 6.5, null, 2800),
            'SUP_GI' => $this->flatItem('Galvanized Steel Coil', '7210.49.00', 1.2, 1250, null, 4600),
            'SUP_ALLOY' => $this->roundItem('Alloy Steel Round Bar', '7228.30.00', 32.0, 6000, 3400),
        ];
    }

    private function defaultPriceForItem(PrItem $prItem, User $supplier): float
    {
        $basePrice = match ($prItem->material_name) {
            'Cold Rolled Steel' => 1.70,
            'Hot Rolled Steel' => 1.38,
            'Steel Wire Rod' => 1.95,
            'Carbon Steel Plate' => 1.12,
            'Stainless Steel Bar' => 3.25,
            'Galvanized Steel Coil' => 1.78,
            'Alloy Steel Round Bar' => 2.45,
            'Tool Steel SKD11' => 3.80,
            'Spring Steel Wire' => 2.35,
            'Bearing Steel Round Bar' => 2.70,
            default => 1.90,
        };

        $supplierAdjustment = match ($supplier->email) {
            'supplier2@test.com' => -0.02,
            'supplier3@test.com' => 0.04,
            'supplier4@test.com' => 0.02,
            'supplier5@test.com' => -0.01,
            default => 0,
        };

        $date = Carbon::parse($prItem->created_at);
        $yearAdjustment = max(0, $date->year - 2023) * 0.08;
        $monthAdjustment = ($date->month - 1) * 0.01;

        return round(max(0.5, $basePrice + $supplierAdjustment + $yearAdjustment + $monthAdjustment), 2);
    }

    private function exchangeRateKeyForDate(string|Carbon $date): string
    {
        $target = Carbon::parse($date)->startOfMonth();

        return collect(array_keys($this->rates['USD']))
            ->reject(fn ($key) => $key === 'today')
            ->sortDesc()
            ->first(fn ($key) => Carbon::parse($key . '-01')->lte($target))
            ?? 'today';
    }

    private function roundItem(
        string $materialName,
        string $hsCode,
        float $dOuter,
        ?float $length,
        float $weight
    ): array {
        return [
            'hs_code' => $hsCode,
            'material_name' => $materialName,
            'shape' => 'Round',
            'thickness' => null,
            'd_inner' => null,
            'd_outer' => $dOuter,
            'width' => null,
            'length' => $length,
            'weight_needed' => $weight,
        ];
    }

    private function seedPurchaseOrders(User $purchasing): void
    {
        $doneDocuments = [
            'invoice' => 'verified',
            'bl' => 'done',
            'packing_list' => 'verified',
            'form_e' => 'done',
        ];

        $this->purchaseOrder('PO/01/2023/001', 'REQ/01/2023/001', $purchasing, '2023-01-20', '2023-02-28', '2023-03-02', 'completed', $doneDocuments);
        $this->purchaseOrder('PO/04/2023/001', 'REQ/04/2023/001', $purchasing, '2023-04-22', '2023-05-31', '2023-06-03', 'completed', $doneDocuments);
        $this->purchaseOrder('PO/07/2023/001', 'REQ/07/2023/001', $purchasing, '2023-07-18', '2023-08-31', '2023-09-05', 'completed', $doneDocuments);
        $this->purchaseOrder('PO/10/2023/001', 'REQ/10/2023/001', $purchasing, '2023-10-25', '2023-11-30', '2023-12-04', 'completed', $doneDocuments);

        $this->purchaseOrder('PO/01/2024/001', 'REQ/01/2024/001', $purchasing, '2024-01-22', '2024-02-28', '2024-03-03', 'completed', $doneDocuments);
        $this->purchaseOrder('PO/04/2024/001', 'REQ/04/2024/001', $purchasing, '2024-04-20', '2024-05-31', '2024-06-02', 'completed', $doneDocuments);
        $this->purchaseOrder('PO/07/2024/001', 'REQ/07/2024/001', $purchasing, '2024-07-19', '2024-08-30', '2024-09-01', 'completed', $doneDocuments);
        $this->purchaseOrder('PO/10/2024/001', 'REQ/10/2024/001', $purchasing, '2024-10-28', '2024-12-05', '2024-12-08', 'claim_needed', $doneDocuments);

        $this->purchaseOrder('PO/01/2025/001', 'REQ/01/2025/001', $purchasing, '2025-01-20', '2025-02-28', '2025-03-02', 'completed', $doneDocuments);
        $this->purchaseOrder('PO/02/2025/001', 'REQ/02/2025/001', $purchasing, '2025-02-22', '2025-03-31', '2025-04-02', 'claim_needed', $doneDocuments);
        $this->purchaseOrder('PO/03/2025/001', 'REQ/03/2025/001', $purchasing, '2025-03-25', '2025-04-30', '2025-05-02', 'waiting_qc', [
            'invoice' => 'verified',
            'bl' => 'received',
            'packing_list' => 'received',
            'form_e' => 'processing',
        ]);
    }

    private function seedMissingCompletedPurchaseOrders(User $purchasing): void
    {
        $doneDocuments = [
            'invoice' => 'verified',
            'bl' => 'done',
            'packing_list' => 'verified',
            'form_e' => 'done',
        ];

        $requirements = PurchaseRequisition::with([
            'quotations' => fn ($query) => $query->where('status', 'accepted')->with('purchaseOrders'),
        ])
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->get();

        foreach ($requirements as $requirement) {
            $quotation = $requirement->quotations->first();

            if (! $quotation || $quotation->purchaseOrders->isNotEmpty()) {
                continue;
            }

            $createdAt = Carbon::parse($quotation->submitted_at ?? $requirement->created_at)->addDays(7);
            $estimatedArrival = $createdAt->copy()->addDays(45);
            $actualArrival = $estimatedArrival->copy()->addDays(3);

            $purchaseOrder = PurchaseOrder::create([
                'supplier_id' => $quotation->supplier_id,
                'currency' => $quotation->currency,
                'exchange_rate_id' => $quotation->exchange_rate_id,
                'po_number' => $this->nextHistoricalPoNumber($createdAt),
                'status' => 'completed',
                'created_by' => $purchasing->id,
                'estimated_arrival' => $estimatedArrival->toDateString(),
                'actual_arrival' => $actualArrival->toDateString(),
                'notes' => 'Dummy purchase order historis yang dibuat otomatis agar status PR completed konsisten.',
                'created_at' => $createdAt,
                'updated_at' => $actualArrival,
            ]);

            $purchaseOrder->quotations()->syncWithoutDetaching([$quotation->id]);

            foreach ($doneDocuments as $type => $documentStatus) {
                PoDocument::create([
                    'po_id' => $purchaseOrder->id,
                    'doc_type' => $type,
                    'status' => $documentStatus,
                    'created_at' => $createdAt,
                    'updated_at' => $actualArrival->copy()->subDays(2),
                ]);
            }

            $this->purchaseOrders[$purchaseOrder->po_number] = $purchaseOrder;
        }
    }

    private function nextHistoricalPoNumber(Carbon $date): string
    {
        $prefix = 'PO/' . $date->format('m/Y') . '/';
        $lastSequence = PurchaseOrder::where('po_number', 'like', $prefix . '%')
            ->pluck('po_number')
            ->map(function ($number) {
                $parts = explode('/', $number);

                return (int) end($parts);
            })
            ->max() ?? 0;

        return $prefix . str_pad((string) ($lastSequence + 1), 3, '0', STR_PAD_LEFT);
    }

    private function purchaseOrder(
        string $poNumber,
        string $prNumber,
        User $creator,
        string $createdAt,
        string $estimatedArrival,
        ?string $actualArrival,
        string $status,
        array $documentStatuses
    ): PurchaseOrder {
        $quotation = $this->quotations[$prNumber]['supplier1@test.com'];
        $date = Carbon::parse($createdAt);

        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $quotation->supplier_id,
            'currency' => $quotation->currency,
            'exchange_rate_id' => $quotation->exchange_rate_id,
            'po_number' => $poNumber,
            'status' => $status,
            'created_by' => $creator->id,
            'estimated_arrival' => $estimatedArrival,
            'actual_arrival' => $actualArrival,
            'notes' => 'Dummy purchase order untuk simulasi end-to-end.',
            'created_at' => $date,
            'updated_at' => $actualArrival ? Carbon::parse($actualArrival) : $date,
        ]);

        $purchaseOrder->quotations()->syncWithoutDetaching([$quotation->id]);

        foreach ($documentStatuses as $type => $documentStatus) {
            PoDocument::create([
                'po_id' => $purchaseOrder->id,
                'doc_type' => $type,
                'status' => $documentStatus,
                'created_at' => $date,
                'updated_at' => $actualArrival ? Carbon::parse($actualArrival)->subDays(2) : $date,
            ]);
        }

        $this->purchaseOrders[$poNumber] = $purchaseOrder;

        return $purchaseOrder;
    }

    private function seedQcInspections(User $qc): void
    {
        $this->inspection('PO/01/2023/001', $qc, 'ok', '2023-03-03');
        $this->inspection('PO/04/2023/001', $qc, 'ok', '2023-06-04');
        $this->inspection('PO/07/2023/001', $qc, 'ok', '2023-09-06');
        $this->inspection('PO/10/2023/001', $qc, 'ok', '2023-12-05');

        $this->inspection('PO/01/2024/001', $qc, 'ok', '2024-03-04');
        $this->inspection('PO/04/2024/001', $qc, 'ok', '2024-06-03');
        $this->inspection('PO/07/2024/001', $qc, 'ok', '2024-09-02');
        $this->inspection('PO/10/2024/001', $qc, 'ng', '2024-12-09', [
            'Carbon Steel Plate' => [
                'actual_thickness' => 9.3,
                'actual_width' => 2001,
                'actual_length' => 6002,
                'actual_weight' => 8980,
                'status' => 'ng',
                'notes' => 'Tebal aktual berbeda 7 persen dari spesifikasi.',
            ],
        ], true);

        $this->inspection('PO/01/2025/001', $qc, 'ok', '2025-03-03', [
            'Cold Rolled Steel' => [
                'actual_thickness' => 2.01,
                'actual_width' => 1220,
                'actual_length' => 2440,
                'actual_weight' => 4998,
                'status' => 'ok',
            ],
            'Steel Wire Rod' => [
                'actual_d_outer' => 6.51,
                'actual_weight' => 2998,
                'status' => 'ok',
            ],
        ]);
        $this->inspection('PO/02/2025/001', $qc, 'ng', '2025-04-03', [
            'Hot Rolled Steel' => [
                'actual_thickness' => 4.3,
                'actual_width' => 1501,
                'actual_length' => 3001,
                'actual_weight' => 7995,
                'status' => 'ng',
                'notes' => 'Tebal aktual berbeda 4.4 persen dari spesifikasi.',
            ],
        ], true);
    }

    private function inspection(
        string $poNumber,
        User $qc,
        string $status,
        string $inspectedAt,
        array $overrides = [],
        bool $withDummyEvidence = false
    ): QcInspection {
        $purchaseOrder = $this->purchaseOrders[$poNumber];
        $date = Carbon::parse($inspectedAt);

        $inspection = QcInspection::create([
            'po_id' => $purchaseOrder->id,
            'inspected_by' => $qc->id,
            'status' => $status,
            'inspected_at' => $date,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        $purchaseOrder->loadMissing('quotations.items.prItem');

        foreach ($purchaseOrder->allQuotationItems() as $quotationItem) {
            $prItem = $quotationItem->prItem;
            $actual = $this->actualInspectionValues($prItem);
            $override = $overrides[$prItem->material_name] ?? [];

            $inspection->items()->create(array_merge($actual, $override, [
                'pr_item_id' => $prItem->id,
                'status' => $override['status'] ?? 'ok',
                'notes' => $override['notes'] ?? 'Dimensi aktual masih dalam toleransi.',
                'created_at' => $date,
                'updated_at' => $date,
            ]));
        }

        if ($withDummyEvidence) {
            $this->dummyEvidence($inspection, $qc, $date);
        }

        $this->inspections[$poNumber] = $inspection;

        return $inspection;
    }

    private function actualInspectionValues(PrItem $prItem): array
    {
        return [
            'actual_thickness' => $prItem->thickness ? round($prItem->thickness * 1.005, 2) : null,
            'actual_d_inner' => $prItem->d_inner ? round($prItem->d_inner * 1.005, 2) : null,
            'actual_d_outer' => $prItem->d_outer ? round($prItem->d_outer * 1.002, 2) : null,
            'actual_width' => $prItem->width ? round($prItem->width * 1.001, 2) : null,
            'actual_length' => $prItem->length ? round($prItem->length * 1.001, 2) : null,
            'actual_weight' => round($prItem->weight_needed * 0.999, 2),
        ];
    }

    private function dummyEvidence(QcInspection $inspection, User $uploadedBy, Carbon $date): void
    {
        $path = 'attachments/' . $date->format('Y/m') . '/qc-ng-evidence-' . $inspection->id . '.jpg';

        Storage::disk('local')->put($path, 'Dummy QC NG evidence for inspection ' . $inspection->id);

        $inspection->attachments()->create([
            'file_path' => $path,
            'file_name' => basename($path),
            'file_type' => 'image/jpeg',
            'uploaded_by' => $uploadedBy->id,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function seedMaterialClaims(User $purchasing, User $supplier1): void
    {
        $this->claim(
            'PO/10/2024/001',
            $purchasing,
            $supplier1,
            'resolved',
            '2024-12-10',
            '2024-12-29',
            'Material Carbon Steel Plate tidak sesuai spesifikasi. Tebal aktual 9.3mm, diminta 10mm. Selisih 7% melebihi toleransi yang diizinkan.',
            'Penggantian material sesuai spesifikasi dalam 14 hari kerja atau pengembalian dana sebagian senilai selisih material.',
            '2025-01-09',
            'Kami mohon maaf. Penggantian material telah kami proses dan akan tiba dalam 10 hari kerja.'
        );

        $this->claim(
            'PO/02/2025/001',
            $purchasing,
            $supplier1,
            'responded',
            '2025-04-04',
            '2025-04-08',
            'Material Hot Rolled Steel tidak sesuai spesifikasi. Tebal aktual 4.3mm, diminta 4.5mm. Selisih melebihi toleransi 5%.',
            'Mohon penggantian material sesuai spesifikasi atau pengembalian dana sebagian.',
            '2025-05-04',
            'Kami mohon maaf atas ketidaksesuaian ini. Kami siap mengirimkan penggantian material dalam 14 hari kerja.'
        );
    }

    private function claim(
        string $poNumber,
        User $submittedBy,
        User $supplier,
        string $status,
        string $createdAt,
        string $updatedAt,
        string $description,
        string $resolutionExpected,
        string $deadline,
        string $supplierResponse
    ): MaterialClaim {
        $purchaseOrder = $this->purchaseOrders[$poNumber];
        $inspection = $this->inspections[$poNumber];

        return MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $purchaseOrder->id,
            'submitted_by' => $submittedBy->id,
            'supplier_id' => $supplier->id,
            'status' => $status,
            'description' => $description,
            'resolution_expected' => $resolutionExpected,
            'deadline' => $deadline,
            'supplier_response' => $supplierResponse,
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($updatedAt),
        ]);
    }

    private function seedAnnouncements(User $admin): void
    {
        $announcements = [
            [
                'title' => 'Jadwal Libur Nasional Mei 2025',
                'content' => 'ADASI akan libur pada tanggal 1-2 Mei 2025 dalam rangka Hari Buruh dan Kenaikan Isa Almasih. Mohon sesuaikan jadwal pengiriman dan penerbitan dokumen impor.',
                'published_at' => now()->subMonth(),
            ],
            [
                'title' => 'Pembaruan Prosedur Pengiriman Dokumen Impor',
                'content' => 'Mulai 1 April 2025, seluruh dokumen impor wajib diunggah ke sistem dalam format PDF. Dokumen fisik tetap diperlukan untuk verifikasi akhir di gudang ADASI.',
                'published_at' => now()->subWeeks(3),
            ],
            [
                'title' => 'Perpanjangan Lisensi Ekspor Supplier',
                'content' => 'Harap memastikan lisensi ekspor perusahaan Anda masih berlaku hingga akhir tahun 2025. Supplier dengan lisensi kadaluarsa tidak dapat mengikuti periode penawaran berikutnya.',
                'published_at' => now()->subWeek(),
            ],
        ];

        foreach ($announcements as $announcement) {
            Announcement::create([
                'title' => $announcement['title'],
                'content' => $announcement['content'],
                'created_by' => $admin->id,
                'published_at' => $announcement['published_at'],
                'created_at' => $announcement['published_at'],
                'updated_at' => $announcement['published_at'],
            ]);
        }
    }

    private function seedNotifications(User $purchasing, User $supplier1): void
    {
        $this->notification($purchasing, 'Penawaran Baru Masuk', 'supplier1 telah mengirim penawaran untuk REQ/04/2025/001', now()->subDays(2), false);
        $this->notification($purchasing, 'Penawaran Baru Masuk', 'supplier2 telah mengirim penawaran untuk REQ/04/2025/001', now()->subDays(2), false);
        $this->notification($purchasing, 'Penawaran Baru Masuk', 'supplier3 telah mengirim penawaran untuk REQ/04/2025/001', now()->subDay(), false);
        $this->notification($purchasing, 'Supplier Merespons Klaim', 'supplier1 telah merespons klaim untuk PO/02/2025/001. Silakan tinjau respons tersebut.', now()->subDays(3), false);
        $this->notification($purchasing, 'Status Dokumen Diperbarui', 'Dokumen Invoice pada PO/03/2025/001 telah diperbarui menjadi Diverifikasi', now()->subDays(5), true);
        $this->notification($purchasing, 'Material Lulus Inspeksi QC', 'Material dari PO/01/2025/001 telah lulus inspeksi QC', now()->subMonths(2), true);

        $this->notification($supplier1, 'Permintaan Material Baru', 'Terdapat permintaan material baru pada Periode April 2025. Silakan berikan penawaran sebelum periode ditutup.', now()->subDays(3), false);
        $this->notification($supplier1, 'Purchase Order Diterbitkan', 'Anda telah dipilih untuk PO/03/2025/001. Silakan proses pengiriman material segera.', now()->subMonth(), false);
        $this->notification($supplier1, 'Klaim Material Diterima', 'ADASI telah mengajukan klaim untuk PO/02/2025/001. Silakan tinjau dan berikan respons.', now()->subWeek(), true);
        $this->notification($supplier1, 'Purchase Order Diterbitkan', 'Anda telah dipilih untuk PO/01/2025/001.', now()->subMonths(3), true);
    }

    private function notification(User $user, string $title, string $message, Carbon $createdAt, bool $read): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SystemNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'title' => $title,
                'message' => $message,
                'url' => '#',
                'icon' => 'bi-bell',
            ]),
            'read_at' => $read ? $createdAt->copy()->addHour() : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
