# GATE 1: Portal Supplier Revisi 27 Oktober 2026
## Audit Repository, Gap Analysis, dan Rencana Implementasi

**Status: GATE 1 AUDIT COMPLETE, PENDING USER APPROVAL FOR GATE 2**

Dokumen ini hanya perencanaan. Tidak ada migrasi, seed, test, instalasi dependency, atau perubahan kode aplikasi yang dijalankan atau dibuat selama Gate 1. Seluruh bukti berasal dari pembacaan kode, `git` read-only, dan `graphify`.

> **Catatan eksekusi plan ini.** Plan mode melarang menulis file selain file plan. Setelah plan ini disetujui, satu-satunya tindakan yang dilakukan adalah menyalin isinya apa adanya ke `docs/plans/GATE-1-PORTAL-REVISION-2026-10-27.md`. Itu bukan persetujuan Gate 2. Gate 2 baru dimulai setelah Anda membaca dokumen itu dan memberi persetujuan terpisah.

Legenda bukti: **[V]** saya baca langsung di kode atau output perintah; **[A]** dibaca agent audit dengan `file:line`, belum saya baca ulang; **[I]** inferensi dari kode, perlu konfirmasi runtime atau browser; **[?]** tidak dapat diputuskan dari repository.

---

## A. Ringkasan eksekutif dan baseline repository

### A.1 Ringkasan

Dari 14 ID revisi, tidak ada yang dimulai dari nol. Semuanya berdiri di atas fondasi Gate 2 Material Procurement (selesai dan sudah merge), sehingga pekerjaan berikutnya adalah penyempurnaan. Komponen yang benar-benar baru hanya empat: Export Form (GEN-03 mode 1), halaman Purchasing Request Overview (SUP-01), tombol Salin dari PO (QC-01), dan sticky pada tab Historical dan Vs Best (PUR-04).

- **Tanpa migrasi database** pada rencana dasar. Dua opsi yang butuh migrasi (partial acceptance per quantity di QC, penanda "nilai disalin" di QC) ditandai sebagai pertanyaan terbuka dengan default tanpa migrasi.
- **Satu endpoint baru yang menerima data klien**: Export Form. Ini satu-satunya permukaan serangan baru, dirancang sinkron, divalidasi ulang di server, tanpa menyimpan transaksi.
- **Satu halaman baru**: Purchasing Request Overview untuk Supplier (read-only), memakai ulang logika visibilitas yang sudah ada.
- **Shipment Concurrency Hotfix tidak disentuh.** Pekerjaan PO, Shipment, dan QC hanya menambah lapisan baca (read model dan tampilan) serta validasi QC sebelum transaksi.
- **Bug nyata yang terbukti** (bukan hanya gap fitur): z-index sticky Material kalah specificity (PUR-04); filter Historical tanpa penjaga race dan rentang dimensi basi lintas shape (PUR-05, PUR-07); Vs Best mencampur shape (PUR-07); `is_overdue` mati setelah kedatangan parsial pertama (PUR-08).

### A.2 Baseline repository

| Item | Fakta | Bukti |
|---|---|---|
| Branch / HEAD | `master` @ `c74386f1` (2026-10-23 16:08 +0700), merge PR #11 | [V] `git log` |
| Gate 2 Material Procurement | Sudah di HEAD (`b4f56cd4`), termasuk Exchange Rate Benchmark, ordered qty, MTC per shipment, Master Harga | [V] `git log --grep`, file kunci ada di HEAD |
| Shipment Concurrency Hotfix | Ada di HEAD: `SUBMIT_ATTEMPTS = 3` di `ShipmentService.php:34,501` | [V] `git grep` |
| Flag `config/procurement.php` | `shipment_mtc_enabled` default **true** (phpunit.xml: false); `ordered_quantity_write_enabled` default false; `allow_offer_surplus` default false; `po_generation_enabled` default true | [V] |
| Working tree | 20 file modified + 4 untracked, **semuanya milik sesi lain** (bagian I) | [V] `git status` |
| Stash | 2 stash lama dari `remediation/item-level-award-shipment-20260905`. Tidak disentuh | [V] |
| Worktree lain | `adasi_integration`, `adasi_perf_baseline`, `adasi_perf_stage1`, `adasi_pkp`, dua worktree temp Tailwind | [V] |
| Proses PHP | Pengecekan pertama menemukan 2 `php.exe`, pengecekan kedua tidak ada. Tidak ada test yang saya jalankan | [V] |
| Advanced Export (sesi lain) | Sudah committed. **Tidak ada** perubahan belum-commit di `app/Exports`, `app/Support/Export`, `ExportDispatcher`, JS export, atau `components/export` | [A] |
| Baseline test 2.658/2.660 | **NOT VERIFIED.** Angka dari checkpoint Gate 2 tanggal 16 Okt. Sejak itu ledger copy diregenerasi (`3c93078`), test Infor diganti fixture sintetis (`33acd5c`), dan key `common.calendar.years` kini ada di kedua locale (`lang/en/common.php:211`, `lang/id/common.php:211`), sehingga kegagalan `TranslationParityTest` kemungkinan sudah hilang. Gate 2 wajib mengukur ulang sebelum mengubah apa pun | [V] untuk key, [I] untuk kesimpulan |

### A.3 Skill yang dipakai

Dimuat: boost, backend-security-coder, ecc:accessibility, ui-styling, antislop (inti, human, code, ui), ecc:make-interfaces-feel-better, ecc:laravel-patterns, ecc:laravel-security, ecc:plan, ecc:orch-change-feature, ecc:change-impact, ecc:coding-standards. Tersedia dan relevan, dimuat pada Gate 2 karena baru berguna saat eksekusi: ecc:search-first, ecc:mysql-patterns, ecc:laravel-tdd, ecc:laravel-verification, ecc:frontend-a11y, ecc:verification-loop.

Penerapan antislop pada dokumen ini: tanpa em dash, tanpa data atau klaim karangan, setiap usulan UI disertai alasan fungsional. Mode antislop (during/after) belum tersimpan (tidak ada `settings.json`); karena Gate 1 tidak membangun UI, mode ditetapkan di awal Gate 2 dengan default **during**.

---

## B. Pemetaan 14 revisi ke implementasi existing

| ID | Klasifikasi | Existing (lokasi utama) | Gap utama | Migrasi |
|---|---|---|---|---|
| PUR-01 | PARTIALLY IMPLEMENTED | Pipeline import PR lengkap: `PrItemsImport`, `PrItemProcessor`, `_import.blade.php`; payload preview sudah memuat 5 dimensi | Tabel preview hanya Row, Material, HS, Shape, Qty, KG/Unit, Remark; **tanpa kolom dimensi** (`_import.blade.php:48-54`) | Tidak |
| PUR-02 | PARTIALLY IMPLEMENTED | Footer grand total Qty/KG di form PR (`_form_totals`) dan halaman show; per-item di show | Tidak ada kolom Total KG per item di form dan preview; tidak ada grand total di preview; hitung ulang footer rapuh | Tidak |
| PUR-03 | PARTIALLY IMPLEMENTED | Estimasi pengiriman sudah tampil di header matrix (`_inter_supplier_content:232-235`) | Header memakai `users.name`, bukan `suppliers.company_name`; PIC (kontak, telepon, email) masih di-include (`:236-238`) | Tidak |
| PUR-04 | EXISTING BUT BUGGY (Inter-Supplier); MISSING (Historical, Vs Best) | `.ui-table-sticky` / `.ui-sticky-col` (`app.css:3921-3953`) | Matrix pakai `.col-sticky-material` privat; z-index header Material kalah; offset baris header kedua tetap | Tidak |
| PUR-05 | PARTIALLY IMPLEMENTED | AJAX per tab (server tabs, fetch JSON Historical, DataTables Vs Best) | Tidak ada sinkron material lintas tab; Historical tanpa penjaga race; form mata uang basi; `replaceState` menghapus state tab | Tidak |
| PUR-06 | PARTIALLY IMPLEMENTED; NEEDS CLARIFICATION (contoh vs preferensi) | `RegionalDisplayFormatter::number()`, `money()` | Banyak titik render memakai gaya berbeda (`Rp` + spasi, `IDR`, `toLocaleString('id-ID')`, `number_format`) | Tidak |
| PUR-07 | EXISTING BUT BUGGY | Filter `key_min/key_max`, peta kunci per shape (`MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE`) | Rentang tidak direset saat shape berganti; material sama beda shape tertimpa; Vs Best mem-partisi per `material_name` saja | Tidak |
| PUR-08 | PARTIALLY IMPLEMENTED | `itemFulfillmentStatus()` agregasi semua shipment; `MaterialProgressService`; tabel per award di PO show | Tidak ada daftar shipment di PO; QC hanya inspeksi terakhir; NG tidak ditampilkan; `is_overdue` mati setelah kedatangan parsial | Tidak |
| PUR-09 | EXISTING AND WORKING (celah laten); NEEDS CLARIFICATION (partial acceptance) | `reconcileOperationalStatus()` satu-satunya penulis `completed` (`PurchaseOrder.php:807`) | NG tidak pernah dihitung accepted (benar); belum ada penerimaan parsial dalam satu baris; cabang legacy `actual_arrival` bisa menyelesaikan PO tanpa QC | Tidak (opsi: ya) |
| QC-01 | PARTIALLY IMPLEMENTED | Form QC: input dimensi kosong awal, hanya field relevan | Tidak wajib (`nullable|numeric`, `QcInspectionController.php:176-181`); tidak ada tombol Copy dari PO | Tidak |
| SUP-01 | PARTIALLY IMPLEMENTED | `scopeVisibleToSupplier`, alur Periode, PR, Quotation form | Tidak ada halaman read-only lintas periode; spesifikasi hanya terlihat di dalam form penawaran | Tidak |
| GEN-01 | PARTIALLY IMPLEMENTED | Sama dengan PUR-06 | Aturan format harga belum satu pintu | Tidak |
| GEN-02 | PARTIALLY IMPLEMENTED | Semua menu Import Excel sudah punya Download Template | Template Supplier: tiga urutan berbeda (template, form, preview); belum ada test kontrak urutan | Tidak |
| GEN-03 | Mode 2: PARTIALLY IMPLEMENTED; Mode 1: MISSING | Advanced Export `supplier.quotations` | Katalog hanya `requested_dimensions`/`offered_dimensions` gabungan; tidak ada format angka Excel; tidak ada jalur untuk baris form belum tersimpan | Tidak |

