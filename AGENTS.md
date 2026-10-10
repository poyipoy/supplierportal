# AGENTS.md — ADASI Portal Supplier

> Baca file ini sepenuhnya sebelum menulis satu baris kode pun.

> Diselaraskan dengan working tree repositori pada **28 September 2026**, termasuk migrasi sampai `2026_09_25_000002`. Keberadaan migrasi bukan bukti sudah diterapkan pada database lokal, staging, atau produksi.
>
> Diperbarui **8 Oktober 2026** untuk perubahan sesudahnya (migrasi sampai `2026_10_07_000003`): preferensi user dan lokalisasi, Advanced Export, UoM pada GR, kanonikalisasi tipe klaim GA, pencabutan `refund_reference`, penghapusan draft DRP oleh role GA, serta (migrasi `2026_10_08_000002`–`000003`) kuesioner & Company Profile registrasi supplier dan aturan berkas tidak hilang saat validasi gagal. **9 Oktober 2026:** fitur Supplier Audit (migrasi `2026_10_09_000001`–`000002`), lihat bagian "Supplier Audit". Bagian yang tidak menyebut perubahan itu terakhir dicocokkan penuh pada 28 September.

> **File ini adalah satu-satunya sumber fakta proyek untuk semua agent.** [CLAUDE.md](CLAUDE.md) hanya berisi `@AGENTS.md` (import) agar Claude Code memuat file ini; jangan menaruh fakta proyek di `CLAUDE.md`. Perubahan aturan, skema, atau konvensi ditulis **di sini**, satu kali.

## Inisialisasi Konteks dan Cara Kerja

Sebelum merencanakan, menganalisis, mengubah, atau mereview kode pada setiap task baru:

1. Baca `AGENTS.md` dan [claudes-cognitive-framework-for-laravel-development.md](claudes-cognitive-framework-for-laravel-development.md) sepenuhnya. [CLAUDE.md](CLAUDE.md) hanya meng-import file ini dan tidak menyimpan fakta proyek sendiri, jadi tidak perlu dibaca terpisah. Jika file tidak ada, nyatakan dan lanjutkan dengan panduan yang tersedia.
2. Cari `AGENTS.md` yang lebih spesifik pada direktori yang disentuh. Untuk Local Supplier, GA, vendor master, atau payment engine, baca [context.md](context.md) sebagai kontrak domain tambahan.
3. Cocokkan dokumentasi dengan routes, middleware, requests, policies, models, migrations, services, konfigurasi, dan tests yang terlibat. Framework kognitif adalah metodologi, bukan sumber fakta skema atau versi proyek.
4. Periksa `git status` dan diff awal. Pertahankan perubahan pengguna yang sudah ada; jangan melakukan commit, push, reset, clean, atau mutasi database tanpa instruksi yang sesuai.

Prioritas: aturan platform → instruksi eksplisit pengguna → `AGENTS.md` paling spesifik (direktori yang disentuh) → `AGENTS.md` root → [context.md](context.md) untuk detail Local Supplier/GA/payment (punya drift yang diketahui, lihat akhir bagian Local Supplier) → framework kognitif → konvensi umum Laravel. **Kode, migrasi, dan tes adalah sumber kebenaran akhir.** Bila dokumentasi bertentangan dengan implementasi, verifikasi ke sumber itu dan laporkan perbedaan yang material.

Pilih perubahan terkecil yang memenuhi kebutuhan. Pertahankan kontrak route, selector JavaScript, public model methods, otorisasi, snapshot keuangan, dan histori. Jangan menambah layer repository/service/interface, package, atau migrasi hanya untuk merapikan arsitektur. Pisahkan hasil **diinspeksi**, **diverifikasi lewat eksekusi**, **inferensi**, dan **belum diverifikasi** saat melaporkan pekerjaan.

Aturan kerja tambahan:

- Pahami misi sebelum mengedit dan nyatakan ulang cakupannya bila ambigu. Baca file terdampak, pemanggilnya, dan tesnya; repo ini punya konvensi yang tidak standar Laravel, jadi asumsi Laravel bawaan sering salah.
- Ikuti pola yang sudah dipakai modul yang disentuh dan pertahankan business logic kecuali misi memintanya diubah.
- Batasi perubahan pada misi: tanpa refactor oportunistik, pembersihan modul lain, atau rename yang merambat. Jangan mengganti arsitektur yang berfungsi hanya karena alternatifnya tampak lebih rapi.
- Pertahankan kompatibilitas route, nama route, kolom DB, dan public model methods; view dan JS yang ada bergantung padanya.
- Jangan menambah package kecuali kemampuan yang ada benar-benar tidak cukup. Bila terpaksa, pertimbangkan dampak bundle/CDN/provider (`bootstrap/providers.php`)/publish config, pin versi eksak, dan jelaskan alasannya. Utamakan yang sudah ada: Laravel Excel, dompdf, yajra DataTables, hashids, google2fa, blade-lucide-icons, Alpine, Chart.js, SweetAlert2, `cally`.
- Sebutkan apa yang diverifikasi setelah implementasi dan apa yang tidak bisa diverifikasi.

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
| Lokalisasi | `lang/en` dan `lang/id`; bahasa dipilih per user (lihat bagian Lokalisasi dan Preferensi Tampilan) |

Aplikasi ini server-rendered Blade tanpa API layer. Satu-satunya endpoint JSON adalah feed DataTables, polling status export, badge notifikasi/chat, katalog kolom export, dan beberapa endpoint search/preview.

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
| `ga` | Employee master, pengajuan/revisi reimbursement claim, dan basic verification. Batch DRP GA dibuat oleh Finance (`finance.drp.ga*`), bukan role `ga` |
| `accounting` | Role legacy pada jalur kompatibilitas `accounting.*`; jangan dianggap memiliki seluruh akses `finance.*` |

`users.role` adalah enum MySQL; tidak ada permissions package. `RoleMiddleware` memeriksa daftar role secara eksplisit—admin tidak otomatis melewati route yang tidak menyebutnya. Migrasi `2026_09_11_000001` memindahkan user accounting yang ada ke finance sambil mempertahankan nilai enum accounting.

### Scope Supplier dan Registrasi

