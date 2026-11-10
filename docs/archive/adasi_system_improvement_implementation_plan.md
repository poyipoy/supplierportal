# ADASI Portal Supplier — Master Implementation Plan (IMP-01 s.d. IMP-18)

**Status Dokumen:** Actionable Engineering Implementation Plan (Boost Protocol)  
**Target Sistem:** ADASI Portal Supplier (`poyipoy/supplierportal` — PHP `^8.2`, Laravel `^12.0`)  
**Cakupan:** Seluruh 18 Rekomendasi Improvement (`IMP-01` s.d. `IMP-18`) lintas 10 Aspek Evaluasi Sistem  
**Prinsip Utama:** *Evidence-First Investigation, Minimal Necessary Change, Contract Preservation, Risk-Proportional Verification*

## 1. Intent, Scope, dan Kontrak Penyelesaian (Completion Contract)

Rencana implementasi ini menerjemahkan seluruh temuan pada **System Analysis & Improvement Assessment** menjadi langkah teknis tingkat kode (*file-level & line-level execution plan*) yang siap dieksekusi secara bertahap tanpa merusak perilaku operasional yang sudah berjalan.

### 1.1 Invarian Sistem yang Wajib Dipertahankan (`AGENTS.md` & `DESIGN.md`)
Setiap perubahan kode di dalam rencana ini terikat pada kontrak berikut:
- **Kontrak Route & Parameter**: Nama route (`route(...)`), struktur URL, prefix `PortalPath::prefix()`, dan *allow-list* `DecodeHashids` tidak boleh berubah secara destruktif.
- **Isolasi Data Supplier**: Owner `supplier_id` pada dokumen operasional (`Quotation`, `PurchaseOrder`, `Shipment`, `MaterialClaim`, `LocalInvoice`, `LocalPurchaseOrder`, `SupplierScope`, `SupplierAudit`) menunjuk ke `users.id`. Query *supplier-facing* wajib mempertahankan filter `->where('supplier_id', auth()->id())` atau scope/policy terkait.
- **Mutasi Status Dokumen**: Seluruh perubahan status dokumen wajib melewati masing-masing *State Engine* (`PurchaseRequisitionStateEngine`, `QuotationStateEngine`, `LocalInvoiceStateEngine`, `PaymentBatchStateEngine`, `GaClaimStateEngine`, `SupplierAuditStateEngine`) agar *guard tests* di `tests/Unit/Architecture/*StatusMutationGuardTest.php` tetap hijau.
- **Disiplin Zona Waktu Bisnis**: Seluruh perhitungan tanggal kalender bisnis wajib melewati `App\Support\BusinessTime` (`Asia/Jakarta`) dan lulus `Tests\Unit\Architecture\BusinessTimeGuardTest`.
- **Kontrak UI & Selector DOM**: Selector atribut data (`data-*`), `x-data` Alpine.js, class `.ui-*`, dan prefix Tailwind v4 (`tw:`) wajib dipertahankan sesuai `DESIGN.md`.

### 1.2 Matriks Ringkasan Eksekusi (IMP-01 s.d. IMP-18)

| ID | Fase | Area Utama | Prioritas | Effort | Bukti Lokasi Kode Utama | Status Bukti |
|---|---|---|---|---|---|---|
| **IMP-01** | Fase 1 (Short-Term) | Functionality (Auth Redirect) | Critical | Small | `bootstrap/app.php:113-124`, `AuthenticatedSessionController.php:58-68` | `[Verified]` |
| **IMP-02** | Fase 1 (Short-Term) | Functionality (Supplier Quotation) | High | Small | `app/Http/Controllers/Supplier/QuotationController.php:63-72` | `[Verified]` |
| **IMP-03** | Fase 1 (Short-Term) | Functionality & Database (Timezone) | High | Small | `app/Services/LocalInvoice/InvoiceQuery.php:57-61` | `[Verified]` |
| **IMP-05** | Fase 1 (Short-Term) | Performance (QC N+1 Query) | High | Small | `app/Http/Controllers/Qc/QcInspectionController.php:50-87` | `[Verified]` |
| **IMP-06** | Fase 1 (Short-Term) | Performance & File Security | Medium | Small | `app/Services/FileSecurity/FileAccessGuard.php:12-66` | `[Verified]` |
| **IMP-07** | Fase 1 (Short-Term) | Performance & Functionality (Cache) | High | Small | `app/Http/Controllers/Purchasing/PurchasingController.php:23-85, 129-143` | `[Verified]` |
| **IMP-11** | Fase 1 (Short-Term) | Security (Mass Assignment & Password) | High | Small | 22 Model di `app/Models/*.php`, `SupplierRegistrationRequest.php:27-33` | `[Verified]` |
| **IMP-12** | Fase 1 (Short-Term) | Security & Reliability (Login Tarpit) | Medium | Small | `app/Http/Requests/Auth/LoginRequest.php:154-175`, `config/auth_security.php:34-39` | `[Verified]` |
| **IMP-16** | Fase 1 (Short-Term) | UI/UX, Localization & Accessibility | Medium | Small | 3 Controller Core 1 & 21 lokasi `tw:text-outline` di `resources/views/` | `[Verified]` |
| **IMP-15** | Fase 2 (Mid-Term) | Testing (JS Suite & Feature Flags) | High | Medium | `resources/js/unsaved-changes.js:274-278`, `tests/js/server-tabs.test.mjs`, `phpunit.xml` | `[Verified]` |
| **IMP-04** | Fase 2 (Mid-Term) | Performance & Database (Price History) | High | Medium | `app/Http/Controllers/Supplier/SupplierPriceHistoryController.php:214-246` | `[Verified]` |
| **IMP-08** | Fase 2 (Mid-Term) | Performance & Reliability (Notifications) | High | Medium | `app/Services/NotificationSummaryService.php:29-48`, `layouts/app.blade.php:438-519` | `[Verified]` |
| **IMP-09** | Fase 2 (Mid-Term) | Reliability & Scalability (Queue & Lock) | High | Medium | `composer.json:53`, `LocalProcurementImportService.php:285-330` | `[Verified]` |
| **IMP-14** | Fase 2 (Mid-Term) | Database & Schema Governance | Medium | Small | `database/migrations/`, `database/*.sql`, `tests/Unit/Architecture/` | `[Verified]` |
| **IMP-17** | Fase 2 (Mid-Term) | DevOps & Repository Hygiene | Medium | Small | `Dockerfile`, `api/index.php`, `scripts/check-tracked-artifacts.php` | `[Verified]` |
| **IMP-18** | Fase 2 (Mid-Term) | UI/UX & Responsiveness (Tables) | Low | Medium | `supplier/quotations/create.blade.php`, `purchasing/comparison/` | `[Verified]` |
| **IMP-10** | Fase 3 (Long-Term) | Security (Strict CSP & HSTS) | Medium | Medium | `app/Http/Middleware/AddSecurityHeaders.php:34-36, 66-72` | `[Verified]` |
| **IMP-13** | Fase 3 (Long-Term) | System Architecture (Core 1 Refactor) | Medium | Large | `QuotationController.php`, `PurchaseRequisitionController.php`, `QcInspectionController.php` | `[Verified]` |