---

## C. Analisis gap berbasis bukti

### C.1 Workstream A: PR dan Excel

**PUR-01 Preview Import PR**
- *Existing:* `purchasing.requisitions.import-preview` → `PurchaseRequisitionController::importPreview` → `SpreadsheetImportReader` → `PrItemsImport` (all-or-nothing, batas 1000 baris). Parser membaca per nama heading, jadi urutan kolom file tidak memengaruhi parsing. Persistensi per key lewat `PrItemProcessor` dan `$fillable` [A].
- *Expected:* Thickness, Width, OD, ID, Length sebagai kolom terpisah, urutan sama dengan template; dimensi tak relevan boleh kosong.
- *Bukti gap:* header tabel preview tidak memuat dimensi [V `_import.blade.php:48-54`]. Template mengurutkan `material_name, shape, quantity, thickness, width, d_outer, d_inner, length, remark` [A `PrImportTemplateExport.php:31-41`]; urutan baku di kode adalah `PrItem::FIXED_DIMENSION_ORDER` = thickness, width, d_outer, d_inner, length [V `PrItem.php:75-81`].
- *Akar masalah:* terbukti, kolom memang tidak dirender; data sudah ada di payload (spread dari `PrItemProcessor`) [A].
- *Solusi minimal:* tambah lima `<th>`/`<td>` dari `PrItem::FIXED_DIMENSION_ORDER` (dikirim ke JS lewat `@json`); sel dimensi tak relevan tampil `-`. Konstanta `HEADINGS` di `PrItemsImport` memakai urutan lain tetapi hanya untuk peringatan heading tak dikenal [A]; selaraskan agar tidak membingungkan.
- *Keamanan:* tidak berubah (preview sudah memakai role purchasing, throttle, dan pembaca spreadsheet berbatas).
- *Test ada:* `MissionFiveImportTest` (heading template, mapping JSON semua shape, penolakan dimensi tak relevan, all-or-nothing). *Kurang:* tidak ada test markup preview.

**PUR-02 Total KG**
- *Existing:* `PrItem::total_weight` = `round(weight_needed × max(1, qty), 4)` [A `PrItem.php:182-187`]; halaman show menghitung `totalKg`/`totalQty` di PHP dengan float [A]; footer form PR memakai JS [A `_form_totals.blade.php`]. KG/Unit berasal dari `MaterialWeightCalculator` (faktor densitas tetap); override manual hanya jika form mengirim `weight_manual_override`; import Excel mengabaikan `weight_needed` dengan peringatan [A].
- *Expected:* Qty, KG/Unit, Total KG per item, Grand Total Qty, Grand Total KG; otomatis untuk input manual dan import.
- *Bukti gap:* form PR tidak punya kolom Total KG per item (`create.blade.php:110-122`, `_item_row`); preview tidak punya total sama sekali [A]. Penulisan nilai programatik (`.val()`) tidak memicu event; recalculation tampaknya hidup hanya karena teks `.hs-status-badge` ikut berubah dan memicu `MutationObserver` [I]. Tidak ada test yang menyebut `prFormTotalKg` [A].
- *Divergensi frontend/backend:* JS menjepit qty ke minimal 0, server minimal 1 (efek hanya pada qty 0 atau kosong; input sudah `min=1 required`) [A].
- *Presisi:* PR memakai float PHP dengan `round(…, 4)`, bukan BCMath; tabel read-only quotation sudah memakai `bcadd` [A]. Karena KG/Unit berpresisi 4 desimal dan qty bilangan bulat, jumlah JS dan PHP identik pada praktiknya [I].
- *Solusi minimal:* (1) kolom Total KG read-only per baris form; (2) respons preview membawa `total_weight` per baris dan `summary.total_quantity`, `summary.total_weight_kg` dihitung server dengan BCMath skala 4 (preview tidak dapat diedit, jadi tanpa matematika JS); (3) event eksplisit `adasi:pr-items-changed` dari `renderMaterialPreview` dan `appendImportedPrRow`, footer mendengarkan event itu; (4) fungsi hitung murni diekstrak ke `resources/js/pr-totals-core.js` (integer-scaled, tanpa DOM) agar bisa diuji dengan `node --test`; (5) opsional: total di halaman show memakai `bcadd` skala 4 agar konsisten dengan tabel quotation.
- *Test kurang:* JS untuk core dan footer; Feature untuk `summary.total_*` preview; paritas JS vs server pada data campuran.

**GEN-02 Excel Import Template**
- *Inventaris* [A kecuali dicatat]:

| Menu | Import | Template | Urutan konsisten? | Catatan |
|---|---|---|---|---|
| Purchasing, PR create/edit | Ya | Ya (`PrImportTemplateExport`) | Template, parser, form, persistensi selaras; **preview tidak** (PUR-01) | |
| **Supplier, Quotation** | Ya | Ya (`QuotationImportTemplateExport`) | **Tidak: tiga urutan berbeda** | lihat di bawah |
| Finance/Purchasing, Local PO | Ya | Ya (`LocalPoImportTemplateExport`) | Ya, posisional mengikuti ekspor Infor (indeks 4, 9, 10, 16) | tidak diubah |
| Finance/Purchasing, Local GR | Ya | Ya (`LocalGrImportTemplateExport`) | Ya, posisional (indeks 8, 9, 11, 15, 16, 19) | tidak diubah |
| GA, Employee | CLI saja, tanpa UI | Tidak ada | n/a | di luar cakupan |

  Hanya enam view yang memuat unggah spreadsheet; tidak ada impor Excel untuk Material Master, HS Code, Reference Price, Exchange Rate, maupun Vendor Master. Semua template dihasilkan oleh kelas export, bukan file statis.
- *Bukti urutan Supplier* [V]: template = `pr_item_id, material_name, requested_dimension, price_per_kg, available_qty, available_thickness, available_width, available_d_outer, available_d_inner, available_length, notes, availability, offered_weight_per_unit` (`QuotationImportTemplateExport.php:38-53`); form = No, Material, Row Type, Qty, dimensi, KG/Unit, Total KG, Price/kg, Amount, Notes, [MTC], Availability (`create.blade.php:898-913`); preview = PR item id, Availability, Price/kg, Offer Qty, dimensi, KG/Unit, Notes (`create.blade.php:1362-1370`).
- *Akar masalah:* tiga daftar kolom ditulis terpisah tanpa sumber tunggal.
- *Solusi minimal:* satu konstanta urutan kolom template Supplier (kunci heading) dipakai export dan dibandingkan dengan form lewat test kontrak; urutan baru mencerminkan form: `pr_item_id, material_name, requested_dimension, available_qty, available_thickness, available_width, available_d_outer, available_d_inner, available_length, offered_weight_per_unit, price_per_kg, notes, availability` (Total KG dan Amount dihitung, tidak masuk template). Preview diselaraskan. Parser tetap membaca per heading dan tetap menerima file urutan lama (dikunci `MissionFiveImportTest:636` [A]). Local PO/GR tidak disentuh.
- *Asumsi [?]:* "urutan kolom template Supplier" diartikan mengikuti urutan form Supplier. Lihat J, Q6.
- *Test kurang:* kontrak urutan template = form = preview; round-trip (unduh template, isi satu baris, preview, simpan) untuk empat template.

**GEN-03 Export Excel seperti Infor (keputusan C)**
- *Pipeline existing* [A]: `AdvancedExportRequest` (POST) → `ExportDispatcher::dispatch` → `ExportJob` → `ProcessExportJob` (queue `exports`) → finalize → unduh. Request hanya menerima `options` dengan key `columns` dan `format` (`array:columns,format` [V `AdvancedExportRequest.php:20`]); kelas export wajib ada di `SUPPORTED_EXPORT_CLASSES`; argumen harus JSON-serializable dan disimpan di `export_args`; batas 100.000 baris dan 5 job aktif per user; handoff atomik di DB queue. Teks melewati `SpreadsheetCellSanitizer` hanya untuk kolom bertipe Text.
- *Halaman Supplier dengan export:* `supplier.quotations` (`QuotationDefinition`), `supplier.po`, `supplier.price-history`, plus export detail quotation dan PO. Shipment Supplier dan halaman form penawaran belum.
- *Mode 2 (Export Saved Data):* dapat memakai ulang pipeline; filter dan otorisasi sudah di server (`QuotationDefinition::authorize` plus scoping owner di controller). **Gap round-trip:** katalog hanya `requested_dimensions` dan `offered_dimensions` gabungan (`QuotationDefinition.php:50,52`), jadi tidak bisa menjadi input template. **Gap format:** tidak ada `WithColumnFormatting` kecuali di template Local PO [V grep]; angka tetap numerik tetapi tanpa number format.
- *Mode 1 (Export Form):* MISSING. Baris belum tersimpan hanya ada di DOM `#quotationForm` dan draft `localStorage` (`create.blade.php:2795-2830`). Pipeline existing tidak bisa menerimanya karena (a) request menolak key tambahan, (b) kelas export dibangun ulang dari DB di worker, (c) menaruh payload klien di `export_args` berarti data tidak tepercaya mengendap di tabel queue. `package.json` tidak punya pustaka spreadsheet sisi klien.
- *Solusi minimal:* lihat D.12 dan E.D.
- *Risiko:* menambah kolom dimensi ke katalog mengubah kolom default preset yang ada, jadi kolom baru wajib `default: false`; `AdvancedExportBaselineTest` mengunci katalog tertentu.
- *Test kurang:* seluruh Export Form (injection, otorisasi, batas baris, paritas urutan dengan template, tipe sel numerik).

### C.2 Workstream B: Price Comparison

