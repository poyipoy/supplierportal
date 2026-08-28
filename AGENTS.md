# AGENTS.md — ADASI Portal Supplier

> Baca file ini sepenuhnya sebelum menulis satu baris kode pun.

---

## 🏭 Tentang Proyek

**Nama Sistem:** ADASI Portal Supplier  
**Perusahaan Mitra:** PT. Astra Daido Steel Indonesia (ADASI)  
**Jenis:** Sistem Informasi Pengadaan Material Impor Berbasis Web (Tugas Akhir)  
**Tujuan:** Mendigitalisasi proses pengadaan material impor antara tim Purchasing ADASI dengan para supplier — mulai dari permintaan material, penawaran harga, konversi kurs, inspeksi QC, hingga klaim material NG.

---

## 🛠️ Tech Stack

| Layer | Teknologi |
|---|---|
| Backend | PHP 8.2 + Laravel 12 (MVC) |
| Frontend | Blade Template + Bootstrap 5 compatibility layer + prefixed Tailwind utilities (`tw-`) |
| Database | MySQL (Laragon untuk dev) |
| Interaktivitas | JavaScript / jQuery + AJAX, Alpine.js untuk shell & toast |
| Realtime | Pusher Channels melalui Laravel Echo; polling unread-count 30 detik sebagai fallback |
| Grafik | Chart.js (di-load per halaman via CDN) |
| Export Excel | Laravel Excel (Maatwebsite) — export data operasional async lewat queue `exports`; template import tetap direct download |
| Email | Laravel Mail + SMTP |
| Auth | Laravel built-in Auth + Middleware RBAC + 2FA (google2fa) + Turnstile |

---

## 👥 Role Pengguna

> ⚠️ Setiap route dan fitur **WAJIB** diproteksi middleware sesuai role-nya.

| Role | Deskripsi |
|---|---|
| `admin` | Akses penuh — kelola user, kurs, data master |
| `purchasing` | Buat permintaan material, evaluasi penawaran, buat PO |
| `supplier` | Lihat permintaan & input penawaran — **hanya data milik sendiri** |
| `qc` | Input hasil inspeksi material, tentukan OK / NG |

### ⚠️ Isolasi Data Supplier

Supplier **tidak boleh** melihat atau mengubah data supplier lain. Untuk model yang memiliki kolom owner `supplier_id`—terutama `Quotation`, `PurchaseOrder`, dan `MaterialClaim`—query supplier-facing wajib difilter:

```php
->where('supplier_id', auth()->id())
```

> **Penting — semantik `supplier_id`:** kolom `supplier_id` pada `quotations`, `purchase_orders`, `material_claims`, dan pivot `purchase_requisition_suppliers` adalah foreign key ke **`users.id`**, *bukan* ke `suppliers.id`. Tabel `suppliers` hanya menyimpan profil perusahaan dan di-key oleh `user_id`.
>
> Karena itu `->where('supplier_id', auth()->id())` memang benar. Untuk model yang sudah ter-load, bandingkan `(int) $model->supplier_id === (int) auth()->id()`. Jangan join ke `suppliers` untuk keperluan otorisasi.

Tidak semua data supplier-facing memiliki kolom owner tersebut. `PurchaseRequisition` memakai `scopeVisibleToSupplier()` berdasarkan pivot invitation dan quotation milik supplier; percakapan memakai membership/`supplier_user_id` serta policy. Ikuti scope atau policy model terkait—jangan menambahkan filter pada kolom yang tidak ada.

Aturan ini dijaga oleh `tests/Feature/SupplierDataIsolationTest.php` — jalankan test tersebut setelah mengubah query sisi supplier.

---

## 🗄️ Skema Database

> Skema di bawah adalah ringkasan. **Sumber kebenaran tetap `database/migrations/`** (48 migrasi) dan `$fillable` / `casts()` di masing-masing Model. Jangan menebak struktur kolom — baca migrasinya.

