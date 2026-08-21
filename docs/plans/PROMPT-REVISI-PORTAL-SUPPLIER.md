# PROMPT REVISI PORTAL SUPPLIER — VERSI GABUNGAN
> Repo: `poyipoy/supplierportal` · Branch: `master` · Digabung: 03 Agustus 2026

---

## 📎 Catatan Sumber (baca dulu sebelum pakai dokumen ini)

Dokumen ini adalah gabungan dari dua sumber:

1. **Governance layer** — dari `PROMPT-REVISI-PORTAL-SUPPLIER.md` (Role, Execution Rules, Architecture, Security, Testing Requirements, Deliverables, Final Checklist). Bagian ini kuat karena generik-tapi-benar dan sudah mencakup hal yang sebelumnya tidak eksplisit di mission per-fitur (audit broadcast/Reverb, PHPUnit Feature Test konkret, guardrail "jangan ubah perhitungan `amount`", proteksi formula injection, dll).
2. **Technical detail** — dari `MISSION-01` s.d. `MISSION-05`, hasil investigasi langsung ke kode repo per 03 Agustus 2026 (nama file, nama kolom, nama route, pola kode existing yang harus di-reuse).

**Yang wajib kamu tahu:** penggabungan ini dikerjakan tanpa akses langsung ke repo `poyipoy/supplierportal` saat ini (repo private, tidak bisa di-fetch otomatis). Semua detail teknis di bawah adalah klaim dari investigasi sebelumnya — **bukan** re-verifikasi baru. Konsekuensinya: **Phase 1 — Audit Kondisi Existing di bawah tetap wajib dijalankan penuh**, bukan basa-basi. Kalau ada detail di dokumen ini yang ternyata sudah tidak sesuai kode aktual (nama kolom berubah, file pindah, dll), **kode aktual yang menang**, bukan dokumen ini.

### Konflik yang sengaja saya putuskan (perlu kamu tahu sebelum eksekusi)

| Area | Opsi A | Opsi B | Keputusan di dokumen ini | Alasan |
|---|---|---|---|---|
| Nama kolom ketersediaan supplier di `quotation_items` | `offered_quantity`, `offered_thickness`, dst | `available_qty`, `available_thickness`, dst | **`available_*`** | Hasil investigasi grounded ke MISSION-03. Tapi tetap cross-check ke migration existing sebelum eksekusi — kalau ternyata ada konvensi lain yang sudah dipakai di tabel lain untuk kasus serupa, ikuti konvensi repo. |
| Nama kolom template import PR | `weight_per_unit_kg` | `weight_needed` | **`weight_needed`** | Harus sama persis dengan nama kolom asli di `pr_items` (dari MISSION-01/03), supaya `PrItem::sanitizeMaterialData()` bisa langsung dipakai tanpa mapping ulang. |
| Mode import quotation | `Replace/Append` (biner ala PR) | `Replace Imported Fields` / `Fill Empty Fields Only` | **`Replace Imported Fields` / `Fill Empty Fields Only`** | Baris `quotation_items` selalu 1:1 dengan `pr_item_id` (tidak bisa nambah baris baru seperti PR), jadi risikonya bukan "baris nambah" tapi "menimpa harga yang sudah diketik manual". Opsi Fill-Empty-Only lebih aman untuk kasus ini. |

Kalau agent yang eksekusi menemukan bahwa keputusan di atas ternyata salah setelah baca kode asli, **dokumentasikan penyimpangannya di Analysis Summary**, jangan diam-diam diubah tanpa catatan.

---

## ROLE

Bertindak sebagai **Senior Laravel Software Architect, Backend Engineer, Frontend Engineer, Database Engineer, dan QA Engineer**.

Kamu akan melakukan audit dan implementasi revisi terhadap aplikasi berikut:

- **Repository:** `poyipoy/supplierportal`
- **Branch dasar:** `master`
- **Framework:** Laravel 12 · **PHP:** 8.2
- **Frontend:** Blade Template, Bootstrap 5, Bootstrap Icons, JavaScript, jQuery, AJAX
- **Database:** MySQL
- **Tabel besar:** Yajra Laravel DataTables (server-side)
- **Excel:** Maatwebsite Laravel Excel (`FromCollection` + `WithHeadings` + `ShouldAutoSize` untuk export; `ToCollection` + `WithHeadingRow` untuk import — ikuti pola existing, jangan ganti pendekatan)
- **Authorization:** middleware role `admin`, `purchasing`, `supplier`, `qc`

Baca terlebih dahulu **seluruh isi `AGENTS.md`** di root repo dan patuhi seluruh ketentuannya (penamaan route `role.resource.action`, isolasi data supplier, tabel `attachments` polymorphic, pola upload, dll) — dokumen ini melengkapi `AGENTS.md`, bukan menggantikannya.

Referensi UX: dua screenshot Infor LN ("Import" dan "Advanced Export") dipakai sebagai acuan pola interaksi (modal upload + mode, modal export + scope), **bukan** untuk ditiru pixel-perfect. Adaptasikan ke desain Portal Supplier yang sudah ada, sesederhana mungkin sesuai kebutuhan nyata (lihat batasan di tiap Functional Requirement).

---

## OBJECTIVE

| # | Requirement | Setara Mission File |
|---|---|---|
| 1 | Remark per material pada Purchase Requisition | ≈ MISSION-01 Bagian A |
| 2 | Reference/No. PR dan Remark pada tabel Purchase Order | ≈ MISSION-01 Bagian B |
| 3 | Audit dan perbaiki seluruh fitur notifikasi (termasuk broadcast/Reverb) | ≈ MISSION-02 + tambahan |
| 4 | Supplier input dimensi & qty ketersediaan sendiri pada quotation | ≈ MISSION-03 |
| 5 | Tombol salin dimensi/qty permintaan Purchasing → data penawaran Supplier | ≈ MISSION-03 |
| 6 | Export pada menu PR, PO, Quotation | ≈ MISSION-04 |
| 7 | Export pada detail PR | ≈ MISSION-04 |
| 8 | Import pada form tambah PR (Purchasing) | ≈ MISSION-05 Bagian 1 |
| 9 | Import pada form quotation (Supplier) | ≈ MISSION-05 Bagian 2 |
| 10 | Template Excel yang dapat diunduh untuk proses import | ≈ MISSION-05 |