**PUR-03 Informasi Supplier**
- *Existing:* `Quotation::with(['supplier.supplier','items.prItem','exchange_rate'])` (`PriceComparisonController.php:148`, tanpa N+1); `name = $q->supplier->name` (users.name) [A `:153-163`]; estimasi pengiriman `date` nullable, sudah dirender [V `:232-235`].
- *Bukti gap:* PIC masih di-include [V `:236-238`]; `users.name` sama dengan nama perusahaan hanya untuk supplier hasil registrasi mandiri, sedangkan akun buatan admin memiliki nama akun terpisah [A `SupplierRegistrationService.php:219,226`; `Admin/UserController.php:105,132`]. `suppliers.company_name` NOT NULL. Presedennya ada: `SupplierAudit.php:220` memakai `company_name ?: name`.
- *Solusi minimal:* satu `display_name = company_name ?: name` di peta supplier controller dan di dua query SQL (Historical/Vs Best) via `COALESCE`; hapus `@include('quotations._supplier_pic')` dan key `profile` hanya di `_inter_supplier_content`. **Jangan** edit partial `_supplier_pic`: dipakai `purchasing/quotations/show.blade.php:147` dan `purchasing/po/show.blade.php:251`, dan `QuotationPresentationTest.php:124` mengunci `data-supplier-pic` di halaman quotation.
- *Dampak:* label chart, `data-supplier-name` radio award, preview pengelompokan PO ikut berubah ke nama perusahaan (diinginkan).
- *Test kurang:* header memuat nama perusahaan dan tanggal; HTML komparasi tanpa `data-supplier-pic`; halaman quotation tetap memilikinya.

**PUR-04 Sticky header dan Material**
- *Akar masalah (terbukti secara cascade, konfirmasi visual di Gate 2):* aturan generik `.ui-table-sticky > table > thead > tr > th { z-index: 3 }` (specificity 0,1,4) mengalahkan `thead th.col-sticky-material { z-index: 12 }` (0,1,2). Sel body Material ber-`z-index: 5`, jadi saat scroll vertikal body menimpa header Material; sel header Supplier (z-index 3, muncul lebih akhir di DOM) menimpa header Material saat scroll horizontal [V `app.css:3929-3934`, `_inter_supplier_content:19-32`].
- *Akar kedua:* baris header kedua memakai `top: var(--ui-table-sticky-head-row, 2.25rem)` tetap, sedangkan baris pertama tinggi (nama, chip status, estimasi, dan sampai PUR-03 blok PIC) [V markup `:224-239`]. Variabel tidak di-override di repo [A]. Baris kedua menumpuk di atas baris pertama saat scroll.
- *Lain:* offset `12rem` mengasumsikan tabel dekat puncak viewport, padahal ada toolbar, header evaluasi, ringkasan, dan chart di atasnya [A]; `box-shadow rgba(0,0,0,.12)` tak terlihat di dark mode [A]; kolom Material 200-240px mendominasi layar 360px [A].
- *Historical dan Vs Best:* memakai `x-ui.data-table` tanpa `sticky`, hanya `overflow-x`. Historical tidak punya kolom Material (kolomnya PR, Supplier, Price/kg, Converted, PO date, Change). Pada Vs Best, DataTables membungkus tabel dengan div sendiri sehingga selector anak langsung `.ui-table-sticky > table` tidak cocok [A, I].
- *Solusi minimal:* ganti `.col-sticky-material` dengan `.ui-sticky-col` + `--ui-sticky-col-left` (pojok kiri-atas sudah z-index 4 di CSS bersama); set `--ui-table-sticky-head-row` dari tinggi baris pertama hasil ukur (`ResizeObserver`, fallback 2.25rem); `scroll-padding` agar elemen fokus tak tertutup sel sticky (WCAG 2.4.11); batas lebar kolom Material `min(40vw, 12rem)` di layar sempit; Historical: sticky header + kolom identitas pertama; Vs Best: wrapper berbatas tinggi dengan selector turunan.
- *Test:* tidak ada test sticky khusus komparasi (hanya string `ui-table-sticky` di `QuotationPresentationTest`). Perlu test markup (kelas, tidak ada `.col-sticky-material`) dan QA browser.

**PUR-05 Filter material AJAX dan sinkron lintas tab**
- *Existing per tab* [A]: Inter-Supplier: picker PR via server tabs; select Material `#comparisonMaterialFilter` hanya menyaring baris dan data chart di klien, tidak masuk URL. Historical: `data-managed-submit`, fetch `?view=json`, daftar material dari histori PO, URL diperbarui `replaceState`. Vs Best: tidak ada filter material, hanya rentang bulan dan pencarian DataTables. Tautan tab memakai URL daftar yang diingat sesi per tab (`PurchasingNavigation::listUrl()`), jadi filter tidak dibagi.
- *Bug terbukti:* tidak ada `AbortController` atau token urutan di `_scripts.blade.php` [V grep nol hasil], sementara `server-tabs.js` hanya melindungi swap konten tab. Respons lama dapat menimpa respons baru pada `loadMaterialsForSupplier` dan `loadHistoricalPayloadFromFilters` [A]. Input tersembunyi header evaluasi dibangun dari query saat render, jadi setelah filter AJAX, mengganti mata uang mengirim filter lama [A `_evaluation_header:11,33-35`]. `replaceState(null/{} , …)` menghapus `history.state._serverTabs` sehingga tombol back/forward tidak memulihkan tab [I].
- *Solusi minimal:* kontrak query bersama `material_name` (+ `shape`) dibawa di href tab yang dirender server dan diperbarui klien saat filter berubah; tiap tab memvalidasi nilai terhadap semesta opsinya sendiri dan membuangnya dengan pemberitahuan bila tidak valid; filter `material_name` exact-match ditambahkan ke `buildVsBestQuery`; token urutan permintaan + `AbortController` pada dua fetch Historical; pertahankan `history.state`; segarkan input tersembunyi header. Pastikan cabang `ServerTabsResponse::wants()` mendahului cabang `$request->ajax()` (dokumen AGENTS) dan jangan ubah kontrak JSON DataTables.
- *Kode mati:* penanganan `.dimension-field` dan `extractDimensionFilters`/`matchesDimensionFilters` tidak terpakai [A]; hapus hanya jika terbukti tak ada pemanggil dan tidak melebar dari cakupan.
- *Test ada:* `PriceComparisonServerTabsTest` (kontrak JSON), `server-tabs.test.mjs`, `regional-comparison.test.mjs` (regex). *Kurang:* test JS untuk respons tak berurutan, reset filter tidak valid, form mata uang basi.

**PUR-06 / GEN-01 Format harga**
- *Existing:* `RegionalDisplayFormatter::number()` hanya memindah pemisah (tanpa float, tanpa pembulatan); `system` mengembalikan teks apa adanya; preset `international` (`1,250,000.50`) dan `indonesian` (`1.250.000,50`); tidak ada simbol, posisi, atau desimal per mata uang. `money()` ada tetapi tidak dipakai komparasi [A].
- *Inventaris render komparasi* [A]: commercial overview `Rp {{ maxDecimals }}` (melewati formatter); matrix `Rp`+`formatNumber` dan suffix `amount CUR`; indikator `$code.' '.maxDecimals` (tanpa pengelompokan); Historical `IDR`+profil `decimal`; Vs Best `number_format` + profil indonesian hanya untuk IDR; JS `toLocaleString('id-ID')` dan `'Rp '` keras; label chart bahasa Inggris keras; toast "Gagal memuat..." bahasa Indonesia keras. Tooltip dan sumbu chart konsisten satu sama lain lewat `formatAnalyticMoney`. Modul Price Comparison tidak punya export.
- *Akibat bila tidak diperbaiki:* pada preferensi default `system`, sel PHP menampilkan `70000.5` tetapi sel JS menampilkan `Rp 70.000` [A].
- *Konflik interpretasi:* contoh Anda `Rp70,000.00` cocok dengan pemisah preset `international`, tetapi preset tidak menetapkan simbol atau desimal, dan keputusan interview mengunci formatting regional. Pengguna dengan preset `indonesian` akan melihat `Rp70.000,00`. **Rekomendasi default:** preferensi regional menang; `Rp70,000.00` adalah wujud `international`. Lihat J, Q2.
- *Solusi minimal:* satu pintu `RegionalDisplayFormatter::currency(string $decimal, string $code): string` + padanan JS yang diisi dari payload server; konfigurasi simbol dan desimal di file config baru (bukan `config/regional_display.php` yang sedang dimodifikasi sesi lain); input wajib string desimal (tanpa cast float); pembulatan half-up BCMath hanya untuk tampilan; seluruh titik render yang diinventarisasi dialihkan; `data-price` tetap kanonik (dikunci `RegionalComparisonDisplayTest:72-87`). View baru yang memakai formatter wajib terdaftar di `View::composer` `AppServiceProvider.php:144-199` (dijaga `RegionalComparisonDisplayTest`).
- *Excel:* sel tetap angka; format nomor Excel diturunkan dari mata uang (mis. `"Rp"#,##0.00`); CSV tetap angka mentah. Nilai database dan presisi perhitungan tidak berubah.

**PUR-07 Dimensi Historical**
- *Model* [V/A]: Flat → `thickness`; Round → `d_outer`; Hollow → `d_outer` (`d_inner` ditampilkan, tidak pernah menyaring); riwayat memakai `COALESCE(qi.available_<key>, pi.<key>)` (`PurchaseHistoryPriceService.php:226`). Pemetaan Diameter Round ke `d_outer` terkonfirmasi.
- *UI sekarang:* dua input angka bebas `key_min`/`key_max`, bukan daftar opsi; label dipilih JS `shape === 'Flat' ? thickness : d_outer`, jadi Round dan Hollow memakai label yang sama [A].
- *Bug:* (1) rentang tidak direset saat material atau shape berganti, lalu `FormData` mengirim ulang dan server menerapkannya ke kolom kunci shape baru, sehingga filter Flat 5 mm menjadi Round `d_outer ≥ 5` [A kode, I perilaku]; (2) `historicalMaterialsForSupplier` mengelompokkan nama+shape lalu `->unique('material_name')` sehingga satu nama dengan beberapa shape hanya menyisakan satu, urutannya tak terdefinisi [A]; (3) Vs Best mem-partisi `ROW_NUMBER()` dan join per `material_name` saja [V `PriceComparisonController.php:677,691`], dan memakai aturan kelayakan berbeda dari `PurchaseHistoryPriceService`; (4) payload JSON membawa `keyDimension` tetapi `renderPayload` tidak menerapkannya ke label [A].
- *Solusi minimal:* reset rentang saat material atau shape berubah; kunci material = nama + shape; Vs Best memartisi dan join dengan `material_name` + `shape`; label Flat "Thickness (mm)", Round "Diameter (mm)", Hollow "Outer Diameter (mm)"; opsi dimensi diambil dari nilai kunci yang benar-benar ada pada riwayat PO layak untuk material+shape terpilih (endpoint kecil atau perluasan payload materials, dibatasi jumlah), dengan input rentang tetap tersedia. Apakah Vs Best juga mensyaratkan dimensi kunci identik: J, Q4.
- *Test ada:* `PriceComparisonAnalyticsTest::test_ac20` (Hollow menyaring `d_outer`). *Kurang:* rentang basi, tabrakan shape satu nama, isolasi shape Vs Best.