```
users                  id, name, email, password, role[admin|purchasing|supplier|qc],
                          is_active, + kolom auth security (2FA, dsb.)
suppliers              id, user_id, company_name, address, phone, npwp, category
periods                id, name, month (nullable = periode tahunan), year,
                          status[open|closed], created_by
purchase_requisitions  id, period_id, created_by, pr_number, notes,
                          status[draft|submitted|rejected|bidding|completed], created_at
purchase_requisition_suppliers  id, pr_id, supplier_id → users.id, invited_at
pr_items               id, pr_id, material_master_id, hs_code, material_name, quantity,
                          shape, thickness, d_inner, d_outer, width, length,
                          weight_needed, remark, + metadata resolusi HS code & berat
quotations             id, pr_id, supplier_id → users.id, exchange_rate_id, currency,
                          status[draft|submitted|revision_requested|accepted|rejected],
                          submitted_at, reviewed_at, reviewed_by, reviewer_notes,
                          estimated_delivery, payment_terms, validity_period
quotation_items        id, quotation_id, pr_item_id, price_per_kg, amount,
                          available_qty, available_* (dimensi ketersediaan), notes
exchange_rates         id, currency, rate_to_idr, valid_from, created_by
purchase_orders        id, supplier_id → users.id, currency, exchange_rate_id, po_number,
                          status[active|waiting_qc|claim_needed|overdue|completed|cancelled],
                          created_by, created_at, estimated_arrival, actual_arrival, notes
po_quotations          id, po_id, quotation_id     ← pivot PO ⇄ Quotation
po_documents           id, po_id, doc_type[invoice|bl|packing_list|form_e], status, updated_at
qc_inspections         id, po_id, inspected_by, status[ok|ng], inspected_at
qc_items               id, inspection_id, pr_item_id, actual_thickness, actual_d_inner,
                          actual_d_outer, actual_width, actual_length, actual_weight, status
material_claims        id, inspection_id, po_id, submitted_by, supplier_id → users.id,
                          status, description, resolution_expected, deadline, supplier_response
claim_attachments      id, claim_id, file_path, uploaded_by   ← legacy relation; upload aktif memakai attachments
attachments            id, attachable_type, attachable_id, file_path, file_name,
                          file_type, uploaded_by, created_at
announcements          id, title, content, created_by, published_at
document_sequences     id, type[PR|PO], year, month, last_number   ← penomoran atomic
material_masters / material_aliases / hs_code_rules   ← master data material & HS code
conversations / messages     ← negosiasi Purchasing ⇄ Supplier
export_jobs            id, user_id, label, export_class, export_args, file_name, disk, status
auth_audit_logs        jejak audit login & aksi keamanan
```

### Perubahan Skema yang Sudah Diterapkan

Beberapa struktur awal proyek sudah berubah. Ini yang berlaku sekarang:

| Dulu | Sekarang |
|---|---|
| tabel `purchase_requirements` | **`purchase_requisitions`** — di-rename oleh migrasi `2026_06_08_160000`, beserta pivot `purchase_requisition_suppliers` dan nilai `conversable_type` di `conversations`. Model: `PurchaseRequisition` |
| `purchase_orders.quotation_id` (1 PO = 1 quotation) | **pivot `po_quotations`** (satu PO bisa mengonsolidasi beberapa quotation). Relasi aktif: `PurchaseOrder::quotations()` dan `Quotation::purchaseOrders()`, keduanya `belongsToMany`. Kolom `supplier_id`, `currency`, `exchange_rate_id` kini ada langsung di `purchase_orders` (migrasi `2026_05_22_000001`) |
| currency `USD` / `JPY` | **`USD`, `JPY`, `IDR`, `CNY`** pada `quotations`, `purchase_orders`, dan `exchange_rates` (migrasi `2026_05_28_000004`). Konstanta: `ExchangeRate::CURRENCIES` |

### Soft Deletes

