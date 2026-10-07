# AGENTS.md — ADASI Portal Supplier

> Baca file ini sepenuhnya sebelum menulis satu baris kode pun.

> Diselaraskan dengan working tree repositori pada **28 September 2026**, termasuk migrasi sampai `2026_09_25_000002`. Keberadaan migrasi bukan bukti sudah diterapkan pada database lokal, staging, atau produksi.

## Inisialisasi Konteks dan Cara Kerja

Sebelum merencanakan, menganalisis, mengubah, atau mereview kode pada setiap task baru:

1. Baca `AGENTS.md`, [CLAUDE.md](CLAUDE.md), dan [claudes-cognitive-framework-for-laravel-development.md](claudes-cognitive-framework-for-laravel-development.md) sepenuhnya. Jika file tidak ada, nyatakan dan lanjutkan dengan panduan yang tersedia.
2. Cari `AGENTS.md` yang lebih spesifik pada direktori yang disentuh. Untuk Local Supplier, GA, vendor master, atau payment engine, baca [context.md](context.md) sebagai kontrak domain tambahan.
3. Cocokkan dokumentasi dengan routes, middleware, requests, policies, models, migrations, services, konfigurasi, dan tests yang terlibat. Framework kognitif adalah metodologi, bukan sumber fakta skema atau versi proyek.
4. Periksa `git status` dan diff awal. Pertahankan perubahan pengguna yang sudah ada; jangan melakukan commit, push, reset, clean, atau mutasi database tanpa instruksi yang sesuai.

Prioritas: aturan platform → instruksi eksplisit pengguna → `AGENTS.md` paling spesifik → fakta proyek dalam `CLAUDE.md` → framework kognitif → konvensi umum Laravel. Bila dokumentasi bertentangan dengan implementasi, verifikasi sumber kebenaran dan laporkan perbedaan yang material.

Pilih perubahan terkecil yang memenuhi kebutuhan. Pertahankan kontrak route, selector JavaScript, public model methods, otorisasi, snapshot keuangan, dan histori. Jangan menambah layer repository/service/interface, package, atau migrasi hanya untuk merapikan arsitektur. Pisahkan hasil **diinspeksi**, **diverifikasi lewat eksekusi**, **inferensi**, dan **belum diverifikasi** saat melaporkan pekerjaan.

---

## 🏭 Tentang Proyek

**Nama Sistem:** ADASI Portal Supplier  
**Perusahaan Mitra:** PT. Astra Daido Steel Indonesia (ADASI)  
**Jenis:** Portal pengadaan dan pembayaran dengan dua core pada auth dan UI shell bersama.
**Tujuan:** Mendigitalisasi pengadaan Impor, pengelolaan Local Supplier, invoice lokal, reimbursement GA, dan pembayaran melalui DRP.

| Core | Alur utama | Batas domain |
|---|---|---|
| **Import Procurement** | Period → PR → invitation → quotation → item award / konsolidasi PO → shipment → QC → material claim | Material, HS code, berat, kurs snapshot; UI English-first |
| **Local Supplier & Payment** | Local PO/GR → invoice → penerimaan fisik kasir → verifikasi A/B → DRP → Voucher Bayar → settlement; GA claim masuk payment engine yang sama | IDR, vendor master, pajak, rekening, reimbursement; UI Indonesian-first |

Keduanya berbagi `users`, notifikasi, export, dan design system. Jangan menyamakan `PurchaseOrder` Impor dengan `LocalPurchaseOrder`, atau memaksakan workflow dan aturan dokumen satu core ke core lainnya.

---

## 🛠️ Tech Stack

| Layer | Teknologi |
|---|---|
| Backend | PHP `^8.2` + Laravel `^12.0` (lihat `composer.json` dan `composer.lock`) |
| Frontend | Blade Template + Bootstrap 5 compatibility layer + prefixed Tailwind utilities (`tw-`) |
| Database | MySQL (Laragon untuk dev) |
| Interaktivitas | JavaScript / jQuery + AJAX, Alpine.js untuk shell & toast |
| Realtime | Pusher Channels melalui Laravel Echo; polling unread-count 30 detik sebagai fallback |
| Grafik | Chart.js (di-load per halaman via CDN) |
| Export Excel | Laravel Excel (Maatwebsite) — export data operasional async lewat queue `exports`; template import tetap direct download |
| PDF / QR | Laravel Dompdf + `chillerlan/php-qrcode`; receipt menggunakan verifikasi signature |
| Email | Laravel Mail + SMTP |
| Auth | Laravel built-in Auth + Middleware RBAC + 2FA (google2fa) + Turnstile |

---

## 👥 Role Pengguna

> ⚠️ Route operasional **WAJIB** diproteksi auth, role, dan scope/policy yang sesuai. Route publik yang disengaja (login, registrasi, verifikasi receipt) mengikuti proteksi khususnya.

| Role | Deskripsi |
|---|---|
| `admin` | Kelola user, kurs, master, audit, serta modul yang secara eksplisit menerima role admin |
| `purchasing` | PR, penawaran, PO Impor, shipment, klaim; Local PO/GR, DRP Purchasing, review registrasi/vendor sesuai route |
| `supplier` | Portal Impor dan/atau Local Supplier menurut scope — **hanya data milik sendiri** |
| `qc` | Input hasil inspeksi material, tentukan OK / NG |
| `finance` | Penerimaan fisik invoice, verifikasi, DRP, voucher, settlement/refund, vendor master, Local PO/GR |
| `ga` | Employee master, reimbursement claim, dan draft DRP GA |
| `accounting` | Role legacy pada jalur kompatibilitas `accounting.*`; jangan dianggap memiliki seluruh akses `finance.*` |

`users.role` adalah enum MySQL; tidak ada permissions package. `RoleMiddleware` memeriksa daftar role secara eksplisit—admin tidak otomatis melewati route yang tidak menyebutnya. Migrasi `2026_09_11_000001` memindahkan user accounting yang ada ke finance sambil mempertahankan nilai enum accounting.

### Scope Supplier dan Registrasi

- `supplier_scopes` memetakan `supplier_id → users.id` ke `import` dan/atau `local`. Gunakan `User::importEligible()`, `localEligible()`, `isImportEligible()`, atau `isLocalEligible()`; eligibility memeriksa role, `is_active`, `account_status = ACTIVE`, dan scope.
- Portal Impor memakai `supplier.scope:import`; Local Supplier memakai `supplier.scope:local`. Middleware menyimpan scope ke `session('supplier_context')`. Landing page dan menu menggunakan `PortalContext`; supplier dengan dua scope memilih konteks jika belum ada konteks valid.
- `EnforceSupplierDomain` menjaga shared endpoints antar core. Attachment `LocalPurchaseOrder` dan `SupplierOverpaymentRefund` memiliki pengecualian domain yang tetap memerlukan `AttachmentPolicy`; pertahankan keduanya.
- Registrasi publik melalui `SupplierRegistrationService`: akun awal `PENDING` dan tidak aktif, revisi membuat attempt baru, approval mengaktifkan akun dan menetapkan minimal satu scope secara atomik. Status akun (`PENDING`, `REVISION`, `ACTIVE`, `REJECTED`) berbeda dari status attempt (`PENDING`, `REVISION`, `APPROVED`, `REJECTED`).
- Pelacakan registrasi memakai reference/access key dan `registration.session`, bukan login operasional. Review dilindungi `role:admin,finance,purchasing`. Pertahankan validasi duplikasi email/NIB/NPWP, dokumen private, audit, dan pembatasan akses dokumen ke attempt terkait.
- Login normal mensyaratkan `is_active` dan `account_status = ACTIVE`; scope tidak boleh dipakai untuk melewati aktivasi/review.

### ⚠️ Isolasi Data Supplier

Supplier **tidak boleh** melihat atau mengubah data supplier lain. Untuk model yang memiliki kolom owner `supplier_id`—termasuk `Quotation`, `PurchaseOrder`, `Shipment`, `MaterialClaim`, `LocalInvoice`, dan `LocalPurchaseOrder`—query supplier-facing wajib difilter:

```php
->where('supplier_id', auth()->id())
```

> **Penting — semantik `supplier_id`:** owner supplier pada dokumen Impor, Local Supplier, scope, dan pivot invitation adalah foreign key ke **`users.id`**, *bukan* ke `suppliers.id`. Tabel `suppliers` hanya menyimpan profil perusahaan dan di-key oleh `user_id`.
>
> Karena itu `->where('supplier_id', auth()->id())` memang benar. Untuk model yang sudah ter-load, bandingkan `(int) $model->supplier_id === (int) auth()->id()`. Jangan join ke `suppliers` untuk keperluan otorisasi.