### C.3 Workstream C: PO, Shipment, QC

Invarian yang dipertahankan (lokasi penegakan, [A] kecuali dicatat): tanpa over-shipment (`ShipmentService.php:439-446` + proyeksi `PurchaseOrder.php:606-723`; CHECK `shipped_qty > 0`); urutan lock Shipment → PO (urut id) → QuotationItem (urut id) (`ShipmentService.php:287-363`; QC store `QcInspectionController.php:217-262`; klaim PO → inspeksi → klaim); retry deadlock `SUBMIT_ATTEMPTS = 3` [V]; MTC `ShipmentService.php:369-371` + `validatedMtcCount` L738-743; `shipped_qty` integer terpisah dari `actual_weight_kg`; `reconcileOperationalStatus()` dipanggil di bawah lock PO oleh `ShipmentService:580`, `QcInspectionController:357`, `MaterialClaimController:164,229`, `Supplier\ClaimController:126`.

**PUR-08 Sinkronisasi PO dan Shipment**
- *Existing:* `itemFulfillmentStatus` mengagregasi **semua** shipment SUBMITTED/ARRIVED dan hasil QC dari `QcItem` [A]; `MaterialProgressService::projectionForAward`/`poSummary` mengisi tabel per award (`purchasing/po/show.blade.php:411-480`, `supplier/po/show.blade.php:93-130`). Penulis `purchase_orders.status`: `PurchaseOrderGenerationService:237` (`active`), `PurchaseOrderController:294-297` (`waiting_qc`, jalur arrival legacy), dan `reconcileOperationalStatus` (`claim_needed`/`waiting_qc`/`completed`/`active`). Tidak ada yang menulis `draft`, `cancelled`, atau `overdue` (diturunkan) [A; verifikasi ulang di preflight].
- *Gap:* (1) `ng_qty` dan `replacement_eligible_qty` ada di `itemFulfillmentStatus` tetapi `projectionForAward` membuangnya, jadi material ditolak tidak tampil di UI atau export [A]; (2) tampilan "terakhir saja": PO show hanya menampilkan inspeksi QC terbaru (`:504-508`), `PurchaseOrderDetailExport` hanya inspeksi terakhir, `actual_arrival` ditimpa setiap kedatangan [A]; (3) `is_overdue` mensyaratkan `status === 'active'` **dan** `! actual_arrival` [V `PurchaseOrder.php:52-61`], sehingga setelah kedatangan parsial pertama PO tidak pernah lagi overdue walau qty belum lengkap; (4) tidak ada daftar shipment di PO; `delivery_progress` hanya dipakai test [A]; (5) satu kolom status memblokir shipment baru selama ada klaim: `submitShipment` hanya menerima PO `active/overdue/waiting_qc` (`ShipmentService.php:347`) [A]; (6) daftar PO dan export tanpa kolom fulfillment/QC [A].
- *Solusi minimal (read-only, tanpa migrasi, tanpa menyentuh lock/submit):* `PurchaseOrder::fulfillmentSummary()` yang **hanya** menjumlahkan `itemFulfillmentStatus()` per quotation item (menjaga satu resolver, I-15); tabel "Shipments dan QC" di kedua PO show (nomor, status, qty, status QC per shipment, qty ditolak); daftar semua inspeksi menggantikan "terakhir"; kolom NG di tabel award; fase turunan (D.10) sebagai badge tambahan, bukan status tersimpan; `is_overdue` diubah menjadi "estimasi terlewat DAN qty ordered belum seluruhnya diterima" setelah test karakterisasi; satu kolom ringkas "Fulfillment" di daftar PO via subquery agregat tunggal (cek EXPLAIN di Gate 2).
- *Keputusan produk (tidak dikerjakan sebagai default):* apakah klaim membuka blokir shipment untuk material lain. Gerbang `ShipmentService.php:347` tidak diubah. J, Q3.

**PUR-09 Penyelesaian PO**
- *Existing:* `completed` hanya ditulis di `reconcileOperationalStatus` [V L807-809] dan lengket (`cancelled`/`completed` langsung dikembalikan, L785-787). Urutan prioritas [V L803-811]: klaim aktif atau NG belum selesai → `claim_needed`; shipment tiba belum diinspeksi → `waiting_qc`; `isFullyFulfilledAndInspected` → `completed`; selain itu `active`. Uji kelengkapan [V L746-750]: untuk setiap quotation item, `accepted_qty >= ordered_qty`; `accepted_qty` hanya menjumlahkan `shipped_qty` item shipment ARRIVED dengan QC `ok`; NG tidak pernah dihitung; klaim resolved melepas qty NG sehingga pengganti bisa dikirim [A `PurchaseOrder.php:657-681`]. Ini sudah memenuhi "rejected tidak dihitung accepted", multi-shipment, dan multi-material.
- *Celah laten:* (1) cabang tanpa shipment [V L731-736] `! $hasNg && ($hasOk || actual_arrival)` menjadikan PO legacy `completed` hanya karena `actual_arrival` terisi tanpa satu pun inspeksi OK (kemungkinan tak terjangkau, sebab reconcile pada PO legacy dipanggil setelah QC/klaim; butuh test karakterisasi sebelum mengubah apa pun) [I]; (2) `quotationItems->isEmpty()` mengembalikan `true` [V L742-744] (sangat mungkin tak terjangkau, tetapi merupakan jalur penyelesaian prematur yang aman diubah menjadi false); (3) `QcItem` biner ok/ng tanpa qty diterima/ditolak, jadi baris 10 pcs dengan 7 lolos tidak dapat dinyatakan [A `QcItem.php:10-22`]; (4) "seluruh proses penerimaan wajib selesai": dokumen bea cukai dan MTC hanya ditampilkan, tidak menjadi syarat selesai [A]; (5) `QcInspectionController::isPoFullyFulfilledAndInspected` kode mati [A].
- *Rekomendasi:* **pertahankan status administratif tersimpan dengan `reconcileOperationalStatus()` sebagai satu-satunya penulis**; status turunan (D.10) dipakai untuk tampilan. Alasan: filter, export, dan notifikasi bergantung pada kolom `status`; mengubah semantik kolom berisiko lebih besar daripada manfaatnya. Gate 2: test karakterisasi untuk dua celah laten, perketat hanya jika terbukti terjangkau, dan jangan ubah apa pun di jalur shipment-based.
- *Partial acceptance dalam satu baris:* butuh `qc_items.accepted_qty/rejected_qty` (migrasi). J, Q1.

**QC-01 Actual Dimensions**
- *Existing:* form `qc/inspections/create.blade.php:219-233`: input `number step=any`, kosong awal, tanpa `required`/`min`; field relevan dari `PrItem::relevantDimensionFields`; toleransi 5% hanya sorotan merah klien; switch OK/NG manual, default OK [A]. Backend: `store` memvalidasi `nullable|numeric` tanpa FormRequest dan tanpa batas [V `QcInspectionController.php:176-181`]; `sanitizeActualMeasurements` menolkan dimensi tak relevan tetapi tidak mewajibkan yang relevan [A]. Kolom `qc_items.actual_*` nullable `decimal(10,4)`. Inspeksi terikat PO + Shipment. Rute `role:qc`; `show` boleh `qc,purchasing`; tidak ada policy. Jejak audit hanya `inspected_by`/`inspected_at` [I].
- *Gap:* tidak wajib (klien maupun server); tidak ada Copy dari PO; tanpa `min`/`max` sehingga nilai sangat besar melampaui `decimal(10,4)` dan menghasilkan 500 [I].
- *Solusi minimal:* di server, aturan per item dibangun dalam loop yang sudah ada: untuk setiap item, field relevan menurut shape `required|numeric|gt:0|max:999999.9999`, untuk status `ok` maupun `ng`; PR item tanpa shape (legacy) tidak mewajibkan dimensi. Validasi terjadi **sebelum** transaksi dan lock, urutan lock tidak berubah. Di klien, `required` pada field relevan + tombol "Salin dari PO" per baris: mengisi hanya field relevan baris itu dari atribut `data-spec-*`, memicu event `input`, tidak menyentuh switch OK/NG, field tetap dapat diedit, dengan teks `aria-live` "Nilai disalin dari PO. Periksa dan sesuaikan." Berat tidak ikut disalin dan tidak wajib (di luar daftar Anda; spesifikasi berat per pcs berbeda dari berat aktual total shipment [I]).
- *Dampak test:* 31 pemanggilan `qc.inspections.store` di `ShipmentDocumentsAndQcIntegrationTest`, `QcInspectionAttachmentCompensationTest`, `PoItemMaterialProgressTest`, `NotificationDeliveryTest` perlu payload dimensi; sediakan satu helper test (`tests/Support`) yang membangun dimensi dari PR item. Fixture shipment harus tetap memakai `AttachesShipmentMtc` bila flag MTC aktif.
- *Opsi migrasi (tidak default):* penanda nilai-disalin per item. J, Q7.

### C.4 Workstream D: Supplier Overview

**SUP-01 Purchasing Request Overview**
- *Existing:* `PurchaseRequisition::scopeVisibleToSupplier` [V `PurchaseRequisition.php:98-105`]: PR tanpa undangan (terlihat semua supplier), PR yang mengundang supplier ini (pivot `purchase_requisition_suppliers`), atau PR yang sudah dikuotasi supplier ini. `Supplier\QuotationController::period` [V L119-136] memakai scope itu plus `status in (submitted, bidding)` atau supplier punya quotation. Spesifikasi (material, shape, dimensi, qty, remark) hanya terlihat di dalam form `create` (`create.blade.php:916-993`). Rute berada di grup `role:supplier` + `supplier.scope:import` [V `web.php:668-681`]; parameter `{pr_id}` sudah terdaftar di `HASHED_PARAM_KEYS` [A].
- *Gap:* tidak ada halaman daftar lintas periode, read-only. `PrItem`/`PurchaseRequisition` tidak punya field estimasi pengiriman (hanya `Quotation.estimated_delivery`), jadi "estimasi pengiriman bila tersedia" hanya dapat berasal dari quotation milik supplier itu sendiri.
- *Risiko terbukti:* `isVisibleToSupplier` [V L107-117] berperilaku berbeda dari scope saat relasi `invitedSuppliers` sudah dimuat: mengabaikan klausa "sudah pernah menawar". Halaman baru wajib memakai scope (query) untuk daftar maupun detail, dan sebaiknya memperbaiki metode itu dengan test paritas.
- *Kebocoran yang dicegah:* daftar `invitedSuppliers`, quotation supplier lain, harga atau harga referensi, `reviewer_notes`. `pr_items.remark` dan `pr.notes` saat ini sudah terlihat oleh supplier yang diundang di form; halaman baru hanya boleh menampilkan subset dari apa yang form tampilkan (test "same-exposure").
- *Solusi minimal:* controller tipis baru (bukan menambah `QuotationController`); lihat D.11 dan E.D.

