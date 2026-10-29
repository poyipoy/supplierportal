# HANDOFF: 3 perbaikan tambahan + Gate 2 sinkronisasi dua arah dokumen PO ↔ Shipment

**Tanggal:** 29 Oktober 2026 · **Dibuat oleh:** sesi Claude Code yang berhenti karena batas waktu 5 jam · **Untuk:** agen pelanjut (Claude Code / Codex)

> Baca dulu: [AGENTS.md](../../AGENTS.md) (sumber fakta proyek, menang atas dokumen ini), lalu rencana lengkap yang **sudah disetujui pengguna**: [PLAN-GATE2-PO-SHIPMENT-DOCS-AND-3-FIXES-20261029.md](PLAN-GATE2-PO-SHIPMENT-DOCS-AND-3-FIXES-20261029.md). Dokumen ini hanya memuat **status, keputusan, jebakan, dan langkah berikutnya**; jangan mengulang eksplorasi yang sudah dilakukan.
> Sumber permintaan: `GATE-1-PO-SHIPMENT-TWOWAY-DOCUMENT-SYNC-2026-10-29.md` (root, untracked, dari tim; jangan dipindah atau diubah) + tiga perbaikan tambahan di bawah.

## 1. Permintaan pengguna (kutipan)

> "Perbaikan tambahan: 1. perbaiki tampilan/ui footer total qty dan kg pada create pr dan ubah menjadi server side calculate tanpa harus save 2. button create quotation dibalik dengan back to 3. template import quotation disatukan saja dengan versi yang entered data jadi biar supplier ga bingung, button download template sudah termasuk entered data"

Pengguna berbicara bahasa Indonesia; balas dalam bahasa Indonesia, identifier kode tetap Inggris.

## 2. Keputusan yang sudah diambil pengguna (jangan ditanyakan ulang)

| Hal | Keputusan |
|---|---|
| Cakupan | 3 perbaikan **+** Gate 2, satu branch. Tiga perbaikan lebih dulu, lalu Gate 2 dalam 3 tahap |
| Template yang digabung, isian tidak valid | **Fallback ke template kosong** + toast peringatan bahwa data tidak ikut terunduh (bukan menolak) |
| Lampiran untuk verified/done dari halaman PO | `VALIDATED` **atau** `HISTORICAL_UNVERIFIED` (legacy) boleh; halaman review Shipment **tidak** diubah |
| antislop | Mode **DURING** (dipilih di sesi ini; pengaturan global belum tersimpan, jadi umumkan ulang `antislop active: during (session override).` sebelum edit UI pertama) |
| Default produk Gate 2 (disetujui bersama rencana) | PO shipment-aware tanpa Shipment aktif: status manual diblokir; proyeksi berbasis status mentah + `evidence_gap_count`; `all_completed` jalur baru berbasis proyeksi; audit alasan di `shipment_documents.notes` + `Log::info`, **tanpa migrasi** |

## 3. Status pekerjaan

Branch: `feature/gate2-po-shipment-docs-20261029` (dibuat dari `master` @ `08b1a9cd`). **Belum ada commit.** Commit/push hanya atas instruksi pengguna, dan jalankan skill `/verify` tepat sebelum `git commit` (bukan untuk perubahan dokumen/tes saja).

| Item | Status |
|---|---|
| Eksplorasi + rencana + persetujuan | **Selesai** |
| A2 swap tombol (view) | **Diedit, belum diverifikasi** |
| A2 tes `test_detail_header_places_back_before_the_primary_action` | **Ditulis, belum dijalankan** (eksekusi dihentikan pengguna sebelum selesai) |
| A0 diagnosis browser (penyebab footer "0") | **Belum** |
| A1 footer + server-side totals | **Belum dimulai** |
| A3 merge template | **Belum dimulai** |
| Gate 2 (B1 sampai B4) | **Belum dimulai** |

Perubahan yang ada di working tree (milik pekerjaan ini):
- `resources/views/supplier/requests/show.blade.php`: blok Back dipindah ke atas blok aksi utama di slot `actions` (baris ~31-41).
- `tests/Feature/Supplier/SupplierPurchasingRequestOverviewTest.php`: tes baru memakai `assertSeeInOrder([e(__('supplier_requests.detail.back')), 'data-supplier-request-action'], false)`.
- File dokumen ini dan `PLAN-…` (untracked).

Perubahan **tak terkait milik pengguna**, jangan di-stage atau diubah: `.agents/skills/antigravity-design-expert/SKILL.md`, `app/Console/Commands/SsoInstallClient.php`, `app/Models/Passport/Client.php`, `tests/Feature/Auth/SsoAuthorizationFlowTest.php`.

## 4. Langkah berikutnya (urut)

