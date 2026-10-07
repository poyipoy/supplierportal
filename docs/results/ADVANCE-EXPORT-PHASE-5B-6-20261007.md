# Hasil Advance Export - Fase 5b dan 6 (7 Oktober 2026)

Implementasi dilanjutkan dari phase-5a bersih. Fase 5b dan 6 selesai dengan catatan uji manual/deployment. Commit bertingkat, tidak merge/rebase/push. Data test memakai MySQL adasi_portal_test; seluruh suite berjalan serial. Hasil historis fase 0-5a ada di Bagian 11 rencana dan tidak disebut sebagai run independen baru.

## Status fase dan branch

| Fase | Status | Branch |
|---|---|---|
| 0 | Disetujui sebelumnya | feature/advance-export-phase-0 |
| 1 | Selesai sebelumnya; regresi ulang | feature/advance-export-phase-1 |
| 2 | Selesai sebelumnya; regresi ulang | feature/advance-export-phase-2 |
| 3 | Selesai sebelumnya; regresi ulang | feature/advance-export-phase-3 |
| 4 | Selesai sebelumnya; manual UI tertunda | feature/advance-export-phase-4 |
| 5a | Selesai sebelumnya; manual UI/O2 tertunda | feature/advance-export-phase-5a |
| 5b | Selesai dengan catatan manual/deployment | feature/advance-export-phase-5b |
| 6 | Selesai dengan catatan benchmark terbatas/manual/deployment | feature/advance-export-phase-6 |

## Hasil test aktual sesi ini

- Safety database: 2 passed/4 assertions. Baseline sebelum 5b: 257 passed/41.767 assertions/163,05 detik.
- Gerbang 5b: 274 passed/41.953 assertions/210,80 detik; JS 35 passed.
- Gerbang regresi 6: 288 passed/42.067 assertions/209,35 detik; benchmark terpisah yang sudah lulus: 1 test/10 assertions. JS akhir 42 passed.
- Sintaks PHP/JS, scoped Pint, TranslationParity (extractor audit copy), BusinessTime guard, Blade cache, build Vite, route inspection dan diff check dijalankan dan lulus. Composer validate --no-check-publish lulus pada persiapan.

| Test | Fase 5b | Fase 6 |
|---|---|---|
| `AsyncExportQueueTest` | Lulus (24 kasus) | Lulus (24 kasus) |
| `DetailExportSecurityTest` | Lulus (4 kasus) | Lulus (4 kasus) |
| `LocalizationExportTest` | Lulus (3 kasus) | Lulus (3 kasus) |
| `MissionFourExportTest` | Lulus (4 kasus) | Lulus (4 kasus) |
| `RegionalExportHistoryTest` | Lulus (4 kasus) | Lulus (4 kasus) |
| `ShipmentUiAndExportTest` | Lulus (10 kasus) | Lulus (10 kasus) |
| `Finance\PaymentBatchDrpExportTest` | Lulus (14 kasus) | Lulus (14 kasus) |
| `Finance\PaymentBatchTransferExportTest` | Lulus (37 kasus) | Lulus (37 kasus) |
| `LocalInvoice\LocalInvoiceTest` | Lulus (31 kasus) | Lulus (31 kasus) |
| `AdvancedExportBaselineTest` | Lulus (28 kasus) | Lulus (28 kasus) |
| `AdvancedExportFoundationTest` | Lulus (11 kasus) | Lulus (11 kasus) |
| `AdvancedPurchaseOrderExportTest` | Lulus (11 kasus) | Lulus (11 kasus) |
| `ExportPresetTest` | Lulus (7 kasus) | Lulus (7 kasus) |
| `AdvancedExportUiTest` | Lulus (8 kasus) | Lulus (8 kasus) |
| `AdvancedExportRolloutTest` | Lulus (6 kasus) | Lulus (6 kasus) |
| `AdvancedExportPhaseFiveBTest` | Lulus (11 kasus) | Lulus (11 kasus) |
| `AdvancedExportPhaseSixTest` | Belum ada di 5b | Lulus (13 kasus) |
| `AdvancedExportConcurrencyTest` | Belum ada di 5b | Lulus (1 kasus) |
| `LocalInvoice\LocalInvoiceScopeIsolationTest` | Lulus (30 kasus) | Lulus (30 kasus) |
| `SupplierDataIsolationTest` | Lulus (11 kasus) | Lulus (11 kasus) |
| `HashidUrlSecurityTest` | Lulus (6 kasus) | Lulus (6 kasus) |
| `RouteContractTest` | Lulus (7 kasus) | Lulus (7 kasus) |
| `TranslationParityTest` | Lulus (3 kasus) | Lulus (3 kasus) |
| `Architecture\BusinessTimeGuardTest` | Lulus (2 kasus) | Lulus (2 kasus) |
| AdvancedExportQueuedCsvBaselineTest | Lulus (2 kasus en/id) | Lulus (2 kasus en/id) |
| AdvancedExportPerformanceTest | Belum dijalankan | Lulus 50k, pada gerbang penuh pertama |
| Regresi JS SDK/regional | 35 lulus | 42 lulus termasuk format-only/timeout |

