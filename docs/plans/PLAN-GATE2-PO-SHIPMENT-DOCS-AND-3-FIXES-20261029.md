# Rencana: 3 perbaikan tambahan + Gate 2 (sinkronisasi dua arah dokumen PO ↔ Shipment)

## Context

Tim menutup Gate 1 (`GATE-1-PO-SHIPMENT-TWOWAY-DOCUMENT-SYNC-2026-10-29.md`, keputusan B terkunci) dan menambah tiga perbaikan kecil. Cakupan yang dipilih: **semuanya dalam satu branch**, tiga perbaikan dulu sebagai perubahan terpisah, lalu Gate 2 dalam tiga tahap. Persetujuan rencana ini menjadi otorisasi implementasi yang tidak diberikan dokumen Gate 1.

Status bukti: tiga eksplorasi read-only dan verifikasi file kunci di working tree (HEAD `08b1a9cd`, sama dengan snapshot dokumen). Belum ada yang dijalankan di browser; dua titik (penyebab "0" pada footer, tumpang tindih footer) berasal dari pembacaan CSS/JS dan **harus dibuktikan di langkah A0**.

Aturan kerja: antislop **DURING** (diumumkan sebelum edit pertama: `antislop active: during (session override).`), `ShipmentService.php` tidak boleh berubah (byte-identical), tidak ada `migrate` ke DB aplikasi, commit hanya atas instruksi Anda (working tree sudah punya perubahan tak terkait di `.agents/skills/antigravity-design-expert/SKILL.md`, `SsoInstallClient.php`, `Passport/Client.php`, `SsoAuthorizationFlowTest.php`; tidak ikut di-stage). `/ecc:multi-backend` tidak dipakai: runtime `ccg-workflow` tidak terpasang.

---

## Bagian A. Tiga perbaikan tambahan

### A0. Diagnosis sebelum mengubah (read-only, browser)
Reproduksi halaman Create PR di DB seed terpisah + `php artisan serve` (harness loopback pra-autentikasi dari memori proyek). Periksa: console error, `typeof window.AdasiPrTotals`, z-index terhitung footer vs sel sticky body, dan umur `public/build`. Tujuannya menjelaskan kenapa footer menampilkan **0** padahal tiap baris qty 10 (logika `pr-totals-core.js` sendiri benar). Perubahan A1 tetap dikerjakan apa pun hasilnya.

### A1. Footer total qty/kg di Create PR (dan Edit, karena partial yang sama) → server-side

**Akar masalah visual [Verified dari CSS]:** `app.css:3936-3941` membuat sel tfoot `position: sticky; bottom: 0; z-index: 2`, sedangkan sel body sticky-kiri `.pr-sticky-number` (z 7), `.pr-sticky-material` (z 6) dan `.pr-sticky-action` (z 4) di `_form_table_styles.blade.php:116-173` berada di atasnya. Teks petunjuk panjang berada di `<th colspan=4>` (`_form_totals.blade.php:4-7`) yang persis menutup kolom No+Material, jadi sisi kirinya terpotong; ini cocok dengan screenshot. Penyebab kedua: `<colgroup>` berisi 13 `<col>` padahal tabel 14 kolom (`create.blade.php:95-109`, `edit.blade.php:110-124`).

**Visual (CSS + markup, tanpa mengganti struktur):**
- `_form_totals.blade.php`: tfoot tetap `#prFormTotals` dengan jumlah colspan sama (dijaga `MissionFiveImportTest.php:1006-1013`); buang kelas `table-light` (hex Bootstrap, risiko dark mode); label sel hanya "Total".
- Petunjuk dipindah keluar tabel ke satu baris status `<p id="prFormTotalsStatus" role="note">` di bawah container scroll (create + edit). Baris ini juga tempat state error.
- `_form_table_styles.blade.php`: `#itemsTable.pr-items-table tfoot :is(th,td)` → `z-index: 9` (di atas body 7, di bawah thead 10/11 dan dropdown material 30), latar `var(--md-surface-container)`, `border-top: 2px solid var(--md-outline-variant)`, `ui-tabular-nums`, lebar minimum output agar angka tidak menggeser layout.
- Aksesibilitas (WCAG 2.4.11 Focus Not Obscured): `.pr-form-table-scroll { scroll-padding-bottom: <tinggi footer> }` agar input pada baris terakhir tidak tertutup footer sticky saat Tab.
- Tambah `<col>` ke-14 di create dan edit.
- Tidak ada gradient/glow/bayangan dekoratif, tidak ada `transition: all`; hanya token `--md-*`.

