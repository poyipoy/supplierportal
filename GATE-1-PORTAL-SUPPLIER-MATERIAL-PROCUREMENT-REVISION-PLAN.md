# Gate 1 — Material Procurement Supplier Portal: Implementation Plan

**Status:** PLANNING COMPLETE — PENDING APPROVAL FOR IMPLEMENTATION (GATE 2)  
**Tanggal penyusunan:** 14 Oktober 2026  
**Repository:** `poyipoy/supplierportal`  
**Base branch:** master
**Working branch:** feature/material-procurement-revision-gate2
**Cakupan:** Purchasing, Supplier Import, Admin; Material Procurement (PR, Quotation, Award, PO, Shipment, Price Comparison, Master Data)  
**Sumber bisnis:** `Sarapan Algi.docx`, screenshot Infor LN dari user, dan **35 keputusan interview**.  
**Sumber teknis:** pembacaan kode GitHub target branch selama sesi perencanaan. Tidak ada perubahan kode, database, atau commit yang dilakukan dalam sesi ini.

> **Dokumen ini adalah rencana implementasi, bukan hasil implementasi.** Semua usulan nama tabel, field, layanan, dan migration di bawah merupakan target desain untuk diverifikasi pada Gate 2. Agen tidak boleh menganggap pemeriksaan statis GitHub sebagai pengujian runtime.

## 1. Tujuan dan ruang lingkup

Menyelaraskan Material Procurement terhadap kebutuhan user: kebebasan Supplier mengajukan quantity dan dimensi yang berbeda dari PR, pilihan quantity PO oleh Purchasing, transparansi perhitungan KG/harga, import Excel yang benar-benar atomik, konsistensi tampilan Purchasing–Supplier, proses MTC pada Shipment, evaluasi harga dari histori PO dan Master Harga, pengelolaan kurs yang memanfaatkan **Exchange Rate Benchmark existing**, serta akses Purchasing ke dua master yang selama ini berada dalam area Admin.

Tidak termasuk: penggantian total desain Infor, penambahan field Requester/Department/Reference/Workflow Status hanya karena muncul pada screenshot, pembuatan master kurs baru, rewrite histori PO/Quotation, migrasi permanen semua attachment MTC lama, atau pengubahan kurs transaksi historis.

## 2. Matriks keputusan hasil interview (LOCKED)