Run gagal dilaporkan apa adanya: 5b awal 48 lulus/2 gagal (fixture submitted_at), gerbang 5b pertama 273 lulus/1 gagal (QC status error tertutup tanggal). Fase 6 awal 56 lulus/2 gagal (singkatan Agt dan unique quotation_id fixture). Gerbang penuh 6 pertama 288 lulus/1 gagal (selector source UI), kemudian regresi ulang seluruh export lama/golden hijau. JS timeout pertama 38 lulus/3 gagal karena fixture tidak menjalankan timer; run akhir 42 lulus. Tidak mengubah constraint/migration untuk meloloskan fixture.

## Keputusan, penyimpangan dan batas kompatibilitas

- Stack kumulatif yang disetujui: 5a -> 5b -> 6.
- QC dan Price History default menerima pengecualian keamanan yang disetujui: escape teks formula. Safe whitespace/empty text dipertahankan; heading/urutan/default nilai/tanggal/angka tetap.
- O1 100.000; O3 lima job aktif/user berlaku Tier 1/2, termasuk legacy; bukan batas untuk detail/DRP/Transfer. O4 tidak dibuat.
- Local Invoice tetap string dan actor check, Finance register, Accounting register/payments dengan report dalam preset. Semantik tanggal InvoiceQuery existing tetap. QC advanced/history menggunakan business-day UTC, default export legacy tetap; tidak menambah search QC.
- Price History hanya format, tanpa columns/presets. Constructor, builder dan FromCollection tidak diubah menjadi query streaming. CSV 1.001 baris melewati chunk dengan BOM/heading tunggal, scope supplier dan finalizer diuji menggunakan sync.
- Benchmark 724,25 detik melampaui SDK 660 detik. Hardening clock hanya list CSV advanced: 660 detik tanpa progress, update hanya saat row/stage/status maju, bertahan saat navigasi. XLSX/legacy/detail/DRP tetap timeout lama. Ini memperbaiki monitoring, tidak mempercepat native CSV writer.
- Collection compatibility masih dipakai tests/vendor, sehingga dipertahankan. Tidak ada dependency baru; tidak menyentuh export DRP/Transfer/detail/template/PDF.
- Tiga perubahan luar task muncul selama interupsi: Purchasing/Supplier ExportController filename summary_* dan MissionFourExportTest yang mengikutinya. Dipertahankan, tidak masuk commit task. Regresi dijalankan pada working tree dengan perubahan tersebut; branch commits task tidak mencakupnya.

## Pengukuran performa

| Parameter | Hasil aktual |
|---|---|
| Dataset | 50.000 Local Invoice deterministik pada DB test |
| Kolom | submission, invoice, invoice_amount (3) |
| Format/driver/storage | CSV / sync / private disk fake |
| Chunk | 100 x 500 baris |
| Waktu export | 724,25 detik |
| Peak PHP setelah reset sebelum export | 224.395.264 bytes (214 MiB), di bawah budget test 512 MiB |
| File | 2.327.824 bytes, satu BOM dan 50.001 baris termasuk heading |

Pengukuran ini bukan bukti performa XLSX seluruh kolom, 100k baris, Price History FromCollection, produksi, atau concurrency lima export besar. Benchmark tidak diulang sesudah perubahan JS/assertion UI karena kode PHP export/benchmark tidak berubah. Hasil ini tetap memerlukan pengukuran staging pada worker database dan hardware target.

## Perlu ditinjau pemilik bisnis

Quotation: item_notes, requested_amount, exchange_rate tetap memerlukan konfirmasi kebijakan sensitivitas. Kolom itu sudah tersedia di export supplier legacy; tidak ditambahkan reviewer/internal notes, reference price atau margin. Shipments advanced hanya purchasing. Keputusan O2 lama tidak dilonggarkan dalam sesi ini.