**Server-side (satu endpoint baru, tanpa menyimpan):**
- Route `POST purchasing/material-calculations/totals`, nama `purchasing.material-calculations.totals`, di grup `role:purchasing` yang sama dengan `preview` (`routes/web.php:521-523`), `throttle:120,1`. Perluas pola bucket `resources.search` di `LimitOperationalRequests.php:35` yang kini hanya cocok `*.material-calculations.preview`.
- `MaterialTotalsRequest` (otorisasi sama dengan `MaterialCalculationRequest`): `items` array, `max:500`, bidang per baris sama dengan `materialPreviewPayload` (`_material_shape_script.blade.php:174-198`). **Sengaja longgar per baris**: isian setengah jadi tidak membuat seluruh request 422; baris diberi status. Dikirim sebagai JSON + header `X-CSRF-TOKEN` (menghindari `max_input_vars`).
- Service baru `App\Services\Materials\PrTotalsCalculator`: muat `HsCodeRule::active()` dan material sekali; per baris panggil `PrItemProcessor::process($row, false, $actorId, null, $rules, $material)` (pola yang sama dengan `PrItemsImport.php:124`, tanpa persistensi); total baris `Money::multiply(weight_needed, quantity, 4)`, jumlah `Money::add`. Qty baris yang valid (integer ≥ 1) tetap dihitung walau bobot belum ada, menjaga perilaku saat ini.
- Respons: `{rows:{<index>:{unit_kg,total_kg,status}}, summary:{total_quantity,total_weight_kg}, formatted:{total_quantity,total_weight_kg,rows:{...}}}`. String `formatted` dibuat `RegionalDisplayFormatter::number()` (di-resolve dari container karena composer `$regionalFormatter` tidak mencakup create/edit); klien hanya menampilkan string, tanpa matematika.
- Klien: `resources/js/pr-totals-sync.js` menggantikan `pr-totals-core.js` (import statis di `app.js:16` tetap, karena skrip inline memakainya sinkron). `createTotalsSync({url,csrf,collectRows,fetchImpl,debounceMs:400,...})` dengan `AbortController`, penjaga respons usang, dan `aria-busy` pada output. `collectRows` memakai `materialPreviewPayload` yang sudah ada (tanpa `_token`) dengan kunci = indeks `items[N]` dari nama input. Pemicu: `input`/`change`, event `adasi:pr-items-changed` (sudah dipicu dari `renumberPrRows`, `renderMaterialPreview`, import apply), dan `MutationObserver` **hanya `childList` langsung pada body** (tanpa `subtree`/`attributes`, karena server kini menulis sel total baris dan akan membuat loop).
- State (antislop R-27): *loading* = `aria-busy` + output meredup 150 ms (nilai lama tidak dihapus); *error* = baris status "Total belum bisa diperbarui; menampilkan nilai terakhir" dan dicoba lagi pada perubahan berikutnya; *kosong* = 0. Edit page merender nilai awal dari `$pr->items` agar tidak berkedip 0.
- i18n (en + id): ubah `procurement.totals.preview_hint` (`lang/*/procurement.php:23`) menjadi "dihitung server, tidak perlu menyimpan"; tambah `totals.refresh_failed`, `totals.calculating`.
- Dihapus/diganti: `resources/js/pr-totals-core.js`, `tests/js/pr-totals-core.test.mjs` (diganti `pr-totals-sync.test.mjs`). `tests/Fixtures/pr-totals-cases.json` dipertahankan karena `MoneyTest` memakainya dan dipakai ulang untuk tes service.
- Tidak diubah: `PurchaseRequisitionItemSynchronizer`, store/update, `PrItemsImport`, endpoint `preview` (HS code + unit bobot per baris tetap dari situ).

**Tes:** `MaterialTotalsTest` baru (purchasing 200; admin/supplier/qc 403; tamu ditolak; nol penulisan `pr_items` lewat `DB::listen`; paritas dengan fixture; override manual; baris tanpa material tetap menghitung qty; >500 baris 422; payload cacat tidak 500; format en vs id), perluas `MissionFiveImportTest` (petunjuk di luar tfoot, jumlah `<col>` = jumlah `<th>`), `tests/js/pr-totals-sync.test.mjs` (debounce, abort, respons usang, error).

