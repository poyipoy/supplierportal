<?php

namespace Database\Seeders;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalProcurementDummySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first()
            ?? User::where('role', 'finance')->first();

        if (! $admin) {
            $this->command?->error('No Admin or Finance user found. Aborting seeder.');
            return;
        }

        $supplier6 = User::localEligible()->where('id', 20)->first()
            ?? User::localEligible()->whereHas('supplier', fn($q) => $q->where('company_name', 'like', '%Supplier 6%')->orWhere('company_name', 'like', '%Supplier Enam%'))->first()
            ?? User::localEligible()->first();

        if (! $supplier6) {
            $this->command?->error('No local suppliers found. Aborting seeder.');
            return;
        }

        $service = app(LocalProcurementMasterService::class);

        $poDefinitions = [
            [
                'supplier_index' => 0,
                'po_number' => 'PO-LOC-2026-001',
                'description' => 'Pengadaan Plat Baja Karbon SPCC 1.2mm x 1219mm x 2438mm',
                'po_date' => '2026-08-01',
                'gr_unit_amount' => 15000000,
                'gr_desc' => 'Plat Baja SPCC',
            ],
            [
                'supplier_index' => 0,
                'po_number' => 'PO-LOC-2026-002',
                'description' => 'Pengadaan Baut & Mur High Tensile Grade 8.8 M16 x 50mm',
                'po_date' => '2026-08-05',
                'gr_unit_amount' => 5000000,
                'gr_desc' => 'Baut & Mur HT M16',
            ],
            [
                'supplier_index' => 1,
                'po_number' => 'PO-LOC-2026-003',
                'description' => 'Pengadaan Oli Pelumas Mesin Hidrolik ISO VG 68 Drum 209L',
                'po_date' => '2026-08-10',
                'gr_unit_amount' => 8000000,
                'gr_desc' => 'Oli Mesin Hidrolik ISO VG 68',
            ],
            [
                'supplier_index' => 1,
                'po_number' => 'PO-LOC-2026-004',
                'description' => 'Pengadaan Cutting Tool End Mill Carbide 4 Flute D10',
                'po_date' => '2026-08-15',
                'gr_unit_amount' => 12000000,
                'gr_desc' => 'End Mill Carbide D10',
            ],
            [
                'supplier_index' => 2,
                'po_number' => 'PO-LOC-2026-005',
                'description' => 'Pengadaan Sarung Tangan Safety & APD Produksi Pabrik',
                'po_date' => '2026-08-18',
                'gr_unit_amount' => 4000000,
                'gr_desc' => 'Perlengkapan APD Produksi',
            ],
            [
                'supplier_index' => 2,
                'po_number' => 'PO-LOC-2026-006',
                'description' => 'Pengadaan Pipa Stainless Steel SUS 304 Seamless 2 Inch Sch 40',
                'po_date' => '2026-08-20',
                'gr_unit_amount' => 20000000,
                'gr_desc' => 'Pipa Stainless SUS 304',
            ],
            [
                'supplier_index' => 3,
                'po_number' => 'PO-LOC-2026-007',
                'description' => 'Pengadaan Kawat Las E7018 LB-52 3.2mm Dus 20kg',
                'po_date' => '2026-08-25',
                'gr_unit_amount' => 6000000,
                'gr_desc' => 'Kawat Las E7018 LB-52',
            ],
            [
                'supplier_index' => 3,
                'po_number' => 'PO-LOC-2026-008',
                'description' => 'Pengadaan Batu Gerinda Potong 4 Inch & Flap Disc A80',
                'po_date' => '2026-08-28',
                'gr_unit_amount' => 3500000,
                'gr_desc' => 'Batu Gerinda & Flap Disc',
            ],
            [
                'supplier_index' => 4,
                'po_number' => 'PO-LOC-2026-009',
                'description' => 'Pengadaan Bearing SKF Deep Groove 6205-2RSH C3',
                'po_date' => '2026-09-01',
                'gr_unit_amount' => 9500000,
                'gr_desc' => 'Bearing SKF 6205-2RSH',
            ],
            [
                'supplier_index' => 4,
                'po_number' => 'PO-LOC-2026-010',
                'description' => 'Pengadaan V-Belt Industri B-55 & Pulley Cast Iron 2 Groove',
                'po_date' => '2026-09-02',
                'gr_unit_amount' => 7000000,
                'gr_desc' => 'V-Belt B-55 & Pulley',
            ],
        ];

        DB::transaction(function () use ($admin, $supplier6, $service, $poDefinitions) {
            $createdPoCount = 0;
            $createdGrCount = 0;

            foreach ($poDefinitions as $idx => $def) {
                $poTotal = $def['gr_unit_amount'] * 10;

                // Check if PO already exists
                $po = LocalPurchaseOrder::where('po_number', $def['po_number'])->first();
                if (! $po) {
                    $po = $service->createPurchaseOrder($admin, [
                        'po_number' => $def['po_number'],
                        'supplier_id' => $supplier6->id,
                        'po_date' => $def['po_date'],
                        'total_amount' => number_format($poTotal, 2, '.', ''),
                        'description' => $def['description'],
                    ], LocalPurchaseOrder::SOURCE_MANUAL);
                    $createdPoCount++;
                } else {
                    $po->update(['supplier_id' => $supplier6->id]);
                }

                // Generate 10 GRs for this PO
                $baseDate = Carbon::parse($def['po_date'])->addDays(2);
                for ($grIndex = 1; $grIndex <= 10; $grIndex++) {
                    $grPad = str_pad((string) $grIndex, 2, '0', STR_PAD_LEFT);
                    $poSuffix = str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT);
                    $grNumber = "GR-LOC-2026-{$poSuffix}-{$grPad}";
                    $grDate = (clone $baseDate)->addDays(($grIndex - 1) * 2)->format('Y-m-d');

                    if (! LocalGoodsReceipt::where('gr_number', $grNumber)->exists()) {
                        $service->createGoodsReceipt($admin, $po, [
                            'gr_number' => $grNumber,
                            'gr_date' => $grDate,
                            'received_amount' => number_format($def['gr_unit_amount'], 2, '.', ''),
                            'notes' => "Penerimaan {$def['gr_desc']} batch {$grIndex}/10",
                        ], LocalPurchaseOrder::SOURCE_MANUAL);
                        $createdGrCount++;
                    }
                }
            }

            $this->command?->info("Successfully seeded {$createdPoCount} Local POs and {$createdGrCount} Local GRs.");
        });
    }
}