Tidak semua data supplier-facing memiliki kolom owner tersebut. `PurchaseRequisition` memakai `scopeVisibleToSupplier()` berdasarkan pivot invitation dan quotation milik supplier; percakapan memakai membership/`supplier_user_id` serta policy; `LocalGoodsReceipt` melalui PO pemiliknya. Ikuti scope atau policy model terkait—jangan menambahkan filter pada kolom yang tidak ada.

Jalankan `SupplierDataIsolationTest` untuk Impor dan `LocalInvoiceScopeIsolationTest` untuk Local Supplier setelah mengubah boundary terkait; registrasi memiliki suite `tests/Feature/SupplierRegistration/`.

Policy aktif berada di `app/Policies/` dan menggunakan penamaan model untuk auto-discovery. Pertahankan `Gate::authorize()` pada jalur Local Invoice, dokumen, Local PO, shipment, dan percakapan yang sudah menggunakannya. Model binding/hashid bukan pengganti otorisasi.

---

## 🗄️ Skema Database

> Skema di bawah adalah peta domain, bukan DDL lengkap. **Sumber kebenaran tetap `database/migrations/`** dan `$fillable` / `casts()` / relasi masing-masing model. Jumlah migrasi terus berubah; jangan memakai angka snapshot dokumentasi untuk menentukan status database.

```
users                  id, name, email, password, role, is_active, account_status,
                          + kolom auth security (2FA, session version, dsb.)
suppliers              profil perusahaan keyed by user_id; NPWP/NIB/PIC,
                          vendor_category, is_pkp, payment_term_days, dsb.
supplier_scopes        supplier_id → users.id, scope[import|local]
supplier_bank_accounts rekening vendor + status verifikasi
supplier_change_requests / supplier_master_documents   perubahan & dokumen vendor
supplier_registration_attempts / supplier_registration_access /
supplier_registration_audits   attempt, akses status, audit registrasi
periods                id, name, month (nullable = periode tahunan), year,
                          status[open|closed], created_by
purchase_requisitions  id, period_id, created_by, pr_number, notes,
                          status[draft|submitted|rejected|bidding|completed], created_at
purchase_requisition_suppliers  id, pr_id, supplier_id → users.id, invited_at
pr_items               id, pr_id, material_master_id, hs_code, material_name, quantity,
                          shape, thickness, d_inner, d_outer, width, length,
                          weight_needed, remark, + metadata resolusi HS code & berat
quotations             id, pr_id, supplier_id → users.id, exchange_rate_id, currency,
                          status[draft|submitted|revision_requested|accepted|rejected|all_unavailable],
                          submitted_at, reviewed_at, reviewed_by, reviewer_notes,
                          estimated_delivery, payment_terms, validity_period
quotation_items        id, quotation_id, pr_item_id, price_per_kg, amount,
                          is_available, available_qty, available_* (dimensi),
                          offered_weight_per_unit, offered_weight_source, notes
exchange_rates         id, currency, rate_to_idr, valid_from, created_by
purchase_orders        id, supplier_id → users.id, currency, exchange_rate_id, po_number,
                          status[draft|active|waiting_qc|claim_needed|overdue|completed|cancelled],
                          created_by, created_at, estimated_arrival, actual_arrival, notes
po_quotations          id, po_id, quotation_id     ← pivot PO ⇄ Quotation
po_documents           id, po_id, doc_type[invoice|bl|packing_list|form_e], status, updated_at
pr_item_awards         award per PR item, quotation item, supplier, PO assignment
po_item_progress_updates    histori progress per award
shipments / shipment_items / shipment_documents   pengiriman, shipped_qty,
                          actual_weight_kg, dokumen; relasi QC/claim ke shipment
qc_inspections         id, po_id, inspected_by, status[ok|ng], inspected_at
qc_items               id, inspection_id, pr_item_id, actual_thickness, actual_d_inner,
                          actual_d_outer, actual_width, actual_length, actual_weight, status
material_claims        id, inspection_id, po_id, submitted_by, supplier_id → users.id,
                          status, description, resolution_expected, deadline, supplier_response
claim_attachments      id, claim_id, file_path, uploaded_by   ← legacy relation; upload aktif memakai attachments
attachments            id, attachable_type, attachable_id, file_path, file_name,
                          file_type, uploaded_by, created_at
announcements          id, title, content, created_by, published_at
document_sequences     id, type (PR, PO, SHP, dsb.), year, month, last_number
material_masters / material_aliases / hs_code_rules   ← master data material & HS code
conversations / messages     ← negosiasi Purchasing ⇄ Supplier
export_jobs            id, user_id, label, export_class, export_args, file_name, disk, status
auth_audit_logs / auth_known_devices   audit keamanan & perangkat dikenal

local_purchase_orders id, po_number, supplier_id → users.id, total_amount,
                          currency[IDR], status[OPEN|CLOSED|CANCELLED], po_date, source
local_goods_receipts   id, local_purchase_order_id, gr_number, gr_date, qty,
                          description, status[AVAILABLE|RESERVED|INVOICED|CANCELLED],
                          current_invoice_id, source, created_by, updated_by
local_invoices        invoice lokal, local_purchase_order_id, invoice_amount (DPP),
                          tax_amount, tax_invoice_number, revision_number,
                          scheduled_physical_delivery_date, cashier_received_at,
                          payment_term_days_snapshot, due_date, ready_to_pay_at, status
local_invoice_revisions / local_invoice_documents / local_invoice_receipts /
local_invoice_physical_verifications / local_invoice_verifications /
local_invoice_status_histories   revision, dokumen, receipt, verifikasi, timeline
local_invoice_goods_receipts    relasi whole-GR + state & gr_qty_snapshot
employees / ga_claims / ga_claim_documents / ga_claim_receipts /
ga_claim_status_histories       reimbursement GA & historinya
payment_batches → payment_groups → payment_items    DRP; payable polymorphic
local_invoice_vouchers / local_invoice_payments / local_invoice_payment_transfers
                          voucher & settlement per invoice; PRIMARY/CORRECTION
supplier_overpayment_refunds    piutang lebih bayar & pengembalian
local_finance_audit_logs        audit perubahan master/reservasi/voucher/settlement
```

### Perubahan Skema dalam Repositori

Beberapa struktur awal proyek sudah berubah. Ini yang berlaku sekarang:

| Dulu | Sekarang |
|---|---|
| tabel `purchase_requirements` | **`purchase_requisitions`** — di-rename oleh migrasi `2026_06_08_160000`, beserta pivot `purchase_requisition_suppliers` dan nilai `conversable_type` di `conversations`. Model: `PurchaseRequisition` |
| `purchase_orders.quotation_id` (1 PO = 1 quotation) | **pivot `po_quotations`** (satu PO bisa mengonsolidasi beberapa quotation). Relasi aktif: `PurchaseOrder::quotations()` dan `Quotation::purchaseOrders()`, keduanya `belongsToMany`. Kolom `supplier_id`, `currency`, `exchange_rate_id` kini ada langsung di `purchase_orders` (migrasi `2026_05_22_000001`) |
| currency `USD` / `JPY` | **`USD`, `JPY`, `IDR`, `CNY`** pada `quotations`, `purchase_orders`, dan `exchange_rates` (migrasi `2026_05_28_000004`). Konstanta: `ExchangeRate::CURRENCIES` |
| amount quotation selalu berdasarkan berat PR | Penawaran baru memakai Offer Amount dari offered total weight; requested amount dan fallback legacy tetap tersedia pada `QuotationItem` |
| shipment berdasarkan alokasi berat saja | `shipment_items.shipped_qty` (pcs integer) dan `actual_weight_kg`; jangan menghidupkan kembali kolom berat/alokasi lama |
| satu dokumen lokal per tipe/revision | Migrasi `2026_09_17_000001` menghapus `local_document_type_unique`; request mendukung beberapa berkas per tipe |
| `local_goods_receipts.received_amount` / `gr_amount_snapshot` | Dihapus oleh `2026_09_24_160000`; GR menggunakan `qty`, `description`, dan `gr_qty_snapshot`. DPP dibatasi financial ceiling PO, bukan jumlah nominal GR |
| semua supplier langsung aktif | `account_status` dan workflow registrasi ditambahkan `2026_09_23_*`; eligibility menuntut akun ACTIVE dan scope yang sesuai |