### A2. Tukar urutan tombol di halaman Purchasing Request supplier
`resources/views/supplier/requests/show.blade.php:31-41`: pindahkan blok Back (ghost, `arrow-left`) di atas blok aksi utama; aksi utama (`data-supplier-request-action`, label `create|continue|revise|view`) menjadi paling kanan. Urutan DOM = urutan visual (`page-header.blade.php:40-42` tidak memakai `order-*`), jadi satu pemindahan cukup, termasuk mobile. Bila `$action['url']` kosong hanya Back tampil. Berlaku hanya halaman ini (header supplier lain menaruh Back di akhir; tidak disentuh). Tes: tambah `assertSeeInOrder` (Back lalu aksi) di `SupplierPurchasingRequestOverviewTest`; tidak ada tes lama yang menjaga urutan.

### A3. Satukan "Download Template" dan "Export Form (entered data)"
Satu item menu "Download Template" yang sudah memuat data isian. Jalur POST `export-form` yang sudah menghasilkan workbook kompatibel import (13 heading identik, satu sheet, `pr_item_id` mentah divalidasi `Rule::in`) menjadi satu-satunya aksi.
- `supplier/quotations/_import_controls.blade.php:6-13`: hapus `<li>` Export Form; item pertama menjadi `<a href="…import-template" data-quotation-form-export data-form="#quotationForm" data-url="…export-form">` (href = cadangan tanpa JS/klik tengah). Label `supplier.copy.download_template`.
- **Keputusan Anda: fallback ke template kosong.** Pada 422 (isian tidak valid) atau gagal jaringan/5xx, `resources/js/quotation-form-export.js` mengunduh template kosong lewat GET `import-template` dan menampilkan toast **peringatan** yang menyebut isian bermasalah dan bahwa data tidak ikut terunduh (agar tidak diam-diam kehilangan data). `runExport` mengembalikan penanda fallback; tes JS `tests/js/quotation-form-export.test.mjs` diperbarui sesuai.
- `Supplier/QuotationController::exportForm` (`:275-291`): nama unduhan menjadi `template_import_quotation_<PR>.xlsx` (sama dengan template kosong); pertahankan `Cell::setValueBinder(new DefaultValueBinder)` di baris 288 dan `Cache-Control: no-store, private`. Default `fallbackName` di JS ikut diubah.
- Lang (en + id, dijaga `TranslationParityTest`): hapus `supplier.copy.export_form`; ganti `supplier.js.export_form_invalid`/`export_form_done` dengan kunci fallback baru.
- Dipertahankan: kedua route, kedua class export, throttle `15,1`, otorisasi `importableRequisition` (dijalankan sebelum validasi). Form kosong tetap menghasilkan template valid (sel-per-sel setara template kosong).
- Tidak diedit: `docs/results/GATE-2-PORTAL-REVISION-2026-10-27-REPORT.md:275` (dokumen hasil historis, akan basi); dicatat di laporan hasil baru.
- Tes: `MissionFiveImportTest:1038-1050` tetap hijau; `QuotationFormExportTest` (ubah nama file bila ada); tambah tes tampilan (hanya satu item template, tanpa teks "Export Form").

---

## Bagian B. Gate 2: PO → Shipment dua arah (keputusan B)

**Prinsip:** `shipment_documents` adalah sumber kebenaran; Purchasing memilih eksplisit Shipment mana yang diubah; agregat PO adalah proyeksi baca-saja; `app/Services/ShipmentService.php` tidak disentuh (sha1 `b20bf4f2e62b2b5bfa7eb3a4020765fd3661908d`, dijaga `git diff --exit-code HEAD -- app/Services/ShipmentService.php`).

### Penyimpangan dari dokumen Gate 1 (hasil verifikasi kode, perlu Anda ketahui)
1. Dokumen menyebut daftar **ID ShipmentDocument**. `ShipmentDocument` tidak memakai hashid, jadi ID mentah melanggar aturan hashid. Rencana ini memakai **hashid Shipment** (di-decode `HashidCodec::decode`, menolak angka mentah) + `doc_type` pada URL; baris dokumen ditemukan lewat kunci unik `(shipment_id, doc_type)`.
2. Dokumen menulis urutan lock "Shipment → ShipmentDocument → PO doc". Faktanya baris `po_documents` **tidak pernah di-lock** hari ini (`syncFromShipmentDocument` = `firstOrCreate` + bandingkan + update tanpa lock, id PO tak terurut). Itu risiko deadlock/lost update yang nyata dan diperbaiki di sini.
3. "Guard transisi bersama" ke halaman review Shipment **tidak** diterapkan: dua tes (`ShipmentDocumentsAndQcIntegrationTest`) memverifikasi dokumen tanpa lampiran lewat rute itu, jadi rute Shipment tetap longgar. Dampaknya diukur proyeksi lewat `evidence_gap_count`.
4. Dokumen menyebut Purchasing/Admin; grup route hanya `role:purchasing`, admin tidak bisa mencapainya (sama seperti sekarang).
5. Hanya Shipment berstatus `submitted`/`arrived` yang dapat diubah dari halaman PO. Draft dan cancelled ditampilkan tetapi nonaktif; sebelum ini draft/cancelled ikut menyebar ke PO (sudah ada, di luar cakupan; counter baru diletakkan di samping widget 4/4 lama).

### Keputusan produk yang saya ambil sebagai default (ubah saat menyetujui bila tidak cocok)
| # | Pertanyaan | Default |
|---|---|---|
| 1 | PO shipment-aware tanpa Shipment aktif | Status manual PO diblokir, tampil "menunggu Shipment" |
| 2 | Lampiran legacy (`HISTORICAL_UNVERIFIED`) | Boleh dipakai verified/done, diberi tanda "berkas lama" (jawaban Anda: VALIDATED + legacy) |
| 3 | Hitungan proyeksi | Berbasis status mentah + peringatan `evidence_gap_count` |
| 4 | Pemicu notifikasi `all_completed` jalur baru | Berdasarkan proyeksi (semua Shipment aktif × 4 tipe minimal received) |
| 5 | Jejak audit alasan | `shipment_documents.notes` + `Log::info` terstruktur, **tanpa migrasi** |