Jangan berhenti pada dokumen analisis. Setelah Phase 1 Audit selesai, implementasikan perubahan, tambahkan pengujian, dan laporkan hasil pengujian secara jujur (lihat larangan di Execution Rules #20).

---

## IMPORTANT EXECUTION RULES

1. Analisis kode yang benar-benar ada sebelum mengubah apa pun — jangan percaya buta ke tabel kolom/nama di dokumen ini, cross-check ke Phase 1 Audit.
2. Jangan mengasumsikan nama tabel, route, controller, view, relasi, atau field yang tidak kamu verifikasi sendiri.
3. Gunakan struktur dan pola yang sudah ada di repository (contoh pola migration guard, pola export existing, dll — lihat referensi di tiap FR).
4. Jangan mengganti framework atau menambahkan frontend framework baru.
5. Jangan menambahkan CDN baru.
6. Jangan menambahkan package Composer/npm baru — `maatwebsite/excel` sudah jadi dependency, cukup dipakai.
7. Gunakan `maatwebsite/excel` untuk seluruh proses import dan export.
8. Gunakan Bootstrap 5, Bootstrap Icons, jQuery, AJAX, SweetAlert, dan DataTables yang sudah ada.
9. Jangan menulis query database di Blade.
10. Jangan menempatkan seluruh proses import/export di controller besar — pisah ke Import/Export/Service class.
11. Gunakan Form Request, Import class, Export class, Service class, atau DTO ketika diperlukan.
12. Semua perubahan database wajib pakai migration baru yang reversible, dengan guard `Schema::hasColumn()` (ikuti pola `2026_05_29_000001_add_quantity_to_pr_items_table.php`).
13. Jangan mengedit migration lama yang mungkin sudah pernah dijalankan.
14. Semua penyimpanan multi-tabel wajib pakai database transaction.
15. Pertahankan backward compatibility terhadap data PR, quotation, dan PO lama (kolom baru selalu nullable, data lama tanpa nilai baru tetap bisa dibuka tanpa error).
16. Supplier tidak boleh melihat, mengimpor, mengekspor, atau mengubah data supplier lain.
17. Jangan menjalankan migration atau pengujian mutasi data terhadap database production.
18. Jangan mengubah modul lain yang tidak berkaitan tanpa alasan teknis yang jelas.
19. Jangan melakukan direct push ke `master`.
20. Jangan mengklaim test berhasil apabila test tersebut tidak benar-benar dijalankan. Kalau database test tidak tersedia, laporkan dengan jujur test mana yang tidak bisa dijalankan dan alasannya.

---

## PHASE 1 — AUDIT KONDISI EXISTING

Audit minimal file berikut sebelum implementasi. Kolom "Klaim dari Investigasi Sebelumnya" berisi apa yang sudah pernah ditemukan (per catatan di atas: **wajib diverifikasi ulang**, jangan ditelan mentah).

### Routing
- `routes/web.php` — *Klaim: route export existing ada di baris ~118-119, notification route di baris ~38-41. Verifikasi ulang nomor barisnya karena file berubah seiring waktu.*

### Purchase Requisition
- `app/Http/Controllers/Purchasing/PurchaseRequisitionController.php`, `PrItemController.php`
- `app/Models/PurchaseRequisition.php`, `app/Models/PrItem.php`
- `resources/views/purchasing/pr/{index,create,edit,show,_item_row}.blade.php`
- Seluruh migration terkait `purchase_requisitions` dan `pr_items`
- *Klaim: `pr_items` belum punya kolom `remark`. Field "Additional Notes/Remarks" yang sudah ada di `create.blade.php` (~baris 50) adalah remark level header (`purchase_requirements.notes`), bukan per material.*

### Purchase Order
- `app/Http/Controllers/Purchasing/PurchaseOrderController.php`, `app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php`
- `app/Models/PurchaseOrder.php`
- `resources/views/purchasing/po/{index,show}.blade.php`, `resources/views/supplier/purchase-orders/index.blade.php`
- Seluruh migration terkait `purchase_orders` dan `po_quotations`
- *Klaim: `purchase_orders.notes` sudah ada dan fillable, tapi belum ditampilkan di tabel manapun. Relasi multi-PR: `PurchaseOrder -> quotations -> purchaseRequisition -> pr_number`, pola sudah ada contohnya di `app/Exports/PurchaseOrdersExport.php`.*

### Quotation
- `app/Http/Controllers/Supplier/QuotationController.php`, `app/Http/Controllers/Purchasing/QuotationListController.php`
- `app/Models/Quotation.php`, `app/Models/QuotationItem.php`
- `resources/views/supplier/quotations/{create,show}.blade.php`, `resources/views/purchasing/quotations/{index,show}.blade.php`
- Seluruh migration terkait `quotations` dan `quotation_items`
- *Klaim: relasi `quotation_item` 1:1 dengan `pr_item` lewat FK `pr_item_id` — tidak boleh diubah jadi 1-to-many. Field dimensi PR (`thickness`, `d_inner`, `d_outer`, `width`, `length`, semua `decimal(10,4)` nullable) relevansinya tergantung `shape` (`Flat`/`Round`/`Hollow`), lihat `PrItem::RELEVANT_DIMENSIONS`.*

### Import dan Export
- `app/Http/Controllers/Purchasing/ExportController.php`
- `app/Exports/RequisitionsExport.php`, `app/Exports/PurchaseOrdersExport.php`
- Seluruh route dan tombol export yang sudah ada
- Cek dengan `grep -rn "ToModel\|WithValidation\|Excel::import" app/`
- *Klaim: export list PR & PO sudah jalan (`FromCollection`+`WithHeadings`+`ShouldAutoSize`). Export detail PR dan export Quotation **belum ada**. Import **belum ada sama sekali** di codebase manapun.*

### Notification
- `app/Http/Controllers/NotificationController.php`
- `app/Notifications/SystemNotification.php`
- `app/Support/NotificationCategory.php`
- Seluruh pemanggilan `notify(new SystemNotification(...))` — cek dengan `grep -rn "notify(new" app/Http/Controllers`
- Partial navbar/notification drawer, JS polling
- **`config/reverb.php`, konfigurasi broadcasting, `QUEUE_CONNECTION` di `.env`**
- *Klaim: `SystemNotification` mengimplementasikan `ShouldBroadcast`, ada `config/reverb.php`, `QUEUE_CONNECTION=database`. Ini area yang HARUS masuk cakupan audit (lihat FR2) — jangan cuma cek database notification-nya saja.*

### Testing
- `tests/Feature`, `tests/Unit`, `phpunit.xml`, `.env.testing` jika tersedia
- *Klaim: sudah ada `tests/Feature/SupplierDataIsolationTest.php` dengan pola isolasi data supplier — kalau memang ada, ikuti pola test itu untuk test baru terkait isolasi data.*

### Ringkasan Audit Wajib Dibuat, Berisi:
1. Existing behavior (per area di atas)
2. File yang akan diubah
3. Migration yang dibutuhkan
4. Route baru yang dibutuhkan
5. Risiko regresi
6. Masalah authorization yang ditemukan
7. Masalah notification yang ditemukan (termasuk broadcast/Reverb)
8. Masalah import/export yang ditemukan
9. Strategi pengujian
10. **Daftar klaim di dokumen ini yang ternyata SALAH/sudah berubah**, dan penyesuaian apa yang diambil

Setelah Ringkasan Audit selesai, lanjutkan implementasi tanpa menunggu persetujuan — **kecuali** ditemukan blocker yang bisa menyebabkan kehilangan data atau perubahan besar terhadap perhitungan bisnis (mis. rumus `amount`). Kalau ketemu blocker seperti itu, berhenti dan laporkan dulu.

---

# FUNCTIONAL REQUIREMENT 1 — Remark per Material (PR) & Reference/Remark (PO)
*(≈ MISSION-01)*

## 1A. Remark per Material — PR

**Kondisi existing:** PR sudah punya field remark/notes level header (`purchase_requirements.notes`, di `create.blade.php` ~baris 50) — **pertahankan**, jangan dihapus/diganti. `pr_items` **belum** punya kolom remark. Ini fitur tambahan, bukan pengganti.

**Migration baru** (guard `Schema::hasColumn`, ikuti pola `2026_05_29_000001_add_quantity_to_pr_items_table.php`):

```php
// database/migrations/2026_08_03_000001_add_remark_to_pr_items_table.php
public function up(): void
{
    if (! Schema::hasColumn('pr_items', 'remark')) {
        Schema::table('pr_items', function (Blueprint $table) {
            $table->text('remark')->nullable()->after('weight_needed');
        });
    }
}

public function down(): void
{
    if (Schema::hasColumn('pr_items', 'remark')) {
        Schema::table('pr_items', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
}
```

**Model `app/Models/PrItem.php`:**
- Tambahkan `'remark'` ke `$fillable`, ke import mapping, dan ke export mapping.
- Tambahkan ke `sanitizeMaterialData()`, ikuti pola field `hs_code`:
  ```php
  'remark' => self::nullableString($item['remark'] ?? null),
  ```

**Controller `PurchaseRequisitionController.php`**, di `materialValidationRules()`:
```php
'items.*.remark' => 'nullable|string|max:2000',
```

**View yang disentuh:**

| File | Perubahan |
|---|---|
| `resources/views/purchasing/pr/_item_row.blade.php` | Tambah `<td>` baru berisi `<textarea name="items[{{ $index }}][remark]">`, diletakkan setelah kolom Weight/Unit dan sebelum kolom Action |
| `resources/views/purchasing/pr/create.blade.php` | Tambah `<th>Remark</th>`, kecilkan lebar `Dimensions` dari `34%` → `26%`, tambahkan `Remark` `10%` supaya total tetap 100% |
| `resources/views/purchasing/pr/edit.blade.php` | Sama seperti create (partial `_item_row` otomatis ikut) |
| `resources/views/purchasing/pr/show.blade.php` | Tabel Material List (render manual, bukan partial) — tambah `<th>Remark</th>` dan `<td>{{ $item->remark ?? '-' }}</td>` |

## 1B. Reference (No. PR) & Remark — PO

**Kondisi existing:** kolom `purchase_orders.notes` **sudah ada** dan fillable — **tidak perlu migration baru**. Cek dulu apakah form create/edit PO sudah punya input untuk field ini; kalau belum, tambahkan textarea `notes` di form pembuatan PO. Kalau sudah ada tapi cuma tidak tampil di tabel index, langsung ke bagian di bawah.

**Reference/No. PR** — jangan buat kolom database baru; hitung dari relasi (1 PO bisa berasal dari beberapa PR sekaligus lewat `quotations -> purchaseRequisition`):
```php
$prNumbers = $po->quotations->map(fn($qt) => optional($qt->purchaseRequisition)->pr_number)
    ->filter()->unique()->implode(', ') ?: '-';
```
Taruh logic ini di helper/accessor pada model (mis. `PurchaseOrder::getPrReferenceAttribute()`), **jangan duplikasi** logic yang sama di controller, Blade, dan export — reuse dari satu tempat.

**Controller `PurchaseOrderController.php`, method `index()`**, tambahkan 2 `addColumn` di blok `DataTables::eloquent($query)`:
```php
->addColumn('pr_reference', function ($po) {
    return $po->quotations->map(fn($q) => optional($q->purchaseRequisition)->pr_number)
        ->filter()->unique()->implode(', ') ?: '-';
})
->addColumn('remark_display', function ($po) {
    $notes = trim((string) $po->notes);
    if ($notes === '') return '-';
    return '<span title="' . e($notes) . '">' . e(\Illuminate\Support\Str::limit($notes, 40)) . '</span>';
})
```
Tambahkan `'remark_display'` ke `rawColumns([...])` karena mengandung HTML. Pastikan server-side DataTables tetap bisa search berdasarkan nomor PO, nomor PR/reference, supplier, remark, dan status — jangan filtering seluruh dataset di browser kalau mode-nya server-side.

**View `resources/views/purchasing/po/index.blade.php`:**
- Tambah `<th>Reference (No. PR)</th>` dan `<th>Remark</th>` di `<thead>` (setelah `<th>Period</th>`)
- Tambah di array `columns:` JS:
  ```js
  { data: 'pr_reference', name: 'pr_reference', orderable: false },
  { data: 'remark_display', name: 'remark_display', orderable: false, searchable: false },
  ```
- Terapkan juga ke daftar PO Supplier (`resources/views/supplier/purchase-orders/index.blade.php`) kalau strukturnya relevan, dan ke detail PO kalau `notes` belum ditampilkan jelas di sana.

## Acceptance Criteria — FR1
- [ ] Migration `add_remark_to_pr_items_table` jalan tanpa error
- [ ] Form create/edit PR — tiap baris material punya kolom Remark, tersimpan per item (bukan tergabung ke notes header)
- [ ] Detail PR — kolom Remark muncul di Material List, tampil `-` kalau kosong
- [ ] Tabel PO — kolom Reference menampilkan nomor PR benar, termasuk comma-separated untuk PO dari >1 PR, tanpa duplikat
- [ ] Tabel PO — kolom Remark menampilkan `notes`, terpotong+tooltip kalau panjang, `-` kalau kosong, di-escape aman
- [ ] Search server-side DataTables tetap berfungsi untuk kolom baru
- [ ] Tidak ada N+1 signifikan saat load relasi `quotations.purchaseRequisition`
- [ ] Fitur PR/PO existing (search, filter, export, create, edit) tidak rusak

---

# FUNCTIONAL REQUIREMENT 2 — Audit & Perbaikan Fitur Notifikasi
*(≈ MISSION-02 + cakupan broadcast/Reverb yang sebelumnya terlewat)*

> Sifatnya **audit-first**: telusuri kode dan uji behavior dulu sebelum menyimpulkan ada/tidaknya bug.

## Area yang Wajib Diperiksa

### 1. Delivery: Database Notification vs Broadcast/Reverb ⚠️ *(area yang paling penting untuk tidak dilewati)*
Repo ini pakai `ShouldBroadcast` di `SystemNotification`, ada `config/reverb.php`, `QUEUE_CONNECTION=database`. Database notification adalah **fungsi utama**; real-time broadcast adalah **enhancement**. Cek:
- Apakah kegagalan broadcast/Reverb (server down, queue worker tidak jalan) bisa membuat transaksi utama gagal (submit PR, submit quotation, create PO)? **Ini tidak boleh terjadi** — kegagalan broadcast harus ter-catch/ter-queue, bukan menggagalkan proses inti.
- Kondisi ketika queue worker tidak berjalan — apakah database notification tetap tersimpan (via queued job yang menunggu) atau malah hilang?
- Kondisi ketika broadcast server (Reverb) tidak berjalan — apakah ada exception yang bocor ke response HTTP user?
- Tambahkan **Feature Test**: "core transaction (submit PR/quotation/PO) tetap sukses ketika real-time broadcast tidak tersedia".

### 2. URL Normalization (`normalizeNotificationUrl`)
Rewrite host/scheme/port URL notifikasi supaya jalan walau diakses dari domain berbeda (`adasi_portal-supplier.test` vs `localhost:8000`). Cek:
- Apakah normalisasi masih benar kalau di-deploy ke domain produksi (bukan localhost/test)?
- Ada kasus URL ke-generate dengan port salah (HTTPS tapi port ikut ke-append padahal harusnya tidak)?

### 3. Role-based URL Rejection (`isUsableNotificationUrl`)
Reject URL yang prefix route-nya bukan milik role user login (mis. supplier tidak boleh diarahkan ke `/purchasing/...`). Cek:
- Ada notifikasi yang **seharusnya valid** tapi ke-reject karena salah baca prefix?
- Test tiap role (admin, purchasing, supplier, qc) — klik semua jenis notifikasi yang bisa mereka terima, pastikan redirect benar, bukan fallback ke dashboard.
- Jangan menyelesaikan masalah dengan melewati middleware. Solusi aman: route detail read-only untuk role berhak, URL berbeda per role recipient, redirect ke list dengan filter entity, atau jangan kirim notifikasi ke role yang memang tidak punya akses.

### 4. Fallback URL Resolution (`fallbackUrlFor`)
Regex-parsing teks notifikasi (title+message) untuk cari nomor PO/PR sebagai fallback kalau `data['url']` tidak valid:
```php
if (! $po && preg_match('/po\/\d{2}\/\d{4}\/\d{3}/i', $text, $matches)) {
```
Cek:
- Regex ini masih match format nomor PO/PR sekarang? (`PO/MM/YYYY/XXX`, `REQ/MM/YYYY/XXX` — cek di `PurchaseOrder::generatePoNumber()` dan `PurchaseRequisition::generatePrNumber()`)
- Regex ini rawan kalau title notifikasi berubah-ubah format → fallback gagal total → notifikasi dead-end ke dashboard. Kalau ditemukan kasus ini, tandai sebagai bug.
- Entity yang dituju sudah dihapus, atau notifikasi lama punya absolute URL — pastikan tidak error 500 saat diklik.

### 5. Mark All Read per Kategori
Endpoint `markAllRead` menerima parameter `category`, hanya mark notifikasi kategori tersebut. Cek: apakah semua kategori di `NotificationCategory::options()` konsisten dengan `NotificationCategory::key($notification)` untuk **semua** jenis notifikasi yang pernah dikirim sistem (PR submitted, quotation masuk, claim, dll)? Kalau ada notifikasi yang `key()`-nya tidak match kategori manapun, notifikasi itu tidak akan pernah ke-mark saat user pilih kategori spesifik.

### 6. Unread Count Real-time
Di-polling frontend (cek `partials/navbar.blade.php` atau layout terkait). Cek: badge count update tanpa reload? Setelah mark read/mark all read, badge langsung berkurang tanpa delay/mismatch?

### 7. Recipient Correctness
Periksa seluruh event minimal: PR submitted, quotation submitted, revision requested, revised quotation submitted, quotation accepted/rejected, PO issued, material arrived, QC inspection, claim created/responded, conversation/message (jika ada). Pastikan recipient sesuai proses bisnis dan tidak ada duplikasi tanpa alasan.

### 8. Logging
Tambahkan logging berguna ketika notifikasi gagal, tapi **jangan** mencatat data sensitif.

## Feature Test yang Wajib Ditambahkan
- Unread count hanya milik user aktif (bukan bocor ke user lain)
- User tidak bisa mark notifikasi milik user lain
- Mark one as read / mark all as read
- Redirect notifikasi sesuai role (per role: admin, purchasing, supplier, qc)
- Broken URL menggunakan fallback yang aman (tidak 500)
- Supplier tidak bisa diarahkan ke data supplier lain
- **Core transaction tetap sukses ketika broadcast tidak tersedia**
- Tidak ada duplicate notification untuk satu event

## Skenario Testing Manual

| # | Skenario | Expected |
|---|---|---|
| 1 | Login Purchasing, submit PR baru | Admin dapat notifikasi baru, badge count admin bertambah |
| 2 | Klik notifikasi "New PR" sebagai Admin | Redirect ke `purchasing.requisitions.show` PR yang benar |
| 3 | Login Supplier, buka notifikasi quotation | Redirect ke halaman quotation yang benar, tidak ke-reject role mismatch |
| 4 | Klik "Mark all read" tanpa filter kategori | Semua notifikasi unread jadi read, badge jadi 0 |
| 5 | Klik "Mark all read" dengan filter 1 kategori | Hanya kategori itu jadi read, kategori lain tetap unread |
| 6 | Buka lewat 2 host berbeda (test vs production, jika tersedia) | URL notifikasi tetap redirect benar di kedua host |
| 7 | Notifikasi lama, relasi (PO/PR/quotation) sudah dihapus | Tidak error 500 saat diklik |
| 8 | Matikan queue worker / Reverb (simulasi) → submit PR | Submit tetap sukses, database notification tetap tersimpan (mungkin delay), tidak ada 500 |

## Cara Melaporkan Temuan
Untuk **setiap bug**, dokumentasikan sebelum fix:
```
### Bug #N: [judul singkat]
- Lokasi: file:line
- Reproduce: langkah-langkah
- Root cause: penjelasan
- Fix: perubahan kode yang dilakukan
```
Bug dengan fix berisiko (mis. ubah struktur `data['url']` yang sudah tersimpan di notifikasi lama) → tandai **"butuh diskusi"**, jangan langsung dieksekusi tanpa backward-compatibility plan.

Setelah semua bug diperbaiki, buat `../audits/NOTIFICATION-AUDIT-REPORT.md`: daftar bug (format di atas) + area yang **sudah dicek dan tidak ada masalah** (supaya cakupan audit jelas, bukan cuma daftar yang rusak) + hasil audit broadcast/Reverb secara eksplisit.

## Acceptance Criteria — FR2
- [ ] Semua 8 skenario manual dijalankan dan dicatat
- [ ] Semua bug diperbaiki atau didokumentasikan "butuh diskusi"
- [ ] `../audits/NOTIFICATION-AUDIT-REPORT.md` dibuat, termasuk bagian broadcast/Reverb
- [ ] Tidak ada regresi pada fitur notifikasi yang sudah jalan
- [ ] Database notification tetap berfungsi tanpa Reverb
- [ ] Tidak ada notifikasi yang mengarah ke route terlarang untuk penerimanya

---

# FUNCTIONAL REQUIREMENT 3 — Dimensi & Qty Ketersediaan Supplier + Tombol Salin
*(≈ MISSION-03; field `offered_*` di prompt awal diputuskan jadi `available_*` — lihat tabel keputusan di atas)*

## Kondisi Existing
Supplier saat ini hanya input harga per kg + notes bebas per material — dimensi & qty yang tampil murni yang diminta Purchasing (read-only). Tambahkan kemampuan supplier input dimensi/qty **ketersediaan mereka sendiri** per item quotation, plus tombol salin.

**Tidak berubah:** relasi `quotation_item` 1:1 dengan `pr_item` via `pr_item_id`. Ini murni tambah kolom, bukan ubah struktur relasi.

## Database — Migration Baru

```php
// database/migrations/2026_08_03_000002_add_available_dimension_to_quotation_items_table.php
public function up(): void
{
    Schema::table('quotation_items', function (Blueprint $table) {
        if (! Schema::hasColumn('quotation_items', 'available_qty')) {
            $table->unsignedInteger('available_qty')->nullable()->after('amount');
        }
        if (! Schema::hasColumn('quotation_items', 'available_thickness')) {
            $table->decimal('available_thickness', 10, 4)->nullable()->after('available_qty');
        }
        if (! Schema::hasColumn('quotation_items', 'available_d_inner')) {
            $table->decimal('available_d_inner', 10, 4)->nullable()->after('available_thickness');
        }
        if (! Schema::hasColumn('quotation_items', 'available_d_outer')) {
            $table->decimal('available_d_outer', 10, 4)->nullable()->after('available_d_inner');
        }
        if (! Schema::hasColumn('quotation_items', 'available_width')) {
            $table->decimal('available_width', 10, 4)->nullable()->after('available_d_outer');
        }
        if (! Schema::hasColumn('quotation_items', 'available_length')) {
            $table->decimal('available_length', 10, 4)->nullable()->after('available_width');
        }
    });
}

public function down(): void
{
    Schema::table('quotation_items', function (Blueprint $table) {
        $table->dropColumn(['available_qty', 'available_thickness', 'available_d_inner',
            'available_d_outer', 'available_width', 'available_length']);
    });
}
```
Semua field nullable — quotation lama tanpa nilai baru tetap valid. Sesuaikan precision `decimal(10,4)` ini dengan precision dimensi existing di `pr_items` kalau ternyata berbeda saat Phase 1 Audit.

## Model `app/Models/QuotationItem.php`
- Tambahkan ke `$fillable`: `available_qty`, `available_thickness`, `available_d_inner`, `available_d_outer`, `available_width`, `available_length`
- Tambahkan ke `casts()`: decimal:4 untuk field dimensi, integer untuk `available_qty`
- Tambahkan accessor `getAvailableDimensionLabelAttribute()` mirip pola `PrItem::getDimensionLabelAttribute()`, supaya format tampilan konsisten
- Opsional tapi disarankan: helper untuk membandingkan requested vs offered (dipakai di Comparison UI Purchasing, lihat di bawah)

## Backend — `app/Http/Controllers/Supplier/QuotationController.php`, method `store()`
Tambahkan validasi (setelah `items.*.notes`):
```php
'items.*.available_qty' => 'nullable|integer|min:1',
'items.*.available_thickness' => 'nullable|numeric|min:0',
'items.*.available_d_inner' => 'nullable|numeric|min:0',
'items.*.available_d_outer' => 'nullable|numeric|min:0',
'items.*.available_width' => 'nullable|numeric|min:0',
'items.*.available_length' => 'nullable|numeric|min:0',
```
Validasi tambahan wajib (security, bukan opsional):
- `pr_item_id` wajib item dari PR yang sedang dibuka
- Supplier wajib punya akses ke PR tersebut (diundang/berhak lihat)
- **Jangan menerima item dari PR lain lewat request tampering** — validasi ini eksplisit, jangan cuma percaya index array dari form

Cari bagian `store()` yang create/update tiap `quotation_item`, tambahkan field baru ke payload, null-kan field dimensi yang tidak relevan dengan `shape` PR item terkait — reuse pola yang sama dengan `PrItem::sanitizeMaterialData()`, **jangan** tulis mapping shape terpisah di file lain.

## View `resources/views/supplier/quotations/create.blade.php`

Tampilkan dua kelompok informasi per baris material:

**Requested by Purchasing** (read-only): material, HS code, shape, requested quantity, requested dimensions, weight per unit, **remark material dari Purchasing (dari FR1)**.

**Supplier Availability / Offered Specification** (editable) — kolom baru "Ketersediaan Supplier", diletakkan setelah kolom "Material & Specification":

```blade
<td style="min-width: 220px;">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="small text-muted">Dimensi & Qty Tersedia</span>
        <button type="button" class="btn btn-xs btn-outline-secondary copy-from-pr-btn"
            data-index="{{ $index }}" data-shape="{{ $item->shape }}"
            data-qty="{{ $item->quantity_value }}" data-thickness="{{ $item->thickness }}"
            data-d-inner="{{ $item->d_inner }}" data-d-outer="{{ $item->d_outer }}"
            data-width="{{ $item->width }}" data-length="{{ $item->length }}"
            title="Salin dari permintaan PR">
            <i class="bi bi-clipboard-check"></i> Salin dari PR
        </button>
    </div>
    <div class="dimension-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(70px,1fr));gap:.35rem;">
        <input type="number" min="0" step="1" name="items[{{ $index }}][available_qty]" class="form-control form-control-sm avail-input" data-avail-field="qty" placeholder="Qty">
        {{-- render field dimensi sesuai $item->shape, pola sama seperti dimension-cell di _item_row.blade.php --}}
        <input type="number" min="0" step="0.01" name="items[{{ $index }}][available_thickness]" class="form-control form-control-sm avail-input" data-avail-field="thickness" placeholder="Thickness">
        <input type="number" min="0" step="0.01" name="items[{{ $index }}][available_d_inner]" class="form-control form-control-sm avail-input" data-avail-field="d_inner" placeholder="Inner D.">
        <input type="number" min="0" step="0.01" name="items[{{ $index }}][available_d_outer]" class="form-control form-control-sm avail-input" data-avail-field="d_outer" placeholder="Outer D.">
        <input type="number" min="0" step="0.01" name="items[{{ $index }}][available_width]" class="form-control form-control-sm avail-input" data-avail-field="width" placeholder="Width">
        <input type="number" min="0" step="0.01" name="items[{{ $index }}][available_length]" class="form-control form-control-sm avail-input" data-avail-field="length" placeholder="Length">
    </div>
</td>
```

Field dimensi yang tampil ikuti `shape` PR item (`Flat`→thickness/width/length, `Round`→outer diameter/length, `Hollow`→inner+outer diameter/length) — gunakan konstanta `PrItem::RELEVANT_DIMENSIONS`, jangan tulis mapping shape berbeda-beda di banyak file. Shape sudah fix dari PR, supplier tidak bisa mengubahnya.

Layout requested-vs-offered: label jelas ("Requested by Purchasing" | "Offered by Supplier"), stacked di layar kecil, **jangan** hanya bedakan pakai warna — pakai label + icon/text.

## Fitur "Salin dari PR"

Dua tombol:
1. **Copy Requested** per baris (kode di atas)
2. **Copy All Requested Values** di bagian atas tabel (loop semua baris, panggil logic yang sama)

```js
document.querySelectorAll('.copy-from-pr-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const index = this.dataset.index;
        const row = this.closest('tr');
        const map = {
            qty: this.dataset.qty, thickness: this.dataset.thickness,
            d_inner: this.dataset.dInner, d_outer: this.dataset.dOuter,
            width: this.dataset.width, length: this.dataset.length,
        };
        Object.entries(map).forEach(([field, value]) => {
            const input = row.querySelector(`[data-avail-field="${field}"]`);
            if (input && value !== '' && value !== 'null') input.value = value;
        });
    });
});
```

Aturan: field yang tidak relevan dengan shape (kosong/null di PR) tidak dipaksa diisi — biarkan kosong karena memang `d-none`. Price, notes, attachment, dan field quotation lain **tidak boleh berubah** saat copy. Copy hanya terjadi di browser/form, **tidak perlu** simpan flag `copied_from_request` ke database. Tampilkan feedback visual singkat setelah copy; anggap sebagai perubahan form yang belum disimpan (unsaved change).

## Comparison UI — Halaman Review Purchasing

Cari view detail quotation sisi Purchasing (`resources/views/purchasing/quotations/show.blade.php`), tambahkan tampilan read-only dimensi & qty ketersediaan supplier berdampingan dengan yang diminta, dengan status:
```
Exact Match | Different Specification | Quantity Shortage | Not Specified
```
Gunakan tolerance konsisten untuk perbandingan decimal (mis. `0.0001`), **bukan** strict string comparison.

## ⚠️ Keputusan Bisnis yang Tidak Boleh Diubah Diam-diam

Pada revisi ini, offered dimensions/quantity **bersifat informasi ketersediaan saja**. **Jangan** mengubah perhitungan `amount` existing:
```
amount = price_per_kg × requested total weight
```
Jangan mengubah perhitungan PO, price comparison, atau QC berdasarkan offered dimensions pada revisi ini — kecuali kode existing memang sudah pakai nilai supplier untuk itu (verifikasi di Phase 1 Audit). Dokumentasikan keputusan ini di Analysis Summary supaya tidak ada perubahan nilai transaksi tanpa persetujuan bisnis.

## Validation
- Draft: field ketersediaan boleh kosong
- Submitted: tetap nullable untuk backward compatibility
- Kalau diisi: qty minimal 1, dimensi minimal 0

## Acceptance Criteria — FR3
- [ ] Migration jalan, kolom baru muncul di `quotation_items`
- [ ] Form quotation — input ketersediaan per baris, field dimensi menyesuaikan shape
- [ ] Copy Requested (per baris) dan Copy All berfungsi, tidak menimpa field tidak relevan
- [ ] Data ketersediaan tersimpan & muncul kembali saat form dibuka ulang (draft/edit)
- [ ] Purchasing bisa lihat perbandingan requested vs offered dengan status yang jelas
- [ ] Quotation lama (kolom baru = null) tetap bisa dibuka tanpa error
- [ ] Supplier tidak bisa mengubah quotation supplier lain / kirim item dari PR lain
- [ ] Rumus `amount` tidak berubah

---

# FUNCTIONAL REQUIREMENT 4 — Export PR, PO, dan Quotation
*(≈ MISSION-04)*

Gunakan `Maatwebsite\Excel` yang sudah terpasang. Format utama **XLSX**. Ikuti pola `FromCollection`+`WithHeadings`+`ShouldAutoSize` persis seperti `app/Exports/RequisitionsExport.php` — **jangan** pakai `FromQuery`/`FromView`.

## General Export Rules (berlaku untuk semua export baru & update)
- Hormati filter aktif dari halaman daftar (search/status/period/dll — pola `request()->all()` sudah ada di export existing, reuse)
- Nama file jelas + timestamp
- Heading mudah dipahami, auto-size aktif
- Format tanggal konsisten, format angka asli (bukan semua jadi string)
- **Escape/sanitasi nilai yang bisa jadi spreadsheet formula injection** (nilai yang diawali `=`, `+`, `-`, `@` harus di-prefix atau di-strip)
- Jangan export HTML badge mentah
- Jangan export data yang tidak boleh dilihat role terkait (supplier hanya lihat quotation miliknya — pakai `auth()->id()`, **jangan percaya** `supplier_id` dari query string)
- Dataset besar → query/chunking efisien, jangan query per baris Excel

## 4.1 Update Export List PR — `app/Exports/RequisitionsExport.php`
Tambahkan kolom `Remark` per item (`$item->remark ?? '-'`) ke `$rows->push([...])` dan ke `headings()`.

## 4.2 Update Export List PO — `app/Exports/PurchaseOrdersExport.php`
`$prNumbers` sudah dihitung di `collection()` — cek apakah sudah cukup jadi kolom "PR Number" di heading. Tambahkan kolom **Remark** dari `$po->notes` (`$po->notes ?: '-'`) ke rows dan headings.

## 4.3 Export Baru — Detail PR
File baru `app/Exports/PurchaseRequisitionDetailExport.php`:
```php
<?php
namespace App\Exports;

use App\Models\PurchaseRequisition;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PurchaseRequisitionDetailExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $prId;
    public function __construct($prId) { $this->prId = $prId; }

    public function collection()
    {
        $pr = PurchaseRequisition::with(['items', 'period', 'quotations.supplier', 'quotations.items.prItem'])
            ->findOrFail($this->prId);

        $rows = collect();
        foreach ($pr->items as $item) {
            $spec = collect([$item->shape, $item->dimension_label !== '-' ? $item->dimension_label : null])
                ->filter()->implode(' | ');
            $rows->push([
                $pr->pr_number ?? 'DRAFT', $item->hs_code, $item->material_name, $spec ?: '-',
                $item->quantity_value, $item->weight_needed, $item->total_weight, $item->remark ?? '-',
            ]);
        }
        return $rows;
    }

    public function headings(): array
    {
        return ['PR Number', 'HS Code', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'Remark'];
    }
}
```
Isinya hanya material dari PR itu saja, bukan semua PR di sistem.

**Controller** (tambahkan di `app/Http/Controllers/Purchasing/ExportController.php`):
```php
public function requisitionDetail(Request $request, $id)
{
    return Excel::download(new \App\Exports\PurchaseRequisitionDetailExport($id),
        'detail_pr_' . $id . '_' . now()->format('Ymd_His') . '.xlsx');
}
```

**Route** (grup `purchasing.`):
```php
Route::get('/export/requisitions/{id}', [\App\Http\Controllers\Purchasing\ExportController::class, 'requisitionDetail'])->name('export.requisitions.detail');
```

**View** — tombol di `resources/views/purchasing/pr/show.blade.php` (header card):
```blade
<a href="{{ route('purchasing.export.requisitions.detail', $pr->id) }}" class="btn btn-success btn-sm">
    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
</a>
```

## 4.4 Export Baru — List Quotation
File baru `app/Exports/QuotationsExport.php`, filter minimal: `pr_number`, `supplier_id`, `status`, `currency` (reuse filter yang sama persis dengan yang sudah divalidasi di `QuotationListController@index`; boleh tambah `date_from`/`date_to` mengikuti pola sama).

Kolom minimal (gabungan dari kebutuhan governance + yang sudah tergrounded di kode): PR Number, Period, Supplier, Currency, Material, HS Code, Requested Quantity, Requested Dimensions, **Offered Quantity, Offered Dimensions** (dari FR3), Price per Kg, Amount, Exchange Rate, Total IDR, Item Notes, Status, Submitted At.

```php
<?php
namespace App\Exports;

use App\Models\Quotation;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class QuotationsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $filters;
    public function __construct(array $filters = []) { $this->filters = $filters; }

    public function collection()
    {
        $q = Quotation::with(['supplier', 'purchaseRequisition', 'items.prItem', 'exchange_rate'])
            ->whereIn('status', ['submitted', 'revision_requested', 'accepted', 'rejected']);

        if (!empty($this->filters['pr_number'])) {
            $q->whereHas('purchaseRequisition', fn($qq) => $qq->where('pr_number', 'like', '%' . $this->filters['pr_number'] . '%'));
        }
        if (!empty($this->filters['supplier_id'])) $q->where('supplier_id', $this->filters['supplier_id']);
        if (!empty($this->filters['status'])) $q->where('status', $this->filters['status']);
        if (!empty($this->filters['currency'])) $q->where('currency', $this->filters['currency']);

        $rows = collect();
        foreach ($q->orderByDesc('submitted_at')->get() as $quotation) {
            $totalAmount = $quotation->items->sum('amount');
            $rate = $quotation->exchange_rate;
            $totalIdr = $rate ? $totalAmount * $rate->rate_to_idr : 0;
            $rows->push([
                optional($quotation->purchaseRequisition)->pr_number ?? '-',
                optional($quotation->supplier)->name ?? '-',
                strtoupper($quotation->currency),
                number_format($totalAmount, 2),
                'Rp ' . number_format($totalIdr, 0, ',', '.'),
                strtoupper($quotation->status),
                $quotation->submitted_at?->format('d/m/Y H:i') ?? '-',
            ]);
        }
        return $rows;
    }

    public function headings(): array
    {
        return ['PR Number', 'Supplier', 'Currency', 'Total Amount', 'Total IDR', 'Status', 'Submitted At'];
    }
}
```
> Perluas `collection()`/`headings()` di atas dengan kolom Material/HS Code/Requested vs Offered per item kalau granularitas per-item dibutuhkan (bukan cuma 1 baris per quotation) — sesuaikan dengan ekspektasi user saat Phase 1 Audit, karena versi di atas masih agregat per quotation.

**Controller & Route:**
```php
public function quotations(Request $request)
{
    return Excel::download(
        new \App\Exports\QuotationsExport($request->only(['pr_number', 'supplier_id', 'status', 'currency'])),
        'rekap_quotations_' . now()->format('Ymd_His') . '.xlsx'
    );
}
```
```php
Route::get('/export/quotations', [\App\Http\Controllers\Purchasing\ExportController::class, 'quotations'])->name('export.quotations');
```

**Export Quotation sisi Supplier** — tambahkan endpoint terpisah yang scope-nya **wajib** `auth()->id()`, bukan `supplier_id` dari request. Supplier hanya boleh export quotation miliknya sendiri.

**View** — tombol export di `resources/views/purchasing/quotations/index.blade.php`, pastikan `request()->all()` ikut terbawa:
```blade
<a href="{{ route('purchasing.export.quotations', request()->all()) }}" class="btn btn-success btn-sm">
    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
</a>
```

## UX Export
Tombol `<a href="...">Export Excel</a>` yang sudah ada sekarang **sudah cukup** — **jangan** bikin modal kompleks kalau tidak diminta eksplisit. Kalau mau ditambah affordance kecil ikuti referensi Advanced Export (scope "All Filtered Rows", loading state, error feedback) tapi jangan tambah opsi yang tidak punya fungsi nyata.

## Acceptance Criteria — FR4
- [ ] Export list PR menyertakan kolom Remark per item
- [ ] Export list PO menyertakan kolom Remark
- [ ] Export detail PR — bisa diunduh dari halaman detail, isi hanya PR tersebut
- [ ] Export list Quotation — bisa diunduh, menghormati filter aktif (PR number, supplier, status, currency)
- [ ] Export quotation supplier — di-scope ke `auth()->id()`, tidak bisa export quotation supplier lain
- [ ] Semua file Excel terbuka tanpa corrupt, heading rapi, angka/currency terbaca jelas
- [ ] Tidak ada formula injection dari input user (remark, notes, material name) yang tembus ke export
- [ ] Export dataset kosong (filter tidak match) tidak error 500, tetap unduh file berisi header saja

---

# FUNCTIONAL REQUIREMENT 5 — Import Excel (Form PR & Form Quotation) + Template
*(≈ MISSION-05, dikerjakan setelah FR1 & FR3 karena template menyertakan kolom baru dari situ)*

**Belum ada fitur import sama sekali** di codebase — verifikasi dengan `grep -rn "ToModel\|WithValidation\|Excel::import" app/`. `maatwebsite/excel` sudah dependency, langsung pakai `ToCollection`+`WithHeadingRow` untuk parsing (lebih toleran urutan kolom karena baca berdasarkan nama header, bukan posisi).

**Prinsip alur wajib** — import **tidak boleh** langsung insert ke database:
```
Download Template → Isi Excel → Upload → Server-side Parse & Validate
→ Preview / Populate Form (JS mengisi tabel form existing) → User Review
→ Save Draft / Submit lewat aksi form yang SUDAH ADA
```
Pendekatan: **backend-parse** (submit file ke endpoint, backend return JSON hasil parse, JS isi ke tabel form) — bukan parsing client-side (jangan tambah SheetJS/library baru), supaya konsisten dengan stack Laravel yang sudah ada.

## 5.1 Import — Form Tambah PR (Purchasing)

**Template kolom** (pakai `WithHeadingRow`, urutan tidak harus persis karena baca by name):

| Kolom Header | Wajib | Keterangan |
|---|---|---|
| `material_name` | ✅ | |
| `hs_code` | ✅ | |
| `shape` | ❌ | `Flat`/`Round`/`Hollow`, sesuai `PrItem::SHAPES` |
| `quantity` | ✅ | integer |
| `thickness` | ❌ | relevan shape `Flat` |
| `d_inner` | ❌ | relevan shape `Hollow` |
| `d_outer` | ❌ | relevan shape `Round`/`Hollow` |
| `width` | ❌ | relevan shape `Flat` |
| `length` | ❌ | relevan shape `Flat`/`Round`/`Hollow` |
| `weight_needed` | ✅ | berat per unit (Kg) |
| `remark` | ❌ | dari FR1 |

Period, supplier invitation, action draft/submitted, dan header notes **tetap dipilih di form**, bukan dari Excel.

**Template download** — file kecil `app/Exports/PrImportTemplateExport.php` (`FromArray`+`WithHeadings`), atau file statis `public/templates/template_import_pr.xlsx`, berisi header + 1 baris contoh.

**Import class** `app/Imports/PrItemsImport.php`:
```php
<?php
namespace App\Imports;

use App\Models\PrItem;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PrItemsImport implements ToCollection, WithHeadingRow
{
    public array $rows = [];
    public array $errors = [];

    public function collection(\Illuminate\Support\Collection $collection)
    {
        foreach ($collection as $i => $row) {
            $rowNum = $i + 2; // +2: baris 1 = header

            $materialName = trim((string) ($row['material_name'] ?? ''));
            $quantity = $row['quantity'] ?? null;
            $weightNeeded = $row['weight_needed'] ?? null;

            if ($materialName === '' || !$quantity || !$weightNeeded) {
                $this->errors[] = "Baris {$rowNum}: material_name, quantity, dan weight_needed wajib diisi.";
                continue;
            }

            $shape = trim((string) ($row['shape'] ?? ''));
            if ($shape !== '' && !in_array($shape, PrItem::SHAPES, true)) {
                $this->errors[] = "Baris {$rowNum}: shape '{$shape}' tidak valid (harus Flat/Round/Hollow).";
                continue;
            }

            $this->rows[] = PrItem::sanitizeMaterialData([
                'material_name' => $materialName, 'hs_code' => $row['hs_code'] ?? null,
                'shape' => $shape ?: null, 'quantity' => $quantity,
                'thickness' => $row['thickness'] ?? null, 'd_inner' => $row['d_inner'] ?? null,
                'd_outer' => $row['d_outer'] ?? null, 'width' => $row['width'] ?? null,
                'length' => $row['length'] ?? null, 'weight_needed' => $weightNeeded,
                'remark' => $row['remark'] ?? null,
            ]);
        }
    }
}
```
Reuse `PrItem::sanitizeMaterialData()` yang sudah ada — jangan duplikasi logic null-kan dimensi tidak relevan.

**Controller** — method baru di `PurchaseRequisitionController.php`:
```php
public function importPreview(Request $request)
{
    $request->validate(['import_file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);

    $import = new \App\Imports\PrItemsImport();
    \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('import_file'));

    return response()->json([
        'success' => empty($import->errors),
        'rows' => $import->rows,
        'warnings' => [],
        'summary' => [
            'total' => count($import->rows) + count($import->errors),
            'valid' => count($import->rows),
            'invalid' => count($import->errors),
        ],
        'errors' => $import->errors,
    ]);
}
```
> Response JSON terstruktur (`success`/`rows`/`warnings`/`summary`/`errors`) — jangan kirim HTML table mentah dari controller.

**Route** (grup `purchasing.`):
```php
Route::post('/requisitions/import-preview', [...PurchaseRequisitionController::class, 'importPreview'])->name('requisitions.import-preview');
Route::get('/requisitions/import-template', ...)->name('requisitions.import-template');
```
Route statis (`/import-preview`, `/import-template`) harus ditempatkan **sebelum** route wildcard `/requisitions/{id}` kalau ada potensi bentrok.

**Import Mode:** pilihan `Replace Current Rows` (default) / `Append to Current Rows`. Import **tidak boleh** diam-diam menghapus input yang sudah ada — tampilkan konfirmasi kalau form sudah punya data material saat mode Replace dipilih.

**JS** — modal Bootstrap: upload → POST ke `import-preview` → kalau `success`, loop `data.rows`, append/replace ke `#itemsBody` pakai template row existing, jalankan `applyMaterialShapeRules()` per baris baru. Kalau ada `errors`, tampilkan semua (nomor baris + pesan), **jangan** ubah form existing, biarkan user perbaiki file dan upload ulang (no partial silent import).

## 5.2 Import — Form Quotation (Supplier)

Pola sama seperti 5.1, dengan penyesuaian penting: baris `quotation_item` **selalu 1:1 dengan `pr_item_id`**, tidak bisa "nambah baris baru" — jadi import di sini **mengisi baris existing**, bukan append baris.

**Template khusus per PR** — generate dinamis, **pre-filled** dengan `pr_item_id` (read-only secara konvensi) supaya pencocokan baris aman (jangan andalkan `material_name` sebagai key, karena tidak unik):

```php
<?php
namespace App\Exports;

use App\Models\PurchaseRequisition;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class QuotationImportTemplateExport implements FromCollection, WithHeadings
{
    protected $prId;
    public function __construct($prId) { $this->prId = $prId; }

    public function collection()
    {
        $pr = PurchaseRequisition::with('items')->findOrFail($this->prId);
        return $pr->items->map(fn($item) => [
            $item->id, $item->material_name, $item->dimension_label,
            '', '', '', '', '', '', '', // price_per_kg, available_qty, available_thickness, available_d_inner, available_d_outer, available_width, available_length, notes
        ]);
    }

    public function headings(): array
    {
        return ['pr_item_id', 'material_name', 'requested_dimension', 'price_per_kg',
            'available_qty', 'available_thickness', 'available_d_inner',
            'available_d_outer', 'available_width', 'available_length', 'notes'];
    }
}
```

**Import class** `app/Imports/QuotationItemsImport.php` — validasi `pr_item_id` harus benar-benar milik PR yang sedang dibuka (cegah upload template PR lain):
```php
<?php
namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class QuotationItemsImport implements ToCollection, WithHeadingRow
{
    public array $rows = [];
    public array $errors = [];
    protected array $validPrItemIds;

    public function __construct(array $validPrItemIds) { $this->validPrItemIds = $validPrItemIds; }

    public function collection(\Illuminate\Support\Collection $collection)
    {
        foreach ($collection as $i => $row) {
            $rowNum = $i + 2;
            $prItemId = (int) ($row['pr_item_id'] ?? 0);

            if (! in_array($prItemId, $this->validPrItemIds, true)) {
                $this->errors[] = "Baris {$rowNum}: pr_item_id tidak valid untuk PR ini (template mungkin sudah tidak sesuai).";
                continue;
            }
            if (empty($row['price_per_kg'])) {
                $this->errors[] = "Baris {$rowNum}: price_per_kg wajib diisi.";
                continue;
            }

            $this->rows[] = [
                'pr_item_id' => $prItemId, 'price_per_kg' => $row['price_per_kg'],
                'available_qty' => $row['available_qty'] ?? null,
                'available_thickness' => $row['available_thickness'] ?? null,
                'available_d_inner' => $row['available_d_inner'] ?? null,
                'available_d_outer' => $row['available_d_outer'] ?? null,
                'available_width' => $row['available_width'] ?? null,
                'available_length' => $row['available_length'] ?? null,
                'notes' => $row['notes'] ?? null,
            ];
        }
    }
}
```
Cegah `pr_item_id` duplikat dalam satu file. Jangan percaya nilai deskriptif (`material_name`, dimensi) dari file — ambil data requested dari database via `pr_item_id`.

**Controller & Route** — method `importPreview($pr_id)` di `app/Http/Controllers/Supplier/QuotationController.php` (construct `QuotationItemsImport` dengan `$pr->items->pluck('id')->all()`):
```php
Route::post('/quotations/{pr_id}/import-preview', [...QuotationController::class, 'importPreview'])->name('quotations.import-preview');
Route::get('/quotations/{pr_id}/import-template', [...QuotationController::class, 'importTemplate'])->name('quotations.import-template');
```
Jangan taruh endpoint import supplier di middleware purchasing. Import hanya boleh dilakukan supplier yang login, aktif, diundang/berhak lihat PR tersebut, dan hanya mengubah quotation miliknya sendiri.

**Import Mode:** `Replace Imported Fields` / `Fill Empty Fields Only` (bukan Replace/Append seperti PR — lihat tabel keputusan di atas kenapa). `Fill Empty Fields Only` **tidak boleh** menimpa nilai yang sudah diketik user secara manual.

**JS** — isi field berdasarkan pencarian `input[name="items[{index}][pr_item_id]"]` yang value-nya cocok dengan `pr_item_id` hasil import, **bukan** append baris baru (baris sudah fix sejumlah item PR). Header quotation (currency, estimated delivery, payment terms, validity period, general notes) **tetap** dikelola lewat form existing, tidak boleh ikut kosong akibat import item.

**Import tidak boleh langsung:** membuat quotation, mengubah status, submit quotation, menghapus/mengganti attachment/MTC, mengirim notifikasi. Final write tetap lewat Save Draft/Submit existing.

## Security (berlaku untuk kedua import)
- Tipe file: `xlsx`, `xls`, `csv`
- Ukuran maksimal: 5MB (PR) — sesuaikan kalau konvensi upload lain di `AGENTS.md` beda; MISSION-05 sebelumnya sempat sebut 10MB, **cross-check ke `AGENTS.md` bagian upload sebagai sumber kebenaran**
- Batas maksimal baris (mis. 1.000) — reject kalau lebih
- Jangan eksekusi formula Excel dari file yang diupload
- Jangan percaya nama file
- Jangan simpan file permanen kecuali diperlukan — hapus temporary file setelah parsing, **jangan** simpan di folder `public`
- Endpoint wajib middleware role yang sesuai (`purchasing`/`supplier`)
- Validasi MIME **dan** ukuran, bukan cuma ekstensi nama file

## Acceptance Criteria — FR5
- [ ] Template Excel PR & Quotation bisa didownload sesuai kolom yang ditentukan
- [ ] Template Quotation spesifik per PR, berisi `pr_item_id` yang benar
- [ ] Upload file PR valid → baris material otomatis terisi, mode Replace/Append berfungsi
- [ ] Upload file Quotation valid → harga & ketersediaan terisi ke baris `pr_item_id` yang sesuai, bukan baris baru
- [ ] Upload file invalid → error per baris (nomor row + kolom + pesan jelas), baris valid tetap diproses terpisah dari yang gagal, form existing tidak berubah sebelum user review
- [ ] `pr_item_id` dari PR lain ditolak dengan pesan jelas, tidak ada data masuk
- [ ] Tidak ada record database yang dibuat saat tahap preview — user tetap harus tekan Save Draft/Submit
- [ ] Import tidak menghapus attachment/MTC quotation
- [ ] File tervalidasi tipe & ukuran, baris dibatasi, temp file terhapus setelah parsing

---

# DATABASE MIGRATION REQUIREMENTS

Ringkasan seluruh migration baru di dokumen ini:

| Migration | Tabel | Kolom Baru |
|---|---|---|
| `2026_08_03_000001_add_remark_to_pr_items_table` | `pr_items` | `remark` text nullable |
| `2026_08_03_000002_add_available_dimension_to_quotation_items_table` | `quotation_items` | `available_qty`, `available_thickness`, `available_d_inner`, `available_d_outer`, `available_width`, `available_length` |

Wajib untuk semua migration: pakai `Schema::table` + guard `Schema::hasColumn()`, semua field baru nullable, method `down()` lengkap, tidak menghapus data existing, precision sesuai konvensi tabel existing, index hanya kalau memang dipakai untuk query/filter, berhasil migrate **dan** rollback di testing database.

**Jangan** buat kolom Reference PR baru di `purchase_orders` — dihitung dari relasi (lihat FR1B).

---

# ARCHITECTURE REQUIREMENTS

Struktur yang disarankan (nama/jumlah class boleh disesuaikan kondisi repo):

```text
app/
├── Exports/
│   ├── RequisitionsExport.php              (update: + kolom Remark)
│   ├── PurchaseRequisitionDetailExport.php (baru)
│   ├── PurchaseOrdersExport.php            (update: + kolom Remark)
│   ├── QuotationsExport.php                (baru)
│   ├── PrImportTemplateExport.php          (baru)
│   └── QuotationImportTemplateExport.php   (baru)
├── Imports/
│   ├── PrItemsImport.php                   (baru)
│   └── QuotationItemsImport.php            (baru)
├── Http/
│   ├── Controllers/
│   │   ├── Purchasing/
│   │   │   ├── PurchaseRequisitionController.php (+ importPreview)
│   │   │   ├── PurchaseOrderController.php       (+ addColumn pr_reference/remark_display)
│   │   │   └── ExportController.php              (+ requisitionDetail, quotations)
│   │   ├── Supplier/
│   │   │   └── QuotationController.php           (+ importPreview, + store validasi baru)
│   │   └── NotificationController.php            (bug fixes per FR2)
│   └── Requests/ (opsional, kalau validasi import makin kompleks pisah ke Form Request)
└── Models/
    ├── PrItem.php         (+ remark)
    ├── PurchaseOrder.php  (+ accessor pr_reference)
    └── QuotationItem.php  (+ available_* fields)
```

Aturan: controller **tidak boleh** jadi tempat seluruh parsing Excel — pisah ke Import/Export class. Blade **tidak boleh** mengandung business logic. Import mapping dan requested/offered comparison **tidak boleh** diduplikasi di banyak tempat.

---

# ROUTE REQUIREMENTS

Semua route wajib middleware sesuai role. Route baru di dokumen ini:

```text
Purchasing:
GET  /purchasing/requisitions/import-template
POST /purchasing/requisitions/import-preview
GET  /purchasing/export/requisitions/{id}        (export detail PR)
GET  /purchasing/export/quotations

Supplier:
GET  /supplier/quotations/{pr_id}/import-template
POST /supplier/quotations/{pr_id}/import-preview
GET  /supplier/export/quotations                  (scope auth()->id())
```

Route statis (`import-preview`, `import-template`, `export/...`) harus ditempatkan **sebelum** route wildcard (`/requisitions/{id}`, `/quotations/{id}`) kalau berpotensi bentrok pattern. Jangan taruh endpoint import supplier di middleware `purchasing`.

---

# UI/UX REQUIREMENTS

## General
- Pertahankan visual identity Portal Supplier, Bootstrap 5 + Bootstrap Icons
- Modal import/export konsisten satu pola di semua tempat
- Loading spinner saat upload/parse/import/export berjalan
- Disable tombol untuk cegah double submission
- Error AJAX ditampilkan jelas, bukan silent fail
- Responsif desktop & tablet, hindari horizontal overflow

## Import Modal
File drop/input, nama file terpilih, link Download Template, pilihan import mode, summary total baris (valid/invalid), area error, tombol Cancel + Preview/Import, loading state.

## Export
Tombol existing (`<a href="...">Export Excel</a>`) sudah cukup untuk kebutuhan saat ini — **jangan** bikin modal kompleks tanpa permintaan eksplisit.

## Requested vs Offered
Layout `Requested by Purchasing | Offered by Supplier`, stacked di layar kecil, bedakan dengan label+icon bukan cuma warna.

---

# AUTHORIZATION AND SECURITY REQUIREMENTS

1. Semua endpoint perlu `auth`.
2. Endpoint purchasing perlu `role:purchasing`; endpoint supplier perlu `role:supplier`.
3. Supplier query wajib di-scope pakai `auth()->id()` — **jangan** percaya `supplier_id` dari query string/request body sebagai sumber otorisasi.
4. Validasi setiap PR/quotation terhadap supplier yang login sebelum proses apapun (termasuk import/export).
5. Escaping di Blade — **jangan** pakai `{!! !!}` untuk remark/notes dari input user.
6. Lindungi dari mass assignment — cek `$fillable` tiap model yang diubah.
7. Validasi MIME dan ukuran file untuk import.
8. Batasi jumlah baris import.
9. Lindungi export dari spreadsheet formula injection.
10. Jangan simpan temporary import file di folder `public`.
11. Pastikan user tidak bisa menebak ID lalu akses template/data supplier lain (mis. ganti `{pr_id}` di URL template quotation).
12. Gunakan policy atau explicit authorization check yang konsisten di semua endpoint baru.

---

# PERFORMANCE REQUIREMENTS

- Hindari N+1 pada PR, quotation, PO, supplier, item — gunakan eager loading (`with([...])`, sudah dicontohkan di tiap Export class di atas).
- Export dataset besar sebaiknya pakai query/chunking.
- Jangan muat attachment binary saat export.
- Jangan query per baris Excel — ambil seluruh `pr_item_id` yang diperlukan dalam satu query, pakai collection indexing/`keyBy`.
- Pertahankan performa server-side DataTables (jangan filtering seluruh dataset di browser).
- Tambah index hanya kalau didukung pola query nyata.

---

# TESTING REQUIREMENTS

Gunakan PHPUnit / test stack existing. Kalau `tests/Feature/SupplierDataIsolationTest.php` memang ada (perlu diverifikasi di Phase 1), ikuti pola test tersebut untuk test isolasi data baru.

## Migration Tests
Migrate berhasil, rollback berhasil, field existing tidak hilang, data lama tetap terbaca.

## PR Tests
Create PR dengan remark per material · update draft PR dengan remark · detail PR menampilkan remark · valid import preview · invalid shape/quantity/dimension import · mode replace · mode append · export detail PR · export PR terfilter.

## PO Tests
PO dari 1 PR → 1 reference · consolidated PO → beberapa reference unik, tidak duplikat · remark PO tampil · export PO berisi reference & remark · search berdasarkan PR number.

## Quotation Tests
Supplier simpan available quantity/dimensions · dimensi irrelevant tersanitasi (null) · supplier tidak bisa kirim item PR lain · supplier tidak bisa ubah quotation supplier lain · quotation lama tanpa field baru tetap valid · import valid · import dengan `pr_item_id` duplikat ditolak · import dari PR lain ditolak · purchasing bisa lihat requested vs offered · export supplier hanya berisi data sendiri.

## Notification Tests
(Lihat detail lengkap di FR2 — "Feature Test yang Wajib Ditambahkan")

## Frontend/Build Validation
Jalankan minimal (sesuaikan command dengan environment repo aktual):
```bash
composer install
php artisan optimize:clear
php artisan route:list
php artisan test
npm install
npm run build
```
Kalau database test tidak tersedia, laporkan test mana yang tidak bisa dijalankan dan kenapa — **jangan klaim sukses tanpa run**.

---

# ACCEPTANCE TEST SCENARIOS

## Scenario A — PR Manual
Login Purchasing → Create PR → tambah 2 material dengan remark berbeda → Save Draft → buka kembali → verifikasi remark tidak tertukar → Submit. **Expected:** remark per material sesuai urutan, header notes tetap terpisah, notifikasi berjalan.

## Scenario B — PR Import
Download template → isi 3 material → upload mode Replace → verifikasi preview/form → tambah 1 row manual → Save Draft. **Expected:** 3 row import + 1 row manual tersimpan, tidak ada insert saat preview, validation error menunjukkan nomor row.

## Scenario C — Supplier Copy Requested
Login supplier diundang → buka quotation → Copy Requested 1 material, Copy All material lain → ubah manual salah satu qty tersedia → Save Draft → buka kembali. **Expected:** offered values tersimpan, requested values tidak berubah, price & attachment tidak terhapus.

## Scenario D — Quotation Import
Download template khusus PR → isi price + offered quantity + dimensions → import → review → submit. **Expected:** item dipetakan by `pr_item_id`, item PR lain ditolak, notifikasi purchasing terkirim, `amount` tetap pakai requested total weight.

## Scenario E — Consolidated PO
Buat PO dari beberapa quotation lintas PR → buka daftar PO → cek Reference/No. PR → export PO. **Expected:** semua nomor PR tampil sekali (tidak duplikat), export punya reference & remark, tidak ada N+1 berlebihan.

## Scenario F — Notification Role
Trigger notifikasi untuk Admin, Purchasing, Supplier, QC → login masing-masing role → klik notifikasi → mark as read. **Expected:** tidak ada redirect ke route role lain, tidak ada 403 di alur normal, unread count berkurang, supplier tidak lihat data supplier lain.

## Scenario G — Broadcast Down *(tambahan)*
Matikan queue worker/Reverb (simulasi) → submit PR/quotation/PO. **Expected:** transaksi utama tetap sukses, database notification tetap tersimpan, tidak ada 500 ke user.

---

# REQUIRED DELIVERABLES

Setelah implementasi, hasilkan:

1. **Analysis Summary** — kondisi existing, masalah ditemukan, keputusan teknis, risiko, file terdampak (termasuk daftar klaim di dokumen ini yang ternyata perlu disesuaikan)
2. **Implementation Summary** — migration baru, model diubah, route baru, controller/service/import/export baru, view & JS diubah, notification fix
3. **Database Impact** — tabel, field baru, tipe data, nullable, default, index, rollback behavior
4. **Route Map** — route baru + middleware-nya
5. **Import/Export Specification** — nama template, column mapping, validation, import mode, error behavior, authorization behavior
6. **Test Report** — command dijalankan, jumlah pass/fail, error existing, test yang belum bisa dijalankan (+alasan), hasil `npm run build`
7. **Changed Files** — seluruh file dibuat/diubah/dihapus + tujuannya
8. **Remaining Risks** — jangan disembunyikan, termasuk asumsi yang belum terverifikasi
9. **`../audits/NOTIFICATION-AUDIT-REPORT.md`** — khusus dari FR2 (lihat format di sana)

---

# FINAL QUALITY CHECKLIST

- [ ] Remark per material tersimpan dan tampil
- [ ] Header notes PR tidak tertimpa
- [ ] PO menampilkan seluruh Reference PR, tidak duplikat
- [ ] PO menampilkan remark
- [ ] Supplier bisa input offered qty & dimensions
- [ ] Copy Requested (per baris) & Copy All bekerja
- [ ] Requested data (PR) tidak berubah akibat fitur copy
- [ ] Rumus `amount` tidak berubah secara tidak sengaja
- [ ] Export PR list, PR detail, PO, quotation purchasing bekerja
- [ ] Export quotation supplier aman (scope `auth()->id()`)
- [ ] Template import PR & quotation tersedia dan sesuai kolom
- [ ] Preview import PR & quotation bekerja, tidak langsung tulis DB
- [ ] Import error menunjukkan row + column + pesan jelas
- [ ] Supplier isolation terjaga di semua fitur baru (import/export/quotation)
- [ ] Notification URL sesuai role, tidak ada redirect ke route terlarang
- [ ] Notification tetap bekerja tanpa Reverb/broadcast
- [ ] Tidak ada JavaScript console error
- [ ] Tidak ada route collision (statis vs wildcard)
- [ ] Migration rollback berhasil
- [ ] Test backend dijalankan (bukan diklaim tanpa run)
- [ ] Frontend build (`npm run build`) berhasil
- [ ] Tidak ada perubahan di luar scope tanpa penjelasan
- [ ] Semua klaim teknis di dokumen ini yang ternyata salah sudah dicatat di Analysis Summary