## 2. Fase 1 (Short-Term): Perbaikan Bug Fungsional, Penguatan Keamanan Cepat, dan Optimasi Terlokalisasi

Fase ini berfokus pada perubahan berisiko rendah (*Small Effort*) dengan dampak langsung pada fungsionalitas kritis, akurasi zona waktu bisnis, pengurangan *N+1 query*, dan penutupan celah keamanan dasar.

### IMP-01 — Perbaikan Infinite Redirect Loop Role `ga` & Pembersihan Dead Code Redirect
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Pengguna ber-role `ga` yang sudah terautentikasi mengalami `ERR_TOO_MANY_REDIRECTS` saat membuka `/` atau `/login`.
  - **Root Cause**: Pada `bootstrap/app.php:113-124`, closure `$middleware->redirectUsersTo()` tidak memiliki arm `'ga'`, sehingga mengembalikan `'/'`. Sementara itu, `routes/web.php:263-265` mendefinisikan `Route::get('/', fn () => redirect()->route('login'))`, dan `/login` dilindungi middleware `guest` yang kembali memanggil `redirectUsersTo()`. Selain itu, `AuthenticatedSessionController::redirectByRole()` (`app/Http/Controllers/Auth/AuthenticatedSessionController.php:58-68`) tidak pernah dipanggil dan tidak mencakup role `finance`, `accounting`, maupun `ga`.