Migrasi `2026_09_25_*` menambah indeks Ready to Pay/status dan price comparison. Periksa indeks yang sudah ada sebelum menambahkan yang baru.

### Soft Deletes

Dokumen legal/penting memakai Soft Deletes: `purchase_requisitions`, `quotations`, `purchase_orders`, `qc_inspections`, `material_claims`, `announcements`. Query pada tabel-tabel ini harus sadar soft delete, dan jangan menambahkan hard delete di sana.

> **4 tanggal penting yang harus selalu ditracking per PO:**
> - `purchase_requisitions.created_at` — tanggal permintaan dibuat
> - `purchase_orders.created_at` — tanggal PO dibuat
> - `purchase_orders.estimated_arrival` — estimasi material datang (diisi Purchasing)
> - `purchase_orders.actual_arrival` — material tiba melalui jalur shipment Purchasing atau jalur penerimaan QC legacy yang memenuhi guard

Jangan menghapus histori keuangan/GR/master karena model tersebut tidak memakai `SoftDeletes`. Jalur Local PO/GR menyediakan close/cancel, tanpa route delete.

### Attachment (Polymorphic)

Tabel `attachments` bersifat **polymorphic** — bisa dipakai oleh banyak modul:

```php
// Contoh relasi di Model
public function attachments(): MorphMany
{
    return $this->morphMany(Attachment::class, 'attachable');
}

// Menyimpan attachment
$model->attachments()->create([
    'file_path'   => $path,        // storage/app/private/attachments/...
    'file_name'   => $originalName,
    'file_type'   => $mimeType,
    'uploaded_by' => auth()->id(),
]);
```

Jalur polymorphic aktif meliputi MTC pada `QuotationItem`, bukti NG pada `QcInspection`, respons `MaterialClaim`, percakapan `Message`, `ShipmentDocument`, PDF `LocalPurchaseOrder`, dan bukti refund `SupplierOverpaymentRefund`. Relasi `attachments()` pada model lain belum tentu berarti ada endpoint upload aktif.

**Pengecualian domain yang disengaja:** invoice lokal memakai `local_invoice_documents` dengan linkage revision, GA memakai `ga_claim_documents`, dan vendor/registrasi memakai `supplier_master_documents`. Pertahankan tabel dan controller/policy masing-masing; jangan memigrasikannya ke polymorphic `attachments` hanya demi menyeragamkan struktur.

---

## 📁 Struktur File

```
app/
├── Models/             PurchaseRequisition.php, QuotationItem.php   (PascalCase)
├── Http/
│   ├── Controllers/    Admin/, Purchasing/, Supplier/, LocalSupplier/, Qc/,
│   │                   Finance/, Accounting/, Ga/, shared document/auth controllers
│   ├── Middleware/     RoleMiddleware.php, DecodeHashids.php, ...
│   └── Requests/       material/HS code/PR, LocalInvoice/, SupplierRegistration/, Auth/
├── Services/           Materials/, Auth/, LocalInvoice/, Payment/, Ga/, VendorMaster/, Employee/,
│                       ShipmentService, PrItemAwardService, SupplierRegistrationService
├── Data/Materials/     objek hasil immutable (ProcessedPrItemResult, dsb.)
├── Support/            BusinessTime, Money, PortalContext, ExportDispatcher,
│                       StatusHelper, NotificationCategory, NotificationDomain, ...
├── Exports/ Imports/   Laravel Excel
├── Jobs/               ProcessExportJob
├── Traits/             HasHashids.php
└── Policies/           QuotationPolicy.php, AttachmentPolicy.php, ConversationPolicy.php

resources/views/
├── layouts/            app.blade.php, auth.blade.php, guest.blade.php
├── partials/           navbar.blade.php, sidebar.blade.php, alerts.blade.php
├── components/ui/      komponen bersama: x-ui.button, x-ui.data-table, x-ui.icon, ...
├── purchasing/         dashboard, pr/, po/, comparison/, shipments/, drp/, ...
├── supplier/           dashboard, quotations/, po/, shipments/, price-history/, claims/, ...
├── local-supplier/     invoices/, purchase-orders/, vendor-profile/, ...
├── local-invoices/     shared invoice detail/form/table/filter/partials untuk multi-role
├── finance/            invoices/, local-procurement/, drp/, vouchers/, ...
├── accounting/         legacy invoice/report views
├── ga/                 employees/, claims/, drp/
├── qc/                 dashboard, inspections/
└── admin/              dashboard, users/, material-hs-code, ...

routes/
├── web.php             Seluruh route HTTP: Impor, Local Supplier, Finance, Accounting,
│                       GA, shared, registrasi, login, MFA, password, session/security
├── console.php         scheduler & console entrypoint
└── channels.php        otorisasi channel broadcasting

docs/
├── audits/             laporan audit teknis/non-UI
├── guides/             panduan operasional dan deployment
├── plans/              rencana, prompt, dan dokumen persiapan
└── results/            hasil implementasi teknis/non-UI

UI-REDESIGN-RESULT/     seluruh checkpoint, progress, dan hasil redesign UI

ADASI-UI-REDESIGN-PHASE2-MISSIONS/
└── ADASI-UI-REDESIGN-PHASE2-MISSIONS/
    └── *.md             kontrak dan source-of-truth mission Phase 2
```

### Struktur Dokumentasi

- Dokumen baru tidak ditaruh di root. Entrypoint `README.md`, `AGENTS.md`, `CLAUDE.md`, framework kognitif, dan `context.md` tetap di root; jangan memindahkan dokumen historis/root sebagai efek samping task lain.
- Simpan audit umum di `docs/audits/`.
- Simpan panduan operasional atau deployment di `docs/guides/`.
- Simpan planning, prompt, atau dokumen persiapan di `docs/plans/`.
- Simpan laporan hasil implementasi non-UI di `docs/results/`.
- Simpan seluruh laporan, checkpoint, progress, dan hasil final UI di `UI-REDESIGN-RESULT/`.
- Jangan membuat salinan laporan yang sama di root dan di folder tujuan.
- Sebelum memindahkan atau menghapus duplikat, bandingkan isi/hash dan pertahankan satu file canonical.
- Setelah memindahkan dokumen, perbarui semua referensi path relatif yang terkait.
- Jangan memindahkan kontrak mission Phase 2 dari folder source-of-truth-nya tanpa instruksi eksplisit.

---

## 🔐 Definisi Route

Letakkan seluruh route HTTP baru pada section domain yang sesuai di `routes/web.php`. Pertahankan batas group role/scope, pengecualian akses shared, dan urutan route statis sebelum parameter dinamis. `routes/console.php` tetap untuk command/scheduler dan `routes/channels.php` untuk otorisasi channel broadcasting; jangan memasukkan keduanya ke group HTTP. Contoh grup Impor:

```php
// Purchasing — perhatikan resource-nya bernama "requisitions", bukan "requirements"
Route::middleware(['auth', 'role:purchasing', 'purchasing.navigation'])
    ->prefix('purchasing')->name('purchasing.')->group(function () {
        Route::get('/dashboard', [PurchasingController::class, 'dashboard'])->name('dashboard');
        Route::resource('requisitions', PurchaseRequisitionController::class);
        // ...
    });

// Supplier
Route::middleware(['auth', 'role:supplier', 'supplier.scope:import'])->prefix('supplier')->name('supplier.')->group(function () {
    Route::get('/dashboard', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::resource('quotations', QuotationController::class)->only(['index', 'show']);
    // ...
});
```

Grup `admin` dan `qc` mengikuti pola yang sama. Route yang boleh diakses lebih dari satu role menyebut semuanya, contoh `role:qc,purchasing` untuk detail inspeksi dan `role:purchasing,supplier,admin` untuk PDF PO.

Penamaan route: `role.resource.action` — contoh: `purchasing.requisitions.create`

`Finance\LocalProcurementController` dipakai oleh `finance.local-procurement.*` **dan** `purchasing.local-procurement.*` untuk dataset Local PO/GR yang sama. View `local-invoices/` dipakai bersama supplier, accounting, finance, dan purchasing; perubahan di sana harus mempertimbangkan seluruh audience.

Shared document routes juga memakai role dan policy. Verifikasi receipt publik menggunakan signature QR dan throttle; jangan mengubahnya menjadi endpoint data invoice/GA tanpa proteksi. Registrasi publik merupakan pengecualian eksplisit terhadap grup `supplier.*` operasional.

### 🔗 Hashids pada URL

