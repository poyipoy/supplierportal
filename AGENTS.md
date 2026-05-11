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
| Backend | PHP 8.2.30 + Laravel (MVC) |
| Frontend | Blade Template + Bootstrap 5 + Bootstrap Icons |
| Database | MySQL (Laragon untuk dev) |
| Interaktivitas | JavaScript / jQuery + AJAX |
| Grafik | Chart.js / ApexCharts |
| Export Excel | Laravel Excel (Maatwebsite) |
| Email | Laravel Mail + SMTP |
| Auth | Laravel built-in Auth + Middleware RBAC |

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

Supplier **tidak boleh** melihat atau mengubah data supplier lain. Setiap query yang melibatkan data supplier **wajib** difilter:

```php
->where('supplier_id', auth()->id())
```

---

## 🗄️ Skema Database

```
users                 id, name, email, password, role, is_active
suppliers             id, user_id, company_name, address, phone, npwp, category
periods               id, name, month, year, status[open|closed], created_by
purchase_requirements id, period_id, created_by, notes, status, created_at
pr_items              id, pr_id, hs_code, material_name, shape, thickness,
                         d_inner, d_outer, width, length, weight_needed
quotations            id, pr_id, supplier_id, currency[USD|JPY], status, submitted_at
quotation_items       id, quotation_id, pr_item_id, price_per_kg, amount, notes
exchange_rates        id, currency, rate_to_idr, valid_from, created_by
purchase_orders       id, quotation_id, po_number, status, created_by, created_at,
                         estimated_arrival, actual_arrival
po_documents          id, po_id, doc_type[invoice|bl|packing_list|form_e], status, updated_at
qc_inspections        id, po_id, inspected_by, status[ok|ng], inspected_at
qc_items              id, inspection_id, pr_item_id, actual_thickness, actual_d_inner,
                         actual_d_outer, actual_width, actual_length, actual_weight, status
material_claims       id, inspection_id, po_id, submitted_by, supplier_id, status, notes
claim_attachments     id, claim_id, file_path, uploaded_by
attachments           id, attachable_type, attachable_id, file_path, file_name,
                         file_type, uploaded_by, created_at
announcements         id, title, content, created_by, published_at
```

> **4 tanggal penting yang harus selalu ditracking per PO:**
> - `purchase_requirements.created_at` — tanggal permintaan dibuat
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

Modul yang menggunakan attachment: `quotations`, `qc_inspections`, `material_claims`, `purchase_orders`.

---

## 📁 Struktur File

```
app/
├── Models/             PurchaseRequirement.php, QuotationItem.php   (PascalCase)
├── Http/
│   ├── Controllers/    PurchaseRequirementController.php
│   └── Middleware/     RoleMiddleware.php
└── Policies/           QuotationPolicy.php

resources/views/
├── layouts/            app.blade.php, auth.blade.php
├── partials/           navbar.blade.php, sidebar.blade.php, alerts.blade.php
├── purchasing/         dashboard.blade.php, pr/create.blade.php
├── supplier/           dashboard.blade.php, quotation/form.blade.php
├── qc/                 dashboard.blade.php, inspection/form.blade.php
└── admin/              dashboard.blade.php, users/index.blade.php

routes/
└── web.php             semua route dikelompokkan per role dengan middleware
```

---

## 🔐 Definisi Route

```php
// Purchasing
Route::middleware(['auth', 'role:purchasing'])->prefix('purchasing')->name('purchasing.')->group(function () {
    Route::get('/dashboard', [PurchasingController::class, 'dashboard'])->name('dashboard');
    Route::resource('requirements', PurchaseRequirementController::class);
    Route::resource('purchase-orders', PurchaseOrderController::class);
});

// Supplier
Route::middleware(['auth', 'role:supplier'])->prefix('supplier')->name('supplier.')->group(function () {
    Route::get('/dashboard', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::resource('quotations', QuotationController::class);
});

// QC
Route::middleware(['auth', 'role:qc'])->prefix('qc')->name('qc.')->group(function () {
    Route::get('/dashboard', [QcController::class, 'dashboard'])->name('dashboard');
    Route::resource('inspections', QcInspectionController::class);
});

// Admin
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::resource('users', UserController::class);
    Route::resource('exchange-rates', ExchangeRateController::class);
});
```

Penamaan route: `role.resource.action` — contoh: `purchasing.requirements.create`

---

## 💱 Konversi Kurs

**Rumus:** `IDR = price_per_kg × weight_needed × rate_to_idr`

```php
$rate = ExchangeRate::where('currency', $currency)
    ->orderBy('valid_from', 'desc')
    ->first();

$idr = $quotationItem->price_per_kg * $quotationItem->pr_item->weight_needed * $rate->rate_to_idr;
```

> Jangan overwrite kurs lama — selalu `INSERT` baru agar histori tetap akurat.

---

## 📦 Format Nomor Dokumen