| No. | Keputusan final | Lingkup |
|---:|---|---|
| 01 | Supplier dapat menawarkan quantity di atas PR; Purchasing dapat memilih quantity PO dari 1 hingga quantity ditawarkan. | Quotation/Award/PO |
| 02 | Quantity PR tetap orisinal; quantity PO dan selisih terhadap PR ditampilkan terpisah. | PR/PO/report |
| 03 | Sisa quantity penawaran tidak otomatis dipesan; PO saat ini mengunci jumlah pesanan dan Shipment tidak boleh melebihi quantity PO. | Award/PO/Shipment |
| 04 | Dimensi Supplier berbeda dari PR diindikasikan per baris, ditampilkan selisihnya, dan membutuhkan konfirmasi eksplisit Purchasing sebelum PO. | Quotation/Award/PO |
| 05 | Import Excel menggunakan **all-or-nothing**: satu baris error menyebabkan seluruh file ditolak, semua error tetap dilaporkan. | PR/Quotation import |
| 06 | Baris total Quantity dan KG pada PR; total Quantity, KG dan Harga pada form/detail Quotation Purchasing–Supplier yang relevan. | UI/calculation |
| 07 | Total KG PR = Qty PR × KG/unit PR; Quotation = Qty offered × KG/unit offered; PO = Qty ordered × KG/unit approved offer. Shipment actual KG terpisah. | Domain totals |
| 08 | Urutan kolom template Excel: Material, Shape, Quantity, Thickness, **Width**, Outer Diameter, Inner Diameter, Length, Remark. | PR template |
| 09 | Sticky header saat scroll vertikal; sticky kolom Material saat scroll horizontal pada tabel relevan. | UI |
| 10 | Contact Person adalah PIC Supplier, terlihat oleh Purchasing, dengan sumber data profil Supplier. | Supplier profile/quotation/PO |
| 11 | Port of Discharge dan Incoterms dimasukkan di PR lalu dipropagasikan ke Quotation dan PO. Screenshot Infor adalah acuan tata letak field, bukan penambahan semua field Infor. | PR/Quotation/PO |
| 12 | Dua field logistik boleh kosong saat Draft PR, **wajib saat Submit PR**. | PR validation |
| 13 | Opsi berbasis konfigurasi: Port = Tanjung Priok/Tanjung Perak; Incoterms = CFR/CIF/FOB; tidak membuat menu master baru. | Config/UI |
| 14 | Detail Quotation Purchasing meniru struktur form Supplier secara **read-only**, menampilkan indikator Est Weight, quantity match/surplus/shortage, different specification; garis pembatas jelas. | Purchasing UI |
| 15 | Grafik rata-rata harga berasal dari histori **PO**, bukan semua quotation. | Price Comparison |
| 16 | Rata-rata harga = weighted average menggunakan total nilai dan total KG histori PO yang eligible. | Analytics |
| 17 | Price History mendukung Exact Match maupun rentang min–max Thickness/Diameter. | Filters |
| 18 | Flat menggunakan Thickness; Round dan Hollow menggunakan **Outer Diameter** saja untuk filter; Inner Diameter tidak menjadi kriteria filter. | Filters |
| 19 | Tampilkan Best Current Offer, Historical Best Price, dan Historical Average Price secara terpisah. | Comparison |
| 20 | Purchasing dapat memilih IDR/USD/JPY/CNY sebagai mata uang pembanding; mata uang asli tetap terlihat. | FX/Comparison |
| 21 | MTC diunggah pada **level Shipment**, tidak dihubungkan ke item individual; satu Shipment mendukung ≥1 file MTC. | Shipment documents |
| 22 | MTC opsional pada Draft Shipment dan **wajib minimal satu attachment valid saat Submit Shipment**. | Shipment validation |
| 23 | Ganti label Supplier Estimated Ready / Dispatch Date menjadi **Supplier Estimate Delivery**; tidak mengubah arti/tabel/validasi tanggal. | UI labels |
| 24 | Urutan Supplier Quotation: Procurement Context → Commercial Terms and Logistics → Status and Follow-up → Detail Quotation. | Supplier UI |
| 25 | Master Harga referensi diinput manual oleh Admin/Purchasing dan tidak mengubah nilai transaksi. | Master price |
| 26 | Master Harga berlaku umum semua Supplier, berdasarkan material, shape/dimensi, dan mata uang. | Master price |
| 27 | Master Harga memiliki valid_from wajib, valid_until opsional, versi histori, audit; periode yang overlap dilarang. | Master price |
| 28 | Purchasing mendapat Full Access ke Master Material dan HS Code seperti Admin **untuk kedua modul itu saja**, termasuk kemampuan hapus sesuai sistem; integrity guard tetap wajib. | ACL |
| 29 | Master Reference Price muncul pada Price Comparison dan Detail Quotation Purchasing; tampilkan nominal & persen selisih; tidak mengubah harga penawaran. | Master/Comparison |
| 30 | Cocokkan Master Reference Price memakai spesifikasi aktual **penawaran Supplier**, bukan spesifikasi PR. | Pricing lookup |
| 31 | Master Reference Price memakai harga yang berlaku **saat evaluasi Purchasing**; snapshot referensi saat persetujuan PO dipertahankan untuk audit. | Pricing/audit |
| 32 | Jika harga master tak ditemukan, tampilkan Not Available beserta alasan; Purchasing tetap boleh membuat PO, delta master tidak dihitung. | UX/validation |
| 33 | Rentang dimensi Master Harga yang overlap untuk kombinasi material, shape, currency, dan periode aktif yang sama **ditolak**. | Master validation |
| 34 | Konversi analitis memakai kurs **tanggal evaluasi Purchasing**; snapshot kurs asli Quotation/PO tidak berubah. | FX |
| 35 | Gunakan **Exchange Rate Benchmark existing** sebagai sumber kurs. Jangan buat master/input kurs duplikat; kurs tidak tersedia dinyatakan Unavailable dan dikeluarkan dari perbandingan sampai record valid ditambahkan via Update Rate existing. | FX/integration |

**Aturan pengikat:** Keputusan #34–35 berarti ada **dua lapis kurs berbeda**: (i) snapshot transaksi yang immutable untuk nilai finansial dokumen, dan (ii) as-of-date benchmark rate untuk **proyeksi analitis Price Comparison**. Jangan mengganti query historis dan laporan nilai transaksi dengan kurs benchmark terbaru.

## 3. Bukti audit kode awal (poin yang sudah terlihat)