Model dengan trait `HasHashids` mengembalikan hash pada `getRouteKey()`. Daftar aktif mencakup:

- Impor/shared: `User`, `PurchaseRequisition`, `Quotation`, `PurchaseOrder`, `PrItemAward`, `Shipment`, `QcInspection`, `MaterialClaim`, `Conversation`, `ExportJob`, **`Attachment`**.
- Lokal: `LocalInvoice`, `LocalInvoiceDocument`, `LocalInvoicePayment`, `LocalInvoiceVoucher`, `LocalPurchaseOrder`, `LocalGoodsReceipt`, `PaymentBatch`, `PaymentGroup`, `SupplierOverpaymentRefund`, `SupplierMasterDocument`, `GaClaim`, `GaClaimDocument`, `SupplierRegistrationAttempt`.

Periksa trait model sebelum menambah URL; daftar ini tidak berarti semua model/domain item memakai hashid.

- Di view, pakai `$model->hash` atau kirim instance model ke `route()` — **jangan** `$model->id`.
- Route yang memakai parameter mentah (`{id}`, `{pr_id}`, `{po_id}`, dsb.) di-decode oleh middleware `DecodeHashids`. Kalau menambah route dengan nama parameter hashed yang baru, **daftarkan nama parameter itu di `HASHED_PARAM_KEYS`**, kalau tidak controller akan menerima string hash padahal mengharapkan integer.
- `Attachment` sekarang memakai hashid dan implicit binding pada `attachments.show`; jangan perlakukan sebagai ID plain integer atau menambahkannya kembali ke `PLAIN_ROUTE_PREFIXES`.
- Route plain untuk Period, Notification, Announcement, ExchangeRate, PoDocument, dan PrItem mengikuti `PLAIN_ROUTE_PREFIXES`; `verification.verify` dikecualikan melalui `PLAIN_ROUTE_NAMES`. Parameter implicit binding lokal dan `attempt` juga tercakup dalam `HASHED_PARAM_KEYS`; jaga penolakan raw integer.
- Mengirim integer mentah ke parameter hashed akan `abort(404)` — itu memang disengaja. Dijaga oleh `tests/Feature/HashidUrlSecurityTest.php`.

---

## 💱 Konversi Kurs

**Mata uang yang didukung:** `USD`, `JPY`, `IDR`, `CNY` (lihat `ExchangeRate::CURRENCIES`).

### 1. Requested Amount dan Offer Amount berbeda

`Supplier\QuotationController` me-query ulang PR item, men-sanitize availability/offer data, dan menghitung `amount` di backend. Total kiriman browser tidak dipercaya. Gunakan helper pada `QuotationItem` sesuai maknanya:

```php
// App\Models\QuotationItem
$requested = QuotationItem::calculateRequestedAmount($prItem, $pricePerKg);
$offer = QuotationItem::calculateOfferAmount($offeredTotalWeight, $pricePerKg);
$resolved = $quotationItem->resolved_amount; // stored offer + fallback legacy
```

`PrItem::total_weight` adalah **`weight_needed × quantity_value`** (minimum quantity 1). Itu basis requested amount; offer baru memakai offered total weight dan harga/kg. `calculateAmount()` tetap alias backward-compatible untuk requested amount, **bukan** pengganti universal perhitungan offer. `resolved_amount` memprioritaskan amount tersimpan, memakai offer fallback untuk row baru, dan requested fallback untuk row legacy; item unavailable menghasilkan nol.

Dimensi mengikuti `PrItem::relevantDimensionFields()` dan `QuotationItem::sanitizeAvailabilityData()`. Pertahankan quantity, rentang panjang, sumber berat offer, dan nulling dimensi yang tidak relevan; jangan memasukkan input off-shape atau menyamakan requested specification dengan supplier offer.

Pipeline material tetap di `app/Services/Materials/`: `PrItemProcessor`, resolver material/HS code, weight calculator, dan synchronizer. Gunakan hasil immutable di `app/Data/Materials/`; jangan memindahkan perhitungan ini ke Blade atau menduplikasinya dalam controller.

### 2. Kurs di-snapshot, bukan diambil live

`quotations` dan `purchase_orders` sama-sama menyimpan `exchange_rate_id`; pemilihan snapshot mengikuti jalur dokumen:

- quotation mengambil `ExchangeRate::latestRate($currency)` dan menyimpan ID-nya saat quotation di-submit; draft dapat memiliki `exchange_rate_id = null`;
- PO pada `PurchaseOrderGenerationService` memakai `exchange_rate_id` quotation dari award pertama dalam group supplier. Jika kosong, service mengambil kurs currency yang sama berdasarkan `valid_from` terbaru; ID hasilnya disimpan pada PO.

Untuk total dokumen, konversi ke IDR menggunakan amount authoritative yang sesuai offer/legacy, lalu dikalikan dengan `rate_to_idr` dari snapshot:

```php
$amount = $quotationItem->resolved_amount;
$idr = $amount * (float) $quotation->exchangeRate->rate_to_idr;
```

Modul Perbandingan Harga dan Riwayat Harga **join ke kurs snapshot** (`exchange_rates` via `exchange_rate_id` milik quotation/PO), bukan ke kurs terbaru — supaya angka historis tidak berubah setiap kali admin memasukkan kurs baru. Jangan mengganti join ini dengan `latestRate()`.

### 3. Kurs terbaru hanya dipilih ketika snapshot baru dibuat

```php
$rate = ExchangeRate::latestRate($currency); // dipakai saat quotation di-submit; cache 60 menit
```

Cache di-invalidate otomatis lewat `booted()` saat ada `ExchangeRate` baru dibuat.

Fallback kurs terbaru pada pembuatan PO hanya dipakai ketika snapshot quotation tidak tersedia. Setelah `exchange_rate_id` tersimpan pada dokumen, pembacaan historis harus menggunakan relasi snapshot, bukan mengulang query kurs terbaru.

> Jangan overwrite kurs lama — selalu `INSERT` baru agar histori dan snapshot tetap akurat.

---

## 📦 Format Nomor Dokumen

Format PR : `REQ/MM/YYYY/XXX` | Contoh: `REQ/05/2025/001`
Format PO : `PO/MM/YYYY/XXX`  | Contoh: `PO/05/2025/001`
Format Shipment : `SHP/MM/YYYY/XXX` — gunakan `Shipment::generateShipmentNumber()`.

Penomoran memakai tabel **`document_sequences`** (`type`, `year`, `month`, `last_number`, unique per `type+year+month`) di dalam transaksi dengan `lockForUpdate()`, supaya dua user yang submit bersamaan tidak mendapat nomor yang sama.

**Selalu pakai helper yang sudah ada — jangan menghitung nomor sendiri:**

```php
$prNumber = PurchaseRequisition::generatePrNumber();  // REQ/05/2025/001
$poNumber = PurchaseOrder::generatePoNumber();        // PO/05/2025/001
$shipmentNumber = Shipment::generateShipmentNumber();
```

> ⚠️ Pola lama `count() + 1` **tidak boleh dipakai lagi** — pola itu menghasilkan nomor duplikat saat ada submit bersamaan, dan tidak sinkron dengan `document_sequences`.
>
> Nomor PR baru di-generate saat PR **di-submit**; PR berstatus `draft` boleh punya `pr_number` bernilai `null`.

PO tidak memiliki lagi kolom `quotation_id`. Jangan membuat query, validasi, atau relasi baru yang mengandalkan `purchase_orders.quotation_id`; gunakan pivot `po_quotations` melalui `PurchaseOrder::quotations()` atau `Quotation::purchaseOrders()`.

---

## 📊 Modul Perbandingan Harga

Sudah ada 3 view terpisah di `resources/views/purchasing/comparison/` (`inter-supplier`, `historical`, `vs-best`), ditangani `PriceComparisonController`:

| View | Isi |
|---|---|
| Antar Supplier | Semua quotation satu PR ditampilkan side-by-side + grafik batang |
| Historis | Grafik garis harga satu material dari satu supplier lintas periode |
| vs Harga Terbaik | Harga saat ini vs `MIN(price_per_kg)` histori material yang sama |

> Semua perbandingan mengonversi harga ke IDR memakai **kurs snapshot** milik quotation/PO masing-masing (`LEFT JOIN exchange_rates ON ...exchange_rate_id`), dengan fallback ke kurs quotation bila PO belum punya. Jangan menggantinya dengan kurs terbaru — angka historis harus stabil.

Sisi supplier punya modul serupa yang terbatas pada datanya sendiri: `SupplierPriceHistoryController` + `SupplierPriceHistoryBuilder`.