## Checklist manual tertunda dan hal tidak terverifikasi

- QC, Finance, Accounting dan modal fase sebelumnya: filter -> columns/order -> format -> preset -> progress -> cancel/download; report Accounting register/payments, checkbox/date filter dan preset lintas reload.
- Keyboard focus trap/Escape/restore, tombol urutan vs drag, screen reader/live region, responsive layout, hit area dan visual UI: browser tidak dijalankan.
- CSV pada Excel locale Indonesia/international: BOM/quoting diuji otomatis; membuka file di Excel desktop dan Data -> From Text/CSV belum diuji.
- Export besar XLSX semua kolom, Price History 50k, lima export besar, polling/restore lintas tab pada browser dan worker database nyata belum diuji. Uji capacity race hanya dua koneksi dispatch, bukan worker CSV end-to-end.
- Remote storage, staging/produksi, aplikasi/deployment migration fase 1/3: tidak dijalankan/ditentukan penerapannya dalam sesi ini. Tidak ada migration baru 5b/6.
- PHPStan/Psalm tidak tersedia; coverage dan full repository suite tidak dijalankan. Scoped suites yang diminta dan guard terkait dijalankan. Warning metadata PHPUnit deprecated dan logo runtime-resolved existing tetap.

## Diff dan urutan review

Diff fase 5b terhadap 5a: **24 file, +547/-81**. Diff fase 6 terhadap 5b dicatat dari commit sendiri, terpisah dari tiga perubahan filename di working tree. Stat berikut mencakup commit fitur + dokumentasi task, tanpa perubahan filename luar task.

Urutan PR: phase-0 (baseline disetujui) -> phase-1 -> phase-2 -> phase-3 -> phase-4 -> phase-5a -> phase-5b -> phase-6. Masing-masing memakai parent sebelumnya; jangan merge/push paksa. Review O2 dan checklist manual sebelum keputusan rilis.

### Stat Fase 6

```text
 .../Definitions/PriceHistoryDefinition.php         |  38 ++++
 app/Exports/InspectionsExport.php                  |   8 +-
 app/Exports/SupplierPriceHistoryExport.php         |  29 ++-
 .../Controllers/ExportDefinitionController.php     |   2 +
 app/Http/Controllers/ExportPresetController.php    |   2 +-
 .../Supplier/SupplierPriceHistoryController.php    |   4 +-
 app/Jobs/ProcessExportJob.php                      |   2 +-
 app/Policies/ExportPresetPolicy.php                |   4 +-
 app/Services/Export/ExportPresetService.php        |   7 +-
 app/Support/Export/ExportDefinitions.php           |  14 ++
 app/Support/ExportDispatcher.php                   |  21 +-
 app/Support/SpreadsheetCellSanitizer.php           |  10 +-
 config/exports.php                                 |   2 +-
 docs/guides/ADVANCED-EXPORT-DEVELOPMENT.md         |  51 +++++
 .../IMPLEMENTATION-PLAN-ADVANCE-EXPORT-20261007.md |  13 ++
 docs/results/ADVANCE-EXPORT-PHASE-5B-6-20261007.md | 135 ++++++++++++
 public/assets/js/async-export.js                   |  13 +-
 resources/js/advanced-export.js                    |  27 ++-
 .../supplier/price-history/historical.blade.php    |  27 ++-
 routes/web.php                                     |   2 +-
 tests/Feature/AdvancedExportConcurrencyTest.php    |  56 +++++
 tests/Feature/AdvancedExportFoundationTest.php     |   2 +-
 tests/Feature/AdvancedExportPerformanceTest.php    |  53 +++++
 tests/Feature/AdvancedExportPhaseSixTest.php       | 244 +++++++++++++++++++++
 tests/Feature/AdvancedPurchaseOrderExportTest.php  |   4 +-
 tests/Feature/DetailExportSecurityTest.php         |   2 +-
 .../Support/advanced-export-concurrency-worker.php |  42 ++++
 tests/js/advanced-export-csv-timeout.test.mjs      |  76 +++++++
 tests/js/advanced-export-format.test.mjs           |  60 +++++
 tests/js/advanced-export-sdk.test.mjs              |  18 +-
 30 files changed, 925 insertions(+), 43 deletions(-)
```