- `app/Models/PrItem.php`: `Flat`, `Round`, `Hollow`, dimensi yang relevan per shape, perhitungan `weight_needed` dan `total_weight`.
- `app/Http/Controllers/Supplier/QuotationController.php`: sekarang secara eksplisit menolak `available_qty > prItem.quantity_value`; juga menerima file MTC di quotation.
- `app/Imports/QuotationItemsImport.php`: memiliki ceiling quantity terhadap PR; perilaku import harus ikut diubah, tidak cukup frontend.
- `app/Imports/PrItemsImport.php`: menggunakan preview/rejectRow dan `PrItemProcessor`; form/processor saat ini dapat membersihkan field dimensi tidak relevan. Kebutuhan baru menuntut **reject**, bukan hanya clear secara diam-diam ketika Excel menyuplai diameter pada Flat.
- `app/Exports/PrImportTemplateExport.php`: heading masih menempatkan Width setelah diameter.
- `app/Models/PrItemAward.php` / `app/Services/PrItemAwardService.php`: award terutama menyimpan relasi PR item ↔ Quotation item, belum tampak atribut ordered quantity independen.
- `app/Models/PurchaseOrder.php`: ordered totals/fulfillment mengambil `QuotationItem::fulfillment_quantity`/`available_qty` atau fallback PR; harus menggunakan quantity **yang dipilih Purchasing**, bukan otomatis semua yang ditawarkan.
- `app/Services/ShipmentService.php`: sudah melakukan transaksi/row locking dan memeriksa batas quantity Shipment terhadap PO; dasar ordered qty perlu diperbarui secara konsisten.
- `app/Models/ShipmentDocument.php`: registry saat ini hanya `invoice`, `packing_list`, `bl`, `form_e`; jangan sembarang menambah tipe kelima jika ada kode yang mengasumsikan tepat empat dokumen bea cukai.
- `app/Models/Supplier.php`: sudah mempunyai `pic_name`, `pic_email`, `pic_phone`; **reuse** ketiganya.
- `app/Http/Controllers/Purchasing/PriceComparisonController.php`: existing historical comparison menggunakan snapshot kurs pada quotation/PO; grafik dan filter perlu jalur analisis baru/terpisah.
- `resources/views/purchasing/dashboard.blade.php`: sudah ada kartu `Exchange Rate Benchmark` dan modal Update Rate.
- `app/Models/ExchangeRate.php`: menyediakan `currency`, `rate_to_idr`, `valid_from`, cache `latestRate`; untuk tanggal evaluasi **harus ada resolver as-of-date tersendiri**, yang tidak membaca record future-dated.
- `app/Http/Controllers/Purchasing/PurchasingController.php`: `updateKurs` sudah melakukan insert record baru (append), bukan overwrite.
- `app/Http/Controllers/Admin/MaterialMasterController.php`, `app/Models/HsCodeRule.php`, `routes/web.php`: fitur master existing berada di area Admin; perlu otorisasi per-resource Purchasing yang aman.

**Audit lanjutan wajib:** baca semua migration relasi, FormRequest, views, routes, export, notification, dashboard, attachment security, serta seluruh tes terkait pada target HEAD. Keberadaan kode di atas tidak membuktikan bahwa perubahan telah terimplementasi atau berjalan lulus tes.

## 4. Arsitektur target (kontrak bisnis)

### 4.1 PR dan Logistics Reference

- Gunakan dropdown konfigurasi yang typed/validated untuk dua port dan tiga Incoterms; simpan kode kanonik di PR, tampilkan label lengkap di UI.
- Simpan Draft tanpa kedua field, tetapi Submit PR menolaknya jika salah satu kosong. Pembacaan PR lama yang belum mempunyai nilai harus tetap aman; pertimbangkan migration nullable + gate hanya pada submit baru.
- Snapshot nilai PR ke Quotation/PO sesuai alur; karena quotation dapat dibuat sebelum PO, pastikan konsistensi tanpa hard overwrite existing historical fields. Pilih model propagasi eksplisit melalui data/migration setelah audit.
- Tampilan menggunakan label–input sejajar dan grouping ringkas seperti referensi screenshot Infor. **Jangan menyalin requester, reference, status, workflow hanya karena tampil dalam screenshot.**

### 4.2 Quantity, Weight, Amount dan Specification Acceptance