---

## Item Award dan Shipment Impor

- `PrItemAwardService` memilih supplier per PR item; item harus available dan quotation harus award-eligible (`submitted`/`accepted`). `all_unavailable` bukan penawaran yang dapat di-award.
- PO komersial memakai item yang di-award jika award tersedia, dengan fallback legacy yang sudah ada. Gunakan `PurchaseOrder::commercialQuotationItems()`, `commercialQuotations()`, dan `withResolvedTotalIdr()`; jangan menjumlahkan seluruh isi quotation untuk PO yang hanya menerima sebagian item.
- `ShipmentService` menangani draft, submit, cancel, arrival, dokumen, dan alokasi di dalam transaksi/row locks. Fulfillment memakai `shipped_qty` integer pcs; `actual_weight_kg` adalah berat aktual, bukan pengganti quantity.
- Gunakan helper fulfillment dan `reconcileOperationalStatus()` pada `PurchaseOrder` untuk status/penerimaan. Jalur legacy arrival dijaga `isLegacyArrivalEligible()`/`hasLegacyOnlyArrivalState()`; jangan melewati guard untuk mengubah PO legacy menjadi shipment-based receiving.
- Dokumen shipment terhubung ke `ShipmentDocument::attachments()`; `customsDocumentationSummary()` dan `reconcileCustomsDocumentationStatus()` menjaga status Invoice/BL/Packing List/Form E pada PO.
- Perubahan award/alokasi/arrival/QC harus mempertahankan ownership supplier, FK/unique constraints, lock ordering, dan histori claim/replacement. Uji `ItemLevelAwardTest`, `PriceComparisonItemAwardTest`, `Purchasing/SplitAwardPoGenerationTest`, dan suite Shipment sesuai jalur yang disentuh.

---

## Local Supplier, GA, dan Unified Payment Engine

### Local PO/GR dan Financial Ceiling

Sumber implementasi: [LocalGrReservationService](app/Services/LocalInvoice/LocalGrReservationService.php), [LocalProcurementMasterService](app/Services/LocalInvoice/LocalProcurementMasterService.php), serta model/migrasi Local PO/GR.

- Purchasing dan Finance mengelola **satu** master `local_purchase_orders` / `local_goods_receipts`. PO lokal IDR-only; lifecycle PO `OPEN → CLOSED/CANCELLED`, GR `AVAILABLE → RESERVED → INVOICED`, dengan cancel/release sesuai service.
- Invoice authoritative memilih tepat satu PO dan minimal satu GR utuh milik supplier/PO tersebut. Server mengunci PO/GR, menolak GR duplikat/tidak tersedia, dan mencatat nomor/quantity snapshot. Tidak ada partial GR allocation.
- **Skema terbaru memakai quantity GR, bukan nominal GR.** `qty` positif (maks. empat desimal), `description`, dan `gr_qty_snapshot` menggantikan data nominal yang dihapus `2026_09_24_160000`. Jangan menulis query `received_amount`/`gr_amount_snapshot` atau memvalidasi DPP sebagai jumlah nominal GR.
- DPP (`invoice_amount`) divalidasi terhadap financial ceiling `LocalPurchaseOrder::total_amount`. `LocalGrReservationService` menjumlahkan invoice lain pada PO, mengecualikan `REJECTED` dan `CANCELLED`, lalu memakai BCMath untuk memastikan total DPP tidak melampaui PO. Ikuti filter aktual ini; jangan mengubah status yang mengurangi ceiling secara diam-diam.
- Submit mereservasi GR, revision mempertahankan/menyesuaikan reservasi secara atomik, rejection/cancellation/expiry me-release reservasi, dan approval Ready to Pay mengonsumsi GR. PO CLOSED hanya boleh melanjutkan set reservasi existing yang sama.
- Import XLSX memakai preview → token session → confirm, validasi ulang, transaksi, dan audit. Jalur terpisah PO/GR serta jalur combined tetap tersedia. Import create/add-only; jangan overwrite master atau mengirim ulang isi spreadsheet dari client sebagai sumber authoritative saat confirm.
- `LocalPoProviderInterface` di-bind ke `DatabaseLocalPoProvider` dalam `AppServiceProvider`; `FakeLocalPoProvider` tersedia untuk tests. Pertahankan dukungan provider/manual untuk data legacy pada jalur yang memang mendukungnya; referensi authoritative harus lewat ID PO/GR, tanpa GR sintetik.

### Invoice Lokal dan Verifikasi

Services di `app/Services/LocalInvoice/` memiliki state transitions; controller/FormRequest memvalidasi, mengotorisasi, dan mendelegasikan. Filter daftar bersama berada di `InvoiceQuery::filtered()`.

| Tahap | Invariant |
|---|---|
| Submit → `WAITING_PHYSICAL_DOCUMENT` | Nomor invoice unik per supplier; faktur pajak mengikuti eligibility vendor/PKP dan format Coretax 17 digit atau e-Faktur 16 digit pada parser/request; dokumen dikaitkan ke revision |
| Jadwal fisik | Rabu dan tidak di masa lalu, divalidasi backend `DeliveryScheduleValidator` memakai `BusinessTime` |
| Kasir → `UNDER_VERIFICATION` | `cashier_received_at` UTC menjadi trigger due date kalender bisnis + snapshot payment term vendor (1–365 hari) |
| Section A | Pemeriksaan invoice, tax invoice, PO, Surat Jalan, GR; aturan NOT_APPLICABLE mengikuti PKP/kategori vendor |
| Section B | Snapshot PPN verified dan PPh 23 / 4(2) / 21; `netPayableExact()` = DPP + verified PPN − withholding |
| Approval → `READY_TO_PAY` | Receipt fisik dan kedua section wajib valid; verification dikunci, GR dikonsumsi, `ready_to_pay_at` diisi |
| Revisi → `NEED_REVISION` | Resubmit membuat revision/history dan mereset receipt/review/due date sehingga membutuhkan penerimaan fisik baru |
| Expiry / reject / cancel | Ikuti service; missed delivery dua kali dapat menjadi `EXPIRED` dan me-release GR |

Gunakan konstanta status model dan helper legacy (`isReadyToPay()`, `isPaid()`) yang sudah ada. Nilai historis seperti `APPROVED`, `PAYMENT_SCHEDULED`, `COMPLETED`, dan `UNDER_REVIEW` masih muncul pada jalur kompatibilitas; jangan menghapusnya tanpa migrasi/rekonsiliasi yang memang diminta.

### DRP, Voucher, Settlement, Refund, dan GA

- Pertahankan container **`payment_batches → payment_groups → payment_items`**. `PaymentItem::payable()` polymorphic ke `LocalInvoice` atau `GaClaim`; tipe batch `SUPPLIER`/`GA` tidak boleh dicampur.
- `PaymentBatchService` mengunci payable, memeriksa Ready to Pay, rekening verified, dan reservasi DRP existing. Group menyimpan snapshot payee/rekening. Default fee supplier BCA nol, non-BCA Rp2.500; GA selalu nol. Override fee memerlukan alasan audit. Group kosong/cancelled dikeluarkan dari total; finalize memvalidasi ulang active payable.
- **ONE INVOICE → ONE VOUCHER BAYAR → ONE PAYMENT SETTLEMENT** untuk invoice authoritative. `LocalInvoiceVoucherService` membuat voucher FINAL dari verified net payable dan snapshot dokumen/rekening. Amount voucher adalah net payable invoice; fee/net transfer pada group memiliki makna tersendiri—jangan menyamakan kedua amount tanpa memeriksa service.
- `LocalInvoicePaymentService` mencatat satu PRIMARY transfer. Salah transfer kurang menjadi `CORRECTION_REQUIRED`; tambahan transfer CORRECTION berada pada settlement yang sama dengan alasan wajib, bukan cicilan yang direncanakan atau settlement kedua.
- Settlement difinalkan saat total aktual mencapai expected amount; invoice menjadi `PAID`. Group/batch mengikuti penyelesaian semua active invoice/group. Jalur bulk paid tetap harus memanggil logic voucher/settlement dan guard terkait.
- Lebih bayar membuat `SupplierOverpaymentRefund` sebagai piutang terpisah; pengembalian hanya sekali, dengan amount **tepat sebesar lebih bayar**, reference/date, dan proof private. Pertahankan audit dan konfigurasi rekening resmi di `config/finance.php`; jangan hardcode rekening baru di UI/export.
- `PaymentExecutionService::markGroupPaid()` tetap melayani invoice legacy tanpa authoritative PO link dan GA. Jangan gunakan jalur itu untuk melewati settlement per-invoice authoritative; jangan memutus kompatibilitas DRP historis.
- GA memakai `Employee`, `GaClaim`, dokumen/receipt/history sendiri, basic verification, verifikasi Finance, dan DRP GA. Bank fee reimbursement selalu nol, termasuk bank non-BCA.
- `PaymentForecastService` menghindari double counting: active DRP terlebih dahulu, lalu unbatched Ready to Pay; invoice masih dalam review tidak masuk forecast. Pertahankan snapshot, tanggal bisnis, dan perhitungan decimal melalui `App\Support\Money`/BCMath yang sudah digunakan.