---

## D. Usulan UX dan kontrak data

**D.1 Urutan dimensi (satu sumber).** `PrItem::FIXED_DIMENSION_ORDER` = thickness, width, d_outer, d_inner, length dipakai form PR, preview PR, template PR (sudah), dan daftar kolom dimensi pada template/form/preview Supplier. Tidak ada daftar kolom dimensi baru di tempat lain.

**D.2 Preview import PR.** Kolom: Row, Material, HS code, Shape, Qty, KG/Unit, **Thickness, Width, OD, ID, Length**, Total KG, Remark. Sel dimensi tak relevan `-`. Kolom angka rata kanan dengan `ui-tabular-nums`. Di bawah tabel satu baris ringkasan "Total Qty n, Total KG x.xxxx" dari `summary.total_quantity` dan `summary.total_weight_kg` (server, BCMath skala 4). Tidak ada dekorasi tambahan; semua elemen sudah ada dalam pola tabel preview.

**D.3 Total KG.** Rumus tunggal: `total_kg = max(1, qty) × kg_per_unit`, dibulatkan 4 desimal half-up; grand total = jumlah. Form: kolom Total KG read-only, footer Qty/KG, diperbarui oleh event `adasi:pr-items-changed`. Server tetap menghitung ulang saat simpan.

**D.4 Template Supplier.** `pr_item_id | material_name | requested_dimension | available_qty | available_thickness | available_width | available_d_outer | available_d_inner | available_length | offered_weight_per_unit | price_per_kg | notes | availability`. Preview: PR item id, Material, Offer Qty, dimensi, KG/Unit, Price/kg, Notes, Availability. Parser: per heading, menerima urutan lama.

**D.5 Header komparasi Inter-Supplier.** Baris 1: nama perusahaan (`company_name ?: name`); baris 2: chip status; baris 3: "Supplier Estimate Delivery" + tanggal sesuai preferensi regional atau `-`. Tanpa kontak. Header lebih pendek (PIC dihapus) sehingga masalah offset baris kedua mengecil.

**D.6 Kontrak sticky.** Hanya `.ui-table-sticky` + `.ui-sticky-col`. Tangga z-index tetap: header 3, kolom sticky 1, pojok (kolom sticky di thead) 4. `--ui-table-sticky-head-row` diisi dari tinggi baris header pertama yang diukur. `scroll-padding-inline-start` sebesar lebar kolom sticky dan `scroll-padding-block-start` sebesar tinggi header. Area scroll fokusable dengan label (sudah via `x-ui.data-table scroll-label`) dan cincin fokus. Latar sel selalu token opak (`--md-surface`, `--md-surface-container-low`), pemisah berupa garis inset token (tanpa bayangan tetap). Layar sempit: kolom Material `min(40vw, 12rem)`, tidak dilepas dari sticky. Target sentuh kontrol tabel minimal 24 px (WCAG 2.5.8).

**D.7 Kontrak filter.** Parameter query: `material_name` (string exact), `shape` (Flat/Round/Hollow), `supplier_id` (hash), `currency`, `key_min`, `key_max`. Href tab dirender server membawa `material_name`, `shape`, `currency`. Tiap tab memvalidasi terhadap opsinya; nilai tak valid dibuang dan `filter_notice` (i18n, `role="status"`) menjelaskan. Pemuatan: `aria-busy="true"` pada wilayah hasil dan teks status ringkas; respons usang diabaikan lewat token urutan; empty state menyebut penyebab dan tindakan ("Tidak ada riwayat PO untuk material dan shape ini. Ubah rentang atau pilih material lain."); error state menyebut kegagalan dan tombol coba lagi. Semua teks lewat `lang/en` dan `lang/id`.

**D.8 Kontrak format mata uang.** `RegionalDisplayFormatter::currency($decimal, $code)` dan JS `AdasiCurrency.format`. Pemisah ikut preferensi pengguna; `system` diperlakukan sebagai `international` hanya pada fungsi ini (J, Q2). Tabel contoh:

| Preset | IDR 70000 | USD 1234.5 | JPY 450 |
|---|---|---|---|
| international (dan system) | `Rp70,000.00` | `US$1,234.50` | `JP¥450` |
| indonesian | `Rp70.000,00` | `US$1.234,50` | `JP¥450` |

Simbol dan desimal per mata uang di config baru `config/currency_display.php` (IDR `Rp`/2, USD `US$`/2, CNY `CN¥`/2, JPY `JP¥`/0); simbol menempel tanpa spasi; tanda minus sebelum simbol. Input string desimal; tanpa float.

**D.9 Salin dari PO (QC).** Tombol sekunder per baris, label "Salin dari PO", `type="button"`, target sentuh ≥ 40 px. Mengisi hanya field relevan; field tetap `required` dan dapat diedit; teks bantu per baris "Disalin dari PO, belum diukur" yang hilang begitu field diubah; pengumuman `aria-live="polite"`. Status OK/NG tidak berubah.

**D.10 Ringkasan fulfillment PO (read model).** `PurchaseOrder::fulfillmentSummary()` mengembalikan: `ordered_qty`, `shipped_qty` (SUBMITTED), `arrived_qty`, `accepted_qty`, `rejected_qty`, `awaiting_qc_qty`, `remaining_qty`, daftar shipment (nomor, status, qty, status QC, qty ditolak), dan `phase` turunan: `not_shipped | in_transit | partially_received | awaiting_qc | partially_accepted | rework | completed`. Dihitung hanya dari `itemFulfillmentStatus()` per quotation item. Status administratif tersimpan tidak berubah. UI: badge fase di samping badge status; tabel "Shipments dan QC" (semua shipment, bukan terakhir).

**D.11 Halaman Purchasing Request Overview (Supplier).** Rute `supplier.requests.index` dan `supplier.requests.show` (hash `{pr_id}`), grup `supplier.scope:import`. Daftar: PR, periode, tanggal, jumlah item, status penawaran saya (belum / draft / terkirim / revisi), tombol "Isi penawaran" ke `supplier.quotations.create`; filter pencarian nomor PR/material, periode, status penawaran saya; `paginate()`. Detail read-only: kartu logistik PR (port, incoterm; partial yang sudah ada), tabel item (Material, Shape, dimensi relevan, Qty, KG/Unit, Total KG, Remark) dan satu baris "Estimasi pengiriman saya" bila supplier punya quotation. Himpunan data dibatasi allowlist yang dibuktikan test same-exposure. Menu: entri sidebar + UUID `config/page_registry.php` + `quick_access`.

**D.12 Export Form (Supplier).** Tombol sekunder "Export Form" di area impor halaman form penawaran, berdampingan dengan "Download Template". Perilaku: mengumpulkan baris form saat ini (FormData), `POST` ke `supplier.quotations.export-form` via `fetch`, menerima berkas dan mengunduhnya; **form tidak dikirim, tidak disimpan, tidak berpindah halaman, input dan draft `localStorage` utuh**. Saat berjalan: tombol `aria-busy`, status "Menyiapkan file"; gagal: toast dengan alasan. Server: otorisasi PR (scope visibilitas + `importableRequisition`), abaikan key tak dikenal, normalisasi angka sebagai string desimal, batas baris = jumlah item PR, kolom permintaan diambil ulang dari DB (bukan dari klien), semua teks lewat `SpreadsheetCellSanitizer`, sel angka numerik dengan number format, kolom persis sama dengan template D.4 sehingga hasilnya dapat diimpor kembali. Respons `Content-Disposition: attachment`, `Cache-Control: no-store`, nama berkas aman (nomor PR disanitasi), throttle per user, CSRF. Tidak ada antrean, tidak ada `ExportJob`.

---

## E. Rencana implementasi teknis minimal

### E.A Workstream A: PR dan Excel (urutan: PUR-01 → PUR-02 → GEN-02)
- **File kemungkinan terpengaruh:** `resources/views/purchasing/pr/_import.blade.php`, `purchasing/pr/_form_totals.blade.php`, `_item_row.blade.php`, `create.blade.php`/`edit.blade.php`, `_material_shape_script.blade.php`, `app/Imports/PrItemsImport.php` (`summary.total_*`, `HEADINGS`), `app/Http/Controllers/Purchasing/PurchaseRequisitionController.php`, `app/Exports/QuotationImportTemplateExport.php`, `app/Imports/QuotationItemsImport.php`, `resources/views/supplier/quotations/create.blade.php` (preview), baru `resources/js/pr-totals-core.js` (+ penanda lazy di `resources/js/app.js` dan `tests/js/app-lazy-modules.test.mjs`), `lang/{en,id}/purchasing.php`, `lang/{en,id}/supplier.php`.
- **Kontrak dipertahankan:** parser per heading dan menerima urutan lama; all-or-nothing PR; `PrItem::total_weight`; hashid dan route name; selector JS lama (`prImportPreviewBody`, `#totalOfferedQty`, `#totalOfferedKg`, `#totalAmount`).
- **Regression test:** `MissionFiveImportTest`, `PurchaseRequisitionMaterialAutomationTest`, `MaterialCalculationTest`, `tests/js/*` terkait.
- **Rollback:** revert commit; tanpa migrasi.

