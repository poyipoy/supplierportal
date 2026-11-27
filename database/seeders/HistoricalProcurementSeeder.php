<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricalProcurementSeeder extends Seeder
{
    private const START_DATE = '2020-01-01';

    private const END_DATE = '2026-11-27';

    private const BUSINESS_TZ = 'Asia/Jakarta';

    private const INDONESIAN_MONTHS = [
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

    /**
     * Yearly steel commodity index (2020–2026) reflecting global alloy/tool steel cycles.
     *
     * @var array<int, float>
     */
    private const YEAR_PRICE_MULTIPLIER = [
        2020 => 0.91,
        2021 => 1.13,
        2022 => 1.24,
        2023 => 1.09,
        2024 => 1.05,
        2025 => 1.10,
        2026 => 1.15,
    ];

    /**
     * Historical USD/CNY/JPY to IDR rates for exchange_rates table (quarterly checkpoints).
     *
     * @var array<int, array{USD: float, JPY: float, CNY: float}>
     */
    private const YEAR_FX_BASE = [
        2020 => ['USD' => 14580.0, 'JPY' => 136.0, 'CNY' => 2115.0],
        2021 => ['USD' => 14310.0, 'JPY' => 131.0, 'CNY' => 2220.0],
        2022 => ['USD' => 14870.0, 'JPY' => 114.0, 'CNY' => 2210.0],
        2023 => ['USD' => 15240.0, 'JPY' => 109.0, 'CNY' => 2155.0],
        2024 => ['USD' => 15850.0, 'JPY' => 105.0, 'CNY' => 2195.0],
        2025 => ['USD' => 16250.0, 'JPY' => 108.0, 'CNY' => 2235.0],
        2026 => ['USD' => 16500.0, 'JPY' => 110.0, 'CNY' => 2250.0],
    ];

    /**
     * Realistic industrial procurement notes (Indonesian/English operational style).
     *
     * @var list<string>
     */
    private const PR_NOTES = [
        'Kebutuhan produksi reguler workshop tooling dan dies.',
        'Pengadaan stok bahan baku gudang Cikarang.',
        'Permintaan material untuk jadwal machining bulan berjalan.',
        'Replenishment inventory raw material lini otomotif.',
        'Order material proyek dies stamping dan moulding.',
        'Pengadaan rutin material potong CNC & heat treatment.',
        'Kebutuhan material komponen presisi divisi manufaktur.',
        'Restock plate dan round bar sesuai safety stock warehouse.',
    ];

    /**
     * @var list<string>
     */
    private const PAYMENT_TERMS = [
        'T/T 30 Days',
        'T/T 45 Days',
        'T/T 60 Days',
        'L/C at Sight',
        'Net 30 Days after B/L',
    ];

    /**
     * @var list<array{description: string, resolution: string, response: string}>
     */
    private const CLAIM_TEMPLATES = [
        [
            'description' => 'Hasil inspeksi QC menemukan ketebalan aktual berada di luar toleransi pesanan sebesar -1.2 mm pada sebagian batch.',
            'resolution' => 'Penggantian material sesuai spesifikasi dimensi atau credit note proporsional.',
            'response' => 'Kami menyetujui penerbitan credit note dan penyesuaian kalibrasi milling pada pengiriman berikutnya.',
        ],
        [
            'description' => 'Ditemukan permukaan oksidasi dan pitting lokal pada area tepi material saat penerimaan gudang.',
            'resolution' => 'Replacement sebagian unit terdampak beserta laporan tindakan korektif.',
            'response' => 'Tim kualitas kami telah menambahkan pelapisan anti-rust oil tambahan dan memproses penggantian unit.',
        ],
        [
            'description' => 'Nilai kekerasan permukaan hasil uji hardness berada di bawah standar mill test certificate.',
            'resolution' => 'Penggantian lot material yang tidak memenuhi standar kekerasan.',
            'response' => 'Penggantian lot dijadwalkan pada shipment reguler bulan depan dengan MTC re-verified.',
        ],
        [
            'description' => 'Selisih berat aktual melebihi batas toleransi penerimaan sebesar 3.4% dari dokumen packing list.',
            'resolution' => 'Penyesuaian tagihan invoice sesuai berat aktual hasil timbang QC.',
            'response' => 'Disetujui penyesuaian nilai invoice sesuai berita acara penimbangan gudang penerima.',
        ],
        [
            'description' => 'Kelurusan batang (straightness) melebihi toleransi machining pada beberapa batang round bar.',
            'resolution' => 'Rework atau penggantian batang yang melengkung.',
            'response' => 'Kami mengganti batang yang terdampak pada pengiriman berikutnya tanpa biaya tambahan.',
        ],
    ];

    /** @var array<int, array{id: int, code: string, density: float, hs_category: ?string, shapes: list<string>, base_usd: float, weight: int}> */
    private array $materialCatalog = [];

    /** @var array<string, array{id: int, hs_code: string}> */
    private array $hsRulesByCategoryShape = [];

    /** @var array<string, array{id: int, hs_code: string}> */
    private array $hsRulesByShape = [];

    /** @var array<string, list<array{id: int, valid_from: string, rate: float}>> */
    private array $exchangeRateTimeline = [];

    /** @var array<string, int> */
    private array $sequences = [];

    /** @var array<string, int> */
    private array $periodIds = [];

    /** @var list<int> */
    private array $purchasingUserIds = [];

    private int $adminUserId = 1;

    private int $qcUserId = 3;

    /** @var list<array{id: int, name: string, currencies: list<string>, price_factor: float, lead_min: int, lead_max: int}> */
    private array $suppliers = [];

    public function run(): void
    {
        DB::disableQueryLog();
        mt_srand(20261127);

        $this->resolveActors();
        $this->loadMaterialCatalog();
        $this->loadHsCodeRules();
        $this->ensureAnnualExchangeRates();
        $this->ensureHistoricalExchangeRates();
        $this->ensurePeriods();
        $this->loadDocumentSequences();

        $existingBusinessDates = $this->loadExistingCoveredBusinessDates();

        $months = $this->buildMonthBuckets();
        $stats = [
            'days_seeded' => 0,
            'days_skipped' => 0,
            'prs' => 0,
            'pr_items' => 0,
            'quotations' => 0,
            'quotation_items' => 0,
            'awards' => 0,
            'pos' => 0,
            'shipments' => 0,
            'qc_inspections' => 0,
            'claims' => 0,
        ];

        foreach ($months as $ym => $dates) {
            $pendingDates = [];
            foreach ($dates as $dateStr) {
                if (isset($existingBusinessDates[$dateStr])) {
                    $stats['days_skipped']++;

                    continue;
                }
                $pendingDates[] = $dateStr;
            }

            if ($pendingDates === []) {
                continue;
            }

            $monthStats = $this->seedMonth($ym, $pendingDates);
            foreach ($monthStats as $key => $value) {
                $stats[$key] += $value;
            }
        }

        $this->syncDocumentSequences();

        if ($this->command !== null) {
            $this->command->info(sprintf(
                'HistoricalProcurementSeeder selesai: %d hari kerja diisi (%d dilewati) | PR: %d | PR Items: %d | Quotations: %d | Quotation Items: %d | PO: %d | Shipments: %d | QC: %d | Claims: %d',
                $stats['days_seeded'],
                $stats['days_skipped'],
                $stats['prs'],
                $stats['pr_items'],
                $stats['quotations'],
                $stats['quotation_items'],
                $stats['pos'],
                $stats['shipments'],
                $stats['qc_inspections'],
                $stats['claims'],
            ));
        }
    }

    private function resolveActors(): void
    {
        $admin = DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', 1)
            ->orderBy('id')
            ->value('id');
        if ($admin === null) {
            throw new RuntimeException('Active admin user not found.');
        }
        $this->adminUserId = (int) $admin;

        $purchasingUsers = DB::table('users')
            ->where('role', 'purchasing')
            ->where('is_active', 1)
            ->where('account_status', 'ACTIVE')
            ->where('email', 'not like', '%.test')
            ->where('name', 'not like', '%Test%')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($purchasingUsers === []) {
            $purchasingUsers = DB::table('users')
                ->where('role', 'purchasing')
                ->where('is_active', 1)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }
        if ($purchasingUsers === []) {
            throw new RuntimeException('Active purchasing user not found.');
        }
        $this->purchasingUserIds = $purchasingUsers;

        $qc = DB::table('users')
            ->where('role', 'qc')
            ->where('is_active', 1)
            ->orderBy('id')
            ->value('id');
        if ($qc === null) {
            throw new RuntimeException('Active QC user not found.');
        }
        $this->qcUserId = (int) $qc;

        $importSupplierRows = DB::table('users as u')
            ->join('supplier_scopes as ss', 'ss.supplier_id', '=', 'u.id')
            ->leftJoin('suppliers as s', 's.user_id', '=', 'u.id')
            ->where('u.role', 'supplier')
            ->where('u.is_active', 1)
            ->where('u.account_status', 'ACTIVE')
            ->where('ss.scope', 'import')
            ->orderBy('u.id')
            ->select('u.id', DB::raw("COALESCE(NULLIF(TRIM(s.company_name), ''), u.name) as display_name"))
            ->get();

        if ($importSupplierRows->count() < 2) {
            throw new RuntimeException('At least 2 active import suppliers are required.');
        }

        // Assign distinct realistic commercial profiles per existing import supplier.
        // We intentionally use USD, IDR, and CNY so both annual-rate and spot-rate views stay consistent.
        $profiles = [
            ['currencies' => ['USD', 'USD', 'USD', 'IDR'], 'price_factor' => 0.99, 'lead_min' => 16, 'lead_max' => 26],
            ['currencies' => ['IDR', 'IDR', 'USD'], 'price_factor' => 1.02, 'lead_min' => 14, 'lead_max' => 24],
            ['currencies' => ['USD', 'USD', 'CNY'], 'price_factor' => 1.01, 'lead_min' => 18, 'lead_max' => 30],
            ['currencies' => ['USD', 'USD', 'CNY'], 'price_factor' => 0.98, 'lead_min' => 15, 'lead_max' => 25],
            ['currencies' => ['IDR', 'USD', 'IDR'], 'price_factor' => 1.00, 'lead_min' => 14, 'lead_max' => 22],
        ];

        $this->suppliers = [];
        foreach ($importSupplierRows->values() as $idx => $row) {
            $profile = $profiles[$idx % count($profiles)];
            $this->suppliers[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->display_name,
                'currencies' => $profile['currencies'],
                'price_factor' => $profile['price_factor'],
                'lead_min' => $profile['lead_min'],
                'lead_max' => $profile['lead_max'],
            ];
        }
    }

    private function loadMaterialCatalog(): void
    {
        $definitions = [
            // Core high-frequency tool & mould steels (appear consistently across all months)
            'SKD11' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 5.45, 'weight' => 12],
            'DC53' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 6.85, 'weight' => 11],
            'SKD61' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 6.10, 'weight' => 11],
            'P20' => ['shapes' => ['Flat'], 'base_usd' => 3.65, 'weight' => 10],
            'NAK80' => ['shapes' => ['Flat'], 'base_usd' => 7.40, 'weight' => 9],
            'S45C' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 1.65, 'weight' => 10],
            'SCM440' => ['shapes' => ['Round', 'Flat'], 'base_usd' => 2.55, 'weight' => 9],
            'SUS 304' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 3.95, 'weight' => 8],
            'ST52' => ['shapes' => ['Hollow'], 'base_usd' => 2.85, 'weight' => 8],
            'Aluminium 6061' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 4.80, 'weight' => 7],

            // Regular industrial grades
            'DC11' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 5.65, 'weight' => 6],
            'DHA1' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 6.45, 'weight' => 6],
            'DH31' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 6.90, 'weight' => 5],
            'DH32' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 7.10, 'weight' => 4],
            'PX5' => ['shapes' => ['Flat'], 'base_usd' => 4.15, 'weight' => 5],
            'S-Star' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 8.20, 'weight' => 5],
            '1.2316' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 5.95, 'weight' => 6],
            '1.2738' => ['shapes' => ['Flat'], 'base_usd' => 4.35, 'weight' => 6],
            'YK30' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 4.95, 'weight' => 5],
            'GOA' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 5.15, 'weight' => 4],
            'SKH9' => ['shapes' => ['Round', 'Flat'], 'base_usd' => 11.80, 'weight' => 5],
            'DRM1' => ['shapes' => ['Round'], 'base_usd' => 12.60, 'weight' => 3],
            'S50C' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 1.75, 'weight' => 6],
            'S55C' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 1.85, 'weight' => 4],
            'SS400' => ['shapes' => ['Flat'], 'base_usd' => 1.35, 'weight' => 5],
            'SCM440H' => ['shapes' => ['Round'], 'base_usd' => 2.80, 'weight' => 5],
            'SNCM439' => ['shapes' => ['Round'], 'base_usd' => 3.55, 'weight' => 5],
            'SUJ2' => ['shapes' => ['Round'], 'base_usd' => 2.95, 'weight' => 5],
            'HARDOX 500' => ['shapes' => ['Flat'], 'base_usd' => 3.75, 'weight' => 4],
            'Aluminium 7075' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 6.75, 'weight' => 5],
            'ST60' => ['shapes' => ['Hollow'], 'base_usd' => 3.10, 'weight' => 4],
            'DCMX' => ['shapes' => ['Flat', 'Round'], 'base_usd' => 7.25, 'weight' => 4],
        ];

        $masters = DB::table('material_masters')
            ->where('is_active', 1)
            ->whereIn('material_code', array_keys($definitions))
            ->get();

        $this->materialCatalog = [];
        foreach ($masters as $master) {
            $code = (string) $master->material_code;
            $def = $definitions[$code];
            $density = strtolower((string) $master->density_profile) === 'aluminium' ? 2.71 : 7.85;

            $this->materialCatalog[] = [
                'id' => (int) $master->id,
                'code' => $code,
                'density' => $density,
                'hs_category' => $master->hs_category !== null ? (string) $master->hs_category : null,
                'shapes' => $def['shapes'],
                'base_usd' => $def['base_usd'],
                'weight' => $def['weight'],
            ];
        }

        if (count($this->materialCatalog) < 10) {
            throw new RuntimeException('Insufficient active material_masters records for seeding.');
        }
    }

    private function loadHsCodeRules(): void
    {
        $rules = DB::table('hs_code_rules')
            ->where('status', 'active')
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'hs_code', 'material_category', 'shape']);

        $this->hsRulesByCategoryShape = [];
        $this->hsRulesByShape = [];

        foreach ($rules as $rule) {
            $catKey = strtolower((string) $rule->material_category).'|'.strtolower((string) $rule->shape);
            if (! isset($this->hsRulesByCategoryShape[$catKey])) {
                $this->hsRulesByCategoryShape[$catKey] = [
                    'id' => (int) $rule->id,
                    'hs_code' => (string) $rule->hs_code,
                ];
            }

            $shapeKey = strtolower((string) $rule->shape);
            if (! isset($this->hsRulesByShape[$shapeKey])) {
                $this->hsRulesByShape[$shapeKey] = [
                    'id' => (int) $rule->id,
                    'hs_code' => (string) $rule->hs_code,
                ];
            }
        }
    }

    private function ensureAnnualExchangeRates(): void
    {
        $existingYears = DB::table('annual_exchange_rates')
            ->select('year', 'currency')
            ->get()
            ->map(static fn ($row): string => $row->year.'|'.$row->currency)
            ->flip()
            ->all();

        $defaults = [
            2020 => ['USD' => '14580.0000', 'JPY' => '136.0000', 'CNY' => '2115.0000'],
            2021 => ['USD' => '14310.0000', 'JPY' => '131.0000', 'CNY' => '2220.0000'],
            2022 => ['USD' => '14210.0000', 'JPY' => '114.0000', 'CNY' => '2210.0000'],
            2023 => ['USD' => '14632.0000', 'JPY' => '109.0000', 'CNY' => '2155.0000'],
            2024 => ['USD' => '14890.0000', 'JPY' => '105.0000', 'CNY' => '2195.0000'],
            2025 => ['USD' => '15000.0000', 'JPY' => '108.0000', 'CNY' => '2235.0000'],
            2026 => ['USD' => '16500.0000', 'JPY' => '110.0000', 'CNY' => '2250.0000'],
        ];

        $toInsert = [];
        $now = now()->utc()->format('Y-m-d H:i:s');

        foreach ($defaults as $year => $rates) {
            foreach ($rates as $currency => $rateToIdr) {
                if (isset($existingYears[$year.'|'.$currency])) {
                    continue;
                }
                $toInsert[] = [
                    'year' => $year,
                    'revision' => 1,
                    'currency' => $currency,
                    'rate_to_idr' => $rateToIdr,
                    'created_by' => $this->adminUserId,
                    'created_at' => Carbon::create($year, 1, 2, 2, 0, 0, 'UTC')->format('Y-m-d H:i:s'),
                    'updated_at' => $now,
                ];
            }
        }

        if ($toInsert !== []) {
            $maxId = (int) (DB::table('annual_exchange_rates')->max('id') ?? 0);
            if ($maxId > 0) {
                DB::statement('ALTER TABLE `annual_exchange_rates` AUTO_INCREMENT = '.($maxId + 1));
            }
            DB::table('annual_exchange_rates')->insert($toInsert);
        }
    }

    private function ensureHistoricalExchangeRates(): void
    {
        $existing = DB::table('exchange_rates')
            ->select('currency', 'valid_from')
            ->get()
            ->map(static fn ($row): string => $row->currency.'|'.$row->valid_from)
            ->flip()
            ->all();

        $toInsert = [];

        if (! isset($existing['IDR|2020-01-01'])) {
            $toInsert[] = [
                'currency' => 'IDR',
                'rate_to_idr' => '1.0000',
                'valid_from' => '2020-01-01',
                'created_by' => $this->adminUserId,
                'created_at' => '2019-12-31 17:00:00',
                'updated_at' => '2019-12-31 17:00:00',
            ];
        }

        foreach (self::YEAR_FX_BASE as $year => $baseRates) {
            foreach ([1, 4, 7, 10] as $quarterIndex => $month) {
                if ($year === 2026 && $month > 5) {
                    // Preserve 2026 existing rates from May onward.
                    continue;
                }
                $validFrom = sprintf('%04d-%02d-01', $year, $month);
                $drift = [-0.012, 0.004, 0.011, -0.003][$quarterIndex];
                $ts = Carbon::create($year, $month, 1, 1, 0, 0, 'UTC')->format('Y-m-d H:i:s');

                foreach (['USD', 'JPY', 'CNY'] as $currency) {
                    if (isset($existing[$currency.'|'.$validFrom])) {
                        continue;
                    }
                    $rate = round($baseRates[$currency] * (1.0 + $drift), 2);
                    $toInsert[] = [
                        'currency' => $currency,
                        'rate_to_idr' => number_format($rate, 4, '.', ''),
                        'valid_from' => $validFrom,
                        'created_by' => $this->adminUserId,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ];
                }
            }
        }

        if ($toInsert !== []) {
            DB::table('exchange_rates')->insert($toInsert);
        }

        $this->exchangeRateTimeline = [];
        $allRates = DB::table('exchange_rates')
            ->orderBy('valid_from', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'currency', 'valid_from', 'rate_to_idr']);

        foreach ($allRates as $row) {
            $this->exchangeRateTimeline[(string) $row->currency][] = [
                'id' => (int) $row->id,
                'valid_from' => (string) $row->valid_from,
                'rate' => (float) $row->rate_to_idr,
            ];
        }
    }

    private function ensurePeriods(): void
    {
        $existing = DB::table('periods')
            ->whereNotNull('month')
            ->where('name', 'not like', '%Stress%')
            ->where('name', 'not like', '%Test%')
            ->orderBy('id')
            ->get(['id', 'year', 'month']);

        $this->periodIds = [];
        foreach ($existing as $row) {
            $key = sprintf('%04d-%02d', (int) $row->year, (int) $row->month);
            if (! isset($this->periodIds[$key])) {
                $this->periodIds[$key] = (int) $row->id;
            }
        }

        $toInsert = [];
        for ($year = 2020; $year <= 2026; $year++) {
            $maxMonth = $year === 2026 ? 11 : 12;
            for ($month = 1; $month <= $maxMonth; $month++) {
                $key = sprintf('%04d-%02d', $year, $month);
                if (isset($this->periodIds[$key])) {
                    continue;
                }
                $createdAt = Carbon::create($year, $month, 1, 1, 0, 0, 'UTC')->format('Y-m-d H:i:s');
                $toInsert[] = [
                    'name' => 'Periode '.self::INDONESIAN_MONTHS[$month].' '.$year,
                    'month' => $month,
                    'year' => $year,
                    'status' => ($year === 2026 && $month === 11) ? 'open' : 'closed',
                    'created_by' => $this->purchasingUserIds[0],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
        }

        if ($toInsert !== []) {
            DB::table('periods')->insert($toInsert);
            $reloaded = DB::table('periods')
                ->whereNotNull('month')
                ->where('name', 'not like', '%Stress%')
                ->where('name', 'not like', '%Test%')
                ->orderBy('id')
                ->get(['id', 'year', 'month']);
            foreach ($reloaded as $row) {
                $key = sprintf('%04d-%02d', (int) $row->year, (int) $row->month);
                if (! isset($this->periodIds[$key])) {
                    $this->periodIds[$key] = (int) $row->id;
                }
            }
        }
    }

    private function loadDocumentSequences(): void
    {
        $this->sequences = [];

        foreach (DB::table('document_sequences')->get(['type', 'year', 'month', 'last_number']) as $row) {
            $key = sprintf('%s|%04d|%02d', $row->type, (int) $row->year, (int) $row->month);
            $this->sequences[$key] = max($this->sequences[$key] ?? 0, (int) $row->last_number);
        }

        // Also scan existing document numbers to guarantee zero collisions even if document_sequences lagged.
        $scanSources = [
            ['table' => 'purchase_requisitions', 'col' => 'pr_number', 'prefix' => 'REQ', 'type' => 'PR'],
            ['table' => 'purchase_orders', 'col' => 'po_number', 'prefix' => 'PO', 'type' => 'PO'],
            ['table' => 'shipments', 'col' => 'shipment_number', 'prefix' => 'SHP', 'type' => 'SHP'],
        ];

        foreach ($scanSources as $source) {
            $numbers = DB::table($source['table'])
                ->whereNotNull($source['col'])
                ->pluck($source['col']);

            $pattern = '/^'.preg_quote($source['prefix'], '/').'\/(\d{2})\/(\d{4})\/(\d+)$/';
            foreach ($numbers as $num) {
                if (preg_match($pattern, (string) $num, $matches) === 1) {
                    $month = (int) $matches[1];
                    $year = (int) $matches[2];
                    $seq = (int) $matches[3];
                    $key = sprintf('%s|%04d|%02d', $source['type'], $year, $month);
                    $this->sequences[$key] = max($this->sequences[$key] ?? 0, $seq);
                }
            }
        }
    }

    private function nextDocumentNumber(string $type, string $prefix, int $year, int $month): string
    {
        $key = sprintf('%s|%04d|%02d', $type, $year, $month);
        $next = ($this->sequences[$key] ?? 0) + 1;
        $this->sequences[$key] = $next;

        return sprintf('%s/%02d/%04d/%03d', $prefix, $month, $year, $next);
    }

    private function syncDocumentSequences(): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($this->sequences as $key => $lastNumber) {
            [$type, $yearStr, $monthStr] = explode('|', $key);
            $rows[] = [
                'type' => $type,
                'year' => (int) $yearStr,
                'month' => (int) $monthStr,
                'last_number' => $lastNumber,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('document_sequences')->upsert(
                $chunk,
                ['type', 'year', 'month'],
                ['last_number', 'updated_at']
            );
        }
    }

    /**
     * Skip any business date (Asia/Jakarta) that already has a non-draft, non-cancelled PR
     * with at least one non-deleted quotation, ensuring safe idempotency on re-runs.
     *
     * @return array<string, true>
     */
    private function loadExistingCoveredBusinessDates(): array
    {
        $rows = DB::table('purchase_requisitions as pr')
            ->join('quotations as q', function ($join): void {
                $join->on('q.pr_id', '=', 'pr.id')
                    ->whereNull('q.deleted_at');
            })
            ->whereNull('pr.deleted_at')
            ->whereNotIn('pr.status', ['draft', 'cancelled'])
            ->select('pr.created_at')
            ->distinct()
            ->get();

        $dates = [];
        foreach ($rows as $row) {
            if ($row->created_at === null) {
                continue;
            }
            $businessDate = Carbon::parse((string) $row->created_at, 'UTC')
                ->setTimezone(self::BUSINESS_TZ)
                ->format('Y-m-d');
            $dates[$businessDate] = true;
        }

        return $dates;
    }

    /**
     * Build ordered map of YYYY-MM => list of weekday dates (Mon–Fri).
     *
     * @return array<string, list<string>>
     */
    private function buildMonthBuckets(): array
    {
        $buckets = [];
        $period = CarbonPeriod::create(self::START_DATE, self::END_DATE);
        foreach ($period as $day) {
            if ($day->dayOfWeekIso > 5) {
                continue;
            }
            $ym = $day->format('Y-m');
            $buckets[$ym][] = $day->format('Y-m-d');
        }

        return $buckets;
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, int>
     */
    private function seedMonth(string $ym, array $dates): array
    {
        [$yearStr, $monthStr] = explode('-', $ym);
        $year = (int) $yearStr;
        $month = (int) $monthStr;
        $periodId = $this->periodIds[$ym];

        return DB::transaction(function () use ($year, $month, $periodId, $dates): array {
            $dayPlans = [];
            $prInsertRows = [];

            foreach ($dates as $dateStr) {
                $dayPlans[] = $this->buildDayPlan($dateStr, $year, $month, $periodId);
                $prInsertRows[] = $dayPlans[array_key_last($dayPlans)]['pr_row'];
            }

            // 1. Insert PRs & resolve IDs by unique pr_number
            $this->chunkInsert('purchase_requisitions', $prInsertRows);
            $prNumbers = array_column($prInsertRows, 'pr_number');
            $prIdByNumber = DB::table('purchase_requisitions')
                ->whereIn('pr_number', $prNumbers)
                ->pluck('id', 'pr_number')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            // 2. Insert PR invited suppliers & PR items
            $prSupplierRows = [];
            $prItemRows = [];
            foreach ($dayPlans as &$plan) {
                $prId = $prIdByNumber[$plan['pr_number']];
                $plan['pr_id'] = $prId;

                foreach ($plan['invited_suppliers'] as $supplier) {
                    $prSupplierRows[] = [
                        'pr_id' => $prId,
                        'supplier_id' => $supplier['id'],
                        'invited_at' => $plan['pr_created_utc'],
                        'created_at' => $plan['pr_created_utc'],
                        'updated_at' => $plan['pr_created_utc'],
                    ];
                }

                foreach ($plan['items'] as $item) {
                    $prItemRows[] = array_merge(['pr_id' => $prId], $item['pr_item_row']);
                }
            }
            unset($plan);

            $this->chunkInsert('purchase_requisition_suppliers', $prSupplierRows);
            $this->chunkInsert('pr_items', $prItemRows);

            $monthPrIds = array_values($prIdByNumber);
            $prItemIdMap = [];
            foreach (DB::table('pr_items')->whereIn('pr_id', $monthPrIds)->get(['id', 'pr_id', 'material_master_id']) as $row) {
                $prItemIdMap[$row->pr_id.'|'.$row->material_master_id] = (int) $row->id;
            }

            // 3. Insert Quotations
            $quotationRows = [];
            foreach ($dayPlans as $plan) {
                $prId = $plan['pr_id'];
                foreach ($plan['quotations'] as $quote) {
                    $quotationRows[] = array_merge(['pr_id' => $prId], $quote['quotation_row']);
                }
            }
            $this->chunkInsert('quotations', $quotationRows);

            $quotationIdMap = [];
            foreach (DB::table('quotations')->whereIn('pr_id', $monthPrIds)->get(['id', 'pr_id', 'supplier_id']) as $row) {
                $quotationIdMap[$row->pr_id.'|'.$row->supplier_id] = (int) $row->id;
            }

            // 4. Insert Quotation Items
            $quotationItemRows = [];
            foreach ($dayPlans as $plan) {
                $prId = $plan['pr_id'];
                foreach ($plan['quotations'] as $quote) {
                    $quotationId = $quotationIdMap[$prId.'|'.$quote['supplier_id']];
                    foreach ($quote['items'] as $qItem) {
                        $prItemId = $prItemIdMap[$prId.'|'.$qItem['material_master_id']];
                        $quotationItemRows[] = array_merge([
                            'quotation_id' => $quotationId,
                            'pr_item_id' => $prItemId,
                        ], $qItem['row']);
                    }
                }
            }
            $this->chunkInsert('quotation_items', $quotationItemRows);

            $monthQuotationIds = array_values($quotationIdMap);
            $quotationItemIdMap = [];
            foreach (DB::table('quotation_items')->whereIn('quotation_id', $monthQuotationIds)->get(['id', 'quotation_id', 'pr_item_id']) as $row) {
                $quotationItemIdMap[$row->quotation_id.'|'.$row->pr_item_id] = (int) $row->id;
            }

            // 5. Insert Purchase Orders (for non-bidding days)
            $poRows = [];
            foreach ($dayPlans as $plan) {
                foreach ($plan['pos'] as $poPlan) {
                    $poRows[] = $poPlan['po_row'];
                }
            }

            $poIdByNumber = [];
            if ($poRows !== []) {
                $this->chunkInsert('purchase_orders', $poRows);
                $poNumbers = array_column($poRows, 'po_number');
                $poIdByNumber = DB::table('purchase_orders')
                    ->whereIn('po_number', $poNumbers)
                    ->pluck('id', 'po_number')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }

            // 6. Insert po_quotations, po_documents, pr_item_awards
            $poQuotationRows = [];
            $poDocumentRows = [];
            $awardRows = [];

            foreach ($dayPlans as $plan) {
                $prId = $plan['pr_id'];
                foreach ($plan['pos'] as $poPlan) {
                    $poId = $poIdByNumber[$poPlan['po_number']];
                    $quotationId = $quotationIdMap[$prId.'|'.$poPlan['supplier_id']];

                    $poQuotationRows[] = [
                        'po_id' => $poId,
                        'quotation_id' => $quotationId,
                        'created_at' => $poPlan['po_created_utc'],
                        'updated_at' => $poPlan['po_created_utc'],
                    ];

                    foreach ($poPlan['doc_statuses'] as $docType => $docStatus) {
                        $poDocumentRows[] = [
                            'po_id' => $poId,
                            'doc_type' => $docType,
                            'status' => $docStatus,
                            'created_at' => $poPlan['po_created_utc'],
                            'updated_at' => $poPlan['po_updated_utc'],
                        ];
                    }

                    foreach ($poPlan['awarded_items'] as $awarded) {
                        $prItemId = $prItemIdMap[$prId.'|'.$awarded['material_master_id']];
                        $quotationItemId = $quotationItemIdMap[$quotationId.'|'.$prItemId];

                        $awardRows[] = [
                            'pr_id' => $prId,
                            'pr_item_id' => $prItemId,
                            'quotation_id' => $quotationId,
                            'quotation_item_id' => $quotationItemId,
                            'supplier_id' => $poPlan['supplier_id'],
                            'purchase_order_id' => $poId,
                            'awarded_by' => $plan['purchasing_user_id'],
                            'awarded_at' => $poPlan['po_created_utc'],
                            'ordered_qty' => $awarded['ordered_qty'],
                            'ordered_weight_per_unit' => $awarded['ordered_weight_per_unit'],
                            'ordered_total_weight_kg' => $awarded['ordered_total_weight_kg'],
                            'ordered_price_per_kg' => $awarded['ordered_price_per_kg'],
                            'ordered_amount' => $awarded['ordered_amount'],
                            'spec_accepted_by' => null,
                            'spec_accepted_at' => null,
                            'spec_acceptance_snapshot' => null,
                            'spec_acceptance_hash' => null,
                            'created_at' => $poPlan['po_created_utc'],
                            'updated_at' => $poPlan['po_created_utc'],
                        ];
                    }
                }
            }

            $this->chunkInsert('po_quotations', $poQuotationRows);
            $this->chunkInsert('po_documents', $poDocumentRows);
            $this->chunkInsert('pr_item_awards', $awardRows);

            $awardIdByPrItemId = [];
            if ($awardRows !== []) {
                $awardIdByPrItemId = DB::table('pr_item_awards')
                    ->whereIn('pr_id', $monthPrIds)
                    ->pluck('id', 'pr_item_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }

            // 7. Insert Shipments
            $shipmentRows = [];
            foreach ($dayPlans as $plan) {
                foreach ($plan['pos'] as $poPlan) {
                    if ($poPlan['shipment'] !== null) {
                        $shipmentRows[] = $poPlan['shipment']['row'];
                    }
                }
            }

            $shipmentIdByNumber = [];
            if ($shipmentRows !== []) {
                $this->chunkInsert('shipments', $shipmentRows);
                $shipmentNumbers = array_column($shipmentRows, 'shipment_number');
                $shipmentIdByNumber = DB::table('shipments')
                    ->whereIn('shipment_number', $shipmentNumbers)
                    ->pluck('id', 'shipment_number')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }

            // 8. Insert Shipment Items
            $shipmentItemRows = [];
            foreach ($dayPlans as $plan) {
                $prId = $plan['pr_id'];
                foreach ($plan['pos'] as $poPlan) {
                    if ($poPlan['shipment'] === null) {
                        continue;
                    }
                    $poId = $poIdByNumber[$poPlan['po_number']];
                    $quotationId = $quotationIdMap[$prId.'|'.$poPlan['supplier_id']];
                    $shipmentId = $shipmentIdByNumber[$poPlan['shipment']['shipment_number']];

                    foreach ($poPlan['awarded_items'] as $awarded) {
                        $prItemId = $prItemIdMap[$prId.'|'.$awarded['material_master_id']];
                        $quotationItemId = $quotationItemIdMap[$quotationId.'|'.$prItemId];
                        $awardId = $awardIdByPrItemId[$prItemId] ?? null;

                        $shipmentItemRows[] = [
                            'shipment_id' => $shipmentId,
                            'purchase_order_id' => $poId,
                            'quotation_item_id' => $quotationItemId,
                            'pr_item_award_id' => $awardId,
                            'shipped_qty' => $awarded['ordered_qty'],
                            'actual_weight_kg' => $awarded['actual_shipment_weight_kg'],
                            'notes' => null,
                            'created_at' => $poPlan['shipment']['created_utc'],
                            'updated_at' => $poPlan['shipment']['updated_utc'],
                        ];
                    }
                }
            }
            $this->chunkInsert('shipment_items', $shipmentItemRows);

            $shipmentItemIdMap = [];
            if ($shipmentIdByNumber !== []) {
                $monthShipmentIds = array_values($shipmentIdByNumber);
                foreach (DB::table('shipment_items')->whereIn('shipment_id', $monthShipmentIds)->get(['id', 'shipment_id', 'purchase_order_id', 'quotation_item_id']) as $row) {
                    $shipmentItemIdMap[$row->shipment_id.'|'.$row->purchase_order_id.'|'.$row->quotation_item_id] = (int) $row->id;
                }
            }

            // 9. Insert QC Inspections
            $qcRows = [];
            foreach ($dayPlans as $plan) {
                foreach ($plan['pos'] as $poPlan) {
                    if ($poPlan['qc'] === null || $poPlan['shipment'] === null) {
                        continue;
                    }
                    $poId = $poIdByNumber[$poPlan['po_number']];
                    $shipmentId = $shipmentIdByNumber[$poPlan['shipment']['shipment_number']];
                    $qcRows[] = [
                        'po_id' => $poId,
                        'shipment_id' => $shipmentId,
                        'inspected_by' => $this->qcUserId,
                        'status' => $poPlan['qc']['status'],
                        'inspected_at' => $poPlan['qc']['inspected_utc'],
                        'created_at' => $poPlan['qc']['inspected_utc'],
                        'updated_at' => $poPlan['qc']['inspected_utc'],
                    ];
                }
            }
            $this->chunkInsert('qc_inspections', $qcRows);

            $qcIdByPoShipment = [];
            if ($qcRows !== []) {
                $monthPoIds = array_values($poIdByNumber);
                foreach (DB::table('qc_inspections')->whereIn('po_id', $monthPoIds)->get(['id', 'po_id', 'shipment_id']) as $row) {
                    $qcIdByPoShipment[$row->po_id.'|'.$row->shipment_id] = (int) $row->id;
                }
            }

            // 10. Insert QC Items & Material Claims
            $qcItemRows = [];
            $claimRows = [];

            foreach ($dayPlans as $plan) {
                $prId = $plan['pr_id'];
                foreach ($plan['pos'] as $poPlan) {
                    if ($poPlan['qc'] === null || $poPlan['shipment'] === null) {
                        continue;
                    }
                    $poId = $poIdByNumber[$poPlan['po_number']];
                    $quotationId = $quotationIdMap[$prId.'|'.$poPlan['supplier_id']];
                    $shipmentId = $shipmentIdByNumber[$poPlan['shipment']['shipment_number']];
                    $inspectionId = $qcIdByPoShipment[$poId.'|'.$shipmentId];

                    foreach ($poPlan['awarded_items'] as $idx => $awarded) {
                        $prItemId = $prItemIdMap[$prId.'|'.$awarded['material_master_id']];
                        $quotationItemId = $quotationItemIdMap[$quotationId.'|'.$prItemId];
                        $shipmentItemId = $shipmentItemIdMap[$shipmentId.'|'.$poId.'|'.$quotationItemId] ?? null;

                        $isNgLine = $poPlan['qc']['status'] === 'ng' && $idx === 0;
                        $dims = $awarded['dims'];

                        $qcItemRows[] = [
                            'inspection_id' => $inspectionId,
                            'pr_item_id' => $prItemId,
                            'shipment_item_id' => $shipmentItemId,
                            'actual_thickness' => $dims['thickness'] !== null
                                ? number_format(max(1.0, $dims['thickness'] + ($isNgLine ? -1.2 : 0.0)), 4, '.', '')
                                : null,
                            'actual_d_inner' => $dims['d_inner'] !== null
                                ? number_format($dims['d_inner'], 4, '.', '')
                                : null,
                            'actual_d_outer' => $dims['d_outer'] !== null
                                ? number_format(max(5.0, $dims['d_outer'] + ($isNgLine ? -0.8 : 0.0)), 4, '.', '')
                                : null,
                            'actual_width' => $dims['width'] !== null
                                ? number_format($dims['width'], 4, '.', '')
                                : null,
                            'actual_length' => number_format($dims['length'], 4, '.', ''),
                            'actual_weight' => $awarded['actual_shipment_weight_kg'],
                            'status' => $isNgLine ? 'ng' : 'ok',
                            'notes' => $isNgLine ? 'Dimensi/kualitas di luar batas toleransi penerimaan QC.' : 'Sesuai spesifikasi PO & MTC.',
                            'created_at' => $poPlan['qc']['inspected_utc'],
                            'updated_at' => $poPlan['qc']['inspected_utc'],
                        ];
                    }

                    if ($poPlan['claim'] !== null) {
                        $claimRows[] = array_merge([
                            'inspection_id' => $inspectionId,
                            'po_id' => $poId,
                            'submitted_by' => $plan['purchasing_user_id'],
                            'supplier_id' => $poPlan['supplier_id'],
                        ], $poPlan['claim']);
                    }
                }
            }

            $this->chunkInsert('qc_items', $qcItemRows);
            $this->chunkInsert('material_claims', $claimRows);

            return [
                'days_seeded' => count($dayPlans),
                'prs' => count($prInsertRows),
                'pr_items' => count($prItemRows),
                'quotations' => count($quotationRows),
                'quotation_items' => count($quotationItemRows),
                'awards' => count($awardRows),
                'pos' => count($poRows),
                'shipments' => count($shipmentRows),
                'qc_inspections' => count($qcRows),
                'claims' => count($claimRows),
            ];
        });
    }

    /**
     * Build the complete deterministic procurement plan for a single business day.
     *
     * @return array<string, mixed>
     */
    private function buildDayPlan(string $dateStr, int $year, int $month, int $periodId): array
    {
        $purchasingUserId = $this->purchasingUserIds[mt_rand(0, count($this->purchasingUserIds) - 1)];

        // Business morning timestamp (08:30–11:15 WIB) converted to UTC storage.
        $prTimeWib = Carbon::createFromFormat('Y-m-d', $dateStr, self::BUSINESS_TZ)
            ->setTime(mt_rand(8, 10), mt_rand(5, 55), mt_rand(0, 59));
        $prCreatedUtc = $prTimeWib->copy()->utc()->format('Y-m-d H:i:s');

        $prNumber = $this->nextDocumentNumber('PR', 'REQ', $year, $month);
        $portOfDischarge = mt_rand(1, 100) <= 75 ? 'TANJUNG_PRIOK' : 'TANJUNG_PERAK';
        $incotermRoll = mt_rand(1, 100);
        $incoterm = $incotermRoll <= 45 ? 'CFR' : ($incotermRoll <= 80 ? 'CIF' : 'FOB');

        // Determine lifecycle phase from business date relative to 2026-11-27.
        $phase = $this->resolveDayPhase($dateStr);
        $prStatus = $phase === 'bidding' ? 'bidding' : 'completed';

        // Pick 2 to 10 distinct materials per PR & quotation (strict user requirement).
        $itemCount = mt_rand(2, 10);
        $selectedMaterials = $this->pickDistinctMaterials($itemCount);

        $items = [];
        foreach ($selectedMaterials as $mat) {
            $shape = $mat['shapes'][mt_rand(0, count($mat['shapes']) - 1)];
            $dims = $this->generateDimensions($shape);
            $unitWeight = $this->calculateUnitWeight($shape, $dims, $mat['density']);
            $qty = $this->generateQuantity($unitWeight);
            $hs = $this->resolveHsCode($mat['hs_category'], $shape);
            $formulaKey = $this->weightFormulaKey($shape, $mat['density']);
            $weightFactor = $this->weightFactor($shape, $mat['density']);

            // Compute realistic market price in USD/kg for this material & date.
            $dayOfYear = (int) $prTimeWib->dayOfYear;
            $seasonalWave = 1.0 + 0.035 * sin((2.0 * M_PI * $dayOfYear) / 365.0 + ($mat['id'] % 7));
            $yearMult = self::YEAR_PRICE_MULTIPLIER[$year] ?? 1.10;
            $sizeAdj = $shape === 'Flat' && ($dims['thickness'] ?? 0) >= 120 ? 1.04 : 1.0;
            $marketUsdPerKg = $mat['base_usd'] * $yearMult * $seasonalWave * $sizeAdj;

            $items[] = [
                'material_master_id' => $mat['id'],
                'material_code' => $mat['code'],
                'shape' => $shape,
                'dims' => $dims,
                'unit_weight' => $unitWeight,
                'quantity' => $qty,
                'market_usd_per_kg' => $marketUsdPerKg,
                'pr_item_row' => [
                    'material_master_id' => $mat['id'],
                    'hs_code' => $hs['hs_code'],
                    'hs_code_rule_id' => $hs['id'],
                    'hs_code_source' => $hs['id'] !== null ? 'auto' : 'legacy',
                    'hs_code_resolution_status' => $hs['id'] !== null ? 'matched' : 'legacy',
                    'hs_code_manual_selected_by' => null,
                    'hs_code_manual_selected_at' => null,
                    'material_name' => $mat['code'],
                    'shape' => $shape,
                    'thickness' => $dims['thickness'] !== null ? number_format($dims['thickness'], 4, '.', '') : null,
                    'd_inner' => $dims['d_inner'] !== null ? number_format($dims['d_inner'], 4, '.', '') : null,
                    'd_outer' => $dims['d_outer'] !== null ? number_format($dims['d_outer'], 4, '.', '') : null,
                    'width' => $dims['width'] !== null ? number_format($dims['width'], 4, '.', '') : null,
                    'length' => number_format($dims['length'], 4, '.', ''),
                    'weight_needed' => number_format($unitWeight, 4, '.', ''),
                    'weight_calculation_status' => 'calculated',
                    'weight_formula_key' => $formulaKey,
                    'weight_factor' => number_format($weightFactor, 6, '.', ''),
                    'weight_calculated_at' => $prCreatedUtc,
                    'remark' => null,
                    'quantity' => $qty,
                    'created_at' => $prCreatedUtc,
                    'updated_at' => $prCreatedUtc,
                ],
            ];
        }

        // Select 2–3 suppliers (3 suppliers on live bidding days for richer comparison).
        $supplierCount = $phase === 'bidding' ? min(3, count($this->suppliers)) : mt_rand(2, min(3, count($this->suppliers)));
        $invitedSuppliers = $this->pickDistinctSuppliers($supplierCount);

        // Build quotations & item pricing for each invited supplier.
        $quotations = [];
        $itemQuotesByMaterial = []; // material_master_id => list of ['supplier_idx' => ..., 'idr_per_kg' => ..., ...]

        foreach ($invitedSuppliers as $sIdx => $supplier) {
            $currency = $supplier['currencies'][mt_rand(0, count($supplier['currencies']) - 1)];
            $fx = $this->resolveExchangeRate($currency, $dateStr);
            $usdFx = $this->resolveExchangeRate('USD', $dateStr);

            $quoteSubmittedWib = $prTimeWib->copy()->addMinutes(mt_rand(45, 165));
            $quoteSubmittedUtc = $quoteSubmittedWib->copy()->utc()->format('Y-m-d H:i:s');
            $leadDays = mt_rand($supplier['lead_min'], $supplier['lead_max']);
            $paymentTerms = self::PAYMENT_TERMS[mt_rand(0, count(self::PAYMENT_TERMS) - 1)];

            $quoteItems = [];
            foreach ($items as $item) {
                // Realistic supplier spread (-4.5% .. +6.5%) around market USD/kg.
                $jitter = 1.0 + (mt_rand(-45, 65) / 1000.0);
                $supplierUsdPerKg = $item['market_usd_per_kg'] * $supplier['price_factor'] * $jitter;
                $idrPerKg = $supplierUsdPerKg * $usdFx['rate'];

                $priceInCurrency = match ($currency) {
                    'IDR' => round($idrPerKg, 0),
                    'CNY' => round($idrPerKg / $fx['rate'], 2),
                    'JPY' => round($idrPerKg / $fx['rate'], 2),
                    default => round($supplierUsdPerKg, 2),
                };

                $priceInCurrency = max(0.01, $priceInCurrency);
                $totalWeight = round($item['unit_weight'] * $item['quantity'], 4);
                $amount = round($priceInCurrency * $totalWeight, 4);
                $dims = $item['dims'];

                $quoteItems[] = [
                    'material_master_id' => $item['material_master_id'],
                    'price_per_kg' => $priceInCurrency,
                    'idr_per_kg' => $idrPerKg,
                    'unit_weight' => $item['unit_weight'],
                    'total_weight' => $totalWeight,
                    'amount' => $amount,
                    'quantity' => $item['quantity'],
                    'dims' => $dims,
                    'row' => [
                        'price_per_kg' => number_format($priceInCurrency, 4, '.', ''),
                        'amount' => number_format($amount, 4, '.', ''),
                        'available_qty' => $item['quantity'],
                        'available_thickness' => $dims['thickness'] !== null ? number_format($dims['thickness'], 4, '.', '') : null,
                        'available_d_inner' => $dims['d_inner'] !== null ? number_format($dims['d_inner'], 4, '.', '') : null,
                        'available_d_outer' => $dims['d_outer'] !== null ? number_format($dims['d_outer'], 4, '.', '') : null,
                        'available_width' => $dims['width'] !== null ? number_format($dims['width'], 4, '.', '') : null,
                        'available_length' => number_format($dims['length'], 4, '.', ''),
                        'available_length_min' => null,
                        'available_length_max' => null,
                        'offered_weight_per_unit' => number_format($item['unit_weight'], 4, '.', ''),
                        'offered_weight_source' => 'calculated',
                        'notes' => null,
                        'is_available' => 1,
                        'created_at' => $quoteSubmittedUtc,
                        'updated_at' => $quoteSubmittedUtc,
                    ],
                ];

                $itemQuotesByMaterial[$item['material_master_id']][] = [
                    'supplier_idx' => $sIdx,
                    'supplier_id' => $supplier['id'],
                    'idr_per_kg' => $idrPerKg,
                    'quote_item' => $quoteItems[array_key_last($quoteItems)],
                ];
            }

            $quotations[$sIdx] = [
                'supplier_id' => $supplier['id'],
                'currency' => $currency,
                'exchange_rate_id' => $fx['id'],
                'lead_days' => $leadDays,
                'submitted_wib' => $quoteSubmittedWib,
                'submitted_utc' => $quoteSubmittedUtc,
                'payment_terms' => $paymentTerms,
                'items' => $quoteItems,
                'quotation_row' => [
                    'supplier_id' => $supplier['id'],
                    'currency' => $currency,
                    'status' => 'submitted',
                    'submitted_at' => $quoteSubmittedUtc,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'reviewer_notes' => null,
                    'exchange_rate_id' => $fx['id'],
                    'estimated_delivery' => $prTimeWib->copy()->addDays($leadDays)->format('Y-m-d'),
                    'payment_terms' => $paymentTerms,
                    'validity_period' => $prTimeWib->copy()->addDays(14)->format('Y-m-d'),
                    'general_notes' => null,
                    'created_at' => $quoteSubmittedUtc,
                    'updated_at' => $quoteSubmittedUtc,
                ],
            ];
        }

        // Award & PO creation (for all non-bidding days)
        $pos = [];
        $prUpdatedUtc = $prCreatedUtc;

        if ($phase !== 'bidding') {
            // Decide winner per item: ~65% single best overall supplier, ~35% split best per item.
            $splitAward = count($quotations) >= 2 && mt_rand(1, 100) <= 35;
            $awardsBySupplierIdx = [];

            if (! $splitAward) {
                // Pick the supplier with lowest total IDR across all items
                $bestSIdx = 0;
                $bestTotal = INF;
                foreach ($quotations as $sIdx => $q) {
                    $totalIdr = 0.0;
                    foreach ($q['items'] as $qi) {
                        $totalIdr += $qi['idr_per_kg'] * $qi['total_weight'];
                    }
                    if ($totalIdr < $bestTotal) {
                        $bestTotal = $totalIdr;
                        $bestSIdx = $sIdx;
                    }
                }
                foreach ($quotations[$bestSIdx]['items'] as $qi) {
                    $awardsBySupplierIdx[$bestSIdx][] = $qi;
                }
            } else {
                foreach ($itemQuotesByMaterial as $candidates) {
                    usort($candidates, static fn (array $a, array $b): int => $a['idr_per_kg'] <=> $b['idr_per_kg']);
                    $winner = $candidates[0];
                    $awardsBySupplierIdx[$winner['supplier_idx']][] = $winner['quote_item'];
                }
            }

            // PO created same business afternoon (14:00–16:45 WIB) so PO date stays in same business day/month.
            $poCreatedWib = $prTimeWib->copy()->setTime(mt_rand(14, 16), mt_rand(5, 45), mt_rand(0, 59));
            $poCreatedUtc = $poCreatedWib->copy()->utc()->format('Y-m-d H:i:s');
            $prUpdatedUtc = $poCreatedUtc;

            // Update quotation statuses
            foreach ($quotations as $sIdx => &$quote) {
                $isWinner = isset($awardsBySupplierIdx[$sIdx]);
                $quote['quotation_row']['status'] = $isWinner ? 'accepted' : 'rejected';
                $quote['quotation_row']['reviewed_at'] = $poCreatedUtc;
                $quote['quotation_row']['reviewed_by'] = $purchasingUserId;
                $quote['quotation_row']['reviewer_notes'] = $isWinner
                    ? null
                    : 'Dialokasikan ke penawaran supplier dengan harga dan lead time lebih kompetitif.';
                $quote['quotation_row']['updated_at'] = $poCreatedUtc;
            }
            unset($quote);

            $endDateCap = Carbon::createFromFormat('Y-m-d', self::END_DATE, self::BUSINESS_TZ)->startOfDay();

            foreach ($awardsBySupplierIdx as $sIdx => $wonItems) {
                $quote = $quotations[$sIdx];
                $poNumber = $this->nextDocumentNumber('PO', 'PO', $year, $month);

                $etaDate = $poCreatedWib->copy()->addDays($quote['lead_days']);
                $promisedDate = $etaDate->copy()->addDays(mt_rand(-2, 2));
                $ackAtUtc = $poCreatedWib->copy()->addHours(mt_rand(2, 18))->utc()->format('Y-m-d H:i:s');

                [$poStatus, $hasShipment, $shipmentStatus, $hasQc, $qcStatus, $hasClaim, $claimStatus] = $this->resolvePoOutcome($phase);

                $actualArrivalDate = null;
                $poUpdatedUtc = $ackAtUtc;
                $shipmentPlan = null;
                $qcPlan = null;
                $claimPlan = null;

                $awardedItems = [];
                foreach ($wonItems as $qi) {
                    // Realistic actual received weight within ±1.2% tolerance.
                    $weightVariance = 1.0 + (mt_rand(-12, 12) / 1000.0);
                    $actualWeightKg = round(max(0.1, $qi['total_weight'] * $weightVariance), 4);

                    $awardedItems[] = [
                        'material_master_id' => $qi['material_master_id'],
                        'ordered_qty' => $qi['quantity'],
                        'ordered_weight_per_unit' => number_format($qi['unit_weight'], 4, '.', ''),
                        'ordered_total_weight_kg' => number_format($qi['total_weight'], 4, '.', ''),
                        'ordered_price_per_kg' => number_format($qi['price_per_kg'], 4, '.', ''),
                        'ordered_amount' => number_format($qi['amount'], 4, '.', ''),
                        'actual_shipment_weight_kg' => number_format($actualWeightKg, 4, '.', ''),
                        'dims' => $qi['dims'],
                    ];
                }

                if ($hasShipment) {
                    $shipDate = $poCreatedWib->copy()->addDays(mt_rand(3, 7));
                    if ($shipDate->gt($endDateCap)) {
                        $shipDate = $poCreatedWib->copy()->addDay();
                        if ($shipDate->gt($endDateCap)) {
                            $shipDate = $endDateCap->copy();
                        }
                    }

                    $shipYear = (int) $shipDate->year;
                    $shipMonth = (int) $shipDate->month;
                    $shipmentNumber = $this->nextDocumentNumber('SHP', 'SHP', $shipYear, $shipMonth);
                    $invoiceNumber = sprintf('INV/%04d/%02d/%04d', $shipYear, $shipMonth, mt_rand(1000, 9999));
                    $supplierRef = sprintf('BL-%04d%02d-%04d', $shipYear, $shipMonth, mt_rand(1000, 9999));

                    $shipCreatedUtc = $shipDate->copy()->setTime(mt_rand(9, 15), mt_rand(0, 59))->utc()->format('Y-m-d H:i:s');
                    $shipUpdatedUtc = $shipCreatedUtc;

                    if ($shipmentStatus === 'arrived') {
                        $arrived = $etaDate->copy()->addDays(mt_rand(-3, 2));
                        if ($arrived->lte($shipDate)) {
                            $arrived = $shipDate->copy()->addDays(2);
                        }
                        if ($arrived->gt($endDateCap)) {
                            $arrived = $endDateCap->copy()->subDays(mt_rand(0, 2));
                            if ($arrived->lt($shipDate)) {
                                $arrived = $endDateCap->copy();
                            }
                        }
                        $actualArrivalDate = $arrived->format('Y-m-d');
                        $shipUpdatedUtc = $arrived->copy()->setTime(mt_rand(10, 15), mt_rand(0, 59))->utc()->format('Y-m-d H:i:s');
                        $poUpdatedUtc = $shipUpdatedUtc;
                    }

                    $shipmentPlan = [
                        'shipment_number' => $shipmentNumber,
                        'created_utc' => $shipCreatedUtc,
                        'updated_utc' => $shipUpdatedUtc,
                        'row' => [
                            'shipment_number' => $shipmentNumber,
                            'invoice_number' => $invoiceNumber,
                            'supplier_reference' => $supplierRef,
                            'supplier_id' => $quote['supplier_id'],
                            'status' => $shipmentStatus,
                            'shipment_date' => $shipDate->format('Y-m-d'),
                            'estimated_arrival_date' => $etaDate->format('Y-m-d'),
                            'actual_arrival_date' => $actualArrivalDate,
                            'notes' => null,
                            'created_by' => $quote['supplier_id'],
                            'submitted_at' => $shipCreatedUtc,
                            'created_at' => $shipCreatedUtc,
                            'updated_at' => $shipUpdatedUtc,
                        ],
                    ];

                    if ($hasQc && $actualArrivalDate !== null) {
                        $inspectedWib = Carbon::createFromFormat('Y-m-d', $actualArrivalDate, self::BUSINESS_TZ)
                            ->addDays(mt_rand(0, 1))
                            ->setTime(mt_rand(13, 16), mt_rand(5, 50));
                        if ($inspectedWib->gt($endDateCap->copy()->setTime(17, 0))) {
                            $inspectedWib = $endDateCap->copy()->setTime(15, 30);
                        }
                        $inspectedUtc = $inspectedWib->copy()->utc()->format('Y-m-d H:i:s');
                        $poUpdatedUtc = $inspectedUtc;

                        $qcPlan = [
                            'status' => $qcStatus,
                            'inspected_utc' => $inspectedUtc,
                        ];

                        if ($hasClaim) {
                            $tpl = self::CLAIM_TEMPLATES[mt_rand(0, count(self::CLAIM_TEMPLATES) - 1)];
                            $claimCreatedWib = $inspectedWib->copy()->addHours(mt_rand(1, 4));
                            $claimCreatedUtc = $claimCreatedWib->copy()->utc()->format('Y-m-d H:i:s');
                            $claimUpdatedUtc = $claimStatus === 'resolved'
                                ? $claimCreatedWib->copy()->addDays(mt_rand(3, 10))->utc()->format('Y-m-d H:i:s')
                                : $claimCreatedUtc;

                            $claimPlan = [
                                'status' => $claimStatus,
                                'description' => $tpl['description'],
                                'resolution_expected' => $tpl['resolution'],
                                'deadline' => $claimCreatedWib->copy()->addDays(14)->format('Y-m-d'),
                                'supplier_response' => $claimStatus === 'pending' ? null : $tpl['response'],
                                'created_at' => $claimCreatedUtc,
                                'updated_at' => $claimUpdatedUtc,
                            ];
                            $poUpdatedUtc = $claimUpdatedUtc;
                        }
                    }
                }

                $docStatuses = match ($poStatus) {
                    'completed' => ['invoice' => 'verified', 'bl' => 'done', 'packing_list' => 'verified', 'form_e' => 'done'],
                    'claim_needed' => ['invoice' => 'verified', 'bl' => 'done', 'packing_list' => 'verified', 'form_e' => 'done'],
                    'waiting_qc' => ['invoice' => 'verified', 'bl' => 'done', 'packing_list' => 'verified', 'form_e' => 'processing'],
                    default => ['invoice' => 'received', 'bl' => 'issued', 'packing_list' => 'received', 'form_e' => 'processing'],
                };

                $pos[] = [
                    'po_number' => $poNumber,
                    'supplier_id' => $quote['supplier_id'],
                    'po_created_utc' => $poCreatedUtc,
                    'po_updated_utc' => $poUpdatedUtc,
                    'doc_statuses' => $docStatuses,
                    'awarded_items' => $awardedItems,
                    'shipment' => $shipmentPlan,
                    'qc' => $qcPlan,
                    'claim' => $claimPlan,
                    'po_row' => [
                        'supplier_id' => $quote['supplier_id'],
                        'currency' => $quote['currency'],
                        'exchange_rate_id' => $quote['exchange_rate_id'],
                        'po_number' => $poNumber,
                        'status' => $poStatus,
                        'acknowledgement_status' => $phase === 'active' ? 'PENDING' : 'ACKNOWLEDGED',
                        'acknowledged_at' => $phase === 'active' ? null : $ackAtUtc,
                        'acknowledged_by' => $phase === 'active' ? null : $quote['supplier_id'],
                        'created_by' => $purchasingUserId,
                        'estimated_arrival' => $etaDate->format('Y-m-d'),
                        'supplier_promised_date' => $promisedDate->format('Y-m-d'),
                        'actual_arrival' => $actualArrivalDate,
                        'notes' => null,
                        'acknowledgement_notes' => $phase === 'active' ? null : 'Order confirmed sesuai spesifikasi dan jadwal pengiriman.',
                        'sales_contract_status' => $phase === 'active' ? 'pending_supplier' : 'purchasing_signed',
                        'port_of_discharge' => $portOfDischarge,
                        'incoterm' => $incoterm,
                        'created_at' => $poCreatedUtc,
                        'updated_at' => $poUpdatedUtc,
                    ],
                ];
            }
        }

        return [
            'pr_number' => $prNumber,
            'pr_created_utc' => $prCreatedUtc,
            'purchasing_user_id' => $purchasingUserId,
            'invited_suppliers' => $invitedSuppliers,
            'items' => $items,
            'quotations' => $quotations,
            'pos' => $pos,
            'pr_row' => [
                'pr_number' => $prNumber,
                'period_id' => $periodId,
                'created_by' => $purchasingUserId,
                'notes' => self::PR_NOTES[mt_rand(0, count(self::PR_NOTES) - 1)],
                'port_of_discharge' => $portOfDischarge,
                'incoterm' => $incoterm,
                'status' => $prStatus,
                'created_at' => $prCreatedUtc,
                'updated_at' => $prUpdatedUtc,
            ],
        ];
    }

    private function resolveDayPhase(string $dateStr): string
    {
        if ($dateStr >= '2026-11-25') {
            return 'bidding';
        }
        if ($dateStr >= '2026-11-19') {
            return 'active';
        }
        if ($dateStr >= '2026-11-09') {
            return 'recent_arrived';
        }

        return 'historical';
    }

    /**
     * @return array{0: string, 1: bool, 2: string, 3: bool, 4: string, 5: bool, 6: string}
     *                                                                                      [poStatus, hasShipment, shipmentStatus, hasQc, qcStatus, hasClaim, claimStatus]
     */
    private function resolvePoOutcome(string $phase): array
    {
        if ($phase === 'active') {
            return ['active', true, 'submitted', false, 'ok', false, 'pending'];
        }

        if ($phase === 'recent_arrived') {
            $roll = mt_rand(1, 100);
            if ($roll <= 45) {
                return ['completed', true, 'arrived', true, 'ok', false, 'resolved'];
            }
            if ($roll <= 85) {
                return ['waiting_qc', true, 'arrived', false, 'ok', false, 'pending'];
            }

            return ['claim_needed', true, 'arrived', true, 'ng', true, mt_rand(0, 1) === 0 ? 'pending' : 'responded'];
        }

        // Historical completed orders (2020-01-01 .. 2026-11-06): ~94% QC OK, ~6% QC NG with resolved claim.
        $isNg = mt_rand(1, 100) <= 6;
        if ($isNg) {
            return ['completed', true, 'arrived', true, 'ng', true, 'resolved'];
        }

        return ['completed', true, 'arrived', true, 'ok', false, 'resolved'];
    }

    /**
     * Weighted random pick of distinct materials without replacement.
     *
     * @return list<array{id: int, code: string, density: float, hs_category: ?string, shapes: list<string>, base_usd: float, weight: int}>
     */
    private function pickDistinctMaterials(int $count): array
    {
        $pool = $this->materialCatalog;
        $picked = [];
        $count = min($count, count($pool));

        for ($i = 0; $i < $count; $i++) {
            $totalWeight = 0;
            foreach ($pool as $entry) {
                $totalWeight += $entry['weight'];
            }
            $needle = mt_rand(1, $totalWeight);
            $acc = 0;
            $chosenIdx = 0;
            foreach ($pool as $idx => $entry) {
                $acc += $entry['weight'];
                if ($needle <= $acc) {
                    $chosenIdx = $idx;
                    break;
                }
            }
            $picked[] = $pool[$chosenIdx];
            array_splice($pool, $chosenIdx, 1);
        }

        return $picked;
    }

    /**
     * @return list<array{id: int, name: string, currencies: list<string>, price_factor: float, lead_min: int, lead_max: int}>
     */
    private function pickDistinctSuppliers(int $count): array
    {
        $pool = $this->suppliers;
        $count = min($count, count($pool));
        $picked = [];

        for ($i = 0; $i < $count; $i++) {
            $idx = mt_rand(0, count($pool) - 1);
            $picked[] = $pool[$idx];
            array_splice($pool, $idx, 1);
        }

        return $picked;
    }

    /**
     * @return array{thickness: ?float, d_inner: ?float, d_outer: ?float, width: ?float, length: float}
     */
    private function generateDimensions(string $shape): array
    {
        if ($shape === 'Flat') {
            $thicknessOptions = [16, 20, 25, 30, 40, 50, 60, 70, 80, 100, 120, 150, 200];
            $widthOptions = [205, 255, 305, 405, 510, 610];
            $lengthOptions = [1000, 1500, 2000, 2300, 3000];

            return [
                'thickness' => (float) $thicknessOptions[mt_rand(0, count($thicknessOptions) - 1)],
                'd_inner' => null,
                'd_outer' => null,
                'width' => (float) $widthOptions[mt_rand(0, count($widthOptions) - 1)],
                'length' => (float) $lengthOptions[mt_rand(0, count($lengthOptions) - 1)],
            ];
        }

        if ($shape === 'Hollow') {
            $pairs = [
                [60, 40],
                [80, 50],
                [100, 65],
                [120, 80],
                [150, 100],
                [180, 120],
                [200, 140],
            ];
            [$outer, $inner] = $pairs[mt_rand(0, count($pairs) - 1)];
            $lengthOptions = [1500, 2000, 3000, 4000];

            return [
                'thickness' => null,
                'd_inner' => (float) $inner,
                'd_outer' => (float) $outer,
                'width' => null,
                'length' => (float) $lengthOptions[mt_rand(0, count($lengthOptions) - 1)],
            ];
        }

        // Round bar
        $diameterOptions = [20, 25, 30, 40, 50, 65, 80, 100, 120, 150, 180, 220];
        $lengthOptions = [1500, 2000, 3000, 3600, 4000];

        return [
            'thickness' => null,
            'd_inner' => null,
            'd_outer' => (float) $diameterOptions[mt_rand(0, count($diameterOptions) - 1)],
            'width' => null,
            'length' => (float) $lengthOptions[mt_rand(0, count($lengthOptions) - 1)],
        ];
    }

    /**
     * @param  array{thickness: ?float, d_inner: ?float, d_outer: ?float, width: ?float, length: float}  $dims
     */
    private function calculateUnitWeight(string $shape, array $dims, float $density): float
    {
        if ($shape === 'Flat') {
            $kg = (($dims['thickness'] ?? 20.0) * ($dims['width'] ?? 305.0) * $dims['length'] * $density) / 1_000_000.0;

            return round(max(1.0, $kg), 4);
        }

        if ($shape === 'Hollow') {
            $rOut = ($dims['d_outer'] ?? 100.0) / 2.0;
            $rIn = ($dims['d_inner'] ?? 60.0) / 2.0;
            $kg = (M_PI * (($rOut * $rOut) - ($rIn * $rIn)) * $dims['length'] * $density) / 1_000_000.0;

            return round(max(1.0, $kg), 4);
        }

        $r = ($dims['d_outer'] ?? 50.0) / 2.0;
        $kg = (M_PI * ($r * $r) * $dims['length'] * $density) / 1_000_000.0;

        return round(max(1.0, $kg), 4);
    }

    private function generateQuantity(float $unitWeight): int
    {
        if ($unitWeight >= 1000.0) {
            return mt_rand(1, 2);
        }
        if ($unitWeight >= 400.0) {
            return mt_rand(1, 4);
        }
        if ($unitWeight >= 100.0) {
            return mt_rand(2, 6);
        }

        return mt_rand(3, 12);
    }

    private function weightFormulaKey(string $shape, float $density): string
    {
        $isAlu = $density < 4.0;
        if ($shape === 'Flat') {
            return $isAlu ? 'flat_aluminium_v1' : 'flat_steel_v1';
        }
        if ($shape === 'Hollow') {
            return 'hollow_v1';
        }

        return $isAlu ? 'round_aluminium_v1' : 'round_v1';
    }

    private function weightFactor(string $shape, float $density): float
    {
        if ($shape === 'Flat') {
            return round($density / 1000.0, 6);
        }

        return round((M_PI / 4.0) * ($density / 1000.0), 6);
    }

    /**
     * @return array{id: ?int, hs_code: ?string}
     */
    private function resolveHsCode(?string $hsCategory, string $shape): array
    {
        $shapeLower = strtolower($shape);
        if ($hsCategory !== null) {
            $catKey = strtolower($hsCategory).'|'.$shapeLower;
            if (isset($this->hsRulesByCategoryShape[$catKey])) {
                return $this->hsRulesByCategoryShape[$catKey];
            }
        }

        if (isset($this->hsRulesByShape[$shapeLower])) {
            return $this->hsRulesByShape[$shapeLower];
        }

        return ['id' => null, 'hs_code' => null];
    }

    /**
     * @return array{id: int, rate: float}
     */
    private function resolveExchangeRate(string $currency, string $dateStr): array
    {
        $timeline = $this->exchangeRateTimeline[$currency] ?? [];
        if ($timeline === []) {
            throw new RuntimeException("No exchange_rates available for currency [{$currency}].");
        }

        $chosen = $timeline[0];
        foreach ($timeline as $entry) {
            if ($entry['valid_from'] <= $dateStr) {
                $chosen = $entry;
            } else {
                break;
            }
        }

        return ['id' => $chosen['id'], 'rate' => $chosen['rate']];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function chunkInsert(string $table, array $rows, int $chunkSize = 500): void
    {
        if ($rows === []) {
            return;
        }
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