**Drift dokumentasi yang sudah diketahui:** `context.md` masih memiliki contoh nominal GR harus sama dengan DPP dan aturan semua file polymorphic; `CLAUDE.md` masih memiliki tabel drift AGENTS lama, daftar hashid/policy, dan jumlah migrasi/baseline historis. Untuk implementasi terbaru, verifikasi migrasi/model/service terkait; bagian-bagian lama tersebut tidak boleh menghidupkan kembali kolom/aturan yang sudah berubah.

---

## 🔍 Fitur Pencarian & Filter

Semua halaman daftar (tabel) **wajib** memiliki fitur pencarian dan filter.

Halaman ber-volume tinggi yang memakai DataTables menggunakan mode **server-side** (`yajra/laravel-datatables`) — data diambil lewat endpoint JSON terpisah, bukan difilter seluruhnya di browser. Halaman lain seperti announcements, exchange rates, conversations, export history, dan daftar quotation Purchasing memakai pagination server-rendered. Ikuti pola aktif pada controller terkait; jangan mengubah strategi tabel tanpa mempertahankan filter, selector, dan endpoint yang ada.

```php
// Contoh: endpoint data untuk DataTables server-side
if ($request->ajax()) {
    return DataTables::eloquent($query)
        ->addColumn('status_badge', fn ($pr) => /* ... */)
        ->addColumn('action', fn ($pr) => /* tombol aksi */)
        ->rawColumns(['status_badge', 'action'])
        ->toJson();
}
```

Untuk daftar non-DataTables, filter di query dan **jangan lupa `->paginate()`**.

> Nilai filter berupa hashid (mis. `supplier_id` di query string) harus di-resolve dengan aman — ikuti pola `resolveSupplierFilter()` di `Purchasing/ExportController.php`: tolak digit mentah, `resolveRouteBinding()`, lalu pastikan role-nya sesuai.

**Field yang bisa dicari/difilter per halaman:**

| Halaman | Filter yang tersedia |
|---|---|
| Daftar Permintaan (Requisition) | No. PR, Nama material, HS Code, periode, status |
| Daftar Penawaran | Nama supplier, periode, status, mata uang |
| Daftar PO | No. PO, nama supplier, status, rentang tanggal |
| Riwayat Inspeksi QC | Nama material, status OK/NG, rentang tanggal |

---

## 📎 Fitur Upload / Attachment

File upload mengikuti model penyimpanan domain yang sudah aktif. Impor, PDF Local PO, dan proof refund memakai `attachments` polymorphic; invoice lokal, GA, dan vendor master memakai tabel dokumen khusus. Jangan menambah kolom file ad hoc atau menyeragamkan kedua pola tersebut.

**Ketentuan upload:**
- Selalu simpan ke disk `private` (`storage/app/private/`), pada direktori yang ditentukan service domain. **Jangan** ke `public/`.
- Batas ukuran umum: **10 MB** (`max:10240`).
- Tipe file **berbeda per modul** — jangan menyeragamkan aturan mimes tanpa alasan. Ikuti validasi yang sudah ada di controller yang sedang dikerjakan:

| Modul | Aturan yang berlaku sekarang |
|---|---|
| Penawaran (Supplier) — file MTC | `mimes:pdf,jpg,jpeg,png`, `max:5120` (5 MB) |
| QC Inspection | `mimes:jpg,jpeg,png`, `max:10240` — wajib jika status NG |
| Claim Material (Supplier) | `mimes:jpg,jpeg,png,pdf,xlsx,doc,docx`, `max:10240` |
| Conversation Message | `mimes:jpg,jpeg,png,pdf,xlsx,xls,doc,docx`, `max:10240`, maksimal 5 file |
| Shipment Document | `pdf,jpg,jpeg,png,xlsx,doc,docx`, 10 MB; metadata di `shipment_documents`, berkas di polymorphic attachments |
| Purchase Order / PO Document Impor | Tidak ada endpoint upload langsung; status impor juga diagregasi/disinkronkan dari dokumen shipment oleh helper `PurchaseOrder` |
| Local Invoice | `pdf,jpg,jpeg,png`, 5 MB per file; beberapa file per tipe (maks. 5 pada request saat ini), linkage revision wajib |
| GA Claim | `pdf,jpg,jpeg,png,xlsx,xls,doc,docx`, 10 MB |
| Vendor Master / Registrasi | Ikuti FormRequest/controller terkait; upload vendor-profile `pdf,jpg,jpeg,png`, 10 MB |
| Local PO PDF / ZIP | `UploadLocalPoDocumentRequest`: PDF/ZIP maks. 50 MB; nama PDF harus cocok dengan PO milik supplier, PDF magic bytes, batas ZIP, path traversal, dan rollback berkas dijaga `LocalPoDocumentService` |
| Bukti refund | `pdf,jpg,jpeg,png`, 10 MB, wajib; menggunakan private polymorphic attachment |

```php
// Pola penyimpanan yang dipakai di repo (stream + disk private)
$path = 'attachments/'.now()->format('Y/m').'/'.$file->hashName(); // biz-time:ignore storage path

$stream = fopen($file->getPathname(), 'r'); // getPathname(), bukan getRealPath() — Windows
try {
    Storage::disk('private')->put($path, $stream);
} finally {
    fclose($stream);
}

$model->attachments()->create([
    'file_path'   => $path,
    'file_name'   => $file->getClientOriginalName(),
    'file_type'   => $file->getMimeType(),
    'uploaded_by' => auth()->id(),
]);
```

Akses polymorphic file lewat `AttachmentController`/`AttachmentPolicy`. Dokumen lokal memakai `LocalInvoiceDocumentController`, `GaClaimDocumentController`, atau `SupplierMasterDocumentController` dengan policy masing-masing; dokumen registrasi melalui jalur akses/reviewer khusus. Jangan expose path storage langsung. Pertahankan header respons private/no-store dan cleanup berkas saat transaksi gagal.

---

## ⏰ Business Time Invariant (#11)

> **Wajib dibaca sebelum menyentuh logika tanggal, nomor dokumen, jadwal, atau kalender apa pun.**

`app.timezone` dan database selalu **UTC** — jangan diubah. `config/database.php` menetapkan timezone koneksi MySQL/MariaDB `+00:00`. Zona kalender bisnis berasal dari `app.business_timezone` / `APP_BUSINESS_TIMEZONE` (default `Asia/Jakarta`).

Semua logika kalender bisnis wajib melalui `App\Support\BusinessTime`:

| Kebutuhan | Gunakan |
|---|---|
| Tanggal hari ini | `BusinessTime::today()` |
| Momen sekarang | `BusinessTime::now()` |
| Parse string tanggal | `BusinessTime::parseDate('Y-m-d')` |
| Konversi timestamp UTC → zona bisnis | `BusinessTime::toBusiness($carbon)` |
| Konversi momen bisnis → UTC untuk disimpan ke DB | `BusinessTime::toStorage($carbon)` |
| Label zona waktu untuk tampilan | `BusinessTime::label()` |
| Format timestamp di view Blade | `@bizdt($model->created_at)` atau helper `bizdt()` |

**Kolom `date` vs `datetime`:**
- Kolom bertipe `date` (`due_date`, `scheduled_physical_delivery_date`, dsb.) adalah tanggal kalender murni tanpa timezone — bandingkan sebagai string, **jangan dikonversi zona**.
- Kolom bertipe `datetime`/`timestamp` disimpan UTC. Tampilkan hanya via `BusinessTime::format()` / `@bizdt`, selalu sertakan label zona waktu.

Carbon batas hari/minggu/bulan pada query kolom timestamp harus melewati `BusinessTime::toStorage()` sebelum dibind. Scheduler memakai `->timezone(config('app.business_timezone', 'Asia/Jakarta'))`.