- **File yang Diubah**:
  1. `bootstrap/app.php` (baris 113–124)
  2. `app/Support/PortalContext.php`
  3. `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (baris 58–68)
- **Detail Perubahan Kode**:
  Pada `bootstrap/app.php`:
  ```php
  $middleware->redirectUsersTo(function () {
      if (auth()->check()) {
          return match (auth()->user()->role) {
              'admin' => route('admin.dashboard', absolute: false),
              'purchasing' => route('purchasing.dashboard', absolute: false),
              'supplier', 'accounting', 'finance' => PortalContext::dashboard(auth()->user()),
              'ga' => route('ga.dashboard', absolute: false),
              'qc' => route('qc.dashboard', absolute: false),
              default => route('login', absolute: false),
          };
      }

      return route('login', absolute: false);
  });
  ```
  Pada `app/Http/Controllers/Auth/AuthenticatedSessionController.php`:
  - Hapus method `protected function redirectByRole(string $role): string` (baris 58–68) karena alur `store()` sudah menggunakan `route('dashboard', absolute: false)`.
- **Verifikasi**:
  - Tambahkan test case pada `tests/Feature/Auth/AuthenticationTest.php` yang menguji pengguna terautentikasi untuk masing-masing 7 role (`admin`, `purchasing`, `supplier` import/local, `qc`, `finance`, `accounting`, `ga`) ketika melakukan `GET /login` dan `GET /dashboard`.
  - Jalankan: `php artisan test --filter=AuthenticationTest`

### IMP-02 — Perbaikan Kebocoran Periode `closed` pada Halaman Open Quotation Periods Supplier
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Periode penawaran yang sudah ditutup (`status = 'closed'`) tetap muncul di halaman `supplier.quotations.index` apabila supplier pernah mengirimkan quotation pada periode tersebut.
  - **Root Cause**: Pada `app/Http/Controllers/Supplier/QuotationController.php:63-72`, pemanggilan `->orWhereHas('purchaseRequisitions.quotations', ...)` berada di level terluar query `Period::where('status', 'open')`, menghasilkan klausa SQL `WHERE status = 'open' AND EXISTS (...) OR EXISTS (...)`.
- **File yang Diubah**:
  - `app/Http/Controllers/Supplier/QuotationController.php` (baris 63–72)
- **Detail Perubahan Kode**:
  ```php
  $periods = Period::where('status', 'open')
      ->where(function ($periodQuery) use ($supplierId) {
          $periodQuery->whereHas('purchaseRequisitions', function ($query) use ($supplierId) {
              $query->visibleToSupplier($supplierId);
          })->orWhereHas('purchaseRequisitions.quotations', function ($query) use ($supplierId) {
              $query->where('supplier_id', $supplierId);
          });
      })
      ->orderByDesc('year')
      ->orderByRaw('month IS NULL DESC')
      ->orderByDesc('month')
      ->get();
  ```
- **Verifikasi**:
  - Tambahkan test di `tests/Feature/Supplier/SupplierDataIsolationTest.php` yang membuat `Period` berstatus `'closed'` dengan `Quotation` milik supplier tersebut, lalu pastikan `GET /supplier/quotations` tidak menampilkan periode `'closed'` tersebut.
  - Jalankan: `php artisan test --filter=SupplierDataIsolationTest`

### IMP-03 — Perbaikan Filter Tanggal Zona Waktu Bisnis & Optimasi Indeks pada `InvoiceQuery::filtered()`
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Invoice lokal yang disubmit antara pukul 00:00 dan 06:59 WIB tidak muncul saat difilter pada tanggal hari tersebut menggunakan filter `from`/`to`, dan query tidak memanfaatkan indeks kolom.
  - **Root Cause**: `app/Services/LocalInvoice/InvoiceQuery.php:57-61` menggunakan `$query->whereDate($column, $operator, $filters[$key])` untuk `submitted_at` (kolom UTC `TIMESTAMP`) dan `due_date` (kolom `DATE`). Fungsi SQL `DATE(submitted_at)` membandingkan tanggal UTC (bukan WIB) dan membungkus kolom dengan fungsi sehingga mematikan *index range scan*.
- **File yang Diubah**:
  - `app/Services/LocalInvoice/InvoiceQuery.php` (baris 57–61)
- **Detail Perubahan Kode**:
  ```php
  if (! empty($filters['from'])) {
      $fromUtc = BusinessTime::toStorage(BusinessTime::parse((string) $filters['from'])->startOfDay());
      $query->where('submitted_at', '>=', $fromUtc);
  }
  if (! empty($filters['to'])) {
      $toUtc = BusinessTime::toStorage(BusinessTime::parse((string) $filters['to'])->endOfDay());
      $query->where('submitted_at', '<=', $toUtc);
  }
  if (! empty($filters['due_from'])) {
      $query->where('due_date', '>=', (string) $filters['due_from']);
  }
  if (! empty($filters['due_to'])) {
      $query->where('due_date', '<=', (string) $filters['due_to']);
  }
  ```
- **Verifikasi**:
  - Tambahkan test pada `tests/Feature/Timezone/` atau `tests/Feature/LocalInvoice/` dengan invoice yang memiliki `submitted_at = '2026-10-14 18:30:00'` UTC (`2026-10-15 01:30:00` WIB), lalu pastikan filter `from=2026-10-15&to=2026-10-15` mengembalikan invoice tersebut.
  - Jalankan: `php artisan test tests/Feature/Timezone tests/Unit/Architecture/BusinessTimeGuardTest.php`

### IMP-05 — Eliminasi N+1 Query pada Tabel Antrean Inspeksi QC (`QcInspectionController::dataWaiting`)
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Setiap baris PO pada tabel antrean QC memicu 1 query tambahan ke tabel `qc_inspections`.
  - **Root Cause**: Pada `app/Http/Controllers/Qc/QcInspectionController.php:66-76`, di dalam closure `->addColumn('action', function ($po) { ... })`, terdapat pemanggilan langsung `QcInspection::where('po_id', $po->id)->whereNotNull('shipment_id')->pluck('shipment_id')->all()`.
- **File yang Diubah**:
  - `app/Http/Controllers/Qc/QcInspectionController.php` (baris 50–87)
- **Detail Perubahan Kode**:
  ```php
  public function dataWaiting(Request $request, RegionalDisplayFormatter $regionalFormatter)
  {
      $query = $this->waitingPurchaseOrdersQuery()->with([
          'supplier',
          'quotations' => fn ($query) => $query->withCount('items'),
          'shipmentItems.shipment',
          'qcInspections:id,po_id,shipment_id',
      ])
          ->orderBy('actual_arrival', 'asc');

      return DataTables::eloquent($query)
          // ... kolom lainnya tetap sama ...
          ->addColumn('action', function ($po) {
              $inspectedShipmentIds = $po->qcInspections
                  ->whereNotNull('shipment_id')
                  ->pluck('shipment_id')
                  ->all();
              $waitingShipment = $po->shipments()
                  ->first(fn ($s) => $s->status === Shipment::STATUS_ARRIVED && ! in_array($s->id, $inspectedShipmentIds, true));
  ```
- **Verifikasi**:
  - Jalankan: `php artisan test tests/Feature/Qc` dan `php artisan performance:profile-critical --only=qc.inspections.data-waiting`

### IMP-06 — Deduplikasi Query `FileInspection` & Optimasi Verifikasi Integritas pada `FileAccessGuard`
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Setiap pembacaan dokumen melalui `AttachmentController`, `LocalInvoiceDocumentController`, `SupplierMasterDocumentController`, dan `GaClaimDocumentController` mengeksekusi `FileInspection::find()` dua kali dan menghitung ulang `hash_file('sha256')` penuh pada disk.
  - **Root Cause**: `FileAccessGuard::assertReadable()` (`app/Services/FileSecurity/FileAccessGuard.php:52-66`) memanggil `$this->classification($document)` (yang memuat `FileInspection::find($document->file_inspection_id)`) lalu kembali memanggil `FileInspection::findOrFail($document->file_inspection_id)` di baris 57.
- **File yang Diubah**:
  - `app/Services/FileSecurity/FileAccessGuard.php` (baris 12–66)
- **Detail Perubahan Kode**:
  - Ekstrak helper private `resolveInspection(Model $document): ?FileInspection` atau teruskan instance `?FileInspection $inspection` yang sudah dimuat di `assertReadable()` ke `classification(Model $document, ?FileInspection $inspection = null)` agar hanya terjadi 1 kali query `FileInspection::find()`.
  - Pertahankan pemeriksaan ukuran file (`filesize($path) === $inspection->file_size`) dan `hash_equals($inspection->sha256, (string) hash_file('sha256', $path))` untuk memastikan seluruh tes keamanan file (`tests/Feature/FileSecurity*`) tetap lulus tanpa perubahan kontrak keamanan, atau simpan cache integritas jangka pendek berbasis kombinasi `(file_inspection_id, filesize, filemtime)` di memori/cache lokal.
- **Verifikasi**:
  - Jalankan: `php artisan test --filter=FileSecurity`

### IMP-07 — Perbaikan Polusi Cache Lintas Pengguna, Zona Waktu `BusinessTime`, dan Deduplikasi `updateKurs()` pada Dashboard Purchasing
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Label bulan pada grafik dashboard Purchasing menggunakan format `$regionalFormatter` milik user pertama yang memicu cache, rentang bulan menggunakan `Carbon::now()` (UTC) alih-alih `BusinessTime`, dan method `updateKurs()` terduplikasi di `AdminController` dan `PurchasingController`.
  - **Root Cause**: Pada `app/Http/Controllers/Purchasing/PurchasingController.php:23-85`, `$regionalFormatter->monthYear($d)` dieksekusi di dalam closure `Cache::remember('purchasing_dashboard_widgets:'.app()->getLocale(), ...)`, sementara baris 29, 32, dan 40 menggunakan `now()` / `Carbon::now()`.
- **File yang Diubah**:
  1. `app/Http/Controllers/Purchasing/PurchasingController.php` (baris 21–88, 129–143)
  2. `app/Http/Controllers/Admin/AdminController.php` (baris 46–60)
- **Detail Perubahan Kode**:
  - Di dalam closure `Cache::remember('purchasing_dashboard_widgets', now()->addMinutes(10), ...)`, ganti `now()` dan `Carbon::now()` dengan `BusinessTime::now()` / `BusinessTime::today()`, dan simpan array mentah `$prCountsByYearMonth` (tanpa memanggil `$regionalFormatter`).
  - Di luar `Cache::remember(...)`, bangun `$prPerBulan` menggunakan `$regionalFormatter->monthYear($d)` milik user aktif saat ini.
  - Konsolidasikan logika validasi dan pembuatan `ExchangeRate::create([...])` pada `updateKurs()` ke method bersama di `ExchangeRate` atau `ExchangeRateResolver` agar `AdminController::updateKurs()` dan `PurchasingController::updateKurs()` memanggil satu sumber kebenaran yang sama.
- **Verifikasi**:
  - Jalankan: `php artisan test tests/Feature/Purchasing tests/Unit/Architecture/BusinessTimeGuardTest.php`

### IMP-11 — Penggantian `$guarded = ['id']` ke `$fillable` Eksplisit pada 22 Model Core 2 & Penguatan Password Registrasi Supplier
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: 22 model pada domain Local Supplier, GA, dan Payment menggunakan `protected $guarded = ['id'];`, sementara password registrasi publik supplier (`SupplierRegistrationRequest::registrationPasswordRule()`) tidak memeriksa daftar password bocor (`uncompromised()`) di produksi.
  - **Root Cause**: Inersia pengembangan cepat pada gelombang Core 2 dan belum ditambahkannya pengondisian `uncompromised_in_production` pada `SupplierRegistrationRequest.php:27-33`.
- **File yang Diubah**:
  1. `app/Http/Requests/Auth/SupplierRegistrationRequest.php` (baris 27–33)
  2. 22 Model di `app/Models/`:
     - `GaClaim.php`, `GaClaimDocument.php`, `GaClaimReceipt.php`, `GaClaimStatusHistory.php`
     - `LocalFinanceAuditLog.php`, `LocalInvoice.php`, `LocalInvoiceDocument.php`, `LocalInvoiceGoodsReceipt.php`, `LocalInvoicePayment.php`, `LocalInvoicePaymentTransfer.php`, `LocalInvoicePhysicalVerification.php`, `LocalInvoiceReceipt.php`, `LocalInvoiceRevision.php`, `LocalInvoiceStatusHistory.php`, `LocalInvoiceVerification.php`, `LocalInvoiceVoucher.php`
     - `LocalPoDocumentBatch.php`, `LocalPoDocumentEntry.php`, `LocalProcurementImport.php`
     - `PaymentBatch.php`, `PaymentGroup.php`, `PaymentItem.php`
     - `SupplierAuditStatusHistory.php`, `SupplierOverpaymentRefund.php`
- **Detail Perubahan Kode**:
  - Pada masing-masing dari 22 model tersebut, ganti `protected $guarded = ['id'];` dengan `protected $fillable = [...]` yang mencantumkan seluruh kolom tabel non-PK sesuai migrasi terkait.
  - Pada `app/Http/Requests/Auth/SupplierRegistrationRequest.php`:
    ```php
    public static function registrationPasswordRule(): Password
    {
        $rule = Password::min(8)
            ->max(255)
            ->mixedCase()
            ->numbers()
            ->symbols();

        if (app()->environment('production') && config('auth_security.password.uncompromised_in_production', true)) {
            $rule->uncompromised((int) config('auth_security.password.uncompromised_threshold', 3));
        }

        return $rule;
    }
    ```
- **Verifikasi**:
  - Jalankan seluruh suite Local Invoice, GA, Finance, Supplier Audit, dan Supplier Registration:
    `php artisan test tests/Feature/LocalInvoice tests/Feature/Finance tests/Feature/Ga tests/Feature/SupplierAudit tests/Feature/SupplierRegistration`

### IMP-12 — Mitigasi Risiko PHP-FPM Worker Starvation pada Login Tarpit
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Pemanggilan `usleep($delayMs * 1000)` hingga 2.000 ms di dalam `LoginRequest::applyTarpitDelay()` menahan worker PHP-FPM secara sinkron.
  - **Root Cause**: Default `config/auth_security.php:35` mengaktifkan `'enabled' => (bool) env('AUTH_TARPIT_ENABLED', true)`.
- **File yang Diubah**:
  - `config/auth_security.php` (baris 34–39)
  - `.env.example`
- **Detail Perubahan Kode**:
  - Ubah default `'enabled' => (bool) env('AUTH_TARPIT_ENABLED', false)` pada `config/auth_security.php:35` sehingga di produksi perlindungan mengandalkan *rate limiter* bertingkat + Turnstile CAPTCHA tanpa menahan worker PHP-FPM, sementara `LoginSecurityTest` yang menguji tarpit secara eksplisit tetap dapat mengaktifkannya via `config(['auth_security.login.tarpit.enabled' => true])`.
- **Verifikasi**:
  - Jalankan: `php artisan test tests/Feature/Auth/LoginSecurityTest.php`

### IMP-16 — Lokalisasi String Hardcoded pada DataTables Controller Core 1 & Perbaikan Kontras `tw:text-outline`
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Label `'View History'`, `'Not Responded'`, `'Continue'`, dan `'Start Inspection'` tidak berubah saat bahasa diatur ke Bahasa Indonesia, dan 21 elemen berkelas `tw:text-outline` memiliki rasio kontras ~1,48:1 (di bawah batas WCAG 1.4.11 minimal 3:1).
  - **Root Cause**: String langsung ditulis di dalam HTML builder controller, dan token `--md-outline` (`#CBD5E1`) yang diperuntukkan bagi garis *hairline border* dipakai sebagai warna teks/ikon.
- **File yang Diubah**:
  1. `app/Http/Controllers/Supplier/SupplierPriceHistoryController.php:251` (`__('supplier.copy.view_history')`)
  2. `app/Http/Controllers/Supplier/QuotationController.php:174,190` (`__('supplier.copy.not_responded')`, `__('supplier.copy.continue')`)
  3. `app/Http/Controllers/Qc/QcInspectionController.php:84` (`__('qc.copy.start_inspection')`)
  4. `lang/en/supplier.php`, `lang/id/supplier.php`, `lang/en/qc.php`, `lang/id/qc.php`
  5. 20 lokasi ikon/input-group di `resources/views/` (kecuali divider `<div class="vr ... tw:text-outline">` di `navbar.blade.php:26` yang memang garis pemisah): ganti `tw:text-outline` menjadi `tw:text-on-surface-variant`:
     - `resources/views/supplier/shipments/show.blade.php` (baris 128, 150, 173)
     - `resources/views/supplier/shipments/index.blade.php` (baris 41)
     - `resources/views/supplier/shipments/create.blade.php` (baris 144)
     - `resources/views/supplier/price-history/historical.blade.php` (baris 328)
     - `resources/views/supplier/po/show.blade.php` (baris 395)
     - `resources/views/supplier/conversations/index.blade.php` (baris 61)
     - `resources/views/purchasing/shipments/show.blade.php` (baris 110, 132, 155)
     - `resources/views/purchasing/shipments/index.blade.php` (baris 38)
     - `resources/views/purchasing/quotations/show.blade.php` (baris 142, 143, 296, 297)
     - `resources/views/purchasing/pr/_supplier_picker_modal.blade.php` (baris 69)
     - `resources/views/purchasing/po/show.blade.php` (baris 740)
     - `resources/views/purchasing/po/index.blade.php` (baris 35)
     - `resources/views/components/ui/breadcrumb.blade.php` (baris 16)
- **Verifikasi**:
  - Jalankan: `php tests/Support/user-facing-copy-audit.php`, `node --test tests/js/text-contrast.test.mjs`, dan `php artisan test --filter=Localization`

## 3. Fase 2 (Mid-Term): Stabilisasi Suite Pengujian, Refactoring Query Skalabilitas, Pemisahan Queue, dan Kebersihan Repositori

Fase ini menyelesaikan masalah skalabilitas pada data berjumlah besar, memulihkan keandalan 100% pada suite tes JavaScript, dan membersihkan struktur repositori serta infrastruktur build.

### IMP-15 — Perbaikan 26 Subtest JavaScript yang Gagal & Penambahan Matrix Feature Flag di CI
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Menjalankan `node --test tests/js/*.test.mjs` menghasilkan 26 kegagalan dari 669 subtest.
  - **Root Cause**:
    1. Pada `resources/js/unsaved-changes.js` (dan handler `beforeunload` yang menetapkan `event.returnValue = ''`), objek `Event` bawaan Node.js memiliki getter-only `returnValue`, sehingga melempar `TypeError: Cannot set property returnValue of #<Event> which has only a getter` pada `customization-form.test.mjs` dan `unsaved-state-reload.test.mjs` saat event dipicu secara asinkron setelah subtest selesai.
    2. Pada `tests/js/server-tabs.test.mjs`, state history/popstate dan timer cache memerlukan isolasi per-subtest.
    3. `package.json` belum mendefinisikan script `"test:js"`, dan `phpunit.xml` mematikan 5 *feature flag* produksi secara default.
- **File yang Diubah**:
  1. `resources/js/unsaved-changes.js`
  2. `tests/js/server-tabs.test.mjs`, `tests/js/customization-form.test.mjs`, `tests/js/unsaved-state-reload.test.mjs`
  3. `package.json`
- **Detail Perubahan Kode**:
  - Pada `resources/js/unsaved-changes.js`, buat helper aman untuk mengatur `returnValue` pada event `beforeunload`:
    ```javascript
    function setBeforeUnloadReturnValue(event, value = '') {
        try {
            event.returnValue = value;
        } catch {
            try {
                Object.defineProperty(event, 'returnValue', {
                    configurable: true,
                    writable: true,
                    value,
                });
            } catch {
                // Ignore in synthetic environments where Event.prototype.returnValue is non-configurable.
            }
        }
    }
    ```
  - Pastikan setiap subtest di `customization-form.test.mjs` dan `unsaved-state-reload.test.mjs` memanggil `destroy()` / membersihkan listener `window` pada blok `afterEach` / `t.after()`.
  - Tambahkan `"test:js": "node --test tests/js/*.test.mjs"` pada `package.json`.
- **Verifikasi**:
  - Jalankan: `npm run test:js` (pastikan 0 gagal dari seluruh 669 subtest).

### IMP-04 — Refactoring Query `SupplierPriceHistoryController::getOverviewData()` ke Server-Side SQL Tanpa `GROUP_CONCAT`
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: `SupplierPriceHistoryController::getOverviewData()` (`app/Http/Controllers/Supplier/SupplierPriceHistoryController.php:214-246`) menggunakan `GROUP_CONCAT(quotation_items.price_per_kg ORDER BY purchase_orders.created_at DESC SEPARATOR '|')` dan `.get()` lalu `DataTables::collection($rows)`.
  - **Root Cause**: Untuk mengambil `latest_price` dan `latest_status` per grup `(material_name, currency)`, query menggabungkan seluruh histori ke satu string di memori MySQL lalu memuat semua grup ke memori PHP.
- **File yang Diubah**:
  - `app/Http/Controllers/Supplier/SupplierPriceHistoryController.php` (baris 214–275)
- **Detail Perubahan Kode**:
  - Bangun subquery menggunakan *window function* `ROW_NUMBER() OVER (PARTITION BY pr_items.material_name, quotations.currency ORDER BY purchase_orders.created_at DESC, purchase_orders.id DESC, quotation_items.id DESC) as rn` (sebagaimana pola yang sudah dipakai di `VsBestPriceQueryService.php:35`).
  - Gabungkan agregat (`COUNT(DISTINCT ...)`, `MIN(price_per_kg)`, `MAX(price_per_kg)`, `MAX(purchase_orders.created_at)`) dengan baris `rn = 1` melalui derived table, dan teruskan query builder ke `DataTables::query($query)` sehingga pencarian (`search.value`), pengurutan, dan paginasi (`LIMIT/OFFSET`) dieksekusi sepenuhnya di MySQL.
- **Verifikasi**:
  - Jalankan: `php artisan test --filter=SupplierPriceHistory`

### IMP-08 — Optimasi `NotificationSummaryService::countsForUser()` & Penghentian Polling Saat WebSocket Aktif
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Endpoint `notifications.unread-count` memuat seluruh baris `unreadNotifications` beserta kolom JSON `data` ke PHP setiap 30 detik (`layouts/app.blade.php:519`), bahkan saat koneksi Pusher/Echo aktif.
  - **Root Cause**: Klasifikasi kategori (`NotificationCategory::key()`) dan filter `silent` (`$notification->data['silent']`) dilakukan di memori PHP terhadap koleksi Eloquent, dan `setInterval(updateBadges, 30000)` tidak memeriksa status koneksi Pusher.
- **File yang Diubah**:
  1. `app/Services/NotificationSummaryService.php` (baris 29–88)
  2. `resources/views/layouts/app.blade.php` (baris 438–525, 895–960)
- **Detail Perubahan Kode**:
  - Pada `NotificationSummaryService::countsForUser(User $user)`: bungkus hasil perhitungan `{count, category_counts}` di dalam cache singkat berbasis versi atau invalider event (`Cache::remember("user_notif_counts:{$user->id}", 60, ...)`), dan hapus cache tersebut pada `NotificationController::markRead()`, `markAllRead()`, serta listener `NotificationSent` / `BroadcastNotificationCreated`.
  - Pada `resources/views/layouts/app.blade.php`: di dalam `updateBadges()`, jika `window.Echo?.connector?.pusher?.connection?.state === 'connected'`, lewati polling periodik 30 detik (atau perpanjang menjadi 300 detik sebagai *fallback* rekonsiliasi) karena event realtime `.notification()` sudah memanggil `updateBadges()` secara langsung saat ada notifikasi baru.
- **Verifikasi**:
  - Jalankan: `php artisan test --filter=Notification`

### IMP-09 — Dekopling Antrean `default,exports` & Pengurangan Durasi Lock pada `LocalProcurementImportService::commit()`
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Ekspor file besar menunda notifikasi *realtime*, dan transaksi impor PO/GR 70.000 baris menahan lock yang beririsan dengan pengajuan invoice lokal.
  - **Root Cause**: Urutan antrean pada `composer.json:53` dan panduan cPanel adalah `--queue=exports,default` (sehingga `exports` memonopoli worker), dan `LocalProcurementImportService::commit()` melakukan beberapa lookup ulang ke `local_purchase_orders` di dalam transaksi utama.
- **File yang Diubah**:
  1. `composer.json` (baris 53)
  2. `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md`, `docs/guides/PRODUCTION-CRON-COMMANDS-20261012.md`, `docs/guides/AUTH-SECURITY-DEPLOYMENT.md`
  3. `app/Services/LocalInvoice/LocalProcurementImportService.php` (baris 285–360)
- **Detail Perubahan Kode**:
  - Pada `composer.json` dan seluruh panduan deployment, ubah urutan antrean worker menjadi `--queue=default,exports` agar pekerjaan cepat (`BroadcastNotificationCreated`) selalu diprioritaskan di atas job ekspor berdurasi panjang, serta hapus instruksi `--stop-when-empty` yang bertentangan dengan `README.md`.
  - Pada `LocalProcurementImportService::commit()`, gunakan kembali peta PO yang sudah di-lock pada langkah validasi batch dalam satu iterasi memori sehingga jumlah `SELECT` ke `local_purchase_orders` per batch 500 baris berkurang dari 4 menjadi 1–2 query.
- **Verifikasi**:
  - Jalankan: `php artisan test tests/Feature/LocalInvoice/LocalProcurementBackgroundImportTest.php tests/Feature/AsyncExportQueueTest.php`

### IMP-14 — Penertiban File `.sql` Manual & Penambahan Architecture Guard untuk Skema/Migrasi
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Terdapat 4 pasang migrasi dengan prefix timestamp identik (`2026_09_08_000001`, `2026_09_08_000002`, `2026_10_07_000001`, `2026_10_07_000002`) dan 9 file `.sql` manual di `database/` dan root proyek.
  - **Root Cause**: Pembuatan migrasi paralel lintas cabang tanpa pemeriksaan keunikan prefix timestamp dan penyimpanan skrip SQL produksi langsung di folder `database/`.
- **File yang Diubah**:
  1. Pindahkan 9 file `.sql` (`ADASI-PRODUCTION-*.sql` dan `database/*.sql`) ke `database/sql/archive/` (Catatan: jangan mengubah nama 4 pasang file migrasi lama yang sudah tercatat di tabel `migrations` produksi agar tidak merusak histori `php artisan migrate:status`).
  2. Buat `tests/Unit/Architecture/MigrationHygieneGuardTest.php`.
- **Detail Perubahan Kode**:
  - Di dalam `MigrationHygieneGuardTest.php`, daftarkan 4 pasang prefix historis sebagai *known legacy allow-list*, dan gagalkan tes apabila ada file migrasi baru yang menduplikasi prefix 4-segmen pertama (`YYYY_MM_DD_HHMMSS`) atau apabila terdapat file `.sql` baru di root repositori maupun langsung di bawah `database/`.
- **Verifikasi**:
  - Jalankan: `php artisan test tests/Unit/Architecture/MigrationHygieneGuardTest.php`

### IMP-17 — Pembaruan `Dockerfile` ke PHP 8.2+, Penghapusan Artefak Liar di Root, dan Penguatan `check-tracked-artifacts.php`
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: `Dockerfile` memakai image PHP 8.0 (`richarvey/nginx-php-fpm:3.1.6`), `api/index.php` dan `temp_view.html` tertinggal di repositori, serta >35 file `.md`, `TARIKAN TRANSFER.xlsx`, `PNR261178.pdf`, dan `docs/naufal raw material.xlsx` terlacak di Git.
  - **Root Cause**: `Dockerfile` belum diperbarui sejak upgrade ke Laravel 12, `.dockerignore` belum ada, dan `scripts/check-tracked-artifacts.php` hanya memblokir `.zip` dan `storage/app/private/`.
- **File yang Diubah**:
  1. `Dockerfile` dan `.dockerignore` (baru)
  2. `scripts/check-tracked-artifacts.php`
  3. Hapus `api/index.php`, `temp_view.html`, `TARIKAN TRANSFER.xlsx`, `PNR261178.pdf`, `docs/naufal raw material.xlsx`, dan arsipkan dokumen rencana root (`*-PLAN*.md`, `UI-REDESIGN-RESULT/`) ke `docs/archive/`.
- **Detail Perubahan Kode**:
  - Perbarui `Dockerfile` menggunakan multi-stage build (`node:20-alpine` untuk `npm ci && npm run build`, dilanjutkan `serversideup/php:8.2-fpm-nginx` atau `php:8.2-fpm-alpine` dengan ekstensi `pdo_mysql`, `gd`, `zip`, `intl`, `bcmath`, `opcache`).
  - Buat `.dockerignore` yang mengecualikan `.git`, `node_modules`, `vendor`, `storage/logs/*`, `storage/framework/cache/*`, `tests`, dan `docs`.
  - Perbarui `scripts/check-tracked-artifacts.php` agar memblokir file `.zip`, `.sql` di luar `database/sql/`, serta `.xlsx`/`.pdf` di root proyek.
- **Verifikasi**:
  - Jalankan: `php scripts/check-tracked-artifacts.php`

### IMP-18 — Penerapan Sticky Columns & Responsive Controls pada Tabel Perbandingan Harga dan Form Quotation
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Tabel perbandingan harga (`resources/views/purchasing/comparison/_inter_supplier_content.blade.php`) dan form quotation (`resources/views/supplier/quotations/create.blade.php`) membutuhkan *horizontal scrolling* panjang pada layar <1280px, sehingga kolom nama material ikut tergeser keluar layar.
  - **Root Cause**: Kolom identitas utama (`No` dan `Material / Specification`) belum memiliki utility *sticky positioning* pada kontainer `.table-responsive`.
- **File yang Diubah**:
  1. `resources/css/app.css`
  2. `resources/views/purchasing/comparison/_inter_supplier_content.blade.php`
  3. `resources/views/supplier/quotations/create.blade.php`
- **Detail Perubahan Kode**:
  - Tambahkan class utilitas `.ui-table-sticky-col` di `resources/css/app.css` yang menerapkan `position: sticky; left: 0; z-index: 2; background-color: var(--md-surface); border-right: 1px solid var(--md-outline);` pada layar `min-width: 768px` (dan `background-color: var(--md-surface-container)` untuk `thead th.ui-table-sticky-col`).
  - Terapkan `.ui-table-sticky-col` pada kolom nama material di tabel perbandingan harga dan form pengisian quotation.
- **Verifikasi**:
  - Jalankan: `npm run build` dan `node --test tests/js/tailwind-build.test.mjs`

## 4. Fase 3 (Long-Term): Penyelarasan Arsitektur Core 1 dan Penguatan Strict CSP

Fase ini menyelaraskan kualitas arsitektur modul-modul awal (Core 1) agar setara dengan standar modularitas Core 2, serta menuntaskan pengerasan keamanan Content Security Policy.

### IMP-10 — Penguatan Header HSTS (`includeSubDomains`) dan Penghapusan `'unsafe-inline'` & `'unsafe-eval'` pada CSP
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: `AddSecurityHeaders::contentSecurityPolicy()` (`app/Http/Middleware/AddSecurityHeaders.php:66-72`) menyertakan `'unsafe-inline'` dan `'unsafe-eval'` di dalam `script-src` bersama `'nonce-{$nonce}'`, dan HSTS (`baris 35`) hanya mengatur `max-age=31536000`.
  - **Root Cause**: Kompatibilitas awal dengan evaluator ekspresi standar Alpine.js yang menggunakan `new Function()` (`'unsafe-eval'`).
- **File yang Diubah**:
  1. `app/Http/Middleware/AddSecurityHeaders.php` (baris 34–36, 66–72)
  2. `resources/js/` (komponen Alpine.js)
- **Detail Perubahan Kode**:
  - **Langkah 1 (Segera)**: Ubah HSTS di `AddSecurityHeaders.php:35` menjadi:
    ```php
    $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    ```
    dan hapus `'unsafe-inline'` dari `script-src` (karena seluruh tag `<script>` telah menerima atribut `nonce` melalui `Blade::precompiler` di `AppServiceProvider.php:145-163`).
  - **Langkah 2 (Bertahap)**: Migrasikan ekspresi inline kompleks di atribut `x-data`, `x-show`, dan `x-bind` pada Blade ke method di dalam `Alpine.data(...)`, lalu beralih ke build `@alpinejs/csp` dan hapus `'unsafe-eval'` dari `script-src` di `AddSecurityHeaders.php`.
- **Verifikasi**:
  - Jalankan: `php artisan test --filter=SecurityHeaders`

### IMP-13 — Dekomposisi Bertahap Fat Controllers & Fat Models pada Core 1 (Import Procurement)
- **Diagnosis Kausal (`[Verified]`)**:
  - **Symptom**: Lima controller Core 1 memiliki ukuran 600 hingga 1.244 baris (`QuotationController`: 1.244 baris, `ConversationMessageController`: 672 baris, `SupplierShipmentController`: 646 baris, `PurchaseRequisitionController`: 635 baris, `QcInspectionController`: 600 baris) dan model `PurchaseOrder` mencapai 945 baris.
  - **Root Cause**: Validasi input bertingkat, transaksi penyimpanan multi-item, penanganan lampiran file, pengiriman notifikasi, dan perakitan kolom HTML DataTables ditempatkan langsung di dalam method controller.
- **Rencana Dekomposisi Bertahap (4 Sub-Wave Tanpa Mengubah Route/Kontrak)**:
  1. **Wave 13A — Modul Quotation Supplier (`QuotationController.php`)**:
     - Ekstrak aturan validasi `store()` (baris 300–430) ke `App\Http\Requests\Supplier\StoreQuotationRequest`.
     - Ekstrak logika transaksi penyimpanan item, lampiran, perhitungan berat, dan notifikasi ke `App\Services\Procurement\QuotationSubmissionService`.
  2. **Wave 13B — Modul Purchase Requisition (`Purchasing\PurchaseRequisitionController.php`)**:
     - Ekstrak perakitan DataTables `index()` ke `App\Support\DataTables\PurchaseRequisitionDataTable`.
     - Ekstrak logika submit draft dan undangan supplier ke `App\Services\Procurement\PurchaseRequisitionWorkflowService`.
  3. **Wave 13C — Modul Shipment & QC (`SupplierShipmentController.php` & `QcInspectionController.php`)**:
     - Ekstrak validasi `QcInspectionController::store()` dan `storeAttachments()` ke `App\Http\Requests\Qc\StoreQcInspectionRequest` dan `StoreQcInspectionAttachmentsRequest`.
     - Ekstrak transaksi pembuatan inspeksi QC, kalkulasi status OK/NG, dan pemicu klaim material ke `App\Services\Procurement\QcInspectionService`.
  4. **Wave 13D — Modul Percakapan (`ConversationMessageController.php`) & Model `PurchaseOrder.php`**:
     - Ekstrak pengiriman pesan, pemeriksaan `FileInspectionService`, dan pengiriman notifikasi chat ke `App\Services\Conversation\ConversationMessagingService`.
     - Pindahkan query agregasi dokumen kepabeanan dan kalkulasi nilai IDR dari model `App\Models\PurchaseOrder` ke service/calculator khusus (`CustomsDocumentationProgressCalculator` dan `PurchaseOrderValuationService`) dengan tetap mempertahankan method publik pada `PurchaseOrder` sebagai *thin wrapper* demi menjaga kompatibilitas mundur.
- **Verifikasi per Sub-Wave**:
  - Jalankan: `php artisan test tests/Feature/Procurement tests/Feature/Purchasing tests/Feature/Supplier tests/Feature/Qc`

## 5. Protokol Verifikasi Terpadu (Verification Ladder) & Strategi Rollback

Sesuai protokol **Boost**, tidak ada tahapan yang boleh dinyatakan selesai tanpa melewati tangga verifikasi berikut secara berurutan:

### 5.1 Tangga Verifikasi (Verification Ladder)
1. **Level 1 — Formatting & Static Hygiene**:
   - `./vendor/bin/pint --test`
   - `php scripts/check-tracked-artifacts.php`
2. **Level 2 — Architecture & Guardrail Unit Tests**:
   - `php artisan test tests/Unit/Architecture` (Memastikan `BusinessTimeGuardTest`, `LocalInvoiceStatusMutationGuardTest`, dan `WaveTwo/Three/FourStatusMutationGuardTest` lulus 100%).
3. **Level 3 — Frontend & JavaScript Unit Tests**:
   - `npm run build`
   - `node --test tests/js/*.test.mjs` (Memastikan seluruh 669+ subtest lulus tanpa uncaught exception).
4. **Level 4 — Domain Feature, Isolation & Concurrency Tests**:
   - `composer test:parallel` (Menjalankan suite `Parallel` dengan 4 proses dan suite `Serial` untuk konkurensi/migrasi pada `adasi_portal_test`).
5. **Level 5 — Runtime Performance Profiling**:
   - `php artisan performance:profile-critical --runs=10` (Memastikan 0 path error dan memverifikasi penurunan jumlah query pada `qc.inspections.data-waiting`, `purchasing.dashboard`, serta `finance.dashboard`).

### 5.2 Strategi Rollback Produksi
Karena seluruh perubahan pada Fase 1 hingga Fase 3 dirancang **tanpa migrasi database yang bersifat destruktif** (tidak ada `DROP COLUMN` atau perubahan tipe data yang merusak mundur):
1. Jika ditemukan regresi pasca-rilis pada salah satu fase, lakukan `git revert <commit-sha>` pada cabang rilis.
2. Upload ulang artefak build `public/build` yang sesuai dengan commit sebelumnya.
3. Jalankan blok pembersihan dan pembangunan ulang cache secara berurutan:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan icons:cache
   php artisan queue:restart
   ```

## 6. Completion Gate Checklist

Gunakan daftar periksa ini sebagai gerbang persetujuan (*sign-off gate*) sebelum menutup setiap fase implementasi:

- [ ] **IMP-01**: Pengguna role `ga` dapat mengakses `/` dan `/login` saat sesi aktif dan diarahkan ke `/ga/dashboard` tanpa *redirect loop*.
- [ ] **IMP-02**: Halaman `supplier.quotations.index` hanya menampilkan periode dengan `status = 'open'`.
- [ ] **IMP-03**: Filter `from` dan `to` pada `InvoiceQuery::filtered()` menghitung batas hari berdasarkan zona waktu bisnis WIB (`Asia/Jakarta`) melalui `BusinessTime::toStorage()`.
- [ ] **IMP-04**: `SupplierPriceHistoryController::getOverviewData()` tidak lagi menggunakan `GROUP_CONCAT` atau `DataTables::collection()`, melainkan paginasi SQL server-side.
- [ ] **IMP-05**: `QcInspectionController::dataWaiting()` melakukan *eager loading* `qcInspections` dan bebas dari *N+1 query*.
- [ ] **IMP-06**: `FileAccessGuard::assertReadable()` hanya mengeksekusi 1 query `FileInspection` per pembacaan dokumen.
- [ ] **IMP-07**: Cache `PurchasingController::dashboard()` tidak menyimpan string yang sudah diformat oleh `$regionalFormatter` user pertama dan seluruh tanggal menggunakan `BusinessTime`.
- [ ] **IMP-08**: Polling 30 detik di `layouts/app.blade.php` melambat/berhenti saat Pusher terhubung, dan `NotificationSummaryService::countsForUser()` tidak memuat ribuan JSON `data` pada setiap poll.
- [ ] **IMP-09**: Worker queue memprioritaskan `default` sebelum `exports` (`--queue=default,exports`) dan transaksi impor PO mengurangi query berulang per batch.
- [ ] **IMP-10**: Header HSTS menyertakan `includeSubDomains` dan `script-src` CSP bersih dari `'unsafe-inline'` serta `'unsafe-eval'`.
- [ ] **IMP-11**: Seluruh 22 model Core 2 menggunakan `protected $fillable` eksplisit dan `SupplierRegistrationRequest` memeriksa `uncompromised()` di produksi.
- [ ] **IMP-12**: `AUTH_TARPIT_ENABLED` bernilai default `false` di konfigurasi produksi agar worker PHP-FPM tidak tertahan oleh `usleep()`.
- [ ] **IMP-13**: *Fat Controllers* Core 1 terdekomposisi ke dalam *FormRequest* dan *Service* dengan ukuran masing-masing controller <300 baris.
- [ ] **IMP-14**: File `.sql` manual telah diarsipkan dan `MigrationHygieneGuardTest` menjaga keunikan prefix migrasi baru.
- [ ] **IMP-15**: `node --test tests/js/*.test.mjs` lulus 100% (0 gagal) dan terdaftar sebagai `npm run test:js`.
- [ ] **IMP-16**: Seluruh label DataTables di controller Core 1 telah menggunakan `__()` dan 20 lokasi ikon/input `tw:text-outline` telah diganti ke `tw:text-on-surface-variant`.
- [ ] **IMP-17**: `Dockerfile` mendukung PHP 8.2+, `.dockerignore` tersedia, dan root repositori bersih dari file kerja `.xlsx`/`.pdf`/`.md` sementara.
- [ ] **IMP-18**: Tabel perbandingan harga dan form quotation memiliki *sticky column* pada kolom identitas material untuk layar menengah/kecil.