### B1. Tahap 1: inti backend
- `App\Services\PoShipmentDocumentStatusService::updateSelected(PurchaseOrder $po, string $docType, array $rows, string $target, ?string $reason, User $actor): array`.
- Urutan validasi: (di luar transaksi, tanpa lock) role middleware → bentuk FormRequest (hashid via `HashidCodec`, enum, 1-50 baris, Shipment distinct) → `PurchaseOrder::findOrFail`. Lalu `DB::transaction(fn, 3)` (retry deadlock) dengan **lock dahulu sebelum membaca**: (1) lock `shipments` urut id naik; (2) `Gate::forUser($actor)->authorize('reviewDocument', $shipment)` per Shipment, lalu lock `shipment_documents` urut `shipment_id`; (3) baca `shipment_items`, tolak `not_linked` bila PO bukan salah satu `purchase_order_id`, kumpulkan semua PO terdampak; (4) lock `po_documents` `(po_id, doc_type)` urut naik, `firstOrCreate` bila baris hilang; (5) validasi per baris.
- Aturan per baris: `current == target` → `skipped` (tanpa tulis/notifikasi); status Shipment harus `submitted|arrived`; `current != expected_status` → `stale_status`; untuk verified/done, `latest_attachment.id != expected_attachment` → `stale_attachment` (menutup celah re-upload saat status tetap `received`); target dengan rank tidak lebih tinggi (downgrade/pindah sejajar) **wajib alasan**; verified/done wajib lampiran terbaru (baca dengan `lockForUpdate()` + `orderByDesc('created_at')->orderByDesc('id')`, bukan relasi `latestAttachment`) berklasifikasi VALIDATED/HISTORICAL_UNVERIFIED lewat `FileAccessGuard::classification()` (bukan `assertReadable`, yang melempar 404). Status sejajar (issued/processing, verified/done) tidak pernah ditimpa berdasarkan rank saja.
- Himpunan target per tipe (konstanta di service; tes memastikan sama dengan `$docConfig` di view): invoice/packing_list = pending, received, verified; bl = pending, issued, done; form_e = pending, processing, done.
- **Semua-atau-tidak-sama-sekali:** bila ada baris stale/ditolak, lempar `PoShipmentDocumentBatchRefused extends BusinessRuleException` (membawa `code` dan `rows`) sebelum penulisan apa pun; baris valid dilaporkan `not_applied`. Controller memetakan 409 (ada stale) atau 422.
- Tulis: `update(status, notes)` (baris audit berstempel waktu + aktor bila ada alasan) lalu `PoDocument::syncFromShipmentDocument` (tetap forward-only, tanda tangan publik tidak berubah). Setelah commit: notifikasi.
- `PoDocument.php` (tidak dilindungi): urutkan id PO dan kunci baris di dalam `syncFromShipmentDocument` agar jalur upload yang dilindungi ikut tertib naik; pre-lock di service tetap diperlukan karena satu batch memanggil sync berkali-kali.
- Notifikasi (jalur baru saja): pakai event yang sudah terdaftar `document.status_updated` dan `document.all_completed` (`config/notification_preferences.php:102-120`), satu notifikasi per PO terdampak × tipe dokumen × batch dengan jumlah; `eventKey` memuat UUID per-batch (memperbaiki penekanan kunci konstan `all_completed` di jalur baru). Kunci `documents.php` baru di en + id.
- Proyeksi baca-saja `PurchaseOrder::customsDocumentationProgress()` lewat kalkulator bersama (`app/Support/Procurement/`), dipakai juga oleh service untuk cek sebelum/sesudah: universe = Shipment unik tidak soft-deleted dengan status `submitted|arrived`; draft/cancelled dikeluarkan dari penyebut tetapi dihitung/ditampilkan; baris dokumen hilang = pending + `missing_row`; counter `received_count`, `verified_count`, `in_progress_count`, `pending_count`, `files_present`, `evidence_gap_count`; flag `all_received`, `all_verified`, `partial_*`, `waiting`. BL/Form-E: `received` terhitung received tetapi baru verified saat `done`. Shipment baru ditambahkan belakangan menaikkan penyebut dan label kembali ke Partial tanpa menulis apa pun. `customsDocumentationSummary()` dan `reconcileCustomsDocumentationStatus()` tidak diubah (menjaga tes pure-read dan "PO show GET menulis").
- Rencana query tanpa N+1: perluas eager load `PurchaseOrderController.php:245` ke `shipmentItems.shipment.documents.latestAttachment.fileInspection`, tambah `shipmentItems.shipment.items.purchaseOrder:id,po_number` (peringatan multi-PO), satu lookup `file_security_cutovers`; jalur cepat bukti tanpa query per baris, dengan tes kesepakatan terhadap `FileAccessGuard::classification` untuk empat keadaan.
- **Tanpa migrasi** (tidak ada unique `po_documents(po_id,doc_type)` hari ini). Preflight baca-saja pada snapshot produksi: `SELECT po_id, doc_type, COUNT(*) FROM po_documents GROUP BY 1,2 HAVING COUNT(*)>1`, plus `SHOW CREATE TABLE` untuk presisi timestamp. Bila duplikat ada, migrasi unique terpisah (dedupe ditinjau → `ADD UNIQUE … ALGORITHM=INPLACE, LOCK=NONE`, valid di MySQL 8 dan MariaDB 11.4, `down()` = `dropUnique`) dengan rehearsal di staging hasil restore.

### B2. Tahap 2: route, controller, kompatibilitas, uji konkurensi
- Route baru di grup `role:purchasing` dekat `routes/web.php:543`: `PUT purchasing/purchase-orders/{id}/documents/{doc_type}/shipments`, nama `purchasing.purchase-orders.documents.shipments.update`, `throttle:30,1`, `->whereIn('doc_type', ShipmentDocument::DOC_TYPES)`. `{id}` = hashid PO (decoded middleware); `doc_type` bukan kunci hashed; nama tidak berawalan `purchasing.po-documents.` (yang melewati dekode) dan tidak cocok bucket `*.documents.upload` di `LimitOperationalRequests`. `DecodeHashids.php` tidak diubah.
- Body: `{target_status, reason, rows:[{shipment:<hashid>, expected_status, expected_attachment:<hashid|null>}]}`. Respons sukses: `{success, message, counts, rows[{shipment,shipment_number,result,from,to}], affected_pos, rows_state, projection}`; penolakan: `{success:false, code, message, rows[{... result: stale|rejected|not_applied, reason_code, current_status, current_attachment}], rows_state}` agar dialog dapat dirender ulang tanpa menebak.
- `Purchasing\PoShipmentDocumentStatusController` (invokable, tipis), `UpdatePoShipmentDocumentStatusRequest`.
- **Guard legacy:** `PoDocumentController::update` melempar `BusinessRuleException` bila `! $po->isLegacyArrivalEligible()` (PO punya award/shipment item). `PoDocumentStatusValidationTest` memakai PO tanpa award/item sehingga tetap hijau.
- **Tes yang harus diubah:** `NotificationDeliveryTest.php:352-366` (menembak PUT lama ke PO shipment-aware, tepat yang dilarang keputusan B) dipindah ke route baru: pending→pending = skipped tanpa notifikasi; pending→received = notifikasi ke pembuat PO dengan kategori document, payload `document_id` + `shipment_document_ids`.
- **Tes konkurensi MySQL dua koneksi** (`tests/Support/po-shipment-document-worker.php`, meniru `shipment-submit-concurrency-worker.php`, DB `adasi_portal_test`, `DatabaseTruncation`, 4 putaran, disk private ke direktori sementara): re-upload vs verifikasi PO (invarian: lampiran terbaru dokumen verified = yang dilihat Purchasing), upload pertama vs PO `received`, dua aksi PO pada dokumen sama (tepat satu `updated`, satu `stale`), batch {S1,S2} vs {S2,S1} dan upload vs batch pada PO dengan urutan item terbalik (tidak boleh ada `deadlock`), dua PO berbagi satu Shipment. Tambah tes urutan lock lewat `DB::listen` (`shipments` → `shipment_documents` → `po_documents`, naik).