**Guardrail test:** `tests/Unit/Architecture/BusinessTimeGuardTest.php` menolak `today()`, `Carbon::today()`, dan `now()->year|month|toDateString|format` di `app/` tanpa prefix `BusinessTime::`.
Penggunaan instant-UTC yang sah (nama file export, path storage, cache key) harus diberi anotasi `// biz-time:ignore <alasan>`.

---

## 🎨 Panduan UI

| Aspek | Ketentuan |
|---|---|
| Warna | Ambil dari design token CSS custom property (`--md-*`, `--ui-*`) di `resources/css/app.css`. Seed: biru `#1F5FA6`, aksen merah `#C0392B`. Jangan tulis hex langsung di Blade |
| Font | Inter (Google Fonts) |
| Utility CSS | Tailwind **berprefix `tw-`** dengan `preflight` dimatikan — class Tailwind tanpa prefix tidak akan berefek |
| Komponen | Pakai ulang `resources/views/components/ui/` (`x-ui.button`, `x-ui.data-table`, `x-ui.page-header`, `x-ui.status-chip`, dll.) sebelum membuat markup baru |
| Ikon | Lucide melalui `<x-ui.icon>`; jangan gunakan `bi-*` atau `<x-lucide-*>` langsung |
| Date Picker / Kalender | **WAJIB** gunakan custom component `<x-ui.date-picker>` untuk single date dan `<x-ui.date-range-picker>` untuk rentang tanggal. **DILARANG KERAS** menggunakan native browser `<input type="date">` di form/modal/filter mana pun (karena tampilan inkonsisten lintas browser/OS dan merusak visual design system). Jika diletakkan di dalam perulangan atau modal, pastikan atribut `id` diberi suffix unik (mis. `id="estimated_ready_date_{{ $item->id }}"`) agar DOM ID dan controller kalender tidak bentrok |
| Tabel | DataTables server-side — wajib untuk tabel dengan banyak baris |
| Badge status | Ambil label/kelas dari `App\Support\StatusHelper`, jangan tulis `match()` baru di view/controller |
| Notifikasi | AdasiToast untuk feedback transient; AdasiAlert/SweetAlert hanya untuk konfirmasi, prompt, atau keputusan blocking |
| Loading state | Spinner pada tombol submit saat proses berjalan |

Bootstrap 5 tetap menjadi compatibility layer. Pertahankan integrasi DataTables, dropdown, modal, offcanvas, atribut `data-bs-*`, serta selector JavaScript lama yang masih load-bearing.

Bootstrap, jQuery, SweetAlert2, dan dependency halaman tetap lewat CDN; DataTables hanya dimuat pada halaman yang mendeklarasikan `@section('uses-datatables', true)`, Chart.js per halaman. Vite membundel `resources/css/app.css` dan `resources/js/app.js`, dengan modul kalender/shell dimuat sesuai pola aktif.

Class yang dirender dari sisi server (mis. tombol aksi DataTables) tidak terbaca oleh content scanner Tailwind — kalau menambah class semacam itu, daftarkan di `safelist` pada `tailwind.config.js`.

Komponen kalender sendiri merender native `type="date"` sebagai progressive-enhancement base; ini disengaja, bukan pelanggaran larangan menulis native date input langsung pada halaman. Upgrade berada di `resources/js/calendar.js`; helper tanggal murni di `calendar-core.js`, dengan lazy import `cally`. Untuk jadwal fisik lokal, gunakan `allowed-days-of-week`, dan tetap validasi Rabu di backend `DeliveryScheduleValidator`.

Target UI adalah enterprise ERP yang ringkas: hierarki tipografi, border, radius kecil, filter yang mudah dijangkau, aksi utama terlihat, dan state fokus/disabled yang jelas. Pertahankan kontrak mission Phase 2; jangan menambah dekorasi gradient/glassmorphism, hero marketing, atau dinding KPI yang menghambat tabel operasional.

### 🔔 Notifikasi, Pusher, dan Polling

- Provider realtime yang digunakan proyek adalah **Pusher Channels**, bukan Reverb. Backend `NotificationService` mengirim `SystemNotification` melalui channel `database` dan `broadcast`.
- Frontend di `resources/views/layouts/app.blade.php` menginisialisasi Laravel Echo hanya jika `broadcasting.default === 'pusher'`, key Pusher terisi, dan cluster Pusher terisi. Client dibuat dengan `broadcaster: 'pusher'`.
- Package dan konfigurasi Reverb masih ada di repository, tetapi **tidak digunakan** oleh jalur frontend maupun intent deployment. Jangan menjalankan, mengaktifkan, atau mendokumentasikan Reverb sebagai provider realtime aktif.
- `.env.example` sekarang menetapkan **`BROADCAST_CONNECTION=pusher`** dengan placeholder `PUSHER_*`. Placeholder bukan credential aktif; konfigurasi runtime/deployment harus diverifikasi terpisah.
- Fallback yang selalu aktif untuk user terautentikasi adalah `updateBadges()` saat halaman dimuat dan polling setiap **30 detik** ke endpoint unread-count notification; polling unread-count chat juga berjalan untuk role Purchasing dan Supplier. Fallback ini memperbarui badge count, tetapi tidak menjalankan callback Echo yang menyisipkan item notifikasi dan menampilkan toast realtime. Polling badge tetap menjadi baseline ketika Pusher tidak aktif atau gagal tersambung.

- Pertahankan deterministic event key/UUID pada `NotificationService` untuk idempotensi. Kategori memakai `NotificationCategory`; domain `global/import/local` memakai `NotificationDomain`. Summary, count, list/read-state, dan URL harus mengikuti boundary role/scope/konteks aktif; jangan hanya menyembunyikan link di view.
- Dropdown navbar memuat `notifications.summary` secara lazy lewat `NotificationSummaryService`; pertahankan invalidasi/cache state frontend saat konteks atau notifikasi berubah.

**Struktur layout Blade:**

```blade
{{-- resources/views/layouts/app.blade.php --}}
<body>
    @include('partials.sidebar')
    <main>
        @include('partials.navbar')
        @include('partials.alerts')  {{-- flash messages --}}
        @yield('content')
    </main>
</body>
```

---

## Export, Scheduler, Auth, dan Database Safety

### Export dan Scheduler

- Export operasional memakai `ExportDispatcher::dispatch()` → `ExportJob` → `ProcessExportJob` pada queue `exports`, private disk, status polling `public/assets/js/async-export.js`, dan download berotorisasi. Tambahkan export class baru ke `SUPPORTED_EXPORT_CLASSES`; kirim argumen scalar/filter/ID yang JSON-serializable, bukan Eloquent model.
- Handoff export saat ini **atomik pada database yang sama**: `ExportJob` dan queue database dimasukkan dalam transaksi bersama. `assertAtomicQueueConfiguration()` mensyaratkan database driver, connection yang sama, dan `after_commit=false` (sync diterima untuk tests). Jangan mengubahnya menjadi dispatch setelah commit atau Redis tanpa mendesain ulang jaminan handoff tersebut.
- Template import tetap direct download. Jangan mengubah export operasional menjadi render inline atau meniadakan ownership saat polling/download.
- `composer dev` sudah mendengarkan `exports,default`. Deployment tetap membutuhkan worker dan scheduler sesuai [README.md](README.md).
- `routes/console.php`: prune `AuthAuditLog` 02:10, `exports:cleanup` 02:20, reminder/expiry invoice lokal 08:00, semuanya memakai business timezone dan `withoutOverlapping()`.

### Auth dan Konfigurasi

- Auth security di `app/Services/Auth/`, `AuthSecurityServiceProvider`, dan `config/auth_security.php`: 2FA/recovery codes, Turnstile, identity rate limiter, session revocation, password confirmation, known devices, dan audit. Pertahankan respons 429 HTML/AJAX melalui pola `RateLimitResponse` yang aktif.
- Middleware web mencakup `DecodeHashids`, `EnforceAuthSessionSecurity`, `AddSecurityHeaders`, dan `EnforceSupplierDomain`; middleware alias/exception handling di `bootstrap/app.php`. `NoStoreResponse` dipakai pada route yang mendeklarasikan `no-store`, bukan diasumsikan global.
- Event auto-discovery dimatikan (`withEvents(discover: false)`); listener baru harus diregistrasikan eksplisit. Providers berada di `bootstrap/providers.php`.
- `.env.example` adalah template konfigurasi, bukan bukti setup produksi. Session encryption/secure cookie, trusted proxy, Pusher, rekening Finance, queue, dan kredensial eksternal harus mengikuti lingkungan deployment. Jangan menyalin secret dari `.env` ke dokumen, browser, log, atau response.