### E.B Workstream B: Price Comparison (urutan: PUR-06 → PUR-03 → PUR-04 → PUR-07 → PUR-05)
- **Alasan urutan:** formatter (PUR-06) dipakai semua tampilan; PUR-03 memperpendek header sebelum PUR-04 mengukur offset; PUR-07 menetapkan kunci material+shape yang dipakai PUR-05.
- **File:** `PriceComparisonController.php`, `PurchaseHistoryPriceService.php`, `resources/views/purchasing/comparison/{_inter_supplier_content,_historical_content,_vs_best_content,_price_indicators,_evaluation_header,_scripts}.blade.php`, `resources/css/app.css` (blok sticky ~L3921), `resources/js/server-tabs.js` (hanya jika sinkron href berada di sana), `app/Services/RegionalDisplayFormatter.php`, `config/currency_display.php` (baru), `resources/views/layouts/app.blade.php` (payload JS), `app/Providers/AppServiceProvider.php` (daftar composer bila ada view baru), `lang/{en,id}/purchasing.php`.
- **Kontrak dipertahankan:** `data-price` kanonik; `_supplier_pic` tidak diubah; kurs snapshot dokumen vs benchmark analitik (satu `EvaluationContext` per permintaan, I-14); tanpa `COALESCE(rate,1)` (I-8); kontrak JSON `PriceComparisonServerTabsTest`; kunci cache `...:v2:{date}:{currency}` (tambah material+shape bila ikut memengaruhi ringkasan).
- **Regression test:** `PriceComparisonAnalyticsTest`, `PriceComparisonServerTabsTest`, `PriceComparisonPerformanceRegressionTest`, `PriceComparisonItemAwardTest`, `RegionalComparisonDisplayTest`, `PurchaseHistoryPriceTest`, `QuotationPresentationTest`, `tests/js/regional-comparison.test.mjs`, `server-tabs.test.mjs`.
- **Rollback:** revert; tanpa migrasi; `config/currency_display.php` yang tidak dibaca tidak berbahaya.

### E.C Workstream C: PO, Shipment, QC (urutan: QC-01 → PUR-08 read model → PUR-09 karakterisasi dan guard)
- **File:** `app/Http/Controllers/Qc/QcInspectionController.php`, `resources/views/qc/inspections/create.blade.php`, `app/Models/PurchaseOrder.php` (metode baru + `is_overdue`), `app/Services/MaterialProgressService.php`, `resources/views/purchasing/po/show.blade.php`, `resources/views/supplier/po/show.blade.php`, `PurchaseOrderController` dan `SupplierPurchaseOrderController` (kolom daftar), `PurchaseOrderDetailExport.php`, `StatusHelper.php`, `lang/{en,id}/{qc,purchasing,supplier}.php`. **Tidak disentuh:** `ShipmentService.php` (kecuali keputusan Q3 diubah).
- **Kontrak dipertahankan:** I-2, I-9, I-12, I-15; urutan lock; `reconcileOperationalStatus()` satu penulis; `SUBMIT_ATTEMPTS`.
- **Regression test (kedua mode flag MTC, seperti Gate 2):** `ShipmentAndPartialDeliveryTest`, `ShipmentDocumentsAndQcIntegrationTest`, `Qc/QcInspectionAttachmentCompensationTest`, `PoItemMaterialProgressTest`, `Procurement/ShipmentSubmissionConcurrencyTest`, `OrderedQuantityShipmentConcurrencyTest`, `ShipmentMtcTest`, `OrderedQuantityReadPathTest`, `PurchaseOrderOperationalStatusTest`, `SupplierDataIsolationTest`.
- **Rollback:** revert; tanpa migrasi.

### E.D Workstream D: Supplier Overview dan Export (urutan: SUP-01 → GEN-03 mode 2 → GEN-03 mode 1)
- **File SUP-01:** controller baru `app/Http/Controllers/Supplier/PurchasingRequestController.php`, view baru `resources/views/supplier/requests/{index,show}.blade.php`, `routes/web.php`, `config/page_registry.php`, `config/quick_access.php`, `resources/views/partials/sidebar.blade.php`, `PurchaseRequisition.php` (metode paritas visibilitas), `lang/{en,id}/{supplier,navigation}.php`. Saran: ekstrak filter status+visibilitas dari `QuotationController::period` menjadi satu scope bersama agar daftar dan overview tidak menyimpang.
- **File GEN-03:** `app/Exports/Advanced/Definitions/QuotationDefinition.php` (kolom dimensi opsional `default: false`), `QuotationsExport.php`/`UsesColumnCatalog.php` hanya untuk format angka kolom baru; baru `app/Http/Requests/Supplier/ExportQuotationFormRequest.php`, `app/Exports/QuotationFormExport.php`, method `exportForm` di `Supplier\QuotationController` atau controller tipis tersendiri, rute `supplier.quotations.export-form`, `resources/js/quotation-export-form.js` (lazy, penanda DOM), `lang/{en,id}/{supplier,js}.php`, sakelar `config/exports.php` `form_export_enabled` (default true).
- **Kontrak dipertahankan:** Download Template terpisah; GET legacy export tidak berubah; `AdvancedExportBaselineTest` (katalog); kolom baru tidak default; pipeline queue tidak dimodifikasi; isolasi supplier; `EnforceSupplierDomain`; throttle `LimitOperationalRequests`.
- **Regression test:** `SupplierDataIsolationTest`, `HashidUrlSecurityTest`, `PageRegistryTest`, `AdvancedExport*Test`, `DetailExportSecurityTest`, `MissionFiveImportTest`, `MissionFourExportTest`, `tests/js/advanced-export-*.test.mjs`, `app-lazy-modules.test.mjs`.
- **Rollback:** revert; matikan fitur Export Form dengan `form_export_enabled=false` + `config:clear` tanpa deploy kode; tanpa migrasi.

---

## F. Dependensi, risiko, dan penilaian keamanan

### F.1 Dependensi antar item

```
PUR-06/GEN-01 ──► PUR-03 ──► PUR-04
      │
      ├──────────────► PUR-07 ──► PUR-05
      └──► GEN-03 (format angka Excel)
PUR-01 ─► PUR-02          GEN-02 ──► GEN-03 (urutan kolom = template)
QC-01 ─► PUR-08 ─► PUR-09 (karakterisasi dulu)
SUP-01 (mandiri; rute di routes/web.php)
```

### F.2 Risiko

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Perubahan `QcInspectionController::store` memecah 31 pemanggilan test | Banyak test merah | Helper payload di `tests/Support`; jalankan suite QC dan shipment di dua mode flag MTC |
| Menyentuh `ShipmentService` secara tidak sengaja | Regresi over-shipment atau deadlock | Larangan keras di plan; suite konkurensi wajib hijau; diff tidak boleh memuat file itu |
| `routes/web.php` sedang diubah sesi lain | Konflik merge pada rute SUP-01/GEN-03 | Tambahkan rute paling akhir; kerja di worktree; rebase setelah sesi lain commit |
| Kolom katalog baru mengubah preset ekspor | Preset pengguna berubah | Kolom baru `default: false`; test baseline katalog |
| Format mata uang baru mengubah teks yang dikunci test | Test regional merah | Update test yang memang mengunci format lama, tidak melemahkan asersi kanonik `data-price` |
| Kontensi `adasi_portal_test` dengan sesi lain | Hasil test tidak valid | DB uji khusus + `dbguard.php` (K) |
| Sticky bergantung tinggi baris terukur | Glitch saat font atau zoom berubah | `ResizeObserver` + fallback; QA 1440/768/390, light/dark, zoom 200% |
| Perubahan `is_overdue` memengaruhi filter dan dasbor | Hitungan berubah | Karakterisasi dulu; cari semua pemakai accessor; laporkan perubahan angka |
| Impor ulang berkas Export Form | Baris tak sesuai | Kolom identik template; test round-trip |

### F.3 Penilaian keamanan (OWASP, backend-security-coder, laravel-security)

- **Export Form (A01, A03, A04, A05):** satu-satunya endpoint baru yang menerima data klien. Kontrol wajib: otorisasi server (role supplier, scope import, visibilitas PR lewat scope, bukan lewat `isVisibleToSupplier`), tidak pernah menerima `supplier_id` dari klien, whitelist field, batas jumlah baris dan ukuran payload, normalisasi numerik, `SpreadsheetCellSanitizer` untuk semua sel teks (injeksi formula `= + - @`, tab, newline), nama berkas disanitasi (nomor PR memuat `/`), `no-store`, throttle per user, CSRF, tanpa antrean dan tanpa penyimpanan, sakelar nonaktif. Tidak ada penulisan DB.
- **SUP-01 (A01 IDOR):** ID PR hashid + scope visibilitas pada daftar dan detail (`findOrFail` lewat scope, bukan `find` lalu cek); kolom allowlist; test dua supplier dan PR yang tidak diundang harus 404/403 sesuai pola proyek.
- **PUR-05 (A03):** `material_name` diperlakukan string, pencocokan exact berparameter, tanpa `LIKE` dan tanpa SQL mentah dari input; hash `supplier_id` tetap lewat `resolveHashedQueryModel`.
- **QC-01:** validasi ketat numerik berbatas; tidak ada perubahan otorisasi; menyalin dari PO tidak memberi hak tambahan dan tidak menulis status.
- **PUR-03:** mengurangi paparan kontak pada satu halaman; tidak ada penghapusan data.
- **Header dan CSP:** modul JS baru sebagai file lazy (bukan inline baru) agar tidak memperluas ketergantungan pada `unsafe-inline`.
- **Rate limiting:** gunakan limiter `LimitOperationalRequests` yang ada (dispatch 10/menit, PDF 60) atau `throttle:` eksplisit seperti `import-preview` (`throttle:15,1`).

---

## G. Acceptance criteria dan test matrix

### G.1 Acceptance criteria terukur

