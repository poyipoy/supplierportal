# 🔍 Laporan Analisis Kode — ADASI Portal Supplier

**Tanggal:** 3 Juni 2026  
**Cakupan:** 10 file yang dianalisis secara mendalam  
**Total Temuan:** 21 item (3 Kritis, 4 Tinggi, 9 Sedang, 5 Rendah)

---

## File yang Dianalisis

| # | File | Tipe |
|---|------|------|
| 1 | [QcInspectionController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php) | Controller |
| 2 | [MaterialClaimController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php) | Controller |
| 3 | [AttachmentController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/AttachmentController.php) | Controller |
| 4 | [index.blade.php (QC)](file:///c:/laragon/www/adasi_portal_supplier/resources/views/qc/inspections/index.blade.php) | View |
| 5 | [edit.blade.php (PR)](file:///c:/laragon/www/adasi_portal_supplier/resources/views/purchasing/pr/edit.blade.php) | View |
| 6 | [breadcrumb.blade.php](file:///c:/laragon/www/adasi_portal_supplier/resources/views/components/breadcrumb.blade.php) | Component |
| 7 | [empty-state.blade.php](file:///c:/laragon/www/adasi_portal_supplier/resources/views/components/empty-state.blade.php) | Component |
| 8 | [PurchasingNavigation.php](file:///c:/laragon/www/adasi_portal_supplier/app/Support/PurchasingNavigation.php) | Support |
| 9 | [StatusHelper.php](file:///c:/laragon/www/adasi_portal_supplier/app/Support/StatusHelper.php) | Support |
| 10 | [web.php](file:///c:/laragon/www/adasi_portal_supplier/routes/web.php) | Routes |

---

## 🔴 KRITIS (3 Temuan)

---

### K1. XSS via `rawColumns` — HTML Injection pada DataTables

| Atribut | Detail |
|---------|--------|
| **Kategori** | Keamanan |
| **Keparahan** | 🔴 Kritis |
| **Lokasi** | [QcInspectionController.php:47-51](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L47-L51), [L66-73](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L66-L73), [MaterialClaimController.php:40-52](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php#L40-L52), [L60-82](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php#L60-L82) |

**Penjelasan:**  
Kolom-kolom DataTables seperti `supplier_name`, `po_number`, dan `inspector_name` digenerate langsung dari data database tanpa escaping HTML. Meskipun kolom ini **tidak** berada di `rawColumns`, DataTables Yajra secara default melakukan escaping pada kolom yang **bukan** `rawColumns`. Namun, masalah muncul pada kolom yang **ada** di `rawColumns`:

- `status_badge` membangun HTML yang menyertakan `$po->status` via `str_replace('_', ' ', $po->status)` tanpa `e()`.
- `action` kolom membangun HTML secara langsung dengan `route()` — ini aman karena `route()` menghasilkan URL yang valid.

Namun, perhatikan baris ini di `MaterialClaimController`:

```php
// L43 - status tidak di-escape
->addColumn('status_badge', fn($po) => '<span class="badge bg-danger text-uppercase">' 
    . str_replace('_', ' ', $po->status) . '</span>')
```

Jika nilai `status` di database dimanipulasi (misalnya via SQL injection pada modul lain atau admin nakal), maka tag HTML berbahaya bisa ter-inject di halaman. Meskipun risikonya membutuhkan **database compromise terlebih dahulu**, defense-in-depth mengharuskan escaping tetap dilakukan.

**Saran Perbaikan:**  
Selalu gunakan `e()` untuk semua data dari database di dalam kolom `rawColumns`:

```php
->addColumn('status_badge', fn($po) => '<span class="badge bg-danger text-uppercase">' 
    . e(str_replace('_', ' ', $po->status)) . '</span>')
```

Atau lebih baik, gunakan `StatusHelper` yang sudah ada:

```php
->addColumn('status_badge', fn($po) => StatusHelper::badge(
    StatusHelper::poBadge($po->status),
    StatusHelper::poLabel($po->status)
))
```

---

### K2. Race Condition pada Pembuatan Inspeksi QC (Double Submit)

| Atribut | Detail |
|---------|--------|
| **Kategori** | Bug |
| **Keparahan** | 🔴 Kritis |
| **Lokasi** | [QcInspectionController.php:100-246](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L100-L246) |

**Penjelasan:**  
Method `store()` melakukan pengecekan `$po->status !== 'waiting_qc'` di baris 108, lalu membuat inspeksi di baris 159. Namun antara kedua operasi tersebut, **tidak ada database lock** pada row PO. Pada skenario concurrent request:

1. **User A** membuka form inspeksi PO #1 → status `waiting_qc` ✓
2. **User B** membuka form inspeksi PO #1 → status `waiting_qc` ✓
3. **User A** submit → cek status ✓ → buat inspeksi → update PO ke `completed`
4. **User B** submit → cek status ✓ (tergantung timing, bisa masih `waiting_qc` jika belum commit) → buat inspeksi **duplikat**

Pada method `create()` ada pengecekan `QcInspection::where('po_id', $po->id)->exists()` (baris 90), tetapi pengecekan ini **tidak ada** di method `store()`.

**Saran Perbaikan:**  

```php
public function store(Request $request, $po_id)
{
    // Gunakan pessimistic lock
    $po = PurchaseOrder::where('id', $po_id)
        ->lockForUpdate()
        ->firstOrFail();

    // Double-check: pastikan belum pernah diinspeksi
    if (QcInspection::where('po_id', $po->id)->exists()) {
        return redirect()->route('qc.inspections.index')
            ->with('error', 'PO ini sudah pernah diinspeksi.');
    }

    if ($po->status !== 'waiting_qc') {
        return redirect()->route('qc.inspections.index')
            ->with('error', 'PO ini tidak valid untuk diinspeksi.');
    }
    // ... lanjutkan proses
}
```

---

### K3. Header Injection via `Content-Disposition` pada Attachment Download

| Atribut | Detail |
|---------|--------|
| **Kategori** | Keamanan |
| **Keparahan** | 🔴 Kritis |
| **Lokasi** | [AttachmentController.php:25](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/AttachmentController.php#L25) |

**Penjelasan:**  
Baris berikut membangun header `Content-Disposition` menggunakan `addslashes()`:

```php
'Content-Disposition' => 'inline; filename="' . addslashes($attachment->file_name) . '"',
```

`addslashes()` **bukan** fungsi sanitasi yang benar untuk HTTP header. Nama file yang mengandung karakter seperti `\r\n` (CRLF) dapat menyebabkan **HTTP Response Header Injection**. Attacker bisa menyisipkan header tambahan atau bahkan mengontrol response body.

**Saran Perbaikan:**  
Gunakan helper bawaan Symfony/Laravel:

```php
use Symfony\Component\HttpFoundation\HeaderUtils;

return response()->file($disk->path($attachment->file_path), [
    'Content-Type' => $attachment->file_type ?: $disk->mimeType($attachment->file_path),
    'Content-Disposition' => HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_INLINE,
        $attachment->file_name,
        // Fallback ASCII-safe name
        preg_replace('/[^\x20-\x7E]/', '_', $attachment->file_name)
    ),
]);
```

---

## 🟠 TINGGI (4 Temuan)

---

### T1. Tidak Ada Validasi Otorisasi Ganda pada `MaterialClaimController::store()`

| Atribut | Detail |
|---------|--------|
| **Kategori** | Keamanan |
| **Keparahan** | 🟠 Tinggi |
| **Lokasi** | [MaterialClaimController.php:102-141](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php#L102-L141) |

**Penjelasan:**  
Method `store()` menerima `inspection_id` dari request body (baris 105) dan hanya memvalidasi bahwa ID tersebut ada di tabel `qc_inspections`. Tidak ada pengecekan apakah inspeksi tersebut benar berstatus `ng` di dalam `store()`, meskipun pengecekan ini ada di `create()`. Seorang user purchasing bisa mem-bypass form `create()` dan mengirim POST request langsung dengan `inspection_id` dari inspeksi berstatus `ok`.

Juga tidak ada pengecekan duplikasi klaim aktif di `store()`, padahal pengecekan ini ada di `create()` (baris 95).

**Saran Perbaikan:**  

```php
public function store(Request $request)
{
    $request->validate([
        'inspection_id' => 'required|exists:qc_inspections,id',
        'description'   => 'required|string',
        'resolution_expected' => 'required|string',
        'deadline'      => 'required|date|after:today',
    ]);

    $inspection = QcInspection::with('purchaseOrder.supplier')
        ->findOrFail($request->inspection_id);

    // ✅ Tambahkan validasi status
    if ($inspection->status !== 'ng') {
        return back()->with('error', 'Hanya inspeksi NG yang dapat diklaim.');
    }

    // ✅ Tambahkan validasi duplikasi
    if (MaterialClaim::where('inspection_id', $inspection->id)
        ->whereIn('status', ['pending', 'responded', 'escalated'])
        ->exists()) {
        return back()->with('error', 'Klaim aktif sudah ada untuk inspeksi ini.');
    }

    // ... lanjutkan proses
}
```

---

### T2. Pesan Error Meng-expose Detail Internal Exception

| Atribut | Detail |
|---------|--------|
| **Kategori** | Keamanan |
| **Keparahan** | 🟠 Tinggi |
| **Lokasi** | [QcInspectionController.php:245](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L245) |

**Penjelasan:**  
Baris ini meng-expose pesan exception mentah ke user:

```php
return back()->withInput()->with('error', 'Gagal menyimpan inspeksi: ' . $e->getMessage());
```

Pada environment production, `$e->getMessage()` bisa mengandung informasi sensitif seperti:
- Nama tabel dan kolom database
- Path filesystem server
- Stack trace internal

**Saran Perbaikan:**  

```php
} catch (\RuntimeException $e) {
    // RuntimeException = error bisnis yang kita lempar sendiri (pesan aman)
    DB::rollBack();
    return back()->withInput()->with('error', $e->getMessage());
} catch (\Exception $e) {
    DB::rollBack();
    // Log detail error untuk debugging internal
    \Log::error('QC Inspection store failed', [
        'po_id' => $po_id,
        'user_id' => auth()->id(),
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    // Pesan generik ke user
    return back()->withInput()->with('error', 'Terjadi kesalahan saat menyimpan inspeksi. Silakan coba lagi.');
}
```

---

### T3. `resolve()` Tidak Memvalidasi Kepemilikan Klaim

| Atribut | Detail |
|---------|--------|
| **Kategori** | Keamanan |
| **Keparahan** | 🟠 Tinggi |
| **Lokasi** | [MaterialClaimController.php:155-183](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php#L155-L183) |

**Penjelasan:**  
Method `resolve()` hanya memeriksa `$claim->status !== 'responded'`, tetapi **tidak memverifikasi** bahwa user yang melakukan resolve adalah orang yang berhak. Meskipun route dilindungi middleware `role:purchasing`, siapa pun dengan role purchasing bisa me-resolve klaim milik purchasing lain. Ini mungkin acceptable by design, tetapi sebaiknya didokumentasikan.

Yang lebih penting: method ini me-resolve klaim DAN langsung mengubah status PO ke `completed` (baris 167) **tanpa memeriksa** apakah ada klaim aktif lain yang belum resolved pada PO yang sama.

**Saran Perbaikan:**  

```php
public function resolve($id)
{
    $claim = MaterialClaim::with('purchaseOrder')->findOrFail($id);
    
    if ($claim->status !== 'responded') {
        return back()->with('error', 'Hanya klaim yang sudah direspons yang dapat diselesaikan.');
    }

    $claim->update(['status' => 'resolved']);

    // ✅ Hanya set PO completed jika TIDAK ada klaim aktif lain
    if ($claim->purchaseOrder) {
        $hasActiveClaims = MaterialClaim::where('po_id', $claim->po_id)
            ->where('id', '!=', $claim->id)
            ->whereIn('status', ['pending', 'responded', 'escalated'])
            ->exists();

        if (! $hasActiveClaims) {
            $claim->purchaseOrder->update(['status' => 'completed']);
        }
    }
    // ... notifikasi
}
```

---

### T4. Stream File Tidak Ditutup pada Failure Path

| Atribut | Detail |
|---------|--------|
| **Kategori** | Bug |
| **Keparahan** | 🟠 Tinggi |
| **Lokasi** | [QcInspectionController.php:192-203](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L192-L203), [L335-346](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L335-L346) |

**Penjelasan:**  
Kode membuka file stream dan menutupnya secara manual. Jika `Storage::disk('private')->put()` melempar exception, stream **tidak akan ditutup** karena `fclose()` di-skip, menyebabkan **resource leak**:

```php
$stream = fopen($file->getPathname(), 'r');
if ($stream) {
    Storage::disk('private')->put($path, $stream); // ← bisa throw exception
    fclose($stream); // ← tidak dieksekusi jika put() gagal
}
```

**Saran Perbaikan:**  
Gunakan `try/finally` atau lebih baik, gunakan `storeAs()` bawaan Laravel:

```php
// Opsi 1: try/finally
$stream = fopen($file->getPathname(), 'r');
if ($stream) {
    try {
        Storage::disk('private')->put($path, $stream);
    } finally {
        fclose($stream);
    }
    // ... create attachment record
}

// Opsi 2: Gunakan API Laravel langsung (lebih simpel, lebih aman)
$path = $file->storeAs(
    'attachments/' . now()->format('Y/m'),
    $file->hashName(),
    'private'
);
```

---

## 🟡 SEDANG (9 Temuan)

---

### S1. N+1 Query Problem pada `dataWaiting()`

| Atribut | Detail |
|---------|--------|
| **Kategori** | Improvement (Performa) |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [QcInspectionController.php:42-53](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L42-L53) |

**Penjelasan:**  
`item_count` dihitung dengan `$po->quotations->sum(fn($q) => $q->items->count())`. Meskipun `quotations.items` sudah di-eager-load, relasi `supplier` belum disertakan di eager loading, dan operasi ini mengakses collection di memory, bukan aggregate SQL.

Yang lebih penting: eager load `quotations.items` untuk sekadar menghitung jumlah item sangat boros — seluruh `quotation_items` record dimuat ke memory.

**Saran Perbaikan:**  

```php
$query = PurchaseOrder::with(['supplier'])
    ->withCount(['quotations as item_count' => function ($query) {
        // Jika ingin hitung total item via pivot table
    }])
    ->where('status', 'waiting_qc')
    ->orderBy('actual_arrival', 'asc');
```

Atau gunakan `loadCount()` / `withCount()` dengan subquery yang lebih efisien.

---

### S2. Duplikasi Logika Upload File (DRY Violation)

| Atribut | Detail |
|---------|--------|
| **Kategori** | Improvement (Refactoring) |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [QcInspectionController.php:188-203](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L188-L203) dan [L331-346](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L331-L346) |

**Penjelasan:**  
Logika upload file di method `store()` dan `storeAttachments()` **identik** — 15 baris kode yang sama persis. Ini melanggar prinsip DRY dan mempersulit maintenance.

**Saran Perbaikan:**  
Ekstrak ke private method atau trait:

```php
private function saveAttachment(
    \Illuminate\Http\UploadedFile $file,
    \Illuminate\Database\Eloquent\Model $attachable
): Attachment {
    $path = $file->storeAs(
        'attachments/' . now()->format('Y/m'),
        $file->hashName(),
        'private'
    );

    return $attachable->attachments()->create([
        'file_path'   => $path,
        'file_name'   => $file->getClientOriginalName(),
        'file_type'   => $file->getMimeType(),
        'uploaded_by' => auth()->id(),
    ]);
}
```

---

### S3. `$po->status` Tidak Menggunakan Enum / Konstanta

| Atribut | Detail |
|---------|--------|
| **Kategori** | Improvement (Best Practice) |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | Tersebar di seluruh controller dan model |

**Penjelasan:**  
Status PO menggunakan string literal yang tersebar: `'waiting_qc'`, `'completed'`, `'claim_needed'`, `'active'`, `'overdue'`, dll. Typo pada salah satu string tidak akan terdeteksi oleh IDE atau compiler.

**Saran Perbaikan:**  

```php
// app/Enums/PoStatus.php
enum PoStatus: string
{
    case Active = 'active';
    case WaitingQc = 'waiting_qc';
    case Completed = 'completed';
    case Overdue = 'overdue';
    case ClaimNeeded = 'claim_needed';
    case Cancelled = 'cancelled';
}

// Penggunaan:
if ($po->status !== PoStatus::WaitingQc->value) { ... }
```

---

### S4. `dataHistory()` — Null Pointer pada `inspected_at`

| Atribut | Detail |
|---------|--------|
| **Kategori** | Bug |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [QcInspectionController.php:68](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L68) |

**Penjelasan:**  

```php
->addColumn('inspected_date', fn($i) => $i->inspected_at->format('d M Y, H:i'))
```

Jika `inspected_at` bernilai `null` (misalnya data lama yang tidak terisi), maka kode ini akan melempar `Error: Call to a member function format() on null`. Kolom lain sudah menggunakan null-safe operator (`?->` dan `??`), tetapi kolom ini tidak.

**Saran Perbaikan:**  

```php
->addColumn('inspected_date', fn($i) => $i->inspected_at?->format('d M Y, H:i') ?? '-')
```

---

### S5. Hidden Input `return_url` Rentan Open Redirect

| Atribut | Detail |
|---------|--------|
| **Kategori** | Keamanan |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [edit.blade.php:20](file:///c:/laragon/www/adasi_portal_supplier/resources/views/purchasing/pr/edit.blade.php#L20) |

**Penjelasan:**  

```blade
<input type="hidden" name="return_url" value="{{ request('return_url') }}">
```

Nilai `return_url` dari query string disisipkan langsung ke form tanpa validasi. Meskipun `PurchasingNavigation::isSafeUrl()` memvalidasi URL sebelum redirect, HTML attribute value tetap di-output menggunakan `{{ }}` (yang di-escape oleh Blade), sehingga XSS pada value attribute sudah ter-mitigasi.

Namun, jika controller yang menerima form ini **tidak** menggunakan `isSafeUrl()` untuk memvalidasi sebelum redirect, maka ini menjadi **open redirect vulnerability**.

**Saran Perbaikan:**  
Pastikan setiap controller yang menerima `return_url` selalu memvalidasi via `isSafeUrl()`:

```php
// Di controller:
$returnUrl = $request->input('return_url');
if (PurchasingNavigation::isSafeUrl($returnUrl)) {
    return redirect($returnUrl)->with('success', '...');
}
return redirect()->route('purchasing.requirements.index')->with('success', '...');
```

---

### S6. `MaterialClaimController::dataActionNeeded()` — `qcInspections->last()` Unreliable

| Atribut | Detail |
|---------|--------|
| **Kategori** | Bug |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [MaterialClaimController.php:42-49](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php#L42-L49) |

**Penjelasan:**  
`$po->qcInspections->last()` mengambil elemen terakhir dari collection Eloquent, tetapi **tanpa `orderBy`** eksplisit. Urutan collection bergantung pada urutan record di database (biasanya ascending by `id`), yang mungkin tidak selalu yang terbaru berdasarkan `inspected_at`.

Juga, `->last()` pada empty collection mengembalikan `null`, dan meskipun kode mengecek `if ($lastInspection)`, format tanggal di baris 42 menggunakan `?->` yang aman.

**Saran Perbaikan:**  

```php
// Eager load dengan ordering eksplisit
$query = PurchaseOrder::with(['supplier', 'qcInspections' => function($q) {
    $q->orderBy('inspected_at', 'desc');
}])
```

---

### S7. JavaScript — `confirmButtonColor: 'var(--adasi-blue)'` Tidak Didukung SweetAlert2

| Atribut | Detail |
|---------|--------|
| **Kategori** | Bug |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [edit.blade.php:180](file:///c:/laragon/www/adasi_portal_supplier/resources/views/purchasing/pr/edit.blade.php#L180) |

**Penjelasan:**  
SweetAlert2 property `confirmButtonColor` **tidak mendukung** CSS custom properties (`var(--adasi-blue)`). Ini karena SweetAlert2 menerapkan warna secara inline style menggunakan JavaScript, bukan CSS parser. Hasilnya: tombol konfirmasi menggunakan **warna default** SweetAlert2 (hijau/biru), bukan warna ADASI.

**Saran Perbaikan:**  

```javascript
confirmButtonColor: '#1F5FA6', // Langsung gunakan hex ADASI blue
```

Atau, gunakan `customClass` dan atur warna via CSS:

```javascript
customClass: {
    confirmButton: 'btn btn-primary',
},
buttonsStyling: false,
```

---

### S8. `index.blade.php` — Export Link Tidak Update Saat Tab Waiting Aktif

| Atribut | Detail |
|---------|--------|
| **Kategori** | Bug |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [index.blade.php:21-23](file:///c:/laragon/www/adasi_portal_supplier/resources/views/qc/inspections/index.blade.php#L21-L23) |

**Penjelasan:**  
Tombol "Export Excel" di header card bersifat global untuk kedua tab, tetapi fungsi `updateInspectionFilterState()` hanya mengupdate URL export berdasarkan filter history (status). Saat tab "Menunggu Inspeksi" aktif, tombol export tetap mengarah ke riwayat inspeksi — yang mungkin bukan yang diharapkan user.

**Saran Perbaikan:**  
Tambahkan parameter `tab` ke URL export, atau sembunyikan/tampilkan tombol export sesuai tab aktif:

```javascript
$('button[data-bs-target="#history"]').on('shown.bs.tab', function() {
    $('#inspectionExportLink').show();
    updateInspectionFilterState();
});
$('button[data-bs-target="#waiting"]').on('shown.bs.tab', function() {
    $('#inspectionExportLink').hide(); // Atau update URL ke export waiting
});
```

---

### S9. `breadcrumb.blade.php` — Mengandalkan Urutan Array Asosiatif

| Atribut | Detail |
|---------|--------|
| **Kategori** | Bug (Edge Case) |
| **Keparahan** | 🟡 Sedang |
| **Lokasi** | [breadcrumb.blade.php:5-11](file:///c:/laragon/www/adasi_portal_supplier/resources/views/components/breadcrumb.blade.php#L5-L11) |

**Penjelasan:**  
Komponen menerima `$items` sebagai array asosiatif `['label' => 'url']`. Ini menyebabkan:

1. **Tidak bisa ada 2 breadcrumb item dengan label yang sama** (key collision pada array asosiatif).
2. **Item terakhir (`$loop->last`) menunjukkan halaman aktif** — ini bergantung pada PHP mempertahankan urutan insertion array asosiatif. PHP memang mempertahankannya, tapi ini bisa membingungkan developer lain.

**Saran Perbaikan:**  
Pertimbangkan format array of objects yang lebih eksplisit:

```php
// Pemanggilan:
<x-breadcrumb :items="[
    ['label' => 'Dashboard', 'url' => route('qc.dashboard')],
    ['label' => 'Inspeksi', 'url' => route('qc.inspections.index')],
    ['label' => 'Detail'],  // tanpa url = item aktif
]" />
```

---

## 🟢 RENDAH (5 Temuan)

---

### R1. `RoleMiddleware` — Tidak Cek `is_active`

| Atribut | Detail |
|---------|--------|
| **Kategori** | Keamanan |
| **Keparahan** | 🟢 Rendah |
| **Lokasi** | [RoleMiddleware.php:17-28](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Middleware/RoleMiddleware.php#L17-L28) |

**Penjelasan:**  
Middleware hanya memeriksa `role`, tetapi **tidak** memeriksa `is_active`. User yang di-nonaktifkan (misalnya supplier yang dipecat) masih bisa mengakses sistem selama session-nya masih aktif.

**Saran Perbaikan:**  

```php
public function handle(Request $request, Closure $next, string ...$roles): Response
{
    if (! $request->user()) {
        return redirect()->route('login');
    }

    if (! $request->user()->is_active) {
        auth()->logout();
        $request->session()->invalidate();
        return redirect()->route('login')
            ->with('error', 'Akun Anda telah dinonaktifkan.');
    }

    if (! in_array($request->user()->role, $roles)) {
        abort(403, 'Anda tidak memiliki akses ke halaman ini.');
    }

    return $next($request);
}
```

---

### R2. Unused Import `App\Models\Attachment` di QcInspectionController

| Atribut | Detail |
|---------|--------|
| **Kategori** | Improvement (Keterbacaan) |
| **Keparahan** | 🟢 Rendah |
| **Lokasi** | [QcInspectionController.php:6](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L6) |

**Penjelasan:**  
`use App\Models\Attachment;` di-import tetapi tidak pernah digunakan secara langsung. Attachment dibuat melalui relasi `$inspection->attachments()->create()`, yang tidak memerlukan import model secara eksplisit.

**Saran:** Hapus import yang tidak terpakai.

---

### R3. Unused Import `App\Models\User` di QcInspectionController

| Atribut | Detail |
|---------|--------|
| **Kategori** | Improvement (Keterbacaan) |
| **Keparahan** | 🟢 Rendah |
| **Lokasi** | [QcInspectionController.php:11](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L11) |

**Penjelasan:**  
Import `User` digunakan di baris 213 (`User::where('role', 'purchasing')->get()`), jadi ini sebenarnya **diperlukan**. Namun lebih baik menggunakan scope atau constant:

```php
// User model method
User::purchasing()->get(); // Lebih readable
```

---

### R4. `StatusHelper` — Static Array Bisa Jadi Enum

| Atribut | Detail |
|---------|--------|
| **Kategori** | Improvement (Design Pattern) |
| **Keparahan** | 🟢 Rendah |
| **Lokasi** | [StatusHelper.php](file:///c:/laragon/www/adasi_portal_supplier/app/Support/StatusHelper.php) — seluruh file |

**Penjelasan:**  
`StatusHelper` berisi banyak static array (`$prBadges`, `$prLabels`, `$poBadges`, dll) yang pada dasarnya adalah **enum mapping**. PHP 8.2 sudah mendukung backed enums yang lebih type-safe.

Meskipun refactoring ini tidak urgent, menggunakan enum memberikan keuntungan: IDE autocomplete, compile-time checking, dan kemampuan untuk di-cast di Eloquent model.

---

### R5. `empty-state.blade.php` — Tidak Ada ID Unik untuk Testing

| Atribut | Detail |
|---------|--------|
| **Kategori** | Improvement (Testability) |
| **Keparahan** | 🟢 Rendah |
| **Lokasi** | [empty-state.blade.php](file:///c:/laragon/www/adasi_portal_supplier/resources/views/components/empty-state.blade.php) |

**Penjelasan:**  
Komponen tidak memiliki `id` atau `data-testid` yang unik. Untuk browser testing (e.g., Laravel Dusk), selector CSS yang stabil sangat diperlukan.

**Saran Perbaikan:**  

```blade
@props([
    'id' => null,
    // ...existing props
])
<div {{ $attributes->merge(['class' => 'text-center py-5', 'id' => $id]) }}>
```

---

## 📋 Ringkasan Temuan

| Keparahan | Jumlah | Kategori |
|-----------|--------|----------|
| 🔴 Kritis | 3 | 2 Keamanan, 1 Bug |
| 🟠 Tinggi | 4 | 2 Keamanan, 2 Bug |
| 🟡 Sedang | 9 | 1 Keamanan, 4 Bug, 4 Improvement |
| 🟢 Rendah | 5 | 1 Keamanan, 4 Improvement |
| **Total** | **21** | |

---

## 🎯 Prioritas Perbaikan Utama

Berikut 5 perbaikan yang **harus segera dikerjakan**, diurutkan berdasarkan dampak dan urgensi:

| Prioritas | Temuan | Alasan |
|-----------|--------|--------|
| **1** | **K2** — Race condition inspeksi ganda | Data integrity — bisa menghasilkan inspeksi duplikat dan status PO yang salah. Perbaikan mudah: tambahkan `lockForUpdate()` + cek duplikat di `store()`. |
| **2** | **K3** — Header injection pada attachment | Celah keamanan langsung yang bisa dieksploitasi tanpa hak khusus. Perbaikan 1 baris: ganti `addslashes()` dengan `HeaderUtils::makeDisposition()`. |
| **3** | **T1** — Bypass validasi pada claim store | Memungkinkan pembuatan klaim untuk inspeksi OK. Perbaikan: copy validasi dari `create()` ke `store()`. |
| **4** | **T4** — Resource leak file stream | Bisa menyebabkan file handle habis di bawah beban tinggi. Perbaikan: gunakan `try/finally` atau `storeAs()`. |
| **5** | **T3** — Resolve klaim tanpa cek klaim lain | Bisa membuat PO `completed` padahal masih ada klaim aktif. Perbaikan: tambahkan cek klaim aktif sebelum update PO. |

> [!IMPORTANT]
> Perbaikan **K2**, **K3**, dan **T1** harus diprioritaskan karena berkaitan langsung dengan **integritas data** dan **keamanan**. Ketiganya bisa diperbaiki dalam satu sprint tanpa perubahan arsitektur besar.