- `supplier_scopes` memetakan `supplier_id → users.id` ke `import` dan/atau `local`. Gunakan `User::importEligible()`, `localEligible()`, `isImportEligible()`, atau `isLocalEligible()`; eligibility memeriksa role, `is_active`, `account_status = ACTIVE`, dan scope.
- Portal Impor memakai `supplier.scope:import`; Local Supplier memakai `supplier.scope:local`. `SupplierScopeMiddleware` menyimpan scope ke `session('supplier_context')`. Landing page dan menu menggunakan `PortalContext`; supplier dengan dua scope memilih konteks jika belum ada konteks valid.
- `EnforceSupplierDomain` selalu aktif di stack web dan menjaga shared endpoints antar core: user finance/accounting (`isLocalOperator()`) mendapat 403 pada `attachments.*`, `conversations.*`, dan `shared.pdf.*`; supplier tanpa scope `import` mendapat 403 pada `exports.*`, `supplier.*`, dan endpoint shell Impor yang sama. Attachment `LocalPurchaseOrder`, `SupplierOverpaymentRefund`, dan `SupplierAudit` memiliki pengecualian domain pada `attachments.show` yang tetap memerlukan `AttachmentPolicy`; pertahankan ketiganya.
- Registrasi publik melalui `SupplierRegistrationService`: akun awal `PENDING` dan tidak aktif, revisi membuat attempt baru, approval mengaktifkan akun dan menetapkan minimal satu scope secara atomik. Status akun (`PENDING`, `REVISION`, `ACTIVE`, `REJECTED`) berbeda dari status attempt (`PENDING`, `REVISION`, `APPROVED`, `REJECTED`).
- Wizard registrasi 5 langkah: Akun & Profil → Kuesioner Kualitas & Kepatuhan → Legalitas/PIC/Bank → Dokumen → Tinjau. Kuesioner (6 pertanyaan Ya/Tidak) didefinisikan **hanya** di `App\Support\SupplierComplianceQuestionnaire` (key, jawaban yang diharapkan, rules, normalisasi); wajib dijawab tetapi **tidak memblokir** pengiriman. Jawaban yang menyimpang dari harapan hanya ditandai untuk reviewer. Jawaban disimpan di `suppliers.compliance_questionnaire` dan disalin ke `submission_snapshot` attempt.
- Password registrasi memakai `SupplierRegistrationRequest::registrationPasswordRule()` (min 8, huruf besar & kecil, angka, simbol; cek password bocor di production), **sengaja berbeda** dari `Password::defaults()` (min 12) yang berlaku untuk akun internal dan ganti password. Checklist JS di `auth/supplier-register.blade.php` harus tetap identik dengan rule ini.
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

Policy aktif berada di `app/Policies/` dan memakai **auto-discovery**: tidak ada array `$policies` atau `Gate::policy()`, sehingga policy harus bernama `App\Policies\<Model>Policy` agar terikat. Saat ini ada 13: `Attachment`, `Conversation`, `ExportPreset`, `GaClaimDocument`, `LocalInvoice`, `LocalInvoiceDocument`, `LocalInvoiceReceipt`, `LocalProcurementImport`, `LocalPurchaseOrder`, `Quotation`, `Shipment`, `SupplierAudit`, `SupplierMasterDocument`. Pertahankan `Gate::authorize()` / `$this->authorize()` pada jalur yang sudah menggunakannya. Untuk aksi Core 2 baru, tambahkan method policy, bukan `abort_unless` inline. Sebagian besar controller Core 1 masih memakai pengecekan role/ownership inline; ikuti gaya controller yang sedang dikerjakan. Model binding/hashid bukan pengganti otorisasi.

---

## 🗄️ Skema Database

> Skema di bawah adalah peta domain, bukan DDL lengkap. **Sumber kebenaran tetap `database/migrations/`** dan `$fillable` / `casts()` / relasi masing-masing model. Jumlah migrasi terus berubah; jangan memakai angka snapshot dokumentasi untuk menentukan status database.

```
users                  id, name, email, password, role, is_active, account_status,
                          + kolom auth security (2FA, session version, dsb.)
suppliers              profil perusahaan keyed by user_id; NPWP/NIB/PIC,
                          vendor_category, is_pkp, payment_term_days,
                          compliance_questionnaire (JSON kuesioner registrasi), dsb.
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
export_jobs            id, user_id, label, export_class, export_args, file_name, disk, status,
                          format[xlsx|csv], export_options
export_presets         user_id, export_key, name, columns, filters, format, is_default
                          ← preset pribadi Advanced Export; unique per user+export_key+name
user_preferences / notification_mutes   tema, density, sidebar, dashboard, regional, locale,
                          preferensi notifikasi per user; mute percakapan
auth_audit_logs / auth_known_devices   audit keamanan & perangkat dikenal

local_purchase_orders id, po_number, supplier_id → users.id, total_amount,
                          currency[IDR], status[OPEN|CLOSED|CANCELLED], po_date, source
local_goods_receipts   id, local_purchase_order_id, gr_number, gr_date, qty, uom (nullable, 3 char),
                          description, status[AVAILABLE|RESERVED|INVOICED|CANCELLED],
                          current_invoice_id, source, created_by, updated_by
local_invoices        invoice lokal, local_purchase_order_id, invoice_amount (DPP),
                          tax_amount, tax_invoice_number, revision_number,
                          scheduled_physical_delivery_date, cashier_received_at,
                          payment_term_days_snapshot, due_date, ready_to_pay_at, status
local_invoice_revisions / local_invoice_documents / local_invoice_receipts /
local_invoice_physical_verifications / local_invoice_verifications /
local_invoice_status_histories   revision, dokumen, receipt, verifikasi, timeline
local_invoice_goods_receipts    relasi whole-GR + state, gr_qty_snapshot & gr_uom_snapshot
employees / ga_claims / ga_claim_documents / ga_claim_receipts /
ga_claim_status_histories       reimbursement GA & historinya
payment_batches → payment_groups → payment_items    DRP; payable polymorphic
local_invoice_vouchers / local_invoice_payments / local_invoice_payment_transfers
                          voucher & settlement per invoice; PRIMARY/CORRECTION
supplier_overpayment_refunds    piutang lebih bayar & pengembalian
local_finance_audit_logs        audit perubahan master/reservasi/voucher/settlement
supplier_audit_templates → supplier_audit_sections → supplier_audit_criteria
                          checklist audit berversi (sheet_number untuk export 4 sheet)
supplier_audits           supplier_id → users.id, template, period_label, due_date (date),
                          status[ASSIGNED|DRAFT|SUBMITTED|REVISION_REQUESTED|RESULT_PUBLISHED|CANCELLED]
supplier_audit_answers    snapshot section/kriteria + answer[YES|NO] + score 1–5 (CHECK D3)
supplier_audit_status_histories   timeline audit (termasuk deadline_changed)
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
| GR tanpa satuan | `2026_10_07_000001` menambah `local_goods_receipts.uom` dan `local_invoice_goods_receipts.gr_uom_snapshot` (keduanya nullable, maks. 3 karakter) |
| `supplier_overpayment_refunds.refund_reference` | Dihapus `2026_10_07_000003`; `down()` hanya mengembalikan kolom kosong. Jangan menulis atau memvalidasi reference refund. `refund_date` dan proof tetap ada |
| `ga_claims.claim_type` `Entertain Sales` / `UPD Sales` / `UPD GA` / `Reimburse/Claim` | Enum menjadi `Entertainment`, `Business Travel`, `Reimburse/Claim` (`2026_10_07_000002`); `down()` melempar exception bila data kanonik sudah ada |
| role `ga` membuat draft DRP GA | Dihapus. Finance membuat batch GA (`finance.drp.ga.create`); `payments:cleanup-legacy-ga-drafts` (preflight dulu, `--execute` untuk hapus) membersihkan reservasi draft lama |
| preferensi user hanya tema/sidebar | `user_preferences` (`2026_09_28`–`2026_10_04`) kini juga memuat dashboard, regional, notifikasi, dan `locale`; `notification_mutes` untuk mute percakapan |
| export hanya XLSX tanpa opsi | `export_jobs.format` / `export_options` dan tabel `export_presets` (`2026_10_07_000001`–`000002`) |
| registrasi supplier tanpa kuesioner / company profile | `suppliers.compliance_questionnaire` JSON nullable (`2026_10_08_000002`) berisi `{version, answers, answered_at}`; enum `supplier_master_documents.document_type` + `COMPANY_PROFILE` (`2026_10_08_000003`, `down()` melempar exception bila data `COMPANY_PROFILE` sudah ada) |

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
│                       ShipmentService, PrItemAwardService, SupplierRegistrationService,
│                       UserPreferenceService, RegionalDisplayFormatter, NotificationService
├── Data/Materials/     objek hasil immutable (ProcessedPrItemResult, dsb.)
├── Support/            BusinessTime, Money, PortalContext, ExportDispatcher, Export/ExportDefinitions,
│                       SpreadsheetCellSanitizer, JsTranslations, StatusHelper, NotificationCategory,
│                       NotificationDomain, ...
├── Exports/ Imports/   Laravel Excel; Exports/Advanced/ = ExportDefinition + katalog kolom
├── Jobs/               ProcessExportJob, GenerateWorkbookJob, FinalizeExportJob
├── Listeners/          ApplyNotificationPreferences (didaftarkan manual di AppServiceProvider)
├── Traits/             HasHashids.php
└── Policies/           11 policy, auto-discovery (lihat bagian Isolasi Data Supplier)

resources/views/
├── layouts/            app.blade.php, auth.blade.php, guest.blade.php
├── partials/           navbar.blade.php, sidebar.blade.php, alerts.blade.php
├── components/ui/      komponen bersama: x-ui.button, x-ui.data-table, x-ui.icon, ...
├── components/export/  advanced-modal.blade.php (UI Advanced Export Tier 1)
├── purchasing/         dashboard, pr/, po/, comparison/, shipments/, drp/, ...
├── supplier/           dashboard, quotations/, po/, shipments/, price-history/, claims/, ...
├── local-supplier/     invoices/, purchase-orders/, vendor-profile/, ...
├── local-invoices/     shared invoice detail/form/table/filter/partials untuk multi-role
├── finance/            invoices/, local-procurement/, drp/, vouchers/, ...
├── accounting/         legacy invoice/report views
├── ga/                 employees/, claims/   (draft DRP GA sudah dihapus)
├── qc/                 dashboard, inspections/
└── admin/              dashboard, users/, material-hs-code, ...

lang/
├── en/ id/             terjemahan UI; kedua locale wajib berpasangan (js.php untuk AdasiI18n)

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

- Impor/shared: `User`, `PurchaseRequisition`, `Quotation`, `PurchaseOrder`, `PrItemAward`, `Shipment`, `QcInspection`, `MaterialClaim`, `Conversation`, `ExportJob`, `ExportPreset`, **`Attachment`**. Total 27 model dengan trait ini per 9 Oktober 2026 (working tree); verifikasi ulang dengan `grep -rl "App.Traits.HasHashids" app/Models/`.
- Lokal: `LocalInvoice`, `LocalInvoiceDocument`, `LocalInvoicePayment`, `LocalInvoiceVoucher`, `LocalPurchaseOrder`, `LocalGoodsReceipt`, `LocalProcurementImport`, `PaymentBatch`, `PaymentGroup`, `SupplierOverpaymentRefund`, `SupplierMasterDocument`, `GaClaim`, `GaClaimDocument`, `SupplierRegistrationAttempt`, `SupplierAudit` (parameter route `{supplierAudit}`, implicit binding).

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
- **Skema terbaru memakai quantity GR, bukan nominal GR.** `qty` positif (maks. empat desimal), `uom` opsional (maks. 3 karakter, `2026_10_07_000001`), `description`, serta `gr_qty_snapshot` / `gr_uom_snapshot` menggantikan data nominal yang dihapus `2026_09_24_160000`. Jangan menulis query `received_amount`/`gr_amount_snapshot` atau memvalidasi DPP sebagai jumlah nominal GR.
- DPP (`invoice_amount`) divalidasi terhadap financial ceiling `LocalPurchaseOrder::total_amount`. `LocalGrReservationService` menjumlahkan invoice lain pada PO, mengecualikan `REJECTED` dan `CANCELLED`, lalu memakai BCMath untuk memastikan total DPP tidak melampaui PO. Ikuti filter aktual ini; jangan mengubah status yang mengurangi ceiling secara diam-diam.
- Submit mereservasi GR, revision mempertahankan/menyesuaikan reservasi secara atomik, rejection/cancellation/expiry me-release reservasi, dan approval Ready to Pay mengonsumsi GR. PO CLOSED hanya boleh melanjutkan set reservasi existing yang sama.
- Import PO dan GR terpisah mendukung XLSX/CSV/XLS melalui preview background → token milik pemohon → confirm background pada queue `imports`, staging private, validasi ulang, transaksi seluruh file, dan audit. CSV memakai susunan kolom Infor yang sama; XLSX/CSV maksimal 70.000 baris data, XLS maksimal 65.535 pada sheet pertama. Fitur import gabungan PO + GR sudah dihapus. Import create/add-only; jangan overwrite master atau mengirim ulang isi spreadsheet dari client sebagai sumber authoritative saat confirm. Lihat `docs/guides/LOCAL-PROCUREMENT-IMPORTS.md`.
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
- Lebih bayar membuat `SupplierOverpaymentRefund` sebagai piutang terpisah; pengembalian hanya sekali, dengan amount **tepat sebesar lebih bayar**, tanggal pengembalian (`refund_date`), dan proof private. Kolom `refund_reference` sudah dihapus (`2026_10_07_000003`). Pertahankan audit dan konfigurasi rekening resmi di `config/finance.php`; jangan hardcode rekening baru di UI/export.
- `PaymentExecutionService::markGroupPaid()` tetap melayani invoice legacy tanpa authoritative PO link dan GA. Jangan gunakan jalur itu untuk melewati settlement per-invoice authoritative; jangan memutus kompatibilitas DRP historis.
- GA memakai `Employee`, `GaClaim`, dokumen/receipt/history sendiri, basic verification oleh role `ga`, verifikasi Finance, dan DRP GA yang dibuat Finance (`createGaBatch`); role `ga` tidak membuat batch. Tipe klaim kanonik: `Entertainment`, `Business Travel`, `Reimburse/Claim`. Bank fee reimbursement selalu nol, termasuk bank non-BCA.
- `PaymentForecastService` menghindari double counting: active DRP terlebih dahulu, lalu unbatched Ready to Pay; invoice masih dalam review tidak masuk forecast. Pertahankan snapshot, tanggal bisnis, dan perhitungan decimal melalui `App\Support\Money`/BCMath yang sudah digunakan.

### Supplier Audit (Purchasing ⇄ Supplier Local)

Rencana & keputusan: [docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-20261008.md](docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-20261008.md). Service di `app/Services/SupplierAudit/`.

- Template checklist berversi (`SupplierAuditTemplateSeeder` + `database/data/supplier_audit_template_v1.json`; 16 bagian, 4 sub-bagian, 121 kriteria). Template terisi otomatis oleh migrasi `2026_10_09_000002` (memanggil seeder yang idempoten), jadi tidak ada langkah seeder manual. Template yang sudah dipakai tidak diedit; perubahan = versi baru.
- Penugasan membuat snapshot seluruh kriteria; view/export hanya membaca snapshot. Satu audit aktif per supplier (lock baris `users` di `SupplierAuditAssignmentService`). Hanya `User::localEligible()`.
- D3: Ya → Score 1–5 (boleh kosong saat draft, wajib saat submit); Tidak → Score null (FormRequest + service + CHECK constraint).
- Form supplier satu halaman (navigasi bagian + autosave, `resources/js/supplier-audit-form.js`). Autosave memakai `PATCH local-supplier.supplier-audits.autosave` (`throttle:120,1`, JSON, hanya draft; `action=submit` ditolak 422). Submit tetap lewat route `update` (`throttle:30,1`). Daftar Purchasing memakai tab `?queue=review|waiting|late|done|all` dengan progres dari `SupplierAudit::withAnswerProgress()` (tanpa N+1). Rencana UX: [docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-UX-20261009.md](docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-UX-20261009.md).
- **Blok invoice baru (D13):** supplier dengan audit `ASSIGNED/DRAFT/REVISION_REQUESTED` yang `due_date < BusinessTime::today()` tidak bisa mengajukan invoice baru — ditegakkan `SupplierAuditInvoiceGate` di `InvoiceSubmissionService::submit()` dan `InvoiceController@create/searchPurchaseOrders`. Resubmit revisi & cancel invoice **tidak** diblokir; jangan pindahkan blok ke `LocalInvoicePolicy::create()` karena method itu dipakai ulang resubmit/cancel. Purchasing bisa mengubah/menghapus deadline.
- Export detail async (`SupplierAuditExport`, `GeneratesWorkbook`, 4 sheet, kolom Point/Temuan kosong, Sub Total `=SUM`) hanya pada `SUBMITTED`/`RESULT_PUBLISHED`.
- Notifikasi: event supplier memakai domain `LOCAL`, event `supplier_audit.submitted` ke Purchasing memakai `GLOBAL`. Command terjadwal `supplier-audits:notify-invoice-blocked` hanya mengirim notifikasi (idempoten per deadline).

**Drift dokumentasi yang sudah diketahui:** [context.md](context.md) masih memuat aturan bahwa jumlah nominal whole-GR harus sama dengan DPP, dan aturan "Polymorphic Storage Discipline" yang mewajibkan semua upload memakai `attachments`; keduanya sudah tidak berlaku (lihat bagian GR dan Attachment di atas). Selain dua hal itu, `context.md` tetap rujukan state machine dan rumus Local Supplier/GA/payment. `CLAUDE.md` tidak lagi menyimpan fakta sendiri sehingga tidak ada drift di sana. Untuk implementasi terbaru, verifikasi migrasi/model/service terkait; bagian lama tidak boleh menghidupkan kembali kolom/aturan yang sudah berubah.

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
| Vendor Master / Registrasi | Ikuti FormRequest/controller terkait; upload vendor-profile `pdf,jpg,jpeg,png`, 10 MB. Registrasi: NIB/NPWP/SKNR wajib, SPPKP/SKD opsional (`pdf,jpg,jpeg,png`, 5 MB); Company Profile opsional (`COMPANY_PROFILE`, `pdf,jpg,jpeg,png`, 10 MB) |
| Local PO PDF / ZIP | `UploadLocalPoDocumentRequest`: PDF/ZIP maks. 50 MB; nama PDF harus cocok dengan PO milik supplier, PDF magic bytes, batas ZIP, path traversal, dan rollback berkas dijaga `LocalPoDocumentService` |
| Bukti refund | `pdf,jpg,jpeg,png`, 10 MB, wajib; menggunakan private polymorphic attachment |
| Hasil Supplier Audit | `UploadSupplierAuditResultRequest`: `pdf,xlsx,jpg,jpeg,png`, 10 MB; polymorphic attachment append-only pada `SupplierAudit` (penggantian wajib alasan, file lama = riwayat); supplier hanya boleh mengunduh file terbaru (`AttachmentPolicy`) |

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

### Native File Security dan ZIP PO (working tree 9 Oktober 2026)

- Migrasi native inspection `2026_10_09_000003` dan batch dokumen PO `000004` bersifat additive; keberadaan file bukan bukti migrasi/deployment sudah diterapkan. Panduan: [docs/guides/NATIVE-FILE-SECURITY.md](docs/guides/NATIVE-FILE-SECURITY.md).
- Upload baru memakai `FileInspectionService` dengan profile domain, checked storage, SHA-256 dan `file_inspection_id`. Download dan reuse memakai `FileAccessGuard` setelah policy. `VALIDATED` adalah inspeksi format/resource native, bukan hasil antivirus; berkas historis tetap `HISTORICAL_UNVERIFIED` menurut cutover identity. Jangan memberikan bypass historis pada row baru tanpa inspection.
- ZIP PO memakai bounded metadata/streaming dan tracker tersendiri; default tetap 50 MiB / 100 raw entries / 100 MiB actual expanded. Queue `po-documents` dan async admission default nonaktif sampai host/budget terverifikasi. Global queue `after_commit=false` untuk export/import tidak berubah.
- `po-documents:reconcile` memakai lock admission/processing untuk recovery dan cleanup namespace miliknya. Jangan menghapus prepared file aktif, attachment existing, atau tracker batch yang diperlukan histori/download.
- Aturan permanen reassignment supplier/nomor PO setelah ada PDF belum diubah; tetap memerlukan persetujuan bisnis terpisah.

### Berkas tidak boleh hilang saat validasi gagal

Form dengan upload berkas **tidak boleh** mereset atau menghilangkan berkas yang sudah dipilih user ketika submit gagal karena ada data yang salah. POST biasa yang gagal validasi me-redirect dan me-reload halaman, dan browser tidak bisa mengisi ulang `<input type="file">`; `old()` hanya menyelamatkan teks. Pola wajib:

- Tandai `<form>` dengan `data-async-submit`. `resources/js/async-form-submit.js` mengirim form via `fetch` (tanpa reload) setelah handler validasi form-level selesai (submit yang sudah `preventDefault()` diabaikan). Respons 422 menampilkan ringkasan error bertaut ke field, pesan inline, dan event `adasi:form-errors`, `adasi:reveal-field` (wizard pindah ke step field tersebut), dan `adasi:form-settled` (pulihkan tombol/spinner). `<x-ui.file-upload>` menampilkan error-nya sendiri dari event itu. Tanpa `fetch`, form jatuh ke POST biasa.
- Controller tujuan: bila `$request->expectsJson()`, flash data session seperti biasa lalu kembalikan `response()->json(['redirect' => route(...)])`. Respons non-JSON tetap redirect seperti semula. **Jangan pernah** menaruh rahasia (mis. access key registrasi) di body JSON; data sekali-tampil tetap lewat session flash.
- Validasi, otorisasi, throttle, dan service tidak berubah; 422 dari FormRequest maupun `ValidationException` service otomatis menjadi JSON.
- Jangan menyimpan berkas sementara di server atau browser (IndexedDB/localStorage) hanya untuk menyelamatkan berkas; pola async di atas sudah cukup dan tidak membuka celah penyimpanan pada endpoint publik.
- Form yang sudah patuh: registrasi supplier (`auth/supplier-register`), revisi registrasi (`auth/supplier-registration-edit`), invoice supplier baru & revisi (`local-invoices/form`). Form upload lain (GA claim, quotation, QC, shipment, claim, vendor profile, overpayment, upload PO) **wajib** dimigrasikan ke pola ini saat disentuh berikutnya. Form baru dengan upload berkas wajib langsung memakainya.

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

## 🌐 Lokalisasi dan Preferensi Tampilan per User

UI sepenuhnya dwibahasa dan bahasanya adalah **preferensi per user**, bukan konstanta per core. (Kebijakan istilah per core ada di "UI Language Policy" pada akhir file.)

- **Locale:** `user_preferences.locale` (`en` default atau `id`; `config/user_preferences.php`), di-resolve `UserPreferenceService` dan diterapkan pada setiap request web oleh `ApplyUserLocale` (tamu memakai `session('locale')`). Job queue yang merender teks harus membawa locale pemohon dan menyetelnya di worker, seperti `ProcessExportJob` dan `GenerateWorkbookJob`.
- **Pengecualian dokumen tetap:** PDF PO Impor (`pdf.po-pdf`, `App\Support\PurchaseOrderPdf`) mengikuti formulir PNR261178: label English, tanggal `16 Sep 2026`, angka `633,000.00`, Qty pcs dari `fulfillment_quantity` (offer supplier dengan fallback quantity PR legacy), dan Unit Price tampilan per pcs (`resolved_amount / pcs`, BCMath, maksimum empat desimal; `-` bila pcs nol). Berat kg tetap di Description. Pembulatan Unit Price tidak menghitung ulang total, dan harga/kg quotation tidak berubah. Form tidak mengikuti locale/preferensi regional user. Timestamp PO tetap melalui `BusinessTime`, amount tetap `resolved_amount` dari commercial/award-scoped lines, PPN ditampilkan `-` tanpa kalkulasi pajak baru, dan total serta kotak tanda tangan kosong hanya pada halaman terakhir. Ketentuan ini tidak mengubah PDF QC atau dokumen PO Lokal.
- **Teks UI baru** ditulis sebagai key `__('domain.key')` yang ada di **kedua** `lang/en/<domain>.php` dan `lang/id/<domain>.php`. Teks JS memakai `window.AdasiI18n.t('js.key')` / `.choice()` (`public/assets/js/adasi-i18n.js`), diisi dari `lang/*/js.php` oleh `App\Support\JsTranslations`. Label status berasal dari `lang/*/status.php` lewat `StatusHelper::label()`.
- **Penjaga di CI:** `TranslationParityTest` gagal bila file en/id berbeda domain, key, atau nama `:placeholder`, atau bila key statis `__()` / `AdasiI18n` di `app/`, `resources/views`, `resources/js`, `public/assets/js`, atau `config/` tidak ada di salah satu locale. `UserFacingCopyInventoryTest` dan `tests/Support/user-facing-copy-audit.php` melacak teks yang ditulis langsung; jangan menulis ulang snapshot audit historis di `UI-REDESIGN-RESULT/` sebagai efek samping task lain. `TranslationContentTest` mengunci glosarium status kanonik.
- Jangan menerjemahkan data yang diinput user atau data bisnis (nama supplier/material, catatan, nama file, nomor dokumen) maupun nilai mesin (konstanta `STATUS_*` tetap `UPPER_SNAKE`).
- **Bahasa kode:** kode, komentar, key terjemahan, serta nama route/variabel memakai bahasa Inggris. Sebagian dokumen domain, docblock tes, dan komentar migrasi berbahasa Indonesia; ikuti file di sekitarnya, jangan menyeragamkan.
- **Format regional** juga per user (zona tampilan, format tanggal/jam/angka; `config/regional_display.php`). `RegionalDisplayFormatter` adalah singleton per request yang dibangun dari preferensi user aktif: `date()`, `timestamp()`, `businessTime()`, `number()`. View menerimanya sebagai `$regionalFormatter` **hanya bila** terdaftar di salah satu blok `View::composer` pada `AppServiceProvider`; view baru yang memformat angka/tanggal harus ditambahkan ke sana (atau me-resolve service-nya). Jangan menulis `number_format()` / `->format()` sendiri untuk tampilan. `layouts.app` mendapat `$userPreferences`, `$preferenceFrontendPayload`, dan `$quickAccessItems` dari composer-nya sendiri.

---

## 🎨 Panduan UI

| Aspek | Ketentuan |
|---|---|
| Warna | Ambil dari design token CSS custom property (`--md-*`, `--ui-*`) di `resources/css/app.css`. Seed: biru `#1F5FA6`, aksen merah `#C0392B`. Jangan tulis hex langsung di Blade |
| Font | Inter (Google Fonts) |
| Utility CSS | Tailwind **berprefix `tw-`** dengan `preflight` dimatikan — class Tailwind tanpa prefix tidak akan berefek |
| Komponen | Pakai ulang `resources/views/components/ui/` (`x-ui.button`, `x-ui.data-table`, `x-ui.page-header`, `x-ui.status-chip`, dll.) sebelum membuat markup baru |
| Ikon | Lucide melalui `<x-ui.icon>`, yang memetakan nama `bi-*` lama ke Lucide dan jatuh ke `circle-help` bila nama tidak dikenal; jangan gunakan `bi-*` atau `<x-lucide-*>` langsung |
| Date Picker / Kalender | **WAJIB** gunakan custom component `<x-ui.date-picker>` untuk single date dan `<x-ui.date-range-picker>` untuk rentang tanggal. **DILARANG KERAS** menggunakan native browser `<input type="date">` di form/modal/filter mana pun (karena tampilan inkonsisten lintas browser/OS dan merusak visual design system). Jika diletakkan di dalam perulangan atau modal, pastikan atribut `id` diberi suffix unik (mis. `id="estimated_ready_date_{{ $item->id }}"`) agar DOM ID dan controller kalender tidak bentrok |
| Tabel | DataTables server-side — wajib untuk tabel dengan banyak baris |
| Badge status | Ambil label/kelas dari `App\Support\StatusHelper`; perluas array/`match()` di sana, jangan tulis yang baru di view/controller. Core Impor mengembalikan class badge Bootstrap (`prBadge()`); Core Lokal mengembalikan *tone* design system untuk `x-ui.status-chip` (`localInvoiceTone()`, `localFinanceTone()`) dan label terjemahan dari `lang/*/status.php` (`localInvoiceLabel()`). Pakai pasangan yang dipakai modul Anda |
| Notifikasi | AdasiToast untuk feedback transient; AdasiAlert/SweetAlert hanya untuk konfirmasi, prompt, atau keputusan blocking |
| Loading state | Spinner pada tombol submit saat proses berjalan |

Bootstrap 5 tetap menjadi compatibility layer. Pertahankan integrasi DataTables, dropdown, modal, offcanvas, atribut `data-bs-*`, serta selector JavaScript lama yang masih load-bearing.

**Loader overlay:** setiap layout/view yang memuat `resources/js/app.js` wajib `@include('partials.loader-logo')` sebelum `@vite` (variabel `--adasi-loader-logo` hanya didefinisikan di partial itu; dijaga `FrontendAssetLoadingTest`). Logika loader ada di `resources/js/adasi-loader.js`.

**Dark mode:** elevasi bersifat tonal, bukan bayangan: `--ui-workspace-bg` < card (`--md-surface` / `--ui-card-bg`) < `--ui-menu-bg` (dropdown, popover) < `--ui-dialog-bg` (modal). `.card`, `.modal-content`, `.dropdown-menu`, dan `.popover` Bootstrap dipetakan ke token itu hanya di `:root[data-theme="dark"]` pada `resources/css/app.css`; Bootstrap secara bawaan memakai `--bs-body-bg` (nada terendah) untuk semuanya. Utilitas Bootstrap yang hanya-terang (`.bg-light`, `.bg-white`, `.table-light`, `.btn-outline-*`, badge mentah, `.text-dark`) juga punya adapter dark di blok yang sama; pakai token, jangan `#fff`/`#000` baru. Seri chart di dark memakai `--md-chart-primary` / `--md-chart-warning` (≥ 3:1 terhadap surface); `chart-theme.js` jatuh ke `--md-warning` bila token chart tidak ada. Penjaga: tes "dark overlays form a tonal elevation ladder…" di `tests/js/preferences.test.mjs`. Teks dark diukur terhadap `--md-surface` / `--ui-dialog-bg`, bukan hanya `--md-background`.

**Server tabs:** tab, filter, dan paginasi yang berganti isi tanpa reload memakai `AdasiServerTabs` ([resources/js/server-tabs.js](resources/js/server-tabs.js)). Markup: container ber-`id` dengan `data-server-tabs-container`, area isi `data-server-tabs-content`, form filter `data-server-tabs-form`, tautan tab `data-server-tab` + `data-tab-name`. Controller menjawab fragmen bila `ServerTabsResponse::wants($request)` (header `X-Adasi-Server-Tabs: 1`; **bukan** `$request->ajax()`, yang juga dipakai DataTables di URL yang sama) lewat `ServerTabsResponse::make($html, $tab, $request, $extra)`, dan pengecekan itu harus mendahului cabang `ajax()` yang lama. Nav yang dirender server (`data-server-tabs-nav`, payload `nav`) menjaga href yang membawa filter dan hitungan tetap segar; JS hanya memindahkan `aria-current` di dalamnya, jadi gaya aktif ditulis `aria-[current=page]:tw-…`. Pill DRP dan perbandingan harga yang lama masih diganti kelasnya oleh JS. Antrean kerja yang hitungannya diubah orang lain memakai `AdasiServerTabs.init(selector, { cacheTtlMs: 30000 })`. `innerHTML` tidak menjalankan `<script>`: inisialisasi per halaman lewat event `adasi:server-tabs:before-swap` / `adasi:server-tabs:updated`. Fragmen route Purchasing di `PurchasingNavigation::LIST_ROUTES` ikut diingat sebagai URL daftar. Penjaga: `tests/js/server-tabs.test.mjs`, `ServerTabsResponseTest`, `ServerTabsListUrlTest`.

Bootstrap, jQuery, SweetAlert2, dan dependency halaman tetap lewat CDN; DataTables hanya dimuat pada halaman yang mendeklarasikan `@section('uses-datatables', true)`, Chart.js per halaman. Vite membundel `resources/css/app.css` dan `resources/js/app.js`, dengan modul kalender/shell dimuat sesuai pola aktif.

Class yang dirender dari sisi server (mis. tombol aksi DataTables) tidak terbaca oleh content scanner Tailwind — kalau menambah class semacam itu, daftarkan di `safelist` pada `tailwind.config.js`.

Komponen kalender sendiri merender native `type="date"` sebagai progressive-enhancement base; ini disengaja, bukan pelanggaran larangan menulis native date input langsung pada halaman. Upgrade berada di `resources/js/calendar.js`; helper tanggal murni di `calendar-core.js`, dengan lazy import `cally`. Untuk jadwal fisik lokal, gunakan `allowed-days-of-week`, dan tetap validasi Rabu di backend `DeliveryScheduleValidator`.

Target UI adalah enterprise ERP yang ringkas dan padat, akrab bagi operator ERP. Aturan yang mengikat ada di `ADASI-UI-REDESIGN-PHASE2-MISSIONS/ADASI-UI-REDESIGN-PHASE2-MISSIONS/REDESIGN-PHASE2-GLOBAL-CONTRACT.md`; lampirkan file itu beserta file mission yang relevan untuk pekerjaan UI Phase 2, dan jangan memindahkannya.

- **Jangan menambah:** gradient, glassmorphism atau backdrop blur dekoratif, kartu mengambang beradius besar, bayangan dekoratif yang tebal, dinding KPI di atas tabel operasional, badge/pill tanpa makna semantik, lingkaran ikon dekoratif, hero/marketing yang besar, emoji, animasi spring, palet pastel/pelangi, atau komposisi dashboard SaaS generik.
- **Utamakan:** hierarki tipografi, border sebelum bayangan, radius kecil, densitas desktop-first yang ringkas, filter berfrekuensi tinggi yang terlihat dan filter sekunder di balik "More filters", aksi utama baris yang terlihat dengan aksi sekunder di overflow menu, form bersection dengan sticky action bar, warna semantik hanya untuk state nyata, state fokus/hover/disabled yang aksesibel, dan layout yang tetap layak di tablet dan mobile.
- Jangan menukar fungsi bisnis yang berjalan dengan perbaikan visual. Redesain mempertahankan perilaku, kontrak data, dan keakraban alur kerja.

### 🔔 Notifikasi, Pusher, dan Polling

- Provider realtime yang digunakan proyek adalah **Pusher Channels**, bukan Reverb. Backend `NotificationService` mengirim `SystemNotification` melalui channel `database` dan `broadcast`.
- Frontend di `resources/views/layouts/app.blade.php` menginisialisasi Laravel Echo hanya jika `broadcasting.default === 'pusher'`, key Pusher terisi, dan cluster Pusher terisi. Client dibuat dengan `broadcaster: 'pusher'`.
- Package dan konfigurasi Reverb masih ada di repository, tetapi **tidak digunakan** oleh jalur frontend maupun intent deployment. Jangan menjalankan, mengaktifkan, atau mendokumentasikan Reverb sebagai provider realtime aktif.
- `.env.example` sekarang menetapkan **`BROADCAST_CONNECTION=pusher`** dengan placeholder `PUSHER_*`. Placeholder bukan credential aktif; konfigurasi runtime/deployment harus diverifikasi terpisah.
- Fallback yang selalu aktif untuk user terautentikasi adalah `updateBadges()` saat halaman dimuat dan polling setiap **30 detik** ke endpoint unread-count notification; polling unread-count chat juga berjalan untuk role Purchasing dan Supplier. Fallback ini memperbarui badge count, tetapi tidak menjalankan callback Echo yang menyisipkan item notifikasi dan menampilkan toast realtime. Polling badge tetap menjadi baseline ketika Pusher tidak aktif atau gagal tersambung.

- Pertahankan deterministic event key/UUID pada `NotificationService` (UUIDv5 dari `User::class:{id}:{eventKey}`; `$replace` membuat notifikasi baru menggantikan yang lama) untuk idempotensi. Kategori memakai `NotificationCategory` (`chat`, `quotation`, `document`, `invoice`, `other`; nilai lain jatuh ke `other`); domain `global/import/local` memakai `NotificationDomain`. Summary, count, list/read-state, dan URL harus mengikuti boundary role/scope/konteks aktif; jangan hanya menyembunyikan link di view. Link berasal dari `NotificationUrlResolver`.
- `ApplyNotificationPreferences` mendengarkan `NotificationSending` (didaftarkan eksplisit di `AppServiceProvider`) dan mengembalikan `false` untuk membuang kiriman `database`/`broadcast` bila user menonaktifkan tipe notifikasi itu, atau hanya `broadcast` bila mode pengiriman user `silent`. Notifikasi yang "tidak terkirim" bisa jadi perilaku preferensi, bukan bug. Mute percakapan disimpan di `notification_mutes`.
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
- `routes/console.php`: prune `AuthAuditLog` 02:10, `exports:cleanup` 02:20 (retensi tiga hari), reminder/expiry invoice lokal 08:00, notifikasi blok invoice Supplier Audit 00:10, semuanya memakai business timezone dan `withoutOverlapping()`. Produksi membutuhkan cron `schedule:run` selain worker queue.
- Queue memakai driver `database` dengan `DB_QUEUE_RETRY_AFTER=660`; tests memakai `sync`.

### Advanced Export

Lapisan di atas jalur export di atas untuk **export daftar**. Baca [docs/guides/ADVANCED-EXPORT-DEVELOPMENT.md](docs/guides/ADVANCED-EXPORT-DEVELOPMENT.md) (berbahasa Indonesia) sebelum menambah atau mengubah satu export. Poin yang paling sering salah:

- **Tier 1** (pilih/urut kolom, preset, XLSX/CSV): implementasikan `App\Exports\Advanced\ExportDefinition` (katalog kolom via `UsesColumnCatalog`) dan daftarkan di `App\Support\Export\ExportDefinitions`; UI memakai `<x-export.advanced-modal>`. **Tier 2** hanya memilih format (katalog kosong → tanpa kolom/preset). Nama class tidak pernah berasal dari request; *export key* dan audience ditetapkan controller, bukan role atau input browser, dan admin tidak otomatis mendapat semua audience.
- Request advanced masuk lewat `AdvancedExportRequest` sebagai POST pada **URI yang sama** dengan GET legacy, dan GET legacy harus tetap bekerja. Opsi diterapkan lewat `AcceptsExportOptions::applyOptions()` sebelum query pertama; jangan menambah argumen constructor pada class export yang ada. Katalog kolom dilayani `exports.definitions.show` dan preset pribadi oleh `ExportPresetController` (policy `ExportPreset`, anti-IDOR).
- Class dalam `LIST_EXPORT_CLASSES` pada `ExportDispatcher` berbagi batas di `config/exports.php`: **100.000 baris** (diperiksa saat dispatch *dan* di worker) dan **5 job aktif per user**. Export detail, file DRP/transfer, template import, dan PDF berada di luar Advanced Export.
- Job workbook berantai `ProcessExportJob` / `GenerateWorkbookJob` → `FinalizeExportJob`, semuanya di queue `exports` dan membawa locale pemohon. Instance export harus tetap serializable: jangan menyimpan closure, `ExportColumn`, atau definition sebagai properti; callback katalog hidup di cache static.
- Setiap sel teks melewati `SpreadsheetCellSanitizer` (formula injection) pada XLSX dan CSV. CSV memakai delimiter koma, UTF-8 BOM, ekstensi `.csv`, dan `text/csv; charset=UTF-8`.
- Kolom timestamp bisnis tetap lewat `BusinessTime`; kolom date murni tetap date; snapshot harga/kurs tidak dihitung ulang.

### Auth dan Konfigurasi

- **Email login (`users.email`) tetap setelah akun dibuat**, termasuk pada edit user oleh admin. Profile hanya mengubah Nama Tampilan. Request update boleh tidak menyertakan email atau menyertakan email tersimpan yang identik untuk kompatibilitas; email berbeda ditolak sebelum perubahan lain disimpan. Guard `User::updating` juga menolak perubahan email melalui save model. Jangan menambahkan bulk/query-builder update email yang melewati guard tersebut. SQL langsung dan operasi yang menonaktifkan model events berada di luar perlindungan ini. Pembuatan akun tetap menetapkan email; `suppliers.pic_email` adalah kontak terpisah yang tetap mengikuti workflow Vendor Master. Perubahan nama tidak mereset `email_verified_at` atau mengubah password/sesi/master perusahaan.
- Auth security di `app/Services/Auth/`, `AuthSecurityServiceProvider`, dan `config/auth_security.php`: 2FA/recovery codes, Turnstile, identity rate limiter, session revocation, password confirmation, known devices, dan audit. Pertahankan respons 429 HTML/AJAX melalui pola `RateLimitResponse` yang aktif.
- Middleware web mencakup `ApplyUserLocale`, `DecodeHashids`, `EnforceAuthSessionSecurity`, `AddSecurityHeaders`, dan `EnforceSupplierDomain`; middleware alias/exception handling di `bootstrap/app.php`. `NoStoreResponse` dipakai pada route yang mendeklarasikan `no-store`, bukan diasumsikan global.
- Event auto-discovery dimatikan (`withEvents(discover: false)`); listener baru harus diregistrasikan eksplisit. Providers berada di `bootstrap/providers.php`.
- `.env.example` adalah template konfigurasi, bukan bukti setup produksi. Session encryption/secure cookie, trusted proxy, Pusher, rekening Finance, queue, dan kredensial eksternal harus mengikuti lingkungan deployment. Jangan menyalin secret dari `.env` ke dokumen, browser, log, atau response.

### Database dan Migrasi

- Jangan menjalankan `migrate`, `migrate:fresh`, rollback, `db:wipe`, truncate, atau bulk write terhadap database aplikasi hanya untuk memverifikasi task dokumentasi/audit. Perubahan database memerlukan scope/instruksi eksplisit; gunakan `migrate:status` untuk inspeksi ledger ketika relevan.
- File migrasi, ledger `migrations`, dan physical schema adalah tiga bukti berbeda. SQL catch-up dan PHP migrations adalah jalur alternatif; jangan menerapkan keduanya tanpa pemeriksaan. Back up dan uji pada restored staging sebelum DDL produksi; jangan mengganti data produksi dengan dump lokal.
- Kolom enum (`users.role`, mata uang, status Core 2, `ga_claims.claim_type`) diubah dengan `DB::statement` mentah dan harus punya `down()` yang bekerja. Beberapa `down()` sengaja melempar exception bila data akan hilang (mis. `2026_09_08_000001`, `2026_10_07_000002`); pertahankan guard itu, jangan menggantinya dengan drop diam-diam.
- Kolom non-nullable baru memerlukan default atau backfill, mengikuti migrasi backfill yang sudah ada di repo. Jangan membuat migrasi bila misi tidak memerlukan perubahan skema.
- Pertahankan composite FK, uniqueness invoice/revision/payment, primary transfer guard, dan rollback guards. Constraint ini adalah invarian bisnis (mis. `local_invoice_documents` berkunci `[revision_id, invoice_id]`; `2026_09_14_000001`); jangan menghapus satu pun agar sebuah insert lolos. Validasi aplikasi tidak menggantikan constraint/row lock. Pertimbangkan existing rows, nullable/default/backfill, lock ordering, transaksi, retry/idempotensi, dan cleanup berkas saat gagal.
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
- [ ] Teks UI baru ada di `lang/en` dan `lang/id`; angka/tanggal diformat lewat `$regionalFormatter` atau `BusinessTime`
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
# Beri FILE eksplisit; glob direktori gagal di Windows. Isi tests/js/ mencakup kalender,
# lokalisasi, preferensi, regional, dan SDK Advanced Export.
node --test tests/js/calendar.test.mjs
node --test tests/js/unsaved-changes.test.mjs

git diff --check

# Inspeksi database / integrity read-only jika task menyentuh domain terkait.
php artisan migrate:status
php artisan local-invoices:reconcile --json

# Operasional lokal/deployment, bukan quality check untuk dijalankan sembarang.
composer dev            # serve + queue:listen + pail + vite
npm run dev             # vite saja
php artisan queue:work database --queue=exports,default --tries=3 --timeout=600
php artisan schedule:run
php artisan optimize:clear
```

- PHPUnit memakai **MySQL `adasi_portal_test`**, bukan SQLite (`phpunit.xml`, `.env.testing.example`). `AppServiceProvider` memiliki guard testing untuk database aplikasi/nama non-test. Pastikan konfigurasi/cached config/DB_URL tidak mengarahkan test ke database aplikasi. Jangan menjalankan suite `RefreshDatabase` secara paralel pada database test yang sama.
- Test concurrency menggunakan subprocess pada `tests/Support/` (`local-gr-reservation-worker.php`, `local-invoice-concurrency-worker.php`, `advanced-export-concurrency-worker.php`) dan `tests/Feature/_user-preference-concurrency-worker.php`; pertahankan pengujian MySQL row locks yang nyata, jangan menggantinya dengan simulasi single-process.
- Hanya `UserFactory` yang ada di `database/factories/`. Tes membuat record lain secara eksplisit lewat `Model::create([...])`; ikuti itu dan jangan menambah factory kecuali misi memerlukannya.
- `phpunit-results.log` dan `test-results.log` di root adalah snapshot dari tree lama; bukan baseline. Full suite berjalan serial terhadap MySQL dan memakan beberapa menit. Bandingkan dengan checkout bersih sebelum menyebut sebuah kegagalan pre-existing.
- `php artisan local-invoices:reconcile` bersifat read-only dan aman dijalankan pada data dev; jalankan setelah menyentuh Local PO/GR, settlement, voucher, atau refund untuk memastikan invarian pembayaran utuh.
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
| Supplier Audit | `tests/Feature/SupplierAudit/` (plus `tests/Feature/LocalInvoice/` bila menyentuh blok invoice) |
| Timezone / kalender | `tests/Feature/Timezone/`, `BusinessTimeTest`, `Architecture/BusinessTimeGuardTest`, `CalendarComponentTest`, tests JS kalender |
| Export / asset loading | `AsyncExportQueueTest`, `DetailExportSecurityTest`, `MissionFourExportTest`, `FrontendAssetLoadingTest` |
| Advanced Export | `AdvancedExport*Test`, `AdvancedPurchaseOrderExportTest`, `ExportPresetTest`, `tests/Unit/OperationalWorkbookPresentationTest.php`, `tests/js/advanced-export-*.test.mjs` |
| Teks UI baru / terjemahan | `tests/Unit/TranslationParityTest.php`, `TranslationContentTest`, `UserFacingCopyInventoryTest`, `Localization*Test`, `tests/js/localization*.test.mjs` |
| Format regional / preferensi user | `Regional*Test`, `tests/Unit/RegionalDisplayFormatterTest.php`, `UserLocalePreferencesTest`, `UserRegionalPreferencesTest`, `tests/js/regional-*.test.mjs` |

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

Because every string now exists in both locales (see "Lokalisasi dan Preferensi Tampilan per User"), this policy is applied *inside* the lang files: it decides which canonical domain terms stay untranslated in a given locale (e.g. `DRP`, `NSFP`, `Purchase Order (PO)` are kept as-is in `lang/id`). Pinned glossary values are asserted in `TranslationContentTest`.

Supplier/Vendor glossary (applies to `lang/id`): `Supplier`, `supplier`, `Supplier Portal`, `Supplier Registration`, `Import Supplier`, `Local Supplier`, `Local Vendor`, and `Vendor Master` stay in English. Do not write `Pemasok` or `Rekanan` in Indonesian copy; `TranslationContentTest` fails if they reappear.

Before changing UI terminology:
1. Identify which core the view belongs to.
2. Search for the same terminology in equivalent views.
3. Determine whether the term is an established canonical term.
4. Change only unintended or inconsistent usage.
5. Do not translate domain terminology merely for stylistic consistency.