Dokumen legal/penting memakai Soft Deletes: `purchase_requisitions`, `quotations`, `purchase_orders`, `qc_inspections`, `material_claims`, `announcements`. Query pada tabel-tabel ini harus sadar soft delete, dan jangan menambahkan hard delete di sana.

> **4 tanggal penting yang harus selalu ditracking per PO:**
> - `purchase_requisitions.created_at` — tanggal permintaan dibuat
> - `purchase_orders.created_at` — tanggal PO dibuat
> - `purchase_orders.estimated_arrival` — estimasi material datang (diisi Purchasing)
> - `purchase_orders.actual_arrival` — tanggal material benar-benar tiba (diisi QC)

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

Jalur upload polymorphic yang aktif saat ini adalah MTC pada `QuotationItem`, bukti NG pada `QcInspection`, lampiran respons pada `MaterialClaim`, dan lampiran percakapan pada `Message`. Model `PurchaseRequisition`, `Quotation`, `PurchaseOrder`, dan `PoDocument` juga mendeklarasikan relasi `attachments()`, tetapi keberadaan relasi model saja tidak berarti endpoint upload aktif.

---

## 📁 Struktur File

```
app/
├── Models/             PurchaseRequisition.php, QuotationItem.php   (PascalCase)
├── Http/
│   ├── Controllers/    dipisah per role: Admin/, Purchasing/, Supplier/, Qc/
│   ├── Middleware/     RoleMiddleware.php, DecodeHashids.php, ...
│   └── Requests/       FormRequest untuk jalur material/HS code/requisition
├── Services/           Materials/ (HS code & berat), Auth/, NotificationService
├── Data/Materials/     objek hasil immutable (ProcessedPrItemResult, dsb.)
├── Support/            ExportDispatcher, StatusHelper, NotificationCategory, ...
├── Exports/ Imports/   Laravel Excel
├── Jobs/               ProcessExportJob
├── Traits/             HasHashids.php
└── Policies/           QuotationPolicy.php, AttachmentPolicy.php, ConversationPolicy.php

resources/views/
├── layouts/            app.blade.php, auth.blade.php, guest.blade.php
├── partials/           navbar.blade.php, sidebar.blade.php, alerts.blade.php
├── components/ui/      komponen bersama: x-ui.button, x-ui.data-table, x-ui.icon, ...
├── purchasing/         dashboard, requisitions/, purchase-orders/, comparison/, ...
├── supplier/           dashboard, quotations/, price-history/, claims/, ...
├── qc/                 dashboard, inspections/
└── admin/              dashboard, users/, material-hs-code, ...

routes/
└── web.php             semua route dikelompokkan per role dengan middleware

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

- Root repository hanya untuk entrypoint wajib seperti `README.md`, `AGENTS.md`, dan `CLAUDE.md`.
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

Setiap role punya satu grup route sendiri di `routes/web.php`:

```php
// Purchasing — perhatikan resource-nya bernama "requisitions", bukan "requirements"
Route::middleware(['auth', 'role:purchasing', 'purchasing.navigation'])
    ->prefix('purchasing')->name('purchasing.')->group(function () {
        Route::get('/dashboard', [PurchasingController::class, 'dashboard'])->name('dashboard');
        Route::resource('requisitions', PurchaseRequisitionController::class);
        // ...
    });