- `requested_qty`: tidak pernah dimutasi akibat quotation/PO.
- `offered_qty`: quantity Supplier valid integer ≥1, boleh lebih/kecil/sama dengan requested.
- `ordered_qty`: quantity hasil pilihan Purchasing, valid `1 <= ordered_qty <= offered_qty` (atau fallback legacy yang dapat dipertanggungjawabkan). Disimpan persisten per baris award/PO untuk semua alur hilir. **Nama final field ditetapkan setelah mengecek constraint award dan shipment.**
- `remaining_offer_qty`: hanya informatif; tidak mengizinkan PO kedua otomatis dari quotation sama (#03).
- `requested_total_kg = requested_qty * requested_kg_per_unit`.
- `offered_total_kg = offered_qty * offered_kg_per_unit` (menghormati estimated/manual source yang sah).
- `po_total_kg = ordered_qty * approved_offered_kg_per_unit`.
- `po_line_amount = ordered_qty * approved_offered_kg_per_unit * approved_price_per_kg`, bukan quotation full-offer total. **Kontrak harga PO harus dimigrasi dengan audit agar transaksi lama tidak direkalkulasi diam-diam.**
- Shipment actual_weight_kg terpisah; `SUM(active/submitted shipped_qty)` tidak boleh melebihi `ordered_qty`. Preserve partial deliveries, concurrency lock, idempotency, dan fallback legacy.
- Bandingkan requested dengan offered dims pada atribut relevant (Thickness/Width/Outer/Inner Diameter/Length). Emit indikator yang bisa muncul bersamaan: Quantity Match/Shortage/Surplus, Different Specification, Est Weight. Jangan salah menganggap estimated weight sebagai otomatis mismatch.
- Sebelum generate PO, jika ada Different Specification, wajib explicit `accept_spec_difference` + audit actor/time/revision/diff snapshot untuk setiap line yang affected. Validasi server-side ulang di saat PO creation; jangan sekadar checkbox UI.
- Perubahan atau re-submit quotation setelah acceptance/selection harus menginvalidasi confirmation yang tidak lagi relevan; keputusan auto-cancel/locking mengikuti constraint lifecycle setelah audit, jangan mutate PO final.

### 4.3 Import Excel All-or-Nothing

- Preview memvalidasi semua baris dan mengembalikan **seluruh** error per row/column, bukan berhenti pada error pertama.
- Jika ada satu error kontrak/file/field/dimensi/shape/quantity: status preview `rejected` dan **tidak boleh apply satu baris pun**. Berikan nomor baris Excel asli.
- Pada apply/submit, backend melakukan validasi ulang atas seluruh payload di dalam transaction. Jika ada error, rollback seluruh batch; tidak ada sebagian state tersimpan.
- Shape Flat hanya menerima Thickness/Width/Length; Round Outer Diameter/Length; Hollow Inner+Outer Diameter/Length dengan Outer > Inner. Untuk impor, field asing yang diisi (contoh Flat + Diameter) harus error, **bukan diabaikan otomatis**.
- Revisi template PR hanya mengubah urutan heading; parser tetap `WithHeadingRow` dan harus tetap aman untuk template historis yang kompatibel. Audit template Quotation sebelum ikut mengubahnya; sumber hanya memerintahkan revisi posisi Width pada template import data.
- Pertahankan mode import existing (misalnya replace imported fields/fill empty) hanya setelah file valid total. Jangan menambahkan mode append baru tanpa persetujuan.

### 4.4 Shipment MTC

- Hilangkan kewajiban dan input MTC dari **quotation baru**; jangan menghapus akses ke MTC quotation lama.
- MTC Shipment adalah attachment multi-file pada level **Shipment**. Pertahankan `invoice`, `packing_list`, `bl`, `form_e` dan semua status dokumen tersebut tanpa perubahan semantik.
- Pilih reuse attachment polymorphic terhadap Shipment atau model dokumen MTC tersendiri setelah audit relasi/morph/security; jangan memaksakan MTC masuk ke fixed DOC_TYPES tanpa audit.
- Draft boleh tanpa MTC; submit mensyaratkan `count(valid_non_deleted_mtc_attachments) >= 1`. Validation paling akhir pada `ShipmentService::submitShipment()` dalam transactional/locked flow; bukan cuma JS.
- Owner-only upload/download/delete, server-side MIME/size validation, safe storage path, transaction + cleanup file orphan, scoped authorization, preserve audit history, retry/idempotency.
- Shipment partial tiap batch memerlukan ≥1 MTC miliknya sendiri, bukan reuse otomatis attachment batch lain.

### 4.5 Master Harga

- Buat modul internal `Reference Price Master` terpisah dari master kurs: material ID/master, shape, dimension_type (Thickness untuk Flat; Outer Diameter untuk Round/Hollow), min_value, max_value, currency, reference_price_per_kg, valid_from, valid_until nullable, status/version, created_by/updated_by, audit/notes seperlunya.
- Harga berlaku **umum** seluruh Supplier; tidak ada kolom supplier_id pada identitas bisnis.
- Min/max inklusif; gunakan nilai numerik decimal presisi baku. Dua rentang [100,160] dan [150,200] bertabrakan. Time intervals juga inklusif; tanggal akhir kosong = tanpa batas akhir.
- Validasi keunikan semantik di backend: tidak boleh dua baris yang rentang dimensi **dan** rentang tanggalnya sama-sama overlap untuk material+shape+currency. Lindungi race condition dengan transaction dan locking/kunci per grouping; cek limit constraint MySQL yang realistis.
- Change price menghasilkan versi histori baru; peraturan apakah edit metadata versus versi baru dicatat eksplisit dalam service. Jangan overwrite/delete histori harga aktif/terpakai.
- Master price matching untuk quotation memakai **offered** geometry yang telah divalidasi. Lookup juga harus memperhitungkan effective date dan currency; jika dibandingkan dalam currency lain, konversi memakai kurs benchmark tanggal evaluasi.
- Tidak cocok → `Reference Price Not Available` (reason code eksplisit: material not found, dimension not covered, period not effective, currency/FX missing). Tidak menghasilkan delta, **tidak memblokir PO**.
- Saat PO diapprove, simpan snapshot referensi: source price master record/version, value/currency, evaluation date, conversion rate identifiers, chosen converted value, actor dan timestamp. Pisahkan dari nilai transaksional PO.

### 4.6 Price Comparison, Historical PO & Exchange Rate

**Data:** Histori harga eligible dari baris **PO yang sudah diterbitkan**, bukan seluruh quotation dan bukan PR requested weight. Tinjau status PO cancelled/void, duplikat row dari pivot `po_quotations`, soft-delete, serta sumber PO line/award sebelum membuat agregat.

**Formula utama:**

`historical_weighted_avg_price_per_kg = SUM(eligible_po_line_amount_in_comparison_currency) / SUM(eligible_po_line_ordered_kg)`.

Jika PO lama tak punya ordered_qty snapshot, lakukan fallback historis eksplisit; label/no-data jika tidak bisa dipertanggungjawabkan. Jangan menghitung satu PO line berulang akibat join. `Historical Best` adalah MIN harga/KG per PO eligible; `Best Current Offer` MIN harga/KG quotation eligible untuk PR yang sedang dievaluasi. Semua membandingkan material + shape + dimensi filter setara.

**Dimensi:** Exact atau range min/max. Flat `thickness`, Round/Hollow `d_outer`; `d_inner` tetap disimpan/ditampilkan tetapi **tidak** ikut filter sesuai #18. Perlakukan filter rentang inklusif; harga master lookup menggunakan offered dimension aktual.

**Mata uang:** Purchasing memilih IDR/USD/JPY/CNY. Harga asli dan currency original selalu tetap terlihat. Gunakan mata uang pembanding yang sama untuk tiga indikator, master reference, nilai selisih, dan grafik.

**Rate resolver as-of-date:** Query record di `exchange_rates` dengan `currency = X`, `valid_from <= evaluation_date`, pilih max valid_from (dengan deterministic tie-break), pastikan positive, dan sertakan `rate_id`, valid_from di hasil. Kurs IDR→IDR = 1 sesuai definisi base IDR. Tidak boleh mengambil tanggal masa depan, dan cache tidak boleh reuse `latestRate` tanpa parameter tanggal. Jika tidak ada rate, tandai unavailable, jangan default `COALESCE(rate, 1)` untuk mata uang non-IDR.

**Konversi dari mata uang A ke B melalui IDR:** `converted = original_price * rate_to_idr(A, evaluation_date) / rate_to_idr(B, evaluation_date)`; gunakan decimal/rounding di backend dan hindari akumulasi floating error. Untuk Weighted Average lintas PO, lakukan konversi nilai uang sebelum penjumlahan, bukan rata-rata dari angka hasil pembulatan.

**Dual track yang wajib dijaga:** Laporan finansial dokumen & histori nilai PO tetap memakai transaksi/snapshot `exchange_rate_id` aslinya. **Hanya view/aggregate evaluasi baru** memakai benchmark evaluation-date rate. Jangan mengganti existing historical SQL secara global; gunakan layer analytics/as-of resolver yang terpisah.

**Unavailable FX:** Pada record tanpa benchmark rate, tunjukkan harga asli dan `Exchange Rate Unavailable`, keluarkan hanya record itu dari statistik converted (dengan jumlah excluded ditampilkan). Hindari label *Best* tanpa menjelaskan bahwa sebagian offer tidak dapat dibandingkan.

## 5. Urutan implementasi bertahap (Gate 2 Missions)

> **GATE 2 hanya boleh dimulai setelah user memberi persetujuan tertulis atas plan.** Setiap mission punya commit yang terisolasi, laporan tes, dan gate review. Jangan melakukan direct push/merge ke `master`, perubahan prod DB, maupun backfill massal tanpa review.

### Mission 00 — Read-only audit & contract baseline

**Pekerjaan:** checkout & cek HEAD `master`; inventory routes, migrations, models, tests, views, notification, import, document security, FX, cash totals, role middleware; temukan semua pemanggil `fulfillment_quantity`, total PO, offered quantities; cek test/database isolation.

**Deliverable:** impact matrix file+function, risk register, migration design, test baseline, missing invariants, exact route chart.

**Gate:** tidak ada kode implementasi, database prod untouched; owner menyetujui entity design quantity/MTC/FX sebelum Mission 01.

### Mission 01 — Schema & authoritative domain contracts

**Pekerjaan:** migration additive/nullable untuk PR logistics, PO-line/award selected quantity, per-line spec acceptance evidence, reference master/version/snapshot, shipment MTC support bila diperlukan; model/casts/accessors; central source-of-truth Qty/KG/amount/price. Pastikan rollback kompatibel dan no implicit backfill.

**Gate:** model/schema tests, backward compatibility fixture, no behavior change existing UI sebelum feature use.

### Mission 02 — PR, Infor-style logistics & Excel validation

**Pekerjaan:** PR create/edit/show field dropdown & submit gate; propagate PR logistics; Excel template width order; all-or-nothing PR import preview + apply; explicit shape-dimension validation.

**Gate:** Draft/Submit validation, legacy PR null, wrong-shape Excel rollback, template heading, role tests, server-side payload tampering.

### Mission 03 — Supplier Quotation & Purchasing evaluation UI

**Pekerjaan:** remove supplier quantity ceiling across controller and Excel; maintain min/positive; source offered KG/total/amount; status per-line; new section order; label Supplier Estimate Delivery; remove new quotation MTC upload; purchasing read-only mirror layout; sticky header/material; totals on PR/quotation forms/detail; PIC Supplier reused.

**Gate:** qty surplus/shortage/match; mismatches; estimated weight; quote import; no legacy MTC deletion; responsive sticky + UI manual QA.

### Mission 04 — Award, PO ordered quantity & approval evidence

**Pekerjaan:** Purchasing ordered qty selector per awarded item; acceptance for mismatch; persist immutable ordered qty on PO creation; recalculate PO qty/KG/amount, exports and PDF; enforce one-use quotation semantics; shipment remaining qty sources read selected qty, not offered qty.

**Gate:** requested=10, offered=15, ordered=12, remaining offer 3 not auto-buyable, shipment<=12, and existing PO historical fallback; concurrent submissions/idempotency tests.

### Mission 05 — Shipment-level MTC migration of workflow

**Pekerjaan:** multi-file attachment at shipment, new UI upload/download/delete, backend mandatory gate on submit, legacy quotation MTC read-only, customs-doc flow isolation.

**Gate:** no MTC draft allowed, submit without MTC blocked, with MTC accepted; partial shipments each require MTC; cross-supplier download rejected; four customs-doc types unaffected; rollback/file cleanup.

### Mission 06 — Master Harga & Purchasing Master ACL

**Pekerjaan:** CRUD Admin/Purchasing with validation for range/time collision, history/version/audit, effective reference price resolver and PO approval reference snapshot; scoped routes/policy/sidebar for Material/HS Code full access; preserve user/admin isolation.

**Gate:** overlapping both dimension/date rejected; nonoverlapping historical allowed; both roles authorized; unrelated Admin endpoints Purchasing=403; delete safeguards for referenced masters; audit continuity.

### Mission 07 — Currency Benchmark, Price History & Comparison

**Pekerjaan:** as-of benchmark resolver; analytics conversion currency selector; PO-only weighted history; exact/range Thickness/d_outer; Best Current Offer, Historical Best, Historical Weighted Avg; Master Price overlay + nominal/percent delta; Missing FX/Price states; UI chart table/cache and exports consistency.

**Gate:** as-of date excludes future rate; USD→JPY via IDR; no-rate exclusions; zero KG; no pivot duplicates; old snapshot totals unchanged; 3+ indicators selected currency consistent; performance EXPLAIN at realistic volume.

### Mission 08 — Integration, reconciliation & staging rollout

**Pekerjaan:** complete regression, browser & mobile manual QA, migrations dry-run/rollback on testing clone, data-mapping diagnostics, deployment order, route/view/cache queue validation, staged feature flags as needed, rollout & rollback procedures, release notes.

**Gate:** all tests pass, authorized reviewer signs business examples and QA evidence; production deploy only after separate approval and backup.

## 6. Acceptance scenarios wajib (minimum)

| ID | Given / When | Expected |
|---|---|---|
| AC01 | PR Qty 10, Supplier Qty 15 | Quotation submitted, badge Quantity Surplus +5, PR remains 10. |
| AC02 | Purchasing selects ordered qty 12 | PO 12, not 15; KG/amount follow 12; remaining 3 not auto PO. |
| AC03 | Shipment partial 7 then 5 | Total 12 valid; additional 1 rejected under concurrency lock. |
| AC04 | Supplier changes Thickness 100→105 | Different Spec shows delta; Purchasing must confirm with auditable evidence before PO. |
| AC05 | Excel contains Flat + Diameter | Whole import rejected, row/column reason present, zero row persisted. |
| AC06 | Excel has 95 valid, 5 errors | 0 imported, all 5+ errors reported; after correction may import 100. |
| AC07 | PR Draft missing port/incoterm | Saved; Submit rejected until both supplied. |
| AC08 | Port Tanjung Perak, Incoterm FOB on PR | Propagates correctly to quotation & PO. |
| AC09 | Buyer and supplier material table long/wide | Header sticks vertically; Material sticks horizontally, overlays/readability correct. |
| AC10 | Quotation price/KG+KG/unit+qty differ from PR | Totals authoritative by stage; backend ignores client supplied totals. |
| AC11 | New quotation no MTC | Quotation submit allowed. |
| AC12 | Draft Shipment no MTC | Save allowed; Submit blocked. |
| AC13 | Shipment 2 MTC uploads | Submission allowed; files downloadable by authorized owner/reviewer, not another supplier. |
| AC14 | Historical quotation has old MTC | File still available/readable; no forced migration/deletion. |
| AC15 | Master Price Flat Thickness 100–160 + 150–200 overlapping dates | New conflicting record rejected. |
| AC16 | Master Price valid through Sep and new one effective Oct | Historical version remains; evaluation Sep vs Oct resolves correctly. |
| AC17 | Offered thickness 155, PR 100 | Reference match uses offered 155 and correct price range. |
| AC18 | Reference price unmatched | Not Available + reason; zero delta; PO permitted. |
| AC19 | Price histories PO 100kg×50k, 300kg×70k | Weighted Avg = Rp65k/kg in same comparable currency. |
| AC20 | Round/Hollow Price History filter | Uses d_outer only; d_inner not a filter. |
| AC21 | Supplier A USD vs B JPY, selected IDR | Both projected via as-of benchmark, originals remain. |
| AC22 | Rate valid_from future to evaluation date | Not selected. |
| AC23 | JPY benchmark unavailable | JPY original shown, projected price excluded, informative status. |
| AC24 | PO/Quotation snapshot before current rates | Historical transaction IDR totals unmodified by new benchmark rate. |
| AC25 | Purchasing master Material/HS Code actions | Scoped access succeeds; unrelated Admin endpoints blocked. |
| AC26 | Delete referenced master | Protected or domain-compliant reversible behavior, never dangling PR/PO relations. |
| AC27 | Quotation Supplier sections | Required order and label changed, old business behavior preserved. |
| AC28 | Reference Price at PO approval | Snapshot fixed after master update, audit links remain intact. |

## 7. Quality gates & operational safeguards

- **Test isolation:** PHPUnit/Pest dan JS tests menggunakan DB testing terpisah; tidak pernah menjalankan destructive migrations/truncate pada DB kerja/production. Run migrations on disposable testing database.
- **Quality:** Pint, Laravel test suite scoped/full, route:list, view:cache, Vite build, static syntax checks, authorisation checks, migration rollbacks, export/PDF compatibility, browser/keyboard/mobile manual visual QA.
- **Financial correctness:** decimal precision & deterministic rounding, source provenance of KG/unit and rate, no silent `COALESCE` to a fake price/rate, no duplication across PO/pivot joins.
- **Transactions & concurrency:** database locks for award→PO, shipment submit, and collision detection; atomic invariants at both controller boundary and service; idempotent retries.
- **Compatibility:** Existing PR/Quotation/PO, previous 4 shipment document types, attachments, status machine, Hashids, rate snapshots, supplier isolation, user permissions, and backdated data must still load and function.
- **Performance:** server-side DataTables for high-cardinality lists, indexes based on EXPLAIN, avoid N+1, cache keyed by date/currency/filter/version with invalidation on rate/master changes; stress sampling in staging.
- **Security:** FormRequest/Policies with 403 direct HTTP protection, private file storage, signed/owner-scoped downloads where applicable, validation of XLSX formulas and safe export, audit admin/purchasing actor.
- **Rollout:** backup, migration sequencing, legacy read fallback, smoke tests, reversible feature release switches for analytics, stop/rollback instructions, no destructive data migration by default.

## 8. Audit ambiguities / non-blocking implementation notes

These are **engineering checks**, not requests to reopen the 35 business decisions:

1. Verify source of truth for independent ordered qty per PO line; choose a single entity and update every downstream accessor; do not merely add a form field.
2. PO approval stage for Master Price snapshot: if existing PO workflow activates immediately, define exactly when acceptance snapshot is taken. Do not silently invent a new approval state.
3. For period end nullable use +∞ interval semantics. Reject dimension and time collisions atomically.
4. Multi-quotation consolidated PO may include PRs with distinct Port/Incoterms. If such consolidation is allowed by current system, **reject conflicting logistics combinations** or split PO according to existing grouping rules; do not silently select first.
5. Supplier PIC may already exist, so avoid profile migrations unless audit finds missing persistence/visibility rules.
6. Determine whether MTC should live directly under Shipment polymorphic attachments or separate child documents; implement as Shipment-level multi-file with no per-material attachment, preserving the four fixed customs document statuses.
7. For rate-to-IDR on IDR itself ensure consistent base=1; stale/absent record should not accidentally prevent IDR→IDR.
8. Reuse current Exchange Rate Benchmark for rates. However, `ExchangeRate::latestRate()` is not a safe substitute for as-of-date lookups; preserve existing transaction-snapshot helpers.
9. Master Price UI currency of a quote may differ from reference; currency conversion missing state must remain separate from reference price missing state.
10. For Excel, explicitly distinguish changing **column ordering** from changing Excel column pixel width. User chose ordering only.
11. Master deletion may be constrained by existing DB foreign keys; `full access like Admin` is not permission to corrupt referenced data. Keep exact existing deletion semantics, adding no unsafe forced cascade.
12. No requirement to copy all Infor LN fields. The supplied screenshot is a visual/field-layout reference for Port and Incoterms in PR.

## 9. Suggested Gate 2 work prompts (copy/paste)

### Prompt A — Read-only Gate 2 preflight (wajib pertama)

```text
Repository: poyipoy/supplierportal, branch master.
Read the attached Gate 1 plan completely and inspect actual repository HEAD, migrations, controllers, services, views, existing tests, AGENTS.md and project rules. DO NOT implement features yet, mutate app/prod databases, or push/merge.
Deliver a Gap & Impact Verification Report covering all 35 LOCKED decisions: each requirement's current status, exact files/routes/models, data migration implications, invariant conflicts, transaction+security risks, proposed mission execution order, isolated testing strategy and explicit acceptance coverage. Give special attention to ordered_qty independent from offered_qty, shipment MTC attachments, existing four customs-document types, historical exchange-rate snapshot vs Exchange Rate Benchmark analytic projection, and referential integrity of Material/HS Code masters. Explicitly identify any disagreement between this plan and code HEAD, with a safe design recommendation. Stop and request review before coding.
```

### Prompt B — Mission-specific execution (hanya setelah approval)

```text
Execute exactly ONE approved mission from the reviewed Gate 1 + Gate 2 Gap Report, on a feature branch based on verified master HEAD. Follow AGENTS.md and existing Laravel patterns. Implement DB/API/validation/service/UI/tests together within that mission's approved scope. Do not change unrelated modules; don't push/merge or touch production DB. Test only on isolated testing DB; record tests, Pint, build, migrations, rollback and relevant browser QA status. Preserve legacy documents and historical snapshots. Submit a file-by-file implementation report and acceptance matrix, then STOP for approval before the next mission.
```

## 10. Implementation readiness checklist

- [x] 35 business decisions confirmed through interview.
- [x] Original two-page user revision document read.
- [x] Infor screenshot interpreted as **reference field layout**, not full-field requirements.
- [x] Repository branch and key code paths inspected via GitHub (read-only).
- [x] Risks involving ordered qty, existing rate snapshot, and shipment document registry identified.
- [x] Phased mission architecture, dependency graph, QA criteria, and agent prompts prepared.
- [ ] Fresh HEAD/working tree and full migration/test inventory validated in user's dev environment.
- [ ] Gate 2 read-only Gap Report reviewed and approved.
- [ ] Mission 01+ implementation authorized.
- [ ] Runtime tests, staging visual QA, and deployment separately approved.

**Final outcome:** Business interview complete. Implementation **not started**. Next action: run **Gate 2 Prompt A (read-only preflight)**, review findings, then approve bounded Mission 01 implementation.