| ID | Kriteria (lulus bila) |
|---|---|
| PUR-01 | Preview memuat Thickness, Width, OD, ID, Length berurutan sama dengan `PrItem::FIXED_DIMENSION_ORDER` dan template; baris Flat menampilkan `-` pada OD dan ID; test markup memeriksa urutan heading |
| PUR-02 | Form: Total KG per baris dan grand total berubah segera pada ketikan, pemilihan material, dan baris hasil impor; preview: `summary.total_quantity` dan `summary.total_weight_kg` sama dengan jumlah baris dan dengan hasil simpan; paritas JS vs PHP pada ≥ 20 kasus acak termasuk qty kosong/0 |
| GEN-02 | Test kontrak: heading template Supplier = urutan form = kolom preview; file urutan lama tetap lolos preview; round-trip empat template lulus; tidak ada perubahan pada Local PO/GR |
| PUR-03 | Matrix memuat nama perusahaan dan estimasi pengiriman; HTML komparasi tanpa `data-supplier-pic`; halaman quotation Purchasing dan PO show tetap memuatnya; tanpa tambahan query (assert jumlah query) |
| PUR-04 | Browser: header tetap terlihat saat scroll vertikal; kolom Material tetap saat scroll horizontal; pojok tidak tertimpa; baris header kedua tidak menutup baris pertama; sama pada light/dark, 1440/768/390; tab ke area scroll mencapai sel tanpa tertutup; test markup tidak lagi memuat `.col-sticky-material` |
| PUR-05 | Mengubah material memperbarui tab aktif tanpa reload dan tab lain membawa material itu; material tak valid di tab lain dibuang dengan pemberitahuan; dua perubahan cepat: hasil akhir selalu milik perubahan terakhir (test JS dengan respons tak berurutan); mengganti mata uang setelah filter AJAX mempertahankan filter; back/forward memulihkan tab |
| PUR-06 / GEN-01 | Preset international: `Rp70,000.00`; indonesian: `Rp70.000,00`; USD dan JPY sesuai tabel D.8; seluruh titik render yang diinventarisasi memakai satu pintu; `data-price` tetap kanonik; Excel menyimpan sel numerik dengan number format; tidak ada cast float pada jalur format (test string 20+ digit tidak kehilangan presisi) |
| PUR-07 | Round berlabel Diameter, Flat Thickness, Hollow Outer Diameter; pergantian material atau shape mereset rentang; satu nama dengan dua shape tampil dua opsi; Vs Best tidak memakai riwayat shape lain; grafik dan tabel ikut berubah otomatis |
| PUR-08 | PO show memuat semua shipment dengan status dan qty QC masing-masing, qty ditolak terlihat, semua inspeksi terdaftar; `is_overdue` benar setelah kedatangan parsial; ringkasan sama dengan hasil `itemFulfillmentStatus` (asersi paritas); invarian lock tidak berubah |
| PUR-09 | Test karakterisasi menetapkan perilaku: PO tidak `completed` sebelum accepted ≥ ordered untuk setiap item; NG tidak dihitung; banyak shipment dan banyak material; kasus kosong tidak menghasilkan `completed`; cabang legacy tidak menyelesaikan tanpa QC (bila terbukti terjangkau, diperbaiki dengan test merah-hijau) |
| QC-01 | Server menolak (422) bila field relevan kosong, ≤ 0, atau melampaui batas, untuk OK maupun NG; field irrelevan diabaikan; klien menandai `required`; Salin dari PO hanya mengisi field relevan, tetap dapat diedit, tidak mengubah OK/NG; nilai awal kosong; akun non-QC tidak dapat menyimpan |
| SUP-01 | Supplier A hanya melihat PR miliknya (diundang, tanpa undangan, atau sudah menawar); PR milik supplier B → 404/403; respons tidak mengandung quotation lain, harga referensi, `invitedSuppliers`, `reviewer_notes`; tombol menuju form penawaran yang benar; fokus keyboard dan semantik tabel lulus; EN dan ID lengkap |
| GEN-03 | Mode 1: unduhan memuat semua baris form (termasuk belum disimpan), tanpa menyimpan apa pun (hitung baris DB sebelum dan sesudah), form dan draft utuh; sel berawalan `=`, `+`, `-`, `@` dinetralkan; sel angka bertipe numerik; paritas kolom dengan template; baris melebihi jumlah item ditolak; PR supplier lain ditolak. Mode 2: kolom dimensi opsional hadir, tidak default, hasil dapat diimpor |

### G.2 Test baru yang diusulkan

| File | Cakupan |
|---|---|
| `tests/Feature/Procurement/PrImportPreviewContractTest.php` | PUR-01, PUR-02 (summary), urutan |
| `tests/js/pr-totals-core.test.mjs` | PUR-02 paritas dan pembulatan |
| `tests/Feature/Procurement/ImportTemplateContractTest.php` | GEN-02 urutan dan round-trip |
| `tests/Feature/Procurement/ComparisonSupplierHeaderTest.php` | PUR-03 |
| `tests/Feature/Procurement/ComparisonStickyMarkupTest.php` | PUR-04 (markup); QA browser untuk visual |
| `tests/js/comparison-filters.test.mjs` | PUR-05 race, reset, state |
| `tests/Unit/RegionalCurrencyFormatterTest.php` + `tests/js/currency-format.test.mjs` | PUR-06 (file baru agar tidak bertabrakan dengan `RegionalDisplayFormatterTest` yang sedang diubah sesi lain) |
| `tests/Feature/Procurement/HistoricalShapeIsolationTest.php` | PUR-07 |
| `tests/Feature/Procurement/PoFulfillmentSummaryTest.php` | PUR-08 paritas dan tampilan |
| `tests/Feature/Procurement/PoCompletionCharacterizationTest.php` | PUR-09 |
| `tests/Feature/Qc/QcActualDimensionsTest.php` | QC-01 |
| `tests/Feature/Supplier/PurchasingRequestOverviewTest.php` | SUP-01 isolasi dan same-exposure |
| `tests/Feature/Supplier/QuotationFormExportTest.php` + `tests/js/quotation-export-form.test.mjs` | GEN-03 mode 1 |

### G.3 Verifikasi Gate 2 (rencana)

Targeted dulu per item (RED lalu GREEN), lalu suite terkait di dua mode flag MTC, lalu full suite serial di DB uji khusus. JS: `node --test tests/js/<file>.test.mjs` (berkas eksplisit atau glob bash). Statis: `php -l` file berubah, `vendor/bin/pint --test <file>`, `php artisan view:cache`, `php artisan route:list`, `npm.cmd run build`, `git diff --check`. QA browser dengan Chrome DevTools: sticky, filter, unduhan Export Form dan impor ulang, SUP-01 dengan dua akun supplier, keyboard-only, kontras (alat `check_contrast` antislop), zoom 200%. Hasil dilaporkan terpisah: dijalankan, gagal, terblokir, tidak dijalankan.

---

## H. Kompatibilitas dengan Gate 2 existing dan Shipment Hotfix

| Invarian / kontrak (dari Gate 1 Material Procurement) | Status pada rencana ini |
|---|---|
| I-1, I-2 ordered qty dan batas shipment | Tidak diubah. PUR-08 hanya membaca lewat resolver |
| I-9 minimal satu MTC VALIDATED sebelum submit; I-12 MTC tidak dihapus | Tidak disentuh |
| I-10 supplier hanya data sendiri | Diperkuat oleh test SUP-01 dan Export Form |
| I-14 satu tanggal evaluasi per permintaan | Dipertahankan di PUR-05/06/07 (`EvaluationContext`) |
| I-15 satu resolver ordered qty | `fulfillmentSummary()` tidak boleh menghitung ordered sendiri |
| I-6 snapshot dokumen tidak ditulis ulang; I-8 tanpa kurs implisit | Format mata uang murni tampilan; tanpa konversi baru |
| Hotfix `SUBMIT_ATTEMPTS = 3` dan urutan lock Shipment → PO → QuotationItem | Tidak disentuh; QC-01 menambah validasi **sebelum** transaksi |
| `reconcileOperationalStatus()` satu-satunya penulis `completed` | Dipertahankan |
| Flag `shipment_mtc_enabled` (prod true, phpunit false) | Suite adjacent dijalankan di kedua mode; fixture memakai `AttachesShipmentMtc` |
| Flag `ordered_quantity_write_enabled`, `allow_offer_surplus` (default false) | SUP-01 hanya menampilkan qty PR; tidak bergantung pada flag |
| Advanced Export (sesi lain, sudah committed) | Tidak ada modifikasi pipeline queue; hanya kolom katalog opsional; GET legacy tetap |
| Ledger copy `UserFacingCopyInventoryTest` | **Tidak disentuh.** Teks UI baru dapat menambah kandidat ledger; dilaporkan, tidak diregenerasi tanpa instruksi |

Rekomendasi tindak lanjut dari Gate 2 sebelumnya yang **tidak** termasuk cakupan ini: nav pill PO show overflow di 390 px; penghapusan view `purchasing/po/create.blade.php` yang tak terjangkau; `RegionalGaClaimDisplayTest` flaky. Karena PUR-08 mengubah halaman PO show, overflow 390 px di sana akan terlihat saat QA; dilaporkan, tidak diperbaiki kecuali Anda minta.

---

## I. Laporan kepemilikan file dan konflik antar-agent

### I.1 File sedang diubah sesi lain (jangan disentuh)

| File | Sifat perubahan [V] | Beririsan dengan |
|---|---|---|
| `routes/web.php` | Rute registrasi supplier dan lupa-password dibungkus `Route::prefix(PortalPath::prefix())` (52 baris berubah menurut `git diff --stat`) | **SUP-01 dan GEN-03 menambah rute: risiko konflik tertinggi** |
| `config/regional_display.php` | + profil angka `integer` | PUR-06: pakai config baru |
| `tests/Unit/RegionalDisplayFormatterTest.php` | + satu baris `['1250','integer','1.250']` | PUR-06: pakai file test baru |
| `resources/views/shipments/_mtc_section.blade.php` | profil `integer` → `plain` untuk ukuran KB | Tidak beririsan (PUR-08 memakai view PO, bukan partial ini) |
| `tests/Feature/RegionalShipmentDetailsTest.php` | + test MTC lintas profil angka | Tidak beririsan |
| `lang/en/js.php`, `lang/id/js.php` | + 1 key | PUR-05/06: gunakan `purchasing.js.*`; tambahkan key `js.*` hanya setelah sesi itu commit |
| `lang/en/auth.php`, `lang/id/auth.php`, `resources/js/password-assistance.js`, `resources/views/auth/forgot-password.blade.php`, `tests/Feature/Auth/PasswordAssistanceTest.php`, `tests/js/password-assistance.test.mjs` | Alur lupa password | Tidak beririsan |
| `app/Support/PortalPath.php`, `docs/guides/INFOR-STYLE-URLS.md`, `tests/Feature/PortalPathPrefixTest.php` | Prefix URL | Tidak beririsan |
| `AGENTS.md`, `CLAUDE.md`, `.gitattributes`, `.gitignore` | Dokumentasi dan konfigurasi proyek | Gate 2 **tidak mengedit**; perubahan fakta diusulkan sebagai lampiran untuk disetujui |
| Untracked: `.graphifyignore`, `.rnd`, `docs/audits/ANTISLOP-AUDIT-001-FORGOT-PASSWORD-20261026.md`, `docs/plans/HANDOFF-CODEX-ARCHITECTURE-REMEDIATION-20261023.md` | Milik sesi lain | Tidak disentuh |