### B3. Tahap 3: UI halaman PO Purchasing (`resources/views/purchasing/po/show.blade.php`)
- Untuk PO shipment-aware: tombol per kartu (kelas baru, bukan `.btn-update-doc`) membuka **`x-ui.dialog`** (ukuran 2xl; focus trap Alpine bawaan) via `$dispatch('open-ui-dialog', …)`. Isi: daftar Shipment tertaut (nomor, status Shipment, status dokumen, keberadaan berkas + referensi terbaru + tautan terbatas izin, alasan kelayakan), checkbox **default kosong**, select target **tanpa nilai default** (placeholder; Simpan nonaktif sampai target + minimal satu baris dipilih; menutup jebakan select jatuh ke `pending` untuk BL/Form-E), kolom alasan (wajib saat downgrade/sejajar), ringkasan konfirmasi, tombol "Update selected Shipment document(s)" (tidak ada "Update all"), counter "X/Y received" dan "X/Y verified", peringatan Shipment dipakai beberapa PO (nomor PO lain ditampilkan), area hasil `aria-live` (updated/skipped/stale/rejected), indikator "berkas lama" untuk legacy.
- PO legacy tetap memakai modal + `PUT po-documents/{id}` lama; widget 4/4 lama tetap, status manual PO ditampilkan terpisah sebagai "PO legacy/manual" dan tidak pernah diturunkan diam-diam.
- Setelah sukses: toast + muat ulang halaman (menjaga widget lama dan `reconcile` konsisten; tanpa drift klien). Setelah penolakan: dialog dirender ulang dari `rows_state`, pilihan pengguna dipertahankan bila masih valid.
- Kontrak UI: hanya komponen `x-ui.*` dan token `--md-*` (tanpa hex), dark mode, kunci en + id, tanggal lewat `RegionalDisplayFormatter` (bukan `BusinessTime::format`), teks JS via `AdasiI18n`. Pindai statis yang menjaga file ini tetap lulus: `data-pdf-confirm` (`CustomAdasiAlertTest`), `regionalFormatter` (`RegionalFinalClosureSweepTest`), `@json(__('common.feedback.success'))` (`TranslationContentTest`). Antislop: tanpa dekorasi; state loading/empty/error dialog dijelaskan penyebab + langkah berikutnya.
- Aksesibilitas: fokus kembali ke tombol pemicu saat dialog ditutup, Escape menutup, urutan Tab logis, label checkbox memuat nomor Shipment + status, hasil diumumkan lewat `aria-live`, target klik ≥ 24px, kontras token diperiksa.
- Halaman supplier PO tidak diubah (GET tetap nol-tulis); proyeksi tidak ditambahkan ke sana tanpa tes `DB::listen` nol-tulis.

### B4. Tahap 4: regresi penuh
`composer test` serial, build, uji browser terang/gelap + keyboard saja, `git diff --exit-code HEAD -- app/Services/ShipmentService.php`, laporan hasil di `docs/results/` (bukan root).

---