### Database dan Migrasi

- Jangan menjalankan `migrate`, `migrate:fresh`, rollback, `db:wipe`, truncate, atau bulk write terhadap database aplikasi hanya untuk memverifikasi task dokumentasi/audit. Perubahan database memerlukan scope/instruksi eksplisit; gunakan `migrate:status` untuk inspeksi ledger ketika relevan.
- File migrasi, ledger `migrations`, dan physical schema adalah tiga bukti berbeda. SQL catch-up dan PHP migrations adalah jalur alternatif; jangan menerapkan keduanya tanpa pemeriksaan. Back up dan uji pada restored staging sebelum DDL produksi; jangan mengganti data produksi dengan dump lokal.
- Pertahankan composite FK, uniqueness invoice/revision/payment, primary transfer guard, dan rollback guards. Validasi aplikasi tidak menggantikan constraint/row lock. Pertimbangkan existing rows, nullable/default/backfill, lock ordering, transaksi, retry/idempotensi, dan cleanup berkas saat gagal.
- Untuk perhitungan keuangan exact, gunakan helper `Money`/BCMath yang aktif, bukan round-trip float baru. Query daftar tetap paginated/server-side, agregasi dilakukan di database bila sesuai; eager-load hanya relasi yang dipakai.

---

## 🚫 Larangan

| # | Jangan |
|---|---|
| 1 | Taruh query SQL mentah di View |
| 2 | Return data supplier lain ke pengguna yang login sebagai supplier |
| 3 | Hardcode nilai kurs — selalu ambil dari tabel `exchange_rates` |
| 4 | Buat satu Controller raksasa untuk semua role — pisahkan per role |
| 5 | Lupa `->paginate()` kalau data bisa banyak |
| 6 | Simpan file upload ke `public/` atau expose path storage langsung — gunakan disk `private` dan akses melalui controller/policy |
| 7 | Taruh logika bisnis di View — taruh di Controller atau Service class |
| 8 | Buat kolom file ad hoc atau mengganti tabel dokumen domain yang sudah ada; ikuti pola polymorphic/dedicated yang berlaku |
| 9 | Percaya total dari browser, melewati row lock/constraint, atau membuat settlement kedua untuk satu invoice authoritative |
| 10 | Menyatukan core/scope supplier, melewati review akun, atau membocorkan metadata/dokumen lewat shared endpoints |

---

## ✅ Checklist Sebelum Buat Fitur Baru

- [ ] Route sudah diproteksi middleware role yang sesuai
- [ ] Query supplier memakai `supplier_id` ownership atau scope/policy visibility yang sesuai model
- [ ] Validasi menggunakan FormRequest atau `$request->validate([...])` sesuai pola aktif modul
- [ ] Feedback transient session/AJAX ditampilkan melalui AdasiToast; konfirmasi blocking tetap memakai AdasiAlert/SweetAlert
- [ ] Nama route sesuai format `role.resource.action`
- [ ] Tabel daftar memiliki filter dan server-side DataTables/pagination sesuai volume/pola aktif
- [ ] Upload memakai private disk dan tabel dokumen/policy yang sesuai domain
- [ ] Transaksi, constraint, snapshot, audit, dan boundary core/role/scope dipertahankan
- [ ] Verifikasi sesuai risiko telah dijalankan; keterbatasan disebutkan

---

## Workflow Verifikasi

Jalankan pemeriksaan terkecil yang relevan, lalu perluas sesuai risiko. Jangan memakai hasil log lama sebagai baseline terbaru atau menganggap failure pre-existing tanpa bukti perbandingan.

```powershell
# Targeted tests dan suite; database test harus aman sebelum feature tests.
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test --filter=<TestName>
php artisan test tests/Feature/LocalInvoice --compact
composer test

# Verifikasi perubahan kode/view/assets yang relevan.
php -l path/to/changed-file.php
php vendor/bin/pint --test path/to/changed-file.php
php artisan view:cache
php artisan route:list --no-ansi
npm.cmd run build
node --test tests/js/calendar.test.mjs
node --test tests/js/unsaved-changes.test.mjs

git diff --check

# Inspeksi database / integrity read-only jika task menyentuh domain terkait.
php artisan migrate:status
php artisan local-invoices:reconcile --json

# Operasional lokal/deployment, bukan quality check untuk dijalankan sembarang.
php artisan queue:work database --queue=exports,default --tries=3 --timeout=600
php artisan schedule:run
```

- PHPUnit memakai **MySQL `adasi_portal_test`**, bukan SQLite (`phpunit.xml`, `.env.testing.example`). `AppServiceProvider` memiliki guard testing untuk database aplikasi/nama non-test. Pastikan konfigurasi/cached config/DB_URL tidak mengarahkan test ke database aplikasi. Jangan menjalankan suite `RefreshDatabase` secara paralel pada database test yang sama.
- Test concurrency menggunakan subprocess pada `tests/Support/`; pertahankan pengujian MySQL row locks yang nyata, jangan menggantinya dengan simulasi single-process.
- `composer setup` menjalankan migrasi dan setup environment; jangan memakainya untuk quality check pada existing database.
- Build diperlukan untuk perubahan Blade classes/CSS/JS/Tailwind; `npm.cmd` menghindari shim PowerShell yang dapat diblokir execution policy. Kalender memakai file test eksplisit pada Windows.

| Area perubahan | Coverage utama (pilih sesuai scope) |
|---|---|
| Auth / registrasi | `tests/Feature/Auth/`, `tests/Feature/SupplierRegistration/` |
| Supplier isolation / hashids | `SupplierDataIsolationTest`, `LocalInvoice/LocalInvoiceScopeIsolationTest`, `HashidUrlSecurityTest` |
| Materials / offer / awards | `tests/Unit/Materials/`, `MaterialCalculationTest`, `PurchaseRequisitionMaterialAutomationTest`, `ItemLevelAwardTest`, `PriceComparisonItemAwardTest`, `Purchasing/SplitAwardPoGenerationTest` |
| Shipment / QC / dokumen | `ShipmentAndPartialDeliveryTest`, `ShipmentDocumentsAndQcIntegrationTest`, `PoCustomsDocumentationShipmentSyncTest`, `ShipmentUiAndExportTest` |
| Local PO/GR / invoice | `tests/Feature/LocalInvoice/`, `LocalInvoiceSubmissionV2Test`, `FinanceVerificationV2Test`, `CashierReceiptAndExpiryTest` |
| Pembayaran / forecast | `UnifiedPaymentEngineTest`, `FinanceDrpPaidTest`, `PaymentForecastAndReportingTest`, `tests/Feature/Finance/` |
| Vendor / GA | `VendorMasterV2Test`, `VendorMasterViewsTest`, `SupplierVendorProfileTest`, `GaClaimWorkflowTest` |
| Timezone / kalender | `tests/Feature/Timezone/`, `BusinessTimeTest`, `Architecture/BusinessTimeGuardTest`, `CalendarComponentTest`, tests JS kalender |
| Export / asset loading | `AsyncExportQueueTest`, `DetailExportSecurityTest`, `MissionFourExportTest`, `FrontendAssetLoadingTest` |

Untuk perubahan dokumentasi saja, inspeksi sumber, pemeriksaan path/tautan, review diff, dan `git diff --check` memadai; tidak perlu menjalankan feature suite/build tanpa perubahan runtime.

Sebelum menyatakan selesai, cek requirement, authorization/validation, snapshot/data integrity, race/duplicate/partial failure, compatibility, query/memory cost, dan hasil verifikasi yang benar-benar dieksekusi. Laporkan terpisah automated/static, browser/cross-role, staging/migration, PDF/print, dan produksi yang belum diverifikasi. Passing tests tidak otomatis berarti production-ready.

## UI Language Policy

The portal has two functional cores with different language conventions:

- Import Core: English-first / full English UI.
- Local Core: Indonesian-first UI.
- Local Core may retain English terminology only when it is an established business,
  domain, technical, product, or system term, or when an existing project convention
  explicitly requires it.

Do not treat mixed Indonesian/English text as a defect by itself.

Before changing UI terminology:
1. Identify which core the view belongs to.
2. Search for the same terminology in equivalent views.
3. Determine whether the term is an established canonical term.
4. Change only unintended or inconsistent usage.
5. Do not translate domain terminology merely for stylistic consistency.