1. **Verifikasi A2:** `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest`, lalu `php artisan test tests/Feature/Supplier/SupplierPurchasingRequestOverviewTest.php --compact`. Periksa dulu tidak ada proses `artisan test`/phpunit lain (DB test `adasi_portal_test` dipakai bersama; satu suite pada satu waktu). `php vendor/bin/pint --test` pada kedua file.
2. **A3** (rencana §A3): `_import_controls.blade.php`, `quotation-form-export.js`, `QuotationController::exportForm` (nama file), lang en+id, tes JS/PHP.
3. **A1** (rencana §A1): CSS footer + `<col>` ke-14 + endpoint `purchasing.material-calculations.totals` + `PrTotalsCalculator` + `pr-totals-sync.js` + tes. Lakukan **A0** (browser) sebelum atau bersamaan.
4. **Gate 2** (rencana §B1-B4), mulai dari baseline tes (tahap 0 di bawah).
5. Laporan hasil di `docs/results/` (bukan root); `graphify update .`; laporan akhir memisahkan terverifikasi-eksekusi / diinspeksi / inferensi / belum diverifikasi.

### Tahap 0 Gate 2 (baseline sebelum menyentuh apa pun)
Jalankan dan catat hasil: `PoDocumentStatusValidationTest`, `PoCustomsDocumentationShipmentSyncTest`, `ShipmentDocumentsAndQcIntegrationTest`, `NotificationDeliveryTest`, `Procurement/ShipmentSubmissionConcurrencyTest`, `HashidUrlSecurityTest`. Preflight baca-saja: `SHOW CREATE TABLE po_documents, shipment_documents` (presisi timestamp) dan `SELECT po_id, doc_type, COUNT(*) FROM po_documents GROUP BY 1,2 HAVING COUNT(*)>1` pada snapshot produksi (bila tersedia). **Jangan** `migrate`/`migrate:fresh`/`db:wipe`/truncate ke DB aplikasi.

## 5. Fakta yang sudah diverifikasi (jangan dicari ulang)

**Baseline integritas:** `sha1sum app/Services/ShipmentService.php` = `b20bf4f2e62b2b5bfa7eb3a4020765fd3661908d`. File ini **dilindungi**; gerbang akhir: `git diff --exit-code HEAD -- app/Services/ShipmentService.php`.

**Footer Create PR [Verified dari kode, belum dirender]:**
- `resources/css/app.css:3936-3941`: sel `tfoot` sticky `bottom:0`, `z-index:2`. Sel body sticky-kiri `.pr-sticky-number` z7, `.pr-sticky-material` z6, `.pr-sticky-action` z4 (`resources/views/purchasing/pr/_form_table_styles.blade.php:116-173`) berada di atasnya.
- Teks petunjuk ada di `<th colspan=4>` (`_form_totals.blade.php:4-7`) yang menutup kolom No+Material, jadi bagian kirinya terpotong (cocok dengan screenshot pengguna).
- `<colgroup>` punya 13 `<col>` padahal tabel 14 kolom (`create.blade.php:95-109`, `edit.blade.php:110-124`).
- Footer menampilkan **"0"** padahal tiap baris qty 10: logika `resources/js/pr-totals-core.js` benar dan `public/build/assets/app-BnBVlpHt.js` (dibangun 29 Okt 17:12) **memuat** `AdasiPrTotals`, jadi penyebabnya **bukan bundel basi**. Penyebab belum diketahui; buktikan di browser (console, `typeof window.AdasiPrTotals`, apakah event `adasi:pr-items-changed` memicu `recalc`).
- Nomor baris mulai 4 di screenshot kemungkinan baris 1-3 tergulir; tidak ada offset tersimpan di kode.
- Endpoint yang ada: `purchasing.material-calculations.preview` (`routes/web.php:521-523`, `MaterialCalculationController`, `MaterialCalculationRequest`) hanya per baris, bobot unit dari server. Footer lama hanya mengalikan lalu menjumlah di klien. `PrItemProcessor::process` bebas persistensi dan sudah dipakai `PrItemsImport.php:124`.
- Tes yang mengikat markup footer: `MissionFiveImportTest.php:990-1020` (tfoot `#prFormTotals`, jumlah colspan = jumlah `<th>`), `tests/js/pr-totals-core.test.mjs` (dan fixture `tests/Fixtures/pr-totals-cases.json` yang juga dipakai `MoneyTest`).

**Template quotation (A3):** `supplier/quotations/_import_controls.blade.php:6-13` (dua item), `resources/js/quotation-form-export.js` (POST `export-form`; 422 hanya toast), `Supplier/QuotationController::importTemplate` (258-268) dan `exportForm` (275-291; **pertahankan** `Cell::setValueBinder(new DefaultValueBinder)` baris 288 dan `Cache-Control: no-store, private`). Kedua workbook punya 13 heading identik, satu sheet; import kompatibel (round trip sudah dites: `MissionFiveImportTest:903-952`, `QuotationFormExportTest`). Tes yang tetap harus hijau: `MissionFiveImportTest:1038-1050`, `tests/js/quotation-form-export.test.mjs:90-96`.