## Berkas
**Dibuat:** `PrTotalsCalculator`, `MaterialTotalsRequest`, `pr-totals-sync.js`, `PoShipmentDocumentStatusService`, `PoShipmentDocumentBatchRefused`, `UpdatePoShipmentDocumentStatusRequest`, `Purchasing/PoShipmentDocumentStatusController`, kalkulator proyeksi di `app/Support/Procurement/`, tes baru (`MaterialTotalsTest`, `PoShipmentDocument*Test`, `pr-totals-sync.test.mjs`, worker konkurensi), laporan hasil di `docs/results/`.
**Diubah:** `routes/web.php`, `LimitOperationalRequests`, `MaterialCalculationController`, `app/Models/PoDocument.php`, `PurchaseOrder.php`, `PurchaseOrderController`, `PoDocumentController` (guard saja), view `pr/_form_totals`, `pr/_form_table_styles`, `pr/create|edit`, `supplier/requests/show`, `supplier/quotations/_import_controls`, `purchasing/po/show`, `QuotationController::exportForm` (nama file), `quotation-form-export.js`, `app.js` (satu import), `lang/en|id` (`procurement`, `supplier`, `documents`, `purchasing`), `NotificationDeliveryTest`, `MissionFiveImportTest`, `quotation-form-export.test.mjs`.
**Tidak disentuh:** `ShipmentService.php`, `ShipmentController` dan route review Shipment, `DecodeHashids.php`, `PrItemsImport`, store/update PR.

## Verifikasi (dijalankan hanya setelah persetujuan)
Per tahap, terkecil dulu: `php -l`, `php vendor/bin/pint --test <file>`, `php artisan test --filter=<Nama>`, `TestingEnvironmentDatabaseSafetyTest` sebelum feature test, `php artisan view:cache`, `php artisan route:list --no-ansi`, `npm.cmd run build`, `node --test tests/js/<file>.test.mjs` (file eksplisit), `TranslationParityTest`/`TranslationContentTest`/`FrontendAssetLoadingTest`, tes hashid (`HashidUrlSecurityTest`), `git diff --check`, `graphify update .`. Satu suite pada satu waktu (DB test bersama); periksa proses `artisan test` lain dulu. Browser: DB seed terpisah + `artisan serve`, cek Create PR (footer tidak menimpa baris, total mengikuti edit, qty/kg konsisten dengan hasil simpan, 1440×900 dan 390×844, terang/gelap, Tab ke baris terakhir tidak tertutup), halaman supplier (urutan tombol), dropdown Import Data (satu "Download Template", unduhan terisi data, fallback saat isian tidak valid), dialog PO (keyboard, fokus, hasil per baris). Laporan akhir memisahkan: diverifikasi lewat eksekusi / diinspeksi / inferensi / belum diverifikasi (CI MariaDB, staging, produksi, G-PDF/T-1 tetap gerbang rilis terpisah). Gerbang antislop (R-35) dilaporkan dengan daftar klik per elemen.

## Risiko utama dan mitigasi
| Risiko | Mitigasi |
|---|---|
| Deadlock/lost update `po_documents` | Pre-lock terurut di service + sort/lock di `syncFromShipmentDocument`, retry 3×, tes worker dua koneksi |
| Verifikasi berkas yang diganti supplier | `expected_attachment` + baca lampiran di bawah lock |
| Hashid Shipment dipalsukan / lintas PO | Codec menolak angka mentah; `not_linked` di bawah lock menolak seluruh batch tanpa menulis |
| Notifikasi berulang | Lewati baris `skipped`; kunci per-batch; satu notifikasi per PO × tipe |
| Footer menutupi fokus keyboard | `scroll-padding-bottom` + z-index 9 + uji Tab di browser |
| Template fallback menyesatkan | Toast peringatan eksplisit bahwa data tidak ikut terunduh |
| Totals server membanjiri | Debounce 400 ms + abort + `throttle:120,1` + `items` maks 500 |
| Tes lama yang mengunci perilaku | Hanya `NotificationDeliveryTest` yang diubah (alasan di B2); sisanya hijau tanpa perubahan |

## Belum terbukti (harus dibuktikan saat eksekusi)
Penyebab "0" pada footer (A0); presisi timestamp tabel (`SHOW CREATE TABLE`); ada tidaknya duplikat `po_documents` di produksi; perilaku nyata kedua jalur upload/PO di MariaDB 11.4 (hanya CI `deep`); isolasi snapshot bila produksi mengaktifkan `innodb_snapshot_isolation`.