// Supplier
Route::middleware(['auth', 'role:supplier'])->prefix('supplier')->name('supplier.')->group(function () {
    Route::get('/dashboard', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::resource('quotations', QuotationController::class)->only(['index', 'show']);
    // ...
});
```

Grup `admin` dan `qc` mengikuti pola yang sama. Route yang boleh diakses lebih dari satu role menyebut semuanya, contoh `role:qc,purchasing` untuk detail inspeksi dan `role:purchasing,supplier,admin` untuk PDF PO.

Penamaan route: `role.resource.action` — contoh: `purchasing.requisitions.create`

### 🔗 Hashids pada URL

Model yang memakai trait `HasHashids` (`PurchaseRequisition`, `Quotation`, `PurchaseOrder`, `QcInspection`, `MaterialClaim`, `Conversation`, `ExportJob`, `User`) mengembalikan hash pada `getRouteKey()`, sehingga ID asli tidak pernah tampil di URL.

- Di view, pakai `$model->hash` atau kirim instance model ke `route()` — **jangan** `$model->id`.
- Route yang memakai parameter mentah (`{id}`, `{pr_id}`, `{po_id}`, dsb.) di-decode oleh middleware `DecodeHashids`. Kalau menambah route dengan nama parameter hashed yang baru, **daftarkan nama parameter itu di `HASHED_PARAM_KEYS`**, kalau tidak controller akan menerima string hash padahal mengharapkan integer.
- Route plain-integer untuk Attachment, Period, Notification, Announcement, ExchangeRate, PoDocument, dan PrItem dilewati melalui `PLAIN_ROUTE_PREFIXES`; `verification.verify` dikecualikan melalui `PLAIN_ROUTE_NAMES`.
- Mengirim integer mentah ke parameter hashed akan `abort(404)` — itu memang disengaja. Dijaga oleh `tests/Feature/HashidUrlSecurityTest.php`.

---

## 💱 Konversi Kurs

**Mata uang yang didukung:** `USD`, `JPY`, `IDR`, `CNY` (lihat `ExchangeRate::CURRENCIES`).

### 1. Amount quotation dihitung ulang oleh aplikasi

Pada jalur simpan quotation supplier, `amount` tidak divalidasi sebagai input dan tidak dipercaya sebagai nilai final. Controller me-query ulang `PrItem`, lalu menghitung dan menyimpan `amount` melalui helper model:

```php
// App\Models\QuotationItem
$amount = QuotationItem::calculateAmount($prItem, $pricePerKg);
// = round($pricePerKg * $prItem->total_weight, 4)
```

`PrItem::total_weight` adalah **`weight_needed × quantity_value`** — jadi kuantitas sudah ikut diperhitungkan, bukan hanya berat per unit. `quantity_value` memberi batas minimum 1. Jangan mengganti pemanggilan ini dengan perhitungan manual, dan jangan menjadikan `amount` kiriman supplier sebagai nilai authoritative ketika aplikasi menghitung ulang nilai tersebut.

### 2. Kurs di-snapshot, bukan diambil live

`quotations` dan `purchase_orders` sama-sama menyimpan `exchange_rate_id`, tetapi waktu snapshot-nya berbeda:

- quotation mengambil `ExchangeRate::latestRate($currency)` dan menyimpan ID-nya saat quotation di-submit; draft dapat memiliki `exchange_rate_id = null`;
- PO memilih kurs terbaru untuk currency yang sama saat PO dibuat dan menyimpan ID kurs tersebut pada PO.

Untuk total dokumen, konversi ke IDR menggunakan `amount` authoritative yang sudah mencakup `total_weight`, lalu dikalikan dengan `rate_to_idr` dari snapshot:

```php
$amount = QuotationItem::calculateAmount($quotationItem->prItem, $quotationItem->price_per_kg);
$idr = $amount * (float) $quotation->exchangeRate->rate_to_idr;
```

Modul Perbandingan Harga dan Riwayat Harga **join ke kurs snapshot** (`exchange_rates` via `exchange_rate_id` milik quotation/PO), bukan ke kurs terbaru — supaya angka historis tidak berubah setiap kali admin memasukkan kurs baru. Jangan mengganti join ini dengan `latestRate()`.

### 3. Kurs terbaru hanya dipilih ketika snapshot baru dibuat

```php
$rate = ExchangeRate::latestRate($currency); // dipakai saat quotation di-submit; cache 60 menit
```

Cache di-invalidate otomatis lewat `booted()` saat ada `ExchangeRate` baru dibuat.

Jalur pembuatan PO saat ini melakukan query kurs terbaru berdasarkan `valid_from`, lalu menyimpan `exchange_rate_id` hasil query tersebut. Setelah ID snapshot tersimpan, pembacaan historis harus menggunakan relasi snapshot dokumen, bukan mengulang query kurs terbaru.

> Jangan overwrite kurs lama — selalu `INSERT` baru agar histori dan snapshot tetap akurat.

---

## 📦 Format Nomor Dokumen

Format PR : `REQ/MM/YYYY/XXX` | Contoh: `REQ/05/2025/001`
Format PO : `PO/MM/YYYY/XXX`  | Contoh: `PO/05/2025/001`

Penomoran memakai tabel **`document_sequences`** (`type`, `year`, `month`, `last_number`, unique per `type+year+month`) di dalam transaksi dengan `lockForUpdate()`, supaya dua user yang submit bersamaan tidak mendapat nomor yang sama.

**Selalu pakai helper yang sudah ada — jangan menghitung nomor sendiri:**

```php
$prNumber = PurchaseRequisition::generatePrNumber();  // REQ/05/2025/001
$poNumber = PurchaseOrder::generatePoNumber();        // PO/05/2025/001
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

File upload dipakai di beberapa modul. Selalu gunakan tabel `attachments` (polymorphic) — jangan buat kolom file terpisah di setiap tabel.

**Ketentuan upload:**
- Selalu simpan ke disk `private` (`storage/app/private/attachments/{tahun}/{bulan}/`). **Jangan** ke `public/`.
- Batas ukuran umum: **10 MB** (`max:10240`).
- Tipe file **berbeda per modul** — jangan menyeragamkan aturan mimes tanpa alasan. Ikuti validasi yang sudah ada di controller yang sedang dikerjakan:

| Modul | Aturan yang berlaku sekarang |
|---|---|
| Penawaran (Supplier) — file MTC | `mimes:pdf,jpg,jpeg,png`, `max:5120` (5 MB) |
| QC Inspection | `mimes:jpg,jpeg,png`, `max:10240` — wajib jika status NG |
| Claim Material (Supplier) | `mimes:jpg,jpeg,png,pdf,xlsx,doc,docx`, `max:10240` |
| Conversation Message | `mimes:jpg,jpeg,png,pdf,xlsx,xls,doc,docx`, `max:10240`, maksimal 5 file |
| Purchase Order / PO Document | Tidak ada endpoint upload aktif; modul saat ini hanya memperbarui status Invoice, BL, Packing List, dan Form E |

```php
// Pola penyimpanan yang dipakai di repo (stream + disk private)
$path = 'attachments/'.now()->format('Y/m').'/'.$file->hashName();

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

Akses file lewat `AttachmentController` yang sudah mengecek `AttachmentPolicy` — jangan expose path storage langsung.

---

## 🎨 Panduan UI

| Aspek | Ketentuan |
|---|---|
| Warna | Ambil dari design token CSS custom property (`--md-*`, `--ui-*`) di `resources/css/app.css`. Seed: biru `#1F5FA6`, aksen merah `#C0392B`. Jangan tulis hex langsung di Blade |
| Font | Inter (Google Fonts) |
| Utility CSS | Tailwind **berprefix `tw-`** dengan `preflight` dimatikan — class Tailwind tanpa prefix tidak akan berefek |
| Komponen | Pakai ulang `resources/views/components/ui/` (`x-ui.button`, `x-ui.data-table`, `x-ui.page-header`, `x-ui.status-chip`, dll.) sebelum membuat markup baru |
| Ikon | Lucide melalui `<x-ui.icon>`; jangan gunakan `bi-*` atau `<x-lucide-*>` langsung |
| Tabel | DataTables server-side — wajib untuk tabel dengan banyak baris |
| Badge status | Ambil label/kelas dari `App\Support\StatusHelper`, jangan tulis `match()` baru di view/controller |
| Notifikasi | AdasiToast untuk feedback transient; AdasiAlert/SweetAlert hanya untuk konfirmasi, prompt, atau keputusan blocking |
| Loading state | Spinner pada tombol submit saat proses berjalan |

Bootstrap 5 tetap menjadi compatibility layer. Pertahankan integrasi DataTables, dropdown, modal, offcanvas, atribut `data-bs-*`, serta selector JavaScript lama yang masih load-bearing.

Bootstrap, jQuery, DataTables, SweetAlert2, dan Chart.js di-load lewat CDN di layout; Vite hanya membundel `resources/css/app.css` dan `resources/js/app.js` (Alpine + toast + shell).

Class yang dirender dari sisi server (mis. tombol aksi DataTables) tidak terbaca oleh content scanner Tailwind — kalau menambah class semacam itu, daftarkan di `safelist` pada `tailwind.config.js`.

### 🔔 Notifikasi, Pusher, dan Polling

- Provider realtime yang digunakan proyek adalah **Pusher Channels**, bukan Reverb. Backend `NotificationService` mengirim `SystemNotification` melalui channel `database` dan `broadcast`.
- Frontend di `resources/views/layouts/app.blade.php` menginisialisasi Laravel Echo hanya jika `broadcasting.default === 'pusher'`, key Pusher terisi, dan cluster Pusher terisi. Client dibuat dengan `broadcaster: 'pusher'`.
- Package dan konfigurasi Reverb masih ada di repository, tetapi **tidak digunakan** oleh jalur frontend maupun intent deployment. Jangan menjalankan, mengaktifkan, atau mendokumentasikan Reverb sebagai provider realtime aktif.
- `.env.example` saat ini masih menetapkan `BROADCAST_CONNECTION=log` dan berisi placeholder Reverb. Ini adalah **CONFIGURATION_DOCUMENTATION_DRIFT** terhadap intent Pusher yang sudah dikonfirmasi; deployment Pusher memerlukan `BROADCAST_CONNECTION=pusher` serta credential `PUSHER_*` yang sesuai.
- Fallback yang selalu aktif untuk user terautentikasi adalah `updateBadges()` saat halaman dimuat dan polling setiap **30 detik** ke endpoint unread-count notification; polling unread-count chat juga berjalan untuk role Purchasing dan Supplier. Fallback ini memperbarui badge count, tetapi tidak menjalankan callback Echo yang menyisipkan item notifikasi dan menampilkan toast realtime. Polling badge tetap menjadi baseline ketika Pusher tidak aktif atau gagal tersambung.

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
| 8 | Buat kolom file/attachment terpisah di tabel — gunakan tabel `attachments` polymorphic |

---

## ✅ Checklist Sebelum Buat Fitur Baru

- [ ] Route sudah diproteksi middleware role yang sesuai
- [ ] Query supplier memakai `supplier_id` ownership atau scope/policy visibility yang sesuai model
- [ ] Ada validasi di Controller (`$request->validate([...])`)
- [ ] Feedback transient session/AJAX ditampilkan melalui AdasiToast; konfirmasi blocking tetap memakai AdasiAlert/SweetAlert
- [ ] Nama route sesuai format `role.resource.action`
- [ ] Tabel daftar sudah pakai DataTables.js + filter yang relevan
- [ ] Fitur upload menggunakan tabel `attachments` (polymorphic)

---

## 📌 Urutan Pengerjaan (MVP First)

```
 1  Setup project Laravel + koneksi MySQL
 2  Migrasi semua tabel (termasuk attachments polymorphic)
 3  Seeder data awal (admin, sample supplier, sample kurs)
 4  Auth + Middleware RBAC
 5  Layout & sidebar per role
 6  Modul Permintaan Material (Purchasing)
 7  Modul Penawaran Supplier + Upload Attachment
 8  Konversi Kurs Otomatis
 9  Modul Perbandingan Harga + Chart.js
10  Modul Purchase Order + Tracking Tanggal
11  Modul Tracking Dokumen Impor
12  Modul QC Inspection + Upload Foto NG
13  Modul Claim Material
14  Dashboard per role + Pencarian & Filter
15  Notifikasi + Export Excel
```