Format PR : `REQ/MM/YYYY/XXX` | Contoh: `REQ/05/2025/001`
Format PO : `PO/MM/YYYY/XXX`  | Contoh: `PO/05/2025/001`

```php
// Nomor PR
$count = PurchaseRequirement::whereYear('created_at', now()->year)
    ->whereMonth('created_at', now()->month)
    ->count();
$prNumber = 'REQ/' . now()->format('m/Y') . '/' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

// Nomor PO
$count = PurchaseOrder::whereYear('created_at', now()->year)
    ->whereMonth('created_at', now()->month)
    ->count();

$poNumber = 'PO/' . now()->format('m/Y') . '/' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
```

---

## 📊 Modul Perbandingan Harga

Buat 3 view terpisah:

| View | Isi |
|---|---|
| Antar Supplier | Semua quotation satu PR ditampilkan side-by-side + grafik batang |
| Historis | Grafik garis harga satu material dari satu supplier lintas periode |
| vs Harga Terbaik | Harga saat ini vs `MIN(price_per_kg)` histori material yang sama |

---

## 🔍 Fitur Pencarian & Filter

Semua halaman daftar (tabel) **wajib** memiliki fitur pencarian dan filter. Implementasi dengan DataTables.js untuk pencarian client-side, dan query filter untuk server-side:

```php
// Contoh filter di Controller
$query = PrItem::query();

if ($request->filled('search')) {
    $query->where(function($q) use ($request) {
        $q->where('material_name', 'like', '%' . $request->search . '%')
          ->orWhere('hs_code', 'like', '%' . $request->search . '%');
    });
}

if ($request->filled('supplier_id')) {
    $query->whereHas('quotation', fn($q) => $q->where('supplier_id', $request->supplier_id));
}

$items = $query->paginate(20);
```

**Field yang bisa dicari/difilter per halaman:**

| Halaman | Filter yang tersedia |
|---|---|
| Daftar Permintaan | No. PR, Nama material, HS Code, periode, status |
| Daftar Penawaran | Nama supplier, periode, status, mata uang |
| Daftar PO | No. PO, nama supplier, status, rentang tanggal |
| Riwayat Inspeksi QC | Nama material, status OK/NG, rentang tanggal |

---

## 📎 Fitur Upload / Attachment

File upload dipakai di beberapa modul. Selalu gunakan tabel `attachments` (polymorphic) — jangan buat kolom file terpisah di setiap tabel.

**Ketentuan upload:**
- Tipe file yang diizinkan: `jpg`, `jpeg`, `png`, `pdf`, `xlsx`, `xls`, `doc`, `docx`
- Ukuran maksimal: **10 MB** per file
- Simpan di: `storage/app/private/attachments/{tahun}/{bulan}/`
- Jangan simpan ke `public/` langsung

```php
// Validasi upload di Controller
$request->validate([
    'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf,xlsx,xls,doc,docx|max:10240',
]);

// Simpan file
foreach ($request->file('attachments', []) as $file) {
    $path = $file->store('attachments/' . now()->format('Y/m'), 'private');
    $model->attachments()->create([
        'file_path'   => $path,
        'file_name'   => $file->getClientOriginalName(),
        'file_type'   => $file->getMimeType(),
        'uploaded_by' => auth()->id(),
    ]);
}
```

**Modul yang punya fitur upload:**

| Modul | Kegunaan |
|---|---|
| Penawaran (Supplier) | Lampiran dokumen pendukung quotation |
| QC Inspection | Foto bukti material NG (wajib jika status NG) |
| Claim Material | Bukti kerusakan — otomatis dari attachment QC |
| Purchase Order | Lampiran dokumen impor (Invoice, BL, dll.) |

---

## 🎨 Panduan UI

| Aspek | Ketentuan |
|---|---|
| Warna utama | `#1F5FA6` (biru) |
| Aksen ADASI | `#C0392B` (merah) |
| Background card | `#F4F6F8` |
| Font | Inter atau Poppins (Google Fonts) |
| Ikon | Bootstrap Icons (`bi bi-*`) — jangan campur Font Awesome |
| Tabel | DataTables.js — wajib untuk tabel dengan banyak baris |
| Notifikasi | Toast Bootstrap atau SweetAlert2 |
| Loading state | Spinner pada tombol submit saat proses berjalan |

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
| 6 | Simpan file upload ke `public/` — gunakan `storage/app/private` + symlink |
| 7 | Taruh logika bisnis di View — taruh di Controller atau Service class |
| 8 | Buat kolom file/attachment terpisah di tabel — gunakan tabel `attachments` polymorphic |

---

## ✅ Checklist Sebelum Buat Fitur Baru

- [ ] Route sudah diproteksi middleware role yang sesuai
- [ ] Query supplier sudah difilter `supplier_id`
- [ ] Ada validasi di Controller (`$request->validate([...])`)
- [ ] Flash message success / error sudah ditambahkan
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