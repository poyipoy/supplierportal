# ADASI Portal Supplier — Rencana Implementasi Kapabilitas Berbasis Infor CloudSuite / Infor LN (`@boost` Engineering Plan)

> **Target Repositori:** `poyipoy/supplierportal` (PT. Astra Daido Steel Indonesia — ADASI)  
> **Protokol Rekayasa:** `@boost` (*Evidence-First Investigation, Minimal Necessary Change, Risk-Proportional Verification*)  
> **Cakupan Fitur Terpilih:** `A1`, `A3`, `A4`, `B1`, `C1`, `C2`, `C3`, `D1`, `D2`, `D3`  
> **Referensi Ekosistem Infor:** [Infor LN 10.8 / CloudSuite 2026.x](https://docs.infor.com/ln/2026.10/en-us/lnesolh/lnstudextdg_cl/qzh1511792930213.html), [Infor Supplier Exchange](https://www.b2bnn.com/2026/10/finding-the-best-supplier-management-software-for-manufacturing-companies/), [Infor OS Portal v2](https://blog.cfosolutions.com/infor-os-portal-0), dan [FullOnBaan Infor LN Playbook](https://playbook.fullonbaan.com/)

## 1. Executive Summary & Audit Status Repositori (`[Verified]`)

Berdasarkan inspeksi langsung terhadap kode sumber repositori **ADASI Portal Supplier** (`context.md`, `config/`, `app/Models/`, `app/Services/`, `routes/`, dan `resources/js/`), kesepuluh kapabilitas yang Anda pilih memiliki **dua tingkat kesiapan yang berbeda** di dalam repositori saat ini:

1. **Kelompok Fitur yang Fondasi Kodenya Sudah Tersedia di Repo & Siap Diaktifkan / Disempurnakan (`C3`, `D1`, `D2`, `D3`)** `[Verified]`:
   - **`C3` (Skema URL Bergaya Infor `/v2/ADASI_PRD/` + Hashids 24 Karakter + Page Registry UUID)**: Kelas `App\Support\PortalPath`, `App\Support\PageRegistry`, `App\Support\HashidCodec`, `PortalPageController`, `PortalLegacyPathController`, serta konfigurasi `config/portal_urls.php` dan `config/page_registry.php` **sudah terimplementasi di kode**, namun saat ini berstatus nonaktif secara *default* (`false` di env). Pekerjaan yang tersisa adalah **aktivasi bertahap, penguncian regresi, dan perluasan mode tampilan UUID (C2-URI)**.
   - **`D1` (Cross-Tab Logout via `BroadcastChannel` & 401 Polling Redirect)**: Modul `resources/js/session-sync.js`, `window.AdasiSession` di `layouts/app.blade.php`, dan endpoint `session.status` (`SessionStatusController`) **sudah terpasang dan aktif secara default** (`PORTAL_CROSS_TAB_LOGOUT_ENABLED=true` di `config/portal_session.php`). Pekerjaan yang tersisa adalah **penutupan celah *edge-case* pada halaman form dan verifikasi multi-tab**.
   - **`D2` (User-Scoped `localStorage` & Draft Isolation)**: Kunci draf penawaran di `supplier/quotations/create.blade.php:2802` sudah menggunakan `'quotation_draft_' + draftUserId + '_' + prId`, namun regex pembersih di `resources/js/session-sync.js:11` (`/^quotation_draft_\d+$/`) **masih menggunakan pola lama satu angka** sehingga draf ber-scope user (`quotation_draft_<userId>_<prId>`) dan draf percakapan (`adasi_chat_draft_*`) belum terbersihkan saat *logout*. Ini adalah temuan bug nyata (`[Verified]`) yang wajib diperbaiki.
   - **`D3` (Opsi "Keluar dari Semua Perangkat" pada Menu Logout)**: Backend `AuthenticatedSessionController::destroy` (`app/Http/Controllers/Auth/AuthenticatedSessionController.php:75-82`) dan checkbox `#logout-all-devices` di `resources/views/partials/navbar.blade.php:163-166` **sudah terpasang**, tetapi masih dinonaktifkan secara *default* (`PORTAL_LOGOUT_ALL_DEVICES_ENABLED=false` di `config/portal_session.php`).

2. **Kelompok Fitur Fungsional Baru yang Membutuhkan Pembangunan End-to-End (`A1`, `A3`, `A4`, `B1`, `C1`, `C2`)** `[Verified]`:
   - **`A1` (PO Acknowledgement & Promise Date Handshake)**: Baik `PurchaseOrder` (Impor) maupun `LocalPurchaseOrder` (Lokal) belum memiliki status/kolom *Supplier Acknowledgement* dan negosiasi *Promise Date* seperti di [Infor Supplier Exchange](https://www.b2bnn.com/2026/10/finding-the-best-supplier-management-software-for-manufacturing-companies/).
   - **`A3` (Line-Level / Value-Aware 3-Way Match `tdpur` ↔ `whinh` ↔ `LocalInvoice`)**: Saat ini `local_goods_receipts` (`app/Models/LocalGoodsReceipt.php`) hanya menyimpan `qty` dan `uom` tanpa nominal nilai penerimaan atau harga satuan baris, sehingga `LocalGrReservationService` hanya memeriksa plafon total terhadap header `LocalPurchaseOrder::total_amount`.
   - **`A4` (SLA Watchdog & Auto-Escalation Alerts)**: `routes/console.php` baru memiliki pengingat pengantaran dokumen Rabu (`local-invoices:send-delivery-reminders`) dan pemblokiran audit (`supplier-audits:notify-invoice-blocked`), namun belum memiliki *SLA Watchdog* untuk PO yang belum di-*acknowledge* (>24/48 jam), kuotasi PR yang mendekati penutupan, atau antrean verifikasi Finance/QC yang melewati SLA ([Infor ION Event Management](https://playbook.fullonbaan.com/)).
   - **`B1` (Automated Supplier Performance Scorecard — OTIF, Quality, & Invoice Compliance)**: Data mentah sudah lengkap di tabel `purchase_orders`, `qc_inspections`, `material_claims`, `local_invoices`, dan `supplier_audits`, tetapi belum ada mesin kalkulasi *Scorecard* otomatis yang menyuplai KPI ke halaman detail vendor dan evaluasi `SupplierAudit`.
   - **`C1` (In-Context "Smart Panel" & Document Chain Drillback)**: Portal belum memiliki panel samping kontekstual (*slide-over drawer* ala [Infor OS Smart Panel](https://blog.cfosolutions.com/infor-os-portal-0)) untuk menelusuri rantai dokumen hulu-ke-hilir (`PR ↔ Quotation ↔ PO ↔ Shipment/GR ↔ QC ↔ Invoice ↔ Voucher DRP`) beserta pratinjau dokumen PDF secara berdampingan.
   - **`C2` (Role-Based Operational Homepages — Action-First Widgets)**: `DashboardWidgetService` dan `config/dashboard_widgets.php` sudah mendukung *12-column smart-span grid*, namun perlu diperkaya dengan *widget* antrean kerja operasional baru (seperti *Unacknowledged PO Queue*, *3-Way Match Discrepancy Queue*, dan *Top/At-Risk Supplier Scorecard Summary* ala [Infor LN 10.8 Homepages](https://playbook.fullonbaan.com/)).

## 2. Kontrak Invarian Rekayasa (`AGENTS.md` & `@boost` Gate)

Seluruh perubahan kode dalam rencana ini **wajib** mematuhi invarian arsitektur ADASI Portal Supplier yang telah dikunci di `context.md` dan `AGENTS.md`:

| Invarian | Aturan Wajib di Repositori ADASI |
| :--- | :--- |
| **Supplier Data Isolation** | Kolom `supplier_id` pada tabel operasional selalu merujuk ke **`users.id`** (bukan `suppliers.id`). Seluruh query di sisi supplier wajib menyertakan `->where('supplier_id', auth()->id())`. |
| **Business Time (`Asia/Jakarta`)** | Database dan `app.timezone` selalu **UTC**. Seluruh logika kalender bisnis (hari ini, batas SLA, jatuh tempo, *overdue*) wajib melewati `App\Support\BusinessTime` agar lolos `BusinessTimeGuardTest`. |
| **Presisi Keuangan (`Money` / BCMath)** | Seluruh perhitungan nominal uang, pajak, selisih *3-Way Match*, dan persentase menggunakan `App\Support\Money` dan fungsi `bc*`. Dilarang menggunakan operasi *float* mentah pada kode finansial. |
| **Keamanan Parameter URL (`Hashids`)** | Seluruh model yang tampil di route wajib memakai trait `HasHashids` dan didaftarkan di `DecodeHashids::HASHED_PARAM_KEYS` bila menggunakan nama parameter baru. |
| **Paritas Lokalisasi (`id` & `en`)** | Setiap kunci teks UI baru wajib ditambahkan secara identik di `lang/id/` dan `lang/en/`, serta `AdasiI18n` bila diakses dari JavaScript, agar lolos `TranslationParityTest`. |
| **Arsitektur Styling (`DESIGN.md`)** | Seluruh komponen Blade baru menggunakan utilitas Tailwind v4 dengan prefix **`tw:`** (`tw:bg-surface`, `tw:text-on-surface`, dll.) dan variabel token `--md-*` / `--ui-*` tanpa warna hex mentah, serta mendukung *dark mode* tonal. |
| **Konkurensi & Transaksi** | Seluruh mutasi status dokumen dan reservasi dijalankan di dalam `DB::transaction()` menggunakan `lockForUpdate()`. |

## 3. Rancangan Teknis Mendalam per Fitur (`D1–D3`, `C3`, `A1`, `A3`, `A4`, `B1`, `C1`, `C2`)

### 3.1 Paket D (`D1`, `D2`, `D3`) — Penguatan Sesi, Storage Isolation & Multi-Device Logout

#### Diagnosis Bukti Kode (`[Verified]`)
- **`D1` (`resources/js/session-sync.js`)**: Sudah mengimplementasikan `BroadcastChannel('adasi-session')` dengan *fallback* event `storage` (`adasi:session-event`) dan intersepsi respons `401`/`419` untuk menampilkan dialog rekoneksi (`askToReconnectDialog`) serta penggantian token CSRF (`applyCsrfToken`).
- **`D2` (Bug Regex Pembersihan Storage di `resources/js/session-sync.js:11`)**:
  - Di `resources/views/supplier/quotations/create.blade.php:2802`, kunci penyimpanan draf telah diubah menjadi:
    ```javascript
    const draftKey = 'quotation_draft_' + draftUserId + '_' + prId;
    ```
  - Namun di `resources/js/session-sync.js:10-12`, fungsi `storageKeysToClear(keys)` masih tertulis:
    ```javascript
    export function storageKeysToClear(keys) {
        return keys.filter((key) => key === PENDING_EXPORT_STORAGE_KEY || /^quotation_draft_\d+$/.test(key));
    }
    ```
  - Akibatnya, kunci `quotation_draft_<userId>_<prId>` **tidak pernah cocok** dengan `/^quotation_draft_\d+$/` dan tidak terhapus saat *logout*! Selain itu, kunci `sessionStorage` untuk draf pesan percakapan (`adasi_chat_draft_*`) juga belum dibersihkan saat *logout*.
- **`D3` (`config/portal_session.php` & `navbar.blade.php`)**: Fitur `logout_all_devices` sudah diimplementasikan penuh di `AuthenticatedSessionController::destroy` (memanggil `SessionRevocationService::revokeOtherSessions` dan mencatat `AuthSecurityEvent('other_device_logout')`), namun nilai default di `config/portal_session.php:6` masih `false`.

#### Rancangan Perubahan Minimal (`D1`, `D2`, `D3`)
1. **Perbaikan `resources/js/session-sync.js`**:
   - Perbarui `storageKeysToClear(keys)` agar mencocokkan format lama maupun format ber-scope user serta kunci draf chat/filter sementara:
     ```javascript
     export function storageKeysToClear(keys) {
         return keys.filter((key) =>
             key === PENDING_EXPORT_STORAGE_KEY ||
             /^quotation_draft_\d+(?:_\d+)?$/.test(key) ||
             /^adasi_chat_draft_/.test(key)
         );
     }
     ```
   - Di dalam `clearUserScopedStorage(win)`, tambahkan pembersihan untuk `win.sessionStorage` dengan pola kunci yang sama.
2. **Aktivasi Default `D3` di `config/portal_session.php`**:
   - Ubah `'logout_all_devices' => env('PORTAL_LOGOUT_ALL_DEVICES_ENABLED', true)` dan sesuaikan `.env.example` serta pengujian di `tests/Feature/Auth/`.

### 3.2 Fitur `C3` — Skema URL Bergaya Infor (`/v2/ADASI_PRD/`, Hashids 24-Char, & Page Registry)

#### Diagnosis Bukti Kode (`[Verified]`)
- Infrastruktur Fase 0, Fase 1 (Hashids 24 karakter via koneksi `long` di `App\Support\HashidCodec`), Fase 2 (prefiks `/v2/ADASI_PRD` via `App\Support\PortalPath` & `PortalLegacyPathController`), dan Fase 3 C1 (`App\Support\PageRegistry` & `PortalPageController`) **sudah terpasang di codebase**.
- Saat ini ketiga flag di `config/portal_urls.php` bernilai default `false` agar ratusan *feature test* lama yang menggunakan literal path `/purchasing/...` tetap hijau.

#### Rancangan Perubahan & Rollout (`C3`)
1. **Audit Konsumen Path Literal Tersisa**:
   - Pastikan seluruh panggilan AJAX/fetch di Blade baru (termasuk fitur `A1`, `A3`, `B1`, `C1` yang akan dibangun di rencana ini) menggunakan `route('...')` atau `PortalPath::path(...)`, **tidak pernah** menggunakan string literal `'/purchasing/...'` atau `'/finance/...'`.
2. **Pendaftaran Route Baru ke `config/page_registry.php`**:
   - Setiap halaman indeks baru (misal halaman *Supplier Scorecard* `B1` atau *SLA Monitor* `A4`) wajib diberi UUIDv4 tetap di `config/page_registry.php`.
3. **Konfigurasi Staging & Production Rollout**:
   - Sediakan profil `.env.example` untuk mengaktifkan ketiga lapis secara serentak:
     ```dotenv
     PORTAL_LONG_HASHIDS_ENABLED=true
     PORTAL_LONG_HASHIDS_LENGTH=24
     PORTAL_PATH_PREFIX_ENABLED=true
     PORTAL_PATH_PREFIX=v2/ADASI_PRD
     PORTAL_PAGE_REGISTRY_ENABLED=true
     ```
   - Tambahkan suite pengujian khusus `tests/Feature/Architecture/InforPortalUrlParityTest.php` yang menguji resolusi route baru dengan ketiga flag menyala (`true`).

### 3.3 Fitur `A1` — PO Acknowledgement & Promise Date Handshake (Impor & Lokal)

#### Konsep Bisnis ([Infor Supplier Exchange](https://www.b2bnn.com/2026/10/finding-the-best-supplier-management-software-for-manufacturing-companies/))
Di Infor LN & Supplier Exchange, penerbitan PO harus diikuti oleh konfirmasi elektronik dari supplier (*Order Acknowledgement*). Supplier dapat:
1. **Acknowledge as-is**: Menyetujui harga, kuantitas, dan tanggal estimasi tiba (`estimated_arrival` / `po_date` + *lead time*).
2. **Acknowledge with Promise Date / Note (Request Change)**: Mengajukan tanggal janji kirim baru (`supplier_promised_date`) beserta alasan keterlambatan/perubahan jadwal. Purchasing dapat menyetujui (*Accept Promise Date*) atau menolak (*Reject / Hold*).

#### Desain Skema Database (Migrasi Baru)
Buat migrasi `database/migrations/2026_11_11_000001_add_acknowledgement_columns_to_purchase_orders_tables.php`:
- **Tabel `purchase_orders` (Core 1 — Impor)**:
  - `acknowledgement_status` (`varchar(32)`, default `'PENDING'`, indeks): nilai enum `PENDING`, `ACKNOWLEDGED`, `CHANGE_REQUESTED`, `ACCEPTED_CHANGE`.
  - `acknowledged_at` (`timestamp`, nullable)
  - `acknowledged_by` (`unsignedBigInteger`, nullable, FK ke `users.id`)
  - `supplier_promised_arrival` (`date`, nullable)
  - `supplier_acknowledgement_note` (`text`, nullable)
  - `buyer_response_note` (`text`, nullable)
  - `buyer_responded_at` (`timestamp`, nullable)
- **Tabel `local_purchase_orders` (Core 2 — Lokal)**:
  - `acknowledgement_status` (`varchar(32)`, default `'PENDING'`, indeks): `PENDING`, `ACKNOWLEDGED`, `CHANGE_REQUESTED`, `ACCEPTED_CHANGE`.
  - `acknowledged_at` (`timestamp`, nullable)
  - `acknowledged_by` (`unsignedBigInteger`, nullable, FK ke `users.id`)
  - `supplier_promised_delivery_date` (`date`, nullable)
  - `supplier_acknowledgement_note` (`text`, nullable)

#### Desain Layanan & Komponen (`app/Services/Procurement/PoAcknowledgementService.php`)
- **Method `acknowledgeImportPo(User $supplier, PurchaseOrder $po, array $data)`**:
  - Memastikan `(int) $po->supplier_id === (int) $supplier->id` dan status PO adalah `active`.
  - Di dalam `DB::transaction()` dengan `lockForUpdate()`, jika `supplier_promised_arrival` diisi dan melampaui `$po->estimated_arrival`, set `acknowledgement_status = 'CHANGE_REQUESTED'` dan kirim notifikasi ke Purchasing (`po_promise_date_change_requested`). Jika sesuai atau lebih cepat, set `acknowledgement_status = 'ACKNOWLEDGED'`.
- **Method `respondImportPromiseChange(User $buyer, PurchaseOrder $po, bool $accepted, ?string $note)`**:
  - Jika disetujui oleh Purchasing, salin `supplier_promised_arrival` ke `estimated_arrival` (dengan menyimpan tanggal awal di histori/log audit) dan ubah `acknowledgement_status = 'ACCEPTED_CHANGE'`.
- **Method `acknowledgeLocalPo(User $supplier, LocalPurchaseOrder $po, array $data)`**:
  - Memungkinkan supplier lokal mengonfirmasi PO terbuka (`OPEN`) dari halaman `/local-supplier/purchase-orders/{purchase_order}`.
- **Guard Operasional (Configurable Soft/Hard Gate)**:
  - Tambahkan flag `'require_po_acknowledgement_before_shipment' => env('PROCUREMENT_REQUIRE_PO_ACK', false)` di `config/procurement.php` agar transisi ke wajib *acknowledge* tidak merusak pengujian *legacy* yang langsung membuat `Shipment`.

### 3.4 Fitur `A3` — Line-Level / Value-Aware 3-Way Match (`tdpur` ↔ `whinh` ↔ `LocalInvoice`)

#### Konsep Bisnis ([Infor LN Playbook — AP 3-Way Match](https://playbook.fullonbaan.com/))
Saat ini di ADASI Portal (`context.md` §5.2), `local_goods_receipts` hanya menyimpan `qty` dan `uom` tanpa nilai nominal uang (`receipt_amount` / `unit_price`), sehingga verifikasi Finance hanya membandingkan DPP Invoice terhadap sisa plafon total `LocalPurchaseOrder::total_amount`. Di Infor LN (`tfacp`), *3-Way Match* membandingkan:
1. **PO Ceiling (`tdpur4100m000`)**
2. **Expected GR Value (`whinh3512m600` Received Qty × Unit Price / Receipt Value)**
3. **Submitted Invoice DPP (`LocalInvoice::invoice_amount`)**

#### Desain Skema Database (Migrasi Baru)
Buat migrasi `database/migrations/2026_11_11_000002_add_three_way_match_columns_to_local_gr_and_invoices.php`:
- **Tabel `local_goods_receipts`**:
  - `unit_price` (`decimal(18,4)`, nullable): Harga satuan per UOM dari baris PO/GR Infor LN (opsional/nullable agar file impor lama tetap kompatibel 100%).
  - `receipt_amount` (`decimal(18,2)`, nullable): Nilai nominal GR (`round(qty * unit_price, 2)` atau diambil dari kolom nilai di ekstrak `whinh` bila tersedia).
  - `po_line_reference` (`varchar(64)`, nullable): Nomor posisi/baris PO (`Position` di Infor LN).
- **Tabel `local_invoice_goods_receipts` (Tabel Pivot Snapshot)**:
  - `gr_amount_snapshot` (`decimal(18,2)`, nullable): Snapshot nilai nominal GR saat diikat ke invoice.
- **Tabel `local_invoices` & `local_invoice_verifications`**:
  - `expected_gr_dpp_amount` (`decimal(18,2)`, nullable): Jumlah nominal `receipt_amount` dari seluruh GR yang dipilih pada invoice (terhitung bila semua GR terkait memiliki `receipt_amount !== null`).
  - `gr_dpp_variance_amount` (`decimal(18,2)`, nullable): `Money::sub($invoice_amount, $expected_gr_dpp_amount)`.
  - `three_way_match_status` (`varchar(32)`, default `'PO_CEILING_ONLY'`):
    - `EXACT_MATCH` (selisih = Rp 0)
    - `WITHIN_TOLERANCE` (selisih $\le$ batas toleransi di `config/finance.php`, misal Rp 1.000 atau 0,5%)
    - `VARIANCE_DETECTED` (selisih melebihi toleransi — memicu peringatan kuning/merah di layar Section A & B Verifikator Finance)
    - `PO_CEILING_ONLY` (ketika GR berasal dari impor lama yang belum memiliki `receipt_amount`).

#### Desain Layanan (`app/Services/LocalInvoice/ThreeWayMatchCalculator.php`)
- Memperluas `LocalGrImportService`: membaca kolom opsional `unit_price` / `receipt_amount` / `po_line_reference` bila disertakan pada file unggahan `whinh`, menghitung `receipt_amount` dengan `BCMath`, dan mengagregasi nilai saat konsolidasi baris GR.
- Memperluas `InvoiceSubmissionService` & `InvoiceVerificationService`: menghitung `expected_gr_dpp_amount`, `gr_dpp_variance_amount`, dan `three_way_match_status` secara otomatis tanpa membatalkan aturan plafon `LocalPurchaseOrder::total_amount` yang sudah ada.
- Menampilkan ** Kartu Ringkasan 3-Way Match (PO vs GR vs Invoice DPP)** di halaman verifikasi Finance (`resources/views/finance/invoices/show.blade.php`).

### 3.5 Fitur `A4` — SLA Watchdog & Auto-Escalation Engine ([Infor ION Event Management](https://playbook.fullonbaan.com/))

#### Konsep Bisnis
Meniru mesin **Infor ION Event Management & Escalation Workflows** yang memantau ketepatan waktu di seluruh modul secara otomatis dan mengirimkan pengingat bertingkat (*Reminder* → *Escalation to Lead/Admin*) tanpa membanjiri pengguna (*anti-spam idempotency*).

#### Desain Skema & Konfigurasi
1. **Konfigurasi `config/sla_monitors.php`**:
   - `import_po_unacknowledged_hours` => `48` (Pengingat ke Supplier Impor & Purchasing bila PO `active` belum di-*acknowledge* > 48 jam kerja).
   - `local_po_unacknowledged_hours` => `72` (Pengingat ke Supplier Lokal).
   - `pr_bidding_closing_soon_hours` => `24` (Pengingat ke Supplier terundang yang belum submit `Quotation` saat periode bidding PR tersisa $\le 24$ jam).
   - `qc_inspection_waiting_hours` => `48` (Eskalasi jika `PurchaseOrder` berstatus `waiting_qc` / `Shipment` berstatus `arrived` belum diinspeksi QC > 48 jam).
   - `finance_verification_sla_days` => `5` (Peringatan ke Finance jika `LocalInvoice` berstatus `UNDER_VERIFICATION` sejak `cashier_received_at` sudah melewati 5 hari kalender bisnis).
2. **Tabel Dedikasi Pencatat Idempotensi (`sla_escalation_logs`)**:
   - Migrasi `database/migrations/2026_11_11_000003_create_sla_escalation_logs_table.php` dengan kolom: `id`, `rule_key` (`varchar(64)`), `subject_type` (`varchar(128)`), `subject_id` (`unsignedBigInteger`), `stage` (`REMINDER_1`, `ESCALATED`), `triggered_at` (`timestamp`), `resolved_at` (`timestamp`, nullable). Unique index pada `[rule_key, subject_type, subject_id, stage]` memastikan satu dokumen tidak pernah dikirimi notifikasi duplikat!
3. **Command & Penjadwalan (`app/Console/Commands/RunSlaWatchdogCommand.php`)**:
   - Command `sla:watchdog` dijadwalkan di `routes/console.php` setiap jam pada jam kerja (`hourly()` dengan zona waktu `config('app.business_timezone', 'Asia/Jakarta')` dan `withoutOverlapping()`).
   - Mengirimkan notifikasi melalui `NotificationService` yang sudah ada (menghormati `NotificationPreferenceService` dan `NotificationMute`).

### 3.6 Fitur `B1` — Automated Supplier Performance Scorecard (OTIF, Quality, & Invoice Compliance)

#### Konsep Bisnis ([Infor Supplier Exchange Report Card](https://www.b2bnn.com/2026/10/finding-the-best-supplier-management-software-for-manufacturing-companies/))
Mengubah data historis transaksi yang sudah tersimpan di database ADASI menjadi **Kartu Skor Kinerja Supplier Otomatis (*Automated Supplier Scorecard*)** dengan skala 0–100 dan *Grade* (`A`, `B`, `C`, `D`), serta mengintegrasikannya langsung ke layar penilaian `SupplierAudit`.

#### Rumus & Metrik Objektif (`app/Services/VendorMaster/SupplierScorecardService.php`)
Semua metrik dihitung secara *real-time* (dengan *caching* terarah per supplier + periode) untuk rentang waktu yang dipilih (6 bulan terakhir, 12 bulan terakhir, atau per tahun):

1. **Untuk Supplier Impor (`scope: import`)**:
   - **On-Time Delivery Rate (OTD — Bobot 35%)**:
     $$\text{OTD \%} = \frac{\text{Jumlah PO Selesai dengan } \text{actual\_arrival} \le \text{estimated\_arrival}}{\text{Total PO yang Memiliki } \text{actual\_arrival}} \times 100$$
   - **Quality Acceptance Rate (QAR — Bobot 40%)**:
     $$\text{QAR \%} = \frac{\sum \text{qc\_items dengan status OK}}{\sum \text{Seluruh qc\_items yang Diinspeksi}} \times 100$$
     *(Dikurangi penalti terukur untuk setiap `MaterialClaim` berstatus aktif/terbuka).*
   - **Quotation & PO Responsiveness (Bobot 25%)**:
     Rasio undangan PR (`purchase_requisition_suppliers`) yang direspons dengan `Quotation` tepat waktu + kecepatan rata-rata *PO Acknowledgement* (`A1`).

2. **Untuk Supplier Lokal (`scope: local`)**:
   - **Document Delivery Punctuality (Bobot 35%)**:
     $$\text{Punctuality \%} = \frac{\text{Jumlah Invoice dengan } \text{missed\_delivery\_count} = 0}{\text{Total Invoice yang Mencapai Kasir atau Expired}} \times 100$$
   - **Invoice First-Time-Right Rate (FTR — Bobot 45%)**:
     $$\text{FTR \%} = \frac{\text{Jumlah Invoice Lolos READY\_TO\_PAY pada } \text{revision\_number} = 1}{\text{Total Invoice yang Diverifikasi Finance}} \times 100$$
   - **Audit & Compliance Score (Bobot 20%)**:
     Skor rata-rata dari hasil `SupplierAudit` terakhir yang berstatus `RESULT_PUBLISHED` + kelengkapan `supplier_master_documents`.

3. **Integrasi dengan `SupplierAudit` & Halaman Profil Vendor**:
   - Menampilkan widget **Supplier Scorecard Badge & Radar/Bar Breakdown** di:
     - Halaman detail vendor Purchasing (`purchasing/local-vendors/{vendor}` & `purchasing/import-suppliers/{supplier}`).
     - Halaman detail vendor Finance (`finance/vendor-master/{vendor}`).
     - Halaman review `SupplierAudit` (`purchasing/supplier-audits/{audit}`) sebagai panel referensi objektif bagi auditor saat menilai kuesioner audit, serta di dashboard masing-masing Supplier secara transparan.

### 3.7 Fitur `C1` — In-Context "Smart Panel" & Document Chain Drillback ([Infor OS Portal v2](https://blog.cfosolutions.com/infor-os-portal-0))

#### Konsep Bisnis
Di Infor OS Portal v2 dan Infor LN 10.8, pengguna dapat membuka **Smart Panel** di sisi kanan layar untuk melihat keseluruhan silsilah dokumen (*Document Lineage / Drillback*) dan membuka pratinjau dokumen lampiran tanpa meninggalkan halaman transaksi yang sedang dikerjakan.

#### Desain Teknis (`app/Services/DocumentChain/DocumentChainResolverService.php`)
1. **Endpoint JSON Ringan**:
   - Route: `GET /context-panel/{entityType}/{hash}` (di dalam grup `PortalPath::prefix()`, middleware `auth`), ditangani oleh `App\Http\Controllers\ContextSmartPanelController`.
   - Parameter `{entityType}` mendukung:
     - **Alur Impor**: `pr`, `quotation`, `import-po`, `shipment`, `qc-inspection`, `material-claim`.
     - **Alur Lokal**: `local-po`, `local-gr`, `local-invoice`, `drp-batch`, `voucher`.
2. **Otorisasi Ketat & Isolasi Supplier**:
   - `DocumentChainResolverService` memanggil Policy model terkait (`Gate::authorize('view', $model)`) dan menyaring setiap simpul (*node*) di dalam pohon dokumen agar pengguna **hanya** melihat simpul yang berhak mereka akses (misalnya: Supplier A yang membuka Smart Panel pada PO Impor miliknya hanya melihat PR item yang diundang kepadanya, Kuotasi miliknya sendiri, PO miliknya, Shipment miliknya, dan QC miliknya — tidak pernah melihat kuotasi supplier pesaing!).
3. **Komponen UI `<x-ui.smart-panel />` (`resources/views/components/ui/smart-panel.blade.php`)**:
   - Menggunakan komponen *drawer* samping kanan berbasis Alpine.js + CSS token `--ui-dialog-bg` / `--md-surface` (`DESIGN.md` §3.2) dengan lebar responsif (`420px` di mode ringkas, dapat diperlebar ke `760px` saat mode *Split PDF Preview* aktif).
   - Menampilkan 3 Tab di dalam Smart Panel:
     1. **Rantai Dokumen (*Document Chain*)**: Diagram alur vertikal interaktif (`PR → Quotation → PO → Shipment/GR → QC/Invoice → Voucher/Settlement`) lengkap dengan status badge, tanggal, nilai nominal, dan tombol *Drillback* langsung.
     2. **Pratinjau Dokumen (*Attachments & PDF Split-View*)**: Daftar seluruh berkas PDF/gambar terkait (Faktur Pajak, Surat Jalan, PO PDF, MTC, Bukti Transfer) yang bisa langsung dibuka di dalam `<iframe class="tw:w-full tw:h-[70vh]">` berdampingan dengan form verifikasi Finance/Purchasing.
     3. **SLA & Riwayat Audit Singkat**: Menampilkan status *PO Acknowledgement* (`A1`), hasil *3-Way Match* (`A3`), dan jejak waktu transisi utama.

### 3.8 Fitur `C2` — Role-Based Operational Homepages (Action-First Widgets)

#### Konsep Bisnis ([Infor LN 10.8 Configurable Homepages](https://playbook.fullonbaan.com/))
Memperluas sistem `DashboardWidgetService` (`app/Services/Dashboard/DashboardWidgetService.php`) dan `config/dashboard_widgets.php` yang sudah ada agar setiap *role* memiliki *Action-First Work Queue Widgets* yang menampilkan tugas terdesak dari fitur `A1`, `A3`, `A4`, dan `B1`.

#### Penambahan Widget di `config/dashboard_widgets.php`
1. **Audience `purchasing`**:
   - Perkaya widget `purchasing.exceptions` (*Operational Action Queue*) dengan tab/filter cepat:
     - *PO Menunggu Konfirmasi Supplier / Pengajuan Revisi ETA (`A1`)*
     - *Peringatan SLA Mendekati Tenggat (`A4`)*
   - Tambahkan widget baru `purchasing.supplier_scorecards` (`span: 12`, `kind: 'table'`, `required: false`): *Ringkasan Kinerja & Risiko Supplier (OTIF & Quality `B1`)*.
2. **Audience `finance`**:
   - Tambahkan widget baru `finance.three_way_exceptions` (`span: 12`, `kind: 'list'`, `required: false`): *Antrean Verifikasi dengan Selisih 3-Way Match & SLA Hampir Habis (`A3` & `A4`)*.
3. **Audience `supplier.import` & `supplier.local`**:
   - Tambahkan kartu metrik & antrean *PO Perlu Konfirmasi Segera (`A1`)* serta kartu *Skor Kinerja Saya (`B1`)* pada slot ringkasan dashboard supplier.
4. **Invarian Smart-Span Grid**:
   - Seluruh nilai `span` menggunakan konstanta resmi `DashboardWidgetService::SPANS` (`[3, 4, 5, 6, 7, 8, 9, 12]`) sehingga perhitungan `computeEffectiveSpans()` di PHP dan `resources/js/dashboard-layout-core.js` tetap 100% sinkron dan lolos `tests/Fixtures/dashboard-smart-span-cases.json`.

## 4. Rincian Task & Urutan Eksekusi (5 Fase Terstruktur)

Pekerjaan dibagi menjadi **5 Fase Berurutan (24 Task Granular)** dari yang berisiko paling rendah (*Quick Wins* & penguatan fondasi) hingga fitur lintas modul dan UI kontekstual.

### Fase 1 — Penguatan Sesi, Storage Isolation & Paritas URL Infor (`D1`, `D2`, `D3`, `C3`)

| ID Task | Fitur | Deskripsi Task & File Target | Kriteria Selesai (Acceptance Criteria) |
| :--- | :--- | :--- | :--- |
| **T01** | `D2` | **Perbaikan Regex Pembersihan `localStorage` & `sessionStorage` saat Logout**<br>File: `resources/js/session-sync.js`, `tests/js/session-sync.test.mjs` | `storageKeysToClear()` menangkap `quotation_draft_<userId>_<prId>`, `quotation_draft_<prId>`, `adasi:pending-export-jobs:v1`, dan `adasi_chat_draft_*` baik di `localStorage` maupun `sessionStorage`. |
| **T02** | `D1` | **Hardening Cross-Tab Logout & Penanganan Form Dirty State**<br>File: `resources/js/session-sync.js`, `resources/views/layouts/app.blade.php` | Ketika Tab A melakukan `POST /logout`, Tab B langsung menerima pesan `signed-out` via `BroadcastChannel`, membersihkan storage ber-scope user, memutus koneksi Echo/Pusher, dan mengarahkan ke `/login` tanpa memunculkan pop-up 419. |
| **T03** | `D3` | **Aktivasi Default Opsi "Keluar dari Semua Perangkat" & Audit Test**<br>File: `config/portal_session.php`, `.env.example`, `tests/Feature/Auth/LogoutAllDevicesTest.php` | Flag `logout_all_devices` aktif secara default (`true`), checkbox tampil di dropdown profil navbar, dan saat dicentang menaikkan `auth_session_version`, merotasi `remember_token`, serta mencatat `other_device_logout` di `auth_audit_logs`. |
| **T04** | `C3` | **Audit Path Literal & Suite Verifikasi URL Bergaya Infor (`/v2/ADASI_PRD/` + Hashids 24-Char)**<br>File: `config/portal_urls.php`, `config/page_registry.php`, `tests/Feature/Architecture/InforPortalUrlParityTest.php` | Seluruh route baru terdaftar di `config/page_registry.php`; pengujian otomatis memastikan mode `PORTAL_PATH_PREFIX_ENABLED=true`, `PORTAL_LONG_HASHIDS_ENABLED=true`, dan `PORTAL_PAGE_REGISTRY_ENABLED=true` berjalan mulus beserta redirect 301 *legacy*. |

### Fase 2 — PO Acknowledgement & Line-Level 3-Way Match (`A1`, `A3`)

| ID Task | Fitur | Deskripsi Task & File Target | Kriteria Selesai (Acceptance Criteria) |
| :--- | :--- | :--- | :--- |
| **T05** | `A1` | **Migrasi Database & Enum Status untuk PO Acknowledgement (Impor & Lokal)**<br>File: `database/migrations/2026_11_11_000001_...php`, `app/Enums/PoAcknowledgementStatus.php`, `app/Models/PurchaseOrder.php`, `app/Models/LocalPurchaseOrder.php` | Kolom `acknowledgement_status`, `acknowledged_at`, `acknowledged_by`, `supplier_promised_arrival` / `supplier_promised_delivery_date`, dan catatan negosiasi tersedia di kedua tabel PO dengan default `'PENDING'`. |
| **T06** | `A1` | **Implementasi `PoAcknowledgementService` & Policy Authorization**<br>File: `app/Services/Procurement/PoAcknowledgementService.php`, `app/Policies/PurchaseOrderPolicy.php`, `app/Policies/LocalPurchaseOrderPolicy.php` | Supplier hanya bisa mengonfirmasi PO miliknya sendiri (`supplier_id === auth()->id()`); pengajuan perubahan tanggal janji kirim mengubah status ke `CHANGE_REQUESTED` dan memicu notifikasi ke Purchasing; Purchasing dapat menyetujui/menolak perubahan. |
| **T07** | `A1` | **Controller, FormRequest, Route & Komponen Blade PO Acknowledgement**<br>File: `app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php`, `app/Http/Controllers/LocalSupplier/PurchaseOrderController.php`, `app/Http/Controllers/Purchasing/PurchaseOrderController.php`, `resources/views/supplier/po/show.blade.php`, `resources/views/local-supplier/purchase-orders/show.blade.php` | Tombol *Acknowledge PO* dan modal *Propose New Delivery Date* tampil menggunakan `<x-ui.date-picker>` (bukan `<input type="date">` mentah); Purchasing dapat merespons dari halaman detail PO. |
| **T08** | `A3` | **Migrasi Database untuk Nilai Nominal GR & Status 3-Way Match**<br>File: `database/migrations/2026_11_11_000002_...php`, `app/Models/LocalGoodsReceipt.php`, `app/Models/LocalInvoice.php` | Kolom `unit_price`, `receipt_amount`, `po_line_reference` di `local_goods_receipts`, `gr_amount_snapshot` di `local_invoice_goods_receipts`, serta `expected_gr_dpp_amount`, `gr_dpp_variance_amount`, dan `three_way_match_status` di `local_invoices` tersedia. |
| **T09** | `A3` | **Perluasan Parser `LocalGrImportService` & Mesin `ThreeWayMatchCalculator`**<br>File: `app/Services/LocalInvoice/LocalGrImportService.php`, `app/Services/LocalInvoice/ThreeWayMatchCalculator.php`, `config/finance.php` | Unggahan Excel `whinh` tetap 100% kompatibel dengan file 4 kolom lama, namun otomatis mengekstrak harga/nilai baris bila ada; `ThreeWayMatchCalculator` menghitung varians DPP dengan `App\Support\Money` (BCMath) secara presisi. |
| **T10** | `A3` | **Integrasi 3-Way Match pada Submission Supplier & Layar Verifikasi Finance**<br>File: `app/Services/LocalInvoice/InvoiceSubmissionService.php`, `app/Services/LocalInvoice/InvoiceVerificationService.php`, `resources/views/local-supplier/invoices/create.blade.php`, `resources/views/finance/invoices/show.blade.php` | Saat supplier memilih GR pada pembuatan invoice, estimasi nilai GR tampil otomatis; di layar verifikasi Finance (Section A & B), kartu perbandingan **PO Ceiling vs Total Nilai GR vs DPP Invoice** beserta badge status (`EXACT_MATCH` / `VARIANCE_DETECTED`) tampil jelas. |

### Fase 3 — SLA Watchdog & Automated Supplier Performance Scorecard (`A4`, `B1`)

| ID Task | Fitur | Deskripsi Task & File Target | Kriteria Selesai (Acceptance Criteria) |
| :--- | :--- | :--- | :--- |
| **T11** | `A4` | **Migrasi Tabel `sla_escalation_logs` & Konfigurasi `config/sla_monitors.php`**<br>File: `database/migrations/2026_11_11_000003_...php`, `app/Models/SlaEscalationLog.php`, `config/sla_monitors.php` | Tabel log idempotensi SLA memiliki indeks unik `[rule_key, subject_type, subject_id, stage]` agar setiap eskalasi tercatat satu kali dan tidak pernah mengirim spam. |
| **T12** | `A4` | **Implementasi `SlaWatchdogService` & Command `sla:watchdog`**<br>File: `app/Services/Procurement/SlaWatchdogService.php`, `app/Console/Commands/RunSlaWatchdogCommand.php`, `routes/console.php` | Memantau 5 aturan SLA (PO Impor/Lokal belum di-*acknowledge*, PR bidding akan tutup, QC menunggu inspeksi, dan Invoice Finance menunggu verifikasi) menggunakan `BusinessTime` dan mengirim notifikasi ke pihak terkait. |
| **T13** | `B1` | **Implementasi `SupplierScorecardService` (Mesin Kalkulasi OTIF, Quality, & FTR)**<br>File: `app/Services/VendorMaster/SupplierScorecardService.php`, `app/Data/VendorMaster/SupplierScorecardData.php` | Menghitung skor komposit (0–100) dan *Grade* (`A`/`B`/`C`/`D`) secara akurat untuk Supplier Impor (OTD %, Quality OK %, Responsiveness %) dan Supplier Lokal (Wednesday Punctuality %, Invoice First-Time-Right %, Audit Score %). |
| **T14** | `B1` | **Integrasi Scorecard ke Halaman Vendor Master, Supplier Audit, & Dashboard Supplier**<br>File: `app/Http/Controllers/Purchasing/ImportSupplierController.php`, `app/Http/Controllers/Purchasing/PurchasingLocalVendorController.php`, `app/Http/Controllers/Finance/FinanceVendorController.php`, `app/Http/Controllers/Purchasing/SupplierAuditController.php`, `resources/views/components/ui/supplier-scorecard.blade.php` | Kartu visual `<x-ui.supplier-scorecard>` tampil di profil vendor Purchasing/Finance, di layar review `SupplierAudit` sebagai acuan objektif auditor, dan di dashboard milik supplier yang bersangkutan. |

### Fase 4 — In-Context "Smart Panel" & Operational Homepages (`C1`, `C2`)

| ID Task | Fitur | Deskripsi Task & File Target | Kriteria Selesai (Acceptance Criteria) |
| :--- | :--- | :--- | :--- |
| **T15** | `C1` | **Implementasi `DocumentChainResolverService` dengan Penegakan RBAC & Isolasi Supplier**<br>File: `app/Services/DocumentChain/DocumentChainResolverService.php`, `app/Http/Controllers/ContextSmartPanelController.php`, `routes/web.php` | Membangun grafik rantai dokumen hulu-ke-hilir untuk entitas Impor (`PR → Quotation → PO → Shipment → QC → Claim`) dan Lokal (`Local PO → GR → Invoice → DRP Batch → Voucher/Payment`) dengan menegakkan `Gate::authorize('view', ...)` dan filter `supplier_id = auth()->id()`. |
| **T16** | `C1` | **Komponen UI `<x-ui.smart-panel>` (Slide-Over Drawer + Split PDF Previewer)**<br>File: `resources/views/components/ui/smart-panel.blade.php`, `resources/js/smart-panel.js`, `resources/views/layouts/app.blade.php` | Panel geser kontekstual di kanan layar menampilkan tab *Document Chain*, *Split PDF Preview* (pratinjau lampiran tanpa pindah halaman), dan *Status/SLA Summary*, mendukung tema terang/gelap (`--ui-dialog-bg`). |
| **T17** | `C1` | **Pemasangan Trigger Tombol "Smart Panel / Rantai Dokumen" di Halaman Operasional Utama**<br>File: `resources/views/purchasing/po/show.blade.php`, `resources/views/purchasing/requisitions/show.blade.php`, `resources/views/finance/invoices/show.blade.php`, `resources/views/finance/drp/show.blade.php`, `resources/views/supplier/po/show.blade.php`, `resources/views/local-supplier/invoices/show.blade.php` | Operator Purchasing, Finance, QC, dan Supplier dapat membuka Smart Panel dengan satu klik (`data-smart-panel-trigger`) dari baris tabel maupun header halaman detail dokumen. |
| **T18** | `C2` | **Pendaftaran & Implementasi Widget Baru pada Role-Based Operational Homepages**<br>File: `config/dashboard_widgets.php`, `app/Http/Controllers/Purchasing/PurchasingController.php`, `app/Http/Controllers/Finance/FinanceDashboardController.php`, `app/Http/Controllers/Supplier/SupplierController.php`, `app/Http/Controllers/LocalSupplier/InvoiceController.php` | Widget baru (*PO Acknowledgement Queue*, *3-Way Match Variance Queue*, *Supplier Scorecard Overview*, dan *SLA Alerts*) terdaftar di `config/dashboard_widgets.php` dan mematuhi aturan *smart-span* 12 kolom. |

### Fase 5 — Paritas Lokalisasi (`id`/`en`) & Verifikasi Menyeluruh (`@boost` Verification Ladder)

| ID Task | Fitur | Deskripsi Task & File Target | Kriteria Selesai (Acceptance Criteria) |
| :--- | :--- | :--- | :--- |
| **T19** | All | **Penambahan Kunci Bahasa Indonesia (`lang/id`) & Inggris (`lang/en`) + `JsTranslations`**<br>File: `lang/id/*.php`, `lang/en/*.php`, `app/Support/JsTranslations.php` | Seluruh label UI, pesan validasi, status badge, dan teks notifikasi untuk fitur `A1`, `A3`, `A4`, `B1`, `C1`, `C2`, `D3` memiliki pasangan kunci identik di `lang/id` dan `lang/en`. |
| **T20** | `D1–D3`, `C3` | **Eksekusi & Penambahan Unit/Feature Test untuk Sesi, Storage, dan URL Infor**<br>File: `tests/js/session-sync.test.mjs`, `tests/Feature/Auth/LogoutAllDevicesTest.php`, `tests/Feature/Architecture/InforPortalUrlParityTest.php` | Seluruh tes JS (`vitest` / `node --test`) dan PHPUnit untuk sesi, *logout all devices*, dan *long hashids + prefix URL* lulus 100%. |
| **T21** | `A1`, `A3` | **Feature Test untuk PO Acknowledgement & 3-Way Match Engine**<br>File: `tests/Feature/Procurement/PoAcknowledgementTest.php`, `tests/Feature/LocalInvoice/ThreeWayMatchVerificationTest.php` | Menguji skenario positif, perubahan *promise date*, percobaan akses lintas supplier (wajib 403/404), serta perhitungan varians *3-Way Match* pada berbagai kondisi GR. |
| **T22** | `A4`, `B1` | **Feature Test untuk SLA Watchdog Idempotency & Supplier Scorecard Accuracy**<br>File: `tests/Feature/Procurement/SlaWatchdogCommandTest.php`, `tests/Feature/VendorMaster/SupplierScorecardTest.php` | Memastikan command `sla:watchdog` tidak pernah mengirim notifikasi ganda untuk dokumen yang sama, serta rumus OTD %, Quality %, dan FTR % tepat secara matematis. |
| **T23** | `C1`, `C2` | **Feature Test untuk Isolasi Data Smart Panel & Smart-Span Dashboard**<br>File: `tests/Feature/DocumentChain/SmartPanelIsolationTest.php`, `tests/Feature/Dashboard/DashboardWidgetSmartSpanTest.php` | Memastikan Supplier A tidak pernah bisa mengintip kuotasi Supplier B melalui endpoint JSON Smart Panel, dan susunan widget baru di `config/dashboard_widgets.php` lolos uji `dashboard-smart-span-cases.json`. |
| **T24** | All | **Eksekusi Guard Suite Wajib Repositori ADASI**<br>Perintah: `BusinessTimeGuardTest`, `TranslationParityTest`, `SupplierDataIsolationTest`, `HashidUrlSecurityTest`, `ContextFreshnessTest` | Seluruh *architectural guard tests* bawaan repositori ADASI lulus tanpa satu pun pelanggaran. |

## 5. Matriks Mitigasi Risiko & Kegagalan Material (`@boost` Failure Modes)

| Potensi Mode Kegagalan (Failure Mode) | Dampak Risiko | Mitigasi Arsitektural dalam Rencana Ini |
| :--- | :--- | :--- |
| **Kebocoran Data Lintas Supplier di Endpoint Smart Panel (`C1`)** | Tinggi (IDOR / Keamanan Data) | Endpoint `/context-panel/{entityType}/{hash}` memvalidasi hash via `HashidCodec`, memanggil Policy model utama, dan pada saat merakit pohon dokumen (`PR → Quotation → PO`), setiap query anak wajib difilter dengan `->where('supplier_id', auth()->id())` bila pemanggil adalah `role:supplier`. |
| **Ketidakcocokan File Excel `whinh` Lama yang Tidak Memiliki Kolom Harga (`A3`)** | Tinggi (Gangguan Operasional Impor GR) | Kolom `unit_price` dan `receipt_amount` pada `local_goods_receipts` dibuat `nullable`. Jika file Excel `whinh3512m600` yang diunggah hanya berisi 4 kolom standar lama, status otomatis jatuh ke `PO_CEILING_ONLY` dan alur *Whole-GR Reservation* berjalan normal tanpa error. |
| **Banjir Notifikasi (*Notification Storm*) dari SLA Watchdog (`A4`)** | Sedang–Tinggi (Gangguan Pengguna) | Tabel `sla_escalation_logs` dengan *unique constraint* `[rule_key, subject_type, subject_id, stage]` menjamin setiap tahap pengingat (`REMINDER_1`, `ESCALATED`) hanya pernah dikirim **tepat satu kali** sepanjang umur siklus dokumen tersebut. |
| **Pelanggaran Aturan Zona Waktu di Query SLA & Scorecard (`A4`, `B1`)** | Sedang (Kegagalan CI `BusinessTimeGuardTest`) | Dilarang memanggil `now()->toDateString()` atau `Carbon::today()` secara langsung; seluruh batas tanggal menggunakan `BusinessTime::today()`, `BusinessTime::now()`, dan `BusinessTime::toStorage()`. |
| **Bentrokan Layout Grid 12 Kolom saat Menambah Widget Baru (`C2`)** | Rendah–Sedang (Visual Rusak) | Setiap widget baru di `config/dashboard_widgets.php` menggunakan ukuran `span` yang terdaftar di `DashboardWidgetService::SPANS` dan diuji terhadap algoritma `computeEffectiveSpans()`. |

## 6. Protokol Verifikasi & Perintah Pengujian (`Verification Ladder`)

Saat rencana ini dieksekusi ke dalam kode, urutan perintah verifikasi yang wajib dijalankan adalah:

```bash
# 1. Verifikasi Unit & Guard Arsitektur (Cepat & Wajib Hijau Pertama)
php artisan test --filter=BusinessTimeGuardTest
php artisan test --filter=TranslationParityTest
php artisan test --filter=HashidUrlSecurityTest
php artisan test --filter=ContextFreshnessTest

# 2. Verifikasi Isolasi Data & Keamanan Sesi (D1, D2, D3, C1, C3)
php artisan test --filter=SupplierDataIsolationTest
php artisan test --filter=LocalInvoiceScopeIsolationTest
php artisan test --filter=LogoutAllDevicesTest
php artisan test --filter=InforPortalUrlParityTest
php artisan test --filter=SmartPanelIsolationTest

# 3. Verifikasi Fungsional Pengadaan, 3-Way Match, SLA & Scorecard (A1, A3, A4, B1, C2)
php artisan test --filter=PoAcknowledgementTest
php artisan test --filter=ThreeWayMatchVerificationTest
php artisan test --filter=SlaWatchdogCommandTest
php artisan test --filter=SupplierScorecardTest
php artisan test tests/Feature/LocalInvoice --compact
php artisan test --filter=UnifiedPaymentEngineTest

# 4. Verifikasi Frontend JavaScript (D1, D2, C2)
npm test
```

## 7. Pembaruan Dokumentasi Kanonik (`context.md` & `AGENTS.md`)

Sesuai aturan kontrak di `context.md` dan `ContextFreshnessTest`, setelah seluruh task di atas selesai diimplementasikan:
1. Perbarui bagian **§4 (Import Material Procurement)** dan **§5 (Local Supplier Ecosystem)** di `context.md` untuk mencatat alur *PO Acknowledgement* (`A1`) dan *Value-Aware 3-Way Match* (`A3`).
2. Tambahkan katalog route baru (`/context-panel/{entityType}/{hash}` dan endpoint `acknowledge`) ke **§9 (API & Route Catalog)** di `context.md`.
3. Dokumentasikan variabel environment baru (`PORTAL_LOGOUT_ALL_DEVICES_ENABLED`, `PROCUREMENT_REQUIRE_PO_ACK`, dan konfigurasi `config/sla_monitors.php`) di `.env.example` dan `AGENTS.md`.