**Gate 2:** semua temuan, deviasi dari dokumen Gate 1, urutan lock, route, payload, aturan per baris, proyeksi, dan matriks tes ada di `PLAN-…` §B. Poin yang paling mudah terlewat:
- `ShipmentDocument` **tanpa hashid**; gunakan hashid Shipment (`HashidCodec::decode`) + `doc_type` di URL. Nama route baru **tidak boleh** berawalan `purchasing.po-documents.` (melewati `DecodeHashids`).
- Baris `po_documents` tidak pernah di-lock hari ini dan tidak ada unique `(po_id, doc_type)`; id PO di `syncFromShipmentDocument` tak terurut. Perbaiki di `app/Models/PoDocument.php` (boleh disentuh), bukan di `ShipmentService.php`.
- Rute review Shipment dan `ShipmentController` **tidak diubah** (dua tes memverifikasi dokumen tanpa lampiran lewat rute itu).
- `NotificationDeliveryTest.php:352-366` satu-satunya tes lama yang harus diubah (menembak PUT lama ke PO shipment-aware).
- Jebakan UI lama: supplier upload mengisi `received`, yang bukan opsi untuk BL/Form-E di modal, sehingga select jatuh ke `pending`. Dialog baru: target tanpa nilai default.
- `PurchaseOrder::customsDocumentationSummary()` harus tetap pure-read; Purchasing PO show GET sengaja menulis (reconcile); supplier PO show GET harus nol-tulis (tes `DB::listen`).

## 6. Jebakan lingkungan

- **Hook GateGuard** memblokir Edit/Write **pertama** pada tiap file sampai fakta dinyatakan, lalu diulang. Fakta yang diminta bergantung jenis file: view/kode (siapa mengimpor, fungsi publik terdampak, struktur data, kutipan instruksi pengguna), tes (perilaku yang diuji, tes existing, kutipan), file baru (harness/loader, perubahan perilaku, konfirmasi tidak ada duplikat, atau apa yang digantikan dan dari mana ditautkan, kutipan). **Hanya Glob/Grep/LS/pencarian shell yang dihitung sebagai pencarian, dan harus selesai di giliran sebelumnya (bukan batch paralel yang sama); `Read` tidak dihitung.** Lakukan pencarian, nyatakan fakta, lalu ulangi operasi. **Jangan** menonaktifkan hook.
- Proses lokal milik pengguna sedang berjalan: dua `artisan serve`, `queue:work exports,default`, `queue:work imports`, dan `php -S 127.0.0.1:8000` untuk checkout **lain** (`C:\laragon\www\adasi_rev1027`). Jangan dimatikan. Untuk cek browser, pakai DB seed terpisah + `artisan serve` di port lain (lihat memori proyek tentang harness loopback pra-autentikasi).
- Tes memakai MySQL **nyata** `adasi_portal_test`; ±103 tes menegaskan `DATABASE() = adasi_portal_test`. Baseline bersih: 4 kegagalan yang butuh sampel Infor `*.xlsx` (gitignored); ledger salinan `UserFacingCopyInventoryTest` merah di baseline; pint `line_ending` pada file CRLF. Jangan menyebut kegagalan sebagai pre-existing tanpa perbandingan.
- Windows: `npm.cmd`; `node --test tests/js/<file>.test.mjs` (file eksplisit, bukan direktori). Build wajib setelah perubahan Blade class/CSS/JS: `npm.cmd run build`, lalu `php artisan view:cache`.
- Penulisan file lewat skrip: regex yang gagal mengembalikan `null` dan memotong file jadi 0 byte; `php -l` tetap lolos pada file kosong. Cek ukuran, bukan hanya lint.
- Aturan UI repo: `x-ui.*`, token `--md-*` (tanpa hex), ikon lewat `<x-ui.icon>`, tanggal/angka lewat `RegionalDisplayFormatter`/`BusinessTime`, setiap string baru ada di `lang/en` **dan** `lang/id`; ketentuan lain di AGENTS.md "Panduan UI".
- `/ecc:multi-backend` tidak dapat dipakai (runtime `ccg-workflow` / `~/.claude/bin/codeagent-wrapper` tidak terpasang); jangan dipasang.
- Event auto-discovery dimatikan; listener baru harus didaftarkan eksplisit di `AppServiceProvider`.

## 7. Gerbang penyelesaian

Per tahap: `php -l`, `php vendor/bin/pint --test <file>`, tes terarah, `php artisan view:cache`, `php artisan route:list --no-ansi` (setelah route baru), `npm.cmd run build`, tes JS terkait, `TranslationParityTest` dan `TranslationContentTest`, `git diff --check`. Akhir: `composer test` serial, uji browser terang/gelap + keyboard saja (Create PR footer tidak menimpa baris dan tidak menutup fokus Tab; header supplier; dropdown Import Data; dialog PO), `git diff --exit-code HEAD -- app/Services/ShipmentService.php`, dan laporan antislop Delivery Gate dengan daftar klik per elemen (R-35). Jangan menyatakan sesuatu lulus bila belum dijalankan; CI MariaDB 11.4, staging, produksi, G-PDF dan T-1 tetap gerbang rilis terpisah.