### I.2 Kepemilikan per workstream di Gate 2 (satu pemilik per file)

| File bersama | Pemilik | Aturan |
|---|---|---|
| `lang/{en,id}/purchasing.php` | WS-B lalu WS-A | Diedit berurutan, tidak paralel |
| `lang/{en,id}/supplier.php` | WS-A lalu WS-D | Berurutan |
| `lang/{en,id}/qc.php` | WS-C | |
| `resources/css/app.css` | WS-B | Tambah/ubah hanya blok sticky |
| `resources/js/app.js` | WS-A lalu WS-D | Dua penanda lazy, berurutan; update `app-lazy-modules.test.mjs` |
| `routes/web.php` | WS-D, paling akhir | Satu perubahan kecil; rebase terhadap sesi lain |
| `app/Models/PurchaseOrder.php` | WS-C | |
| `PriceComparisonController.php` dan view `comparison/*` | WS-B | |

Tiga worktree lama (`adasi_integration`, `adasi_perf_stage1`, `adasi_pkp`) dan dua stash dari 5 September tidak relevan terhadap cakupan ini dan tidak disentuh. Checkpoint Gate 2 mencatat bahwa 4-5 sesi Claude pernah berbagi pohon dan DB uji; Gate 2 menghindarinya dengan worktree dan DB uji terpisah.

---

## J. Pertanyaan terbuka (tidak memblokir; default berlaku bila tidak dijawab)

| # | Pertanyaan | Mengapa tidak bisa diputuskan dari repo/keputusan | Default yang diterapkan Gate 2 |
|---|---|---|---|
| Q1 | PUR-09: "partial acceptance" berarti per baris shipment (7 dari 10 pcs lolos), atau per shipment/material? | Repo hanya mendukung ok/ng per baris shipment; pemisahan qty butuh migrasi `qc_items.accepted_qty/rejected_qty` | Tingkat baris shipment (sudah ada), **tanpa migrasi**. Jika butuh pemisahan qty, jadi item opsional dengan migrasi dan persetujuan sendiri |
| Q2 | PUR-06: apakah `Rp70,000.00` format tetap, atau tampilan preset `international`? Pemisah untuk `system`, simbol non-IDR (`US$`, `CN¥`, `JP¥`), dan desimal (IDR 2, JPY 0)? | Preset tidak menetapkan simbol/desimal; contoh bertabrakan dengan preset `indonesian` | Preferensi regional menang (keputusan interview); `system` = `international` hanya untuk fungsi mata uang; simbol dan desimal sesuai D.8 |
| Q3 | PUR-08: apakah klaim terbuka pada satu material boleh memblokir shipment material lain pada PO yang sama (perilaku sekarang)? | Keputusan alur bisnis; mengubahnya menyentuh gerbang `ShipmentService.php:347` | Tidak diubah |
| Q4 | PUR-07: apakah Vs Best hanya mensyaratkan material+shape yang sama, atau juga dimensi kunci identik? | Dimensi identik lebih sebanding tetapi dapat mengosongkan banyak baris | Material + shape; dimensi identik ditunda sebagai opsi |
| Q5 | SUP-01: dari mana "estimasi pengiriman" pada halaman Overview? | `PR`/`PrItem` tidak punya field itu | Dari quotation milik supplier itu sendiri bila ada; selain itu `-` |
| Q6 | GEN-02: "urutan kolom template Supplier" = urutan form Supplier? | Instruksi tidak menyebut urutan target | Mengikuti form (D.4); mudah diubah karena sumber tunggal |
| Q7 | QC-01: perlu mencatat bahwa nilai hasil Salin dari PO tidak diubah (migrasi kecil), dan berat wajib? | Menyangkut kebijakan audit QC | Tidak dicatat, berat opsional |
| Q8 | GEN-03: perluasan Export Form ke form PR Purchasing dan perluasan GEN-01 ke halaman lain (price history Supplier, daftar PO)? | Di luar cakupan terkonfirmasi | **Rekomendasi, bukan bagian Gate 2**: form PR (pola identik) dan retrofit format mata uang bertahap |

---

## K. Rencana eksekusi Gate 2 (tidak dijalankan)

**Persetujuan:** satu persetujuan atas dokumen ini mencakup seluruh langkah di bawah. Tidak ada persetujuan per misi. Berhenti hanya pada kondisi stop.

**K.1 Lingkungan**
- Worktree baru dari `c74386f1`, mis. `C:/laragon/www/adasi_portal_rev_20261027`, branch `feature/portal-revision-20261027`. Pohon `master` yang kotor tidak disentuh. `composer install` dan `npm.cmd install` dijalankan di worktree (diperbolehkan di Gate 2).
- DB uji khusus (mis. `adasi_rev1027_test`; nama harus berakhiran `_test` agar lolos guard `AppServiceProvider`), pembuatannya tercakup dalam persetujuan ini, ditambah `dbguard.php` (`C:/Users/BAHRIALGI/.claude/gate2-tools/dbguard.php`) agar proses tak dikenal terdeteksi. Tidak menjalankan `migrate` pada DB aplikasi.
- Tidak ada commit, push, merge, atau deploy; hasil dibiarkan di worktree sampai ada instruksi. Dokumen hasil: `docs/results/GATE-2-PORTAL-REVISION-2026-10-27.md`; usulan perubahan `AGENTS.md` sebagai lampiran (tidak diedit langsung).

**K.2 Langkah**
0. **Preflight:** ulangi pemeriksaan git, proses PHP, flag procurement; ukur baseline suite terkait (komparasi, impor/ekspor, shipment/QC/PO, regional, terjemahan) **sebelum** mengubah apa pun; catat kegagalan yang sudah ada. Muat skill eksekusi (search-first, laravel-tdd, mysql-patterns, laravel-verification, frontend-a11y).
1. **PUR-06/GEN-01:** formatter dan config; test RED lalu GREEN.
2. **WS-A:** PUR-01 → PUR-02 → GEN-02.
3. **WS-B:** PUR-03 → PUR-04 → PUR-07 → PUR-05.
4. **WS-C:** QC-01 → PUR-08 → PUR-09 (karakterisasi dulu).
5. **WS-D:** SUP-01 → GEN-03 mode 2 → GEN-03 mode 1.
6. **Integrasi:** full suite serial di DB uji khusus; JS; build; pint; view:cache; route:list; QA browser matriks G.3; `git diff --check`; audit diff (tidak ada perubahan pada `ShipmentService.php`, `config/regional_display.php`, ledger, `AGENTS.md`).
7. **Laporan:** dokumen hasil dengan status per ID (diverifikasi, gagal, terblokir, tidak dijalankan), daftar file, keputusan default J yang berlaku.

Tiap langkah memakai siklus RED → GREEN → refactor kecil, tinjau diri (adversarial: dua kali jalan, konkuren, pengguna tak berwenang, dataset kosong dan besar), dan antislop UI checklist untuk setiap permukaan UI.

**K.3 Kondisi stop (berhenti dan lapor)**
- Baseline target suite merah sebelum perubahan dan akar masalahnya bukan dari pekerjaan ini.
- Proses atau koneksi asing terdeteksi pada DB uji.
- Salah satu suite konkurensi shipment/MTC gagal.
- Diff menyentuh `ShipmentService.php` atau perlu migrasi yang tidak ada di rencana.
- Konflik dengan perubahan belum-commit sesi lain pada file yang akan diedit.

**K.4 Rollback:** seluruh pekerjaan kode saja, tanpa migrasi; buang worktree atau `git revert`; Export Form dapat dimatikan lewat `form_export_enabled=false`.

---

## L. Ringkasan akhir

### L.1 Status per revisi

| ID | Status | Rekomendasi tindakan Gate 2 |
|---|---|---|
| PUR-01 | PARTIALLY IMPLEMENTED | Tambah 5 kolom dimensi pada preview |
| PUR-02 | PARTIALLY IMPLEMENTED | Total KG per baris, total preview dari server, event footer |
| PUR-03 | PARTIALLY IMPLEMENTED | Nama perusahaan, hapus PIC dari komparasi saja |
| PUR-04 | EXISTING BUT BUGGY / MISSING | Perbaiki specificity dan offset; tambah pada Historical dan Vs Best |
| PUR-05 | PARTIALLY IMPLEMENTED | Kontrak filter bersama, penjaga race, state riwayat |
| PUR-06 | PARTIALLY IMPLEMENTED, NEEDS CLARIFICATION (default diterapkan) | Satu pintu format mata uang |
| PUR-07 | EXISTING BUT BUGGY | Reset rentang, kunci nama+shape, isolasi Vs Best, label |
| PUR-08 | PARTIALLY IMPLEMENTED | Read model fulfillment, daftar shipment, NG, `is_overdue` |
| PUR-09 | EXISTING AND WORKING (celah laten) | Test karakterisasi, perketat hanya jika terbukti |
| QC-01 | PARTIALLY IMPLEMENTED | Wajib per shape (server+klien), Salin dari PO |
| SUP-01 | PARTIALLY IMPLEMENTED | Halaman Overview read-only memakai scope visibilitas |
| GEN-01 | PARTIALLY IMPLEMENTED | Sama dengan PUR-06 |
| GEN-02 | PARTIALLY IMPLEMENTED | Sumber tunggal urutan template Supplier, test kontrak |
| GEN-03 | Mode 2 PARTIALLY, Mode 1 MISSING | Kolom dimensi opsional; Export Form sinkron |

### L.2 Urutan implementasi yang direkomendasikan

1. PUR-06 / GEN-01 (fondasi tampilan)
2. PUR-01, PUR-02, GEN-02 (perbaikan cepat, risiko rendah)
3. PUR-03, PUR-04, PUR-07, PUR-05 (Price Comparison)
4. QC-01, PUR-08, PUR-09 (area kritis, dikerjakan dengan perhatian penuh pada invarian)
5. SUP-01
6. GEN-03 (bergantung GEN-02 dan formatter)

### L.3 Keterbatasan audit ini

Tidak ada test, migrasi, atau browser yang dijalankan (dilarang di Gate 1). Klaim sticky dan perilaku DOM bertanda **[I]** perlu konfirmasi browser di Gate 2. Baseline test aktual belum diukur. Klaim bertanda **[A]** dibaca agent audit dengan `file:line`; yang paling menentukan keputusan sudah saya baca ulang sendiri.

**Status akhir: GATE 1 AUDIT COMPLETE, PENDING USER APPROVAL FOR GATE 2**
