# ADASI Portal Supplier — Business Timezone Implementation Plan

> **Tanggal:** 28 September 2026
> **Status:** Draft untuk review
> **Basis analisis:** commit `45ba488` pada `poyipoy/supplierportal`
> **Konteks keputusan:** seluruh user internal (Purchasing, QC, Finance, GA, Admin) berada di **WIB**. Supplier impor hanya memakai **tanggal** (tanpa cut-off berbasis jam). Sistem masih tahap development, belum ada data produksi.

---

## 1. Tujuan & Non-Tujuan

**Tujuan**
1. Semua tanggal/jam yang dilihat user benar dan berlabel zona.
2. Semua keputusan bisnis berbasis "hari ini / bulan ini / tahun ini" mengikuti kalender **WIB**, bukan UTC.
3. Mencegah regresi lewat test dan guardrail otomatis.
4. Menyiapkan fondasi agar penambahan zona per-user di masa depan tidak memerlukan refactor besar.

**Non-Tujuan (sengaja tidak dikerjakan sekarang)**
- Zona waktu per user/supplier (`users.timezone`). Tidak ada kebutuhan saat ini. API `BusinessTime` dirancang agar mudah diperluas.
- Cut-off berbasis jam untuk supplier impor.
- Perubahan format penyimpanan data (tetap UTC).

---

## 2. Keputusan Arsitektur

| ID | Keputusan | Alasan |
|---|---|---|
| **D-1** | **Storage tetap UTC** (`app.timezone = UTC`). Zona bisnis dipisah lewat `app.business_timezone = Asia/Jakarta`. | Praktik standar, aman bila kelak ada integrasi lintas zona. Tidak mengubah cara Laravel membaca/menulis kolom timestamp. |
| **D-2** | Semua logika "kalender" (hari ini, awal/akhir bulan, tahun nomor dokumen, batas minggu) **wajib lewat `BusinessTime`**. | Satu pintu, mudah diaudit dan di-guard. |
| **D-3** | Kolom bertipe **`date`** (jadwal Rabu, `due_date`, `deadline`, `estimated_arrival`, dsb.) **tidak pernah dikonversi zona**. | Tanggal kalender bukan momen absolut. Konversi justru menggeser tanggal. |
| **D-4** | Kolom bertipe **`timestamp/datetime`** selalu ditampilkan lewat `BusinessTime::format()` dengan **label zona dinamis**. | Menghilangkan label "WIB" yang di-hardcode pada waktu UTC. |
| **D-5** | Binding Carbon ke query pada kolom timestamp **wajib dikonversi ke zona storage** (`BusinessTime::toStorage()`). | Laravel memformat Carbon menurut zona objek itu sendiri, tanpa konversi otomatis. Objek berzona WIB yang langsung di-bind menghasilkan query yang salah 7 jam. |

### Alternatif yang dipertimbangkan (D-1)

Mengubah `app.timezone` menjadi `Asia/Jakarta` akan otomatis membetulkan hampir semua `today()`/`now()`/`format()` dan memperkecil scope pekerjaan secara drastis. Karena data masih development, biaya migrasinya sekarang minimal.

Kelemahannya: database menyimpan waktu lokal, sehingga integrasi lintas zona atau ekspansi ke user non-WIB di masa depan lebih sulit dan lebih berisiko dikoreksi belakangan (ketika data produksi sudah ada). **Rekomendasi tetap D-1 (UTC + business timezone)**, namun ini keputusan bisnis-teknis yang perlu dikonfirmasi sebelum Fase 1 dimulai. Jika memilih alternatif ini, Fase 2–4 sebagian besar gugur dan digantikan: set config, reset data dev, sapu label "WIB" hardcode, dan guardrail tetap dipertahankan.

Dokumen ini mengasumsikan **D-1**.

---

## 3. Baseline Temuan

Legenda kolom **Status**: ✅ dibaca langsung dari kode, 🔍 perlu diverifikasi sebelum dikerjakan.
Kolom **Pemicu** menunjukkan kapan bug muncul. Banyak bug hanya muncul pada jendela **00:00–06:59 WIB** (saat tanggal UTC masih tertinggal sehari), sehingga kemungkinan terpicu pada jam kerja normal rendah, tetapi dampaknya nyata (dan pasti terjadi pada lembur, batch job, atau data seed).

### 3.1 Bug yang selalu terlihat (prioritas tertinggi)

| # | Lokasi | Masalah | Status |
|---|---|---|---|
| V1 | `resources/views/local-invoices/table.blade.php` | `submitted_at->format('H:i') }} WIB` menampilkan **jam UTC berlabel WIB**. Submit 09:00 WIB tampil "02:00 WIB". | ✅ |
| V2 | `resources/views/purchasing/po/show.blade.php` | `created_at->format('H:i') }} WIB`, sama seperti V1. | ✅ |
| V3 | ±40 view/PDF/export lain | `->format('d M Y H:i')` pada kolom datetime tanpa konversi dan tanpa label. Jam selalu geser 7 jam, tanggal geser pada jendela 00:00–06:59 WIB. Contoh: `finance/*`, `receipts/verify-*`, `qc/*`, `purchasing/*`, `pdf/qc-inspection-pdf`, `exports/index`, `finance/vouchers/print`. | ✅ (sampel), 🔍 (daftar lengkap) |

> **Verifikasi cepat sebelum Fase 1:** buka satu invoice lokal yang baru disubmit di jam kerja dan bandingkan jam pada tabel dengan jam sebenarnya. Pastikan juga tidak ada middleware yang meng-override timezone per request (`grep -rn "date_default_timezone_set\|setTimezone" app bootstrap`).

### 3.2 Bug logika bisnis (jendela 00:00–06:59 WIB)

| # | File | Kode saat ini | Dampak | Sev. | Status |
|---|---|---|---|---|---|
| L1 | `Services/LocalInvoice/InvoicePhysicalReceiptService` | `now()->addDays($term)->toDateString()` | `due_date` **kurang 1 hari**. Mengalir ke forecast dan label urgensi. | Tinggi | ✅ |
| L2 | `Services/Payment/PaymentForecastService` | `Carbon::now()`, `startOfMonth()`, `endOfMonth()`, `whereBetween` semua dalam UTC | Bucket bulan/minggu dipotong pada 00:00 UTC (07:00 WIB). Invoice yang siap bayar 1 Okt 03:00 WIB masuk **September, minggu 5**. | Tinggi | ✅ |
| L3 | `Services/LocalInvoice/InvoiceSubmissionService::submit` | `$sched->isBefore(today())` | Validasi terlalu longgar: jadwal Rabu yang **sudah lewat menurut WIB** masih diterima pada 00:00–06:59 WIB hari Kamis. | Sedang | ✅ |
| L4 | `InvoiceSubmissionService::resubmit` | logika L3 terduplikasi | Sama dengan L3. Perbaikan di satu tempat mudah terlewat di tempat lain. | Sedang | ✅ |
| L5 | `InvoiceSubmissionService::submit` | `now()->year` untuk `SUB-YYYY-xxxxx` dan `TT-YYYY-xxxxx` | Submit 1 Januari 00:00–06:59 WIB memakai **tahun lama** pada nomor dokumen resmi dan counter tahun yang salah. | Sedang | ✅ |
| L6 | `Http/Requests/LocalInvoice/StoreLocalInvoiceRequest` | pesan `scheduled_physical_delivery_date.after_or_equal` mengindikasikan rule `after_or_equal:today` | Rule validator memakai `strtotime('today')` dalam timezone PHP (UTC). Bug identik dengan L3 pada lapisan FormRequest. | Sedang | 🔍 |
| L7 | `routes/console.php` | `dailyAt('08:00')` tanpa `->timezone()` | Pengingat "pagi" berjalan **15:00 WIB**. | Sedang | ✅ |
| L8 | `Console/Commands/SendLocalInvoiceDeliveryReminders` | `today()`, `today()->addDays(3)` | Saat ini **latent**: pada 15:00 WIB tanggal UTC = tanggal WIB. Akan menjadi bug aktif begitu jadwal dipindah sebelum 07:00 WIB (mis. setelah L7 dibetulkan ke 08:00 WIB pun aman, tetapi tetap harus konsisten). | Rendah | ✅ |
| L9 | `Models/LocalInvoice` | `due_date->lt(today()->addDays(7))` (label "Due < 7 Days") | Label urgensi salah 1 hari pada jendela 00:00–06:59 WIB. | Rendah | ✅ |
| L10 | `Http/Controllers/Purchasing/PurchasingController` | `whereDate('deadline', '<', today())` | "Claims Past Deadline" salah 1 hari pada jendela yang sama. | Rendah | ✅ |
| L11 | Generator nomor dokumen impor/DRP/voucher (`document_sequences`: `REQ/MM/YYYY/XXX`, `PO/MM/YYYY/XXX`; `DRP-YYYY-XXXXX`; nomor voucher) | Diduga memakai `now()` untuk bulan/tahun | Salah bulan/tahun pada pergantian bulan/tahun jam 00:00–06:59 WIB. | Sedang | 🔍 |
| L12 | `Services/LocalInvoice/InvoiceExpiryService::rescheduleDelivery` | tidak memvalidasi "tidak boleh masa lampau", hanya cek hari Rabu | Tidak terkait timezone, tapi validasi tanggal ini harus disatukan dengan L3/L4 (lihat Fase 3). | Rendah | ✅ |

### 3.3 Yang sudah benar (dipertahankan)

- `app.timezone = UTC`.
- `scheduled_physical_delivery_date`, `due_date`, `deadline`, `scheduled_payment_date` bertipe `date`.
- `NewDeviceLoginNotification` sudah menampilkan waktu dengan label zona (perlu diseragamkan ke `BusinessTime` agar label dinamis).
- Domain impor (`estimated_arrival`, `actual_arrival`, `deadline` klaim) berbasis tanggal, sesuai keputusan bisnis.

### 3.4 Temuan di luar scope (dicatat agar tidak hilang)

- `InvoiceExpiryService::recordMissedDelivery` **tidak dipanggil oleh scheduler/controller mana pun** yang ditemukan (hanya oleh test), padahal `context.md` menyatakan invoice expire otomatis. Bila kelak dibuat job harian untuk mendeteksi "missed Wednesday", job tersebut wajib memakai `BusinessTime::today()` dan cut-off jam kasir tutup.
- `context.md` menjelaskan prioritas forecast DRP/GA, sedangkan `PaymentForecastService` yang dibaca hanya menghitung `LocalInvoice`. Periksa apakah dokumentasi atau implementasi yang tertinggal.

---

## 4. Rencana Implementasi

Estimasi bersifat kasar dan mengasumsikan satu developer yang sudah familiar dengan codebase.

### Fase 0 — Fondasi (±0,5 hari)

**0.1 Konfigurasi**

```php
// config/app.php  (di bawah 'timezone' => 'UTC')
'business_timezone' => env('APP_BUSINESS_TIMEZONE', 'Asia/Jakarta'),
```

```dotenv
# .env.example
APP_BUSINESS_TIMEZONE=Asia/Jakarta
```

**0.2 Helper `BusinessTime`**

```php
<?php
// app/Support/BusinessTime.php
namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class BusinessTime
{
    private const LABELS = [
        'Asia/Jakarta'  => 'WIB',
        'Asia/Makassar' => 'WITA',
        'Asia/Jayapura' => 'WIT',
    ];

    public static function tz(): string
    {
        return config('app.business_timezone', 'Asia/Jakarta');
    }

    public static function label(): string
    {
        return self::LABELS[self::tz()] ?? self::now()->format('T');
    }

    /** Sekarang, dalam zona bisnis. Memakai now() agar menghormati travelTo()/setTestNow() di test. */
    public static function now(): CarbonImmutable
    {
        return now()->toImmutable()->setTimezone(self::tz());
    }

    /** Awal hari ini menurut kalender zona bisnis. Pengganti today(). */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** Timestamp storage (UTC) atau string DB -> zona bisnis. */
    public static function toBusiness(DateTimeInterface|string $at): CarbonImmutable
    {
        $instance = $at instanceof DateTimeInterface
            ? CarbonImmutable::instance($at)
            : CarbonImmutable::parse($at, config('app.timezone'));

        return $instance->setTimezone(self::tz());
    }

    /** Tanggal kalender 'Y-m-d' -> awal hari di zona bisnis (untuk dibandingkan dengan today()). */
    public static function parseDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::tz())->startOfDay();
    }

    /** Wajib dipakai saat mem-bind Carbon ke kolom timestamp pada query (lihat D-5). */
    public static function toStorage(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));
    }

    /** Format untuk tampilan. Hanya untuk kolom datetime, JANGAN dipakai pada kolom date. */
    public static function format(DateTimeInterface|string|null $at, string $format = 'd M Y H:i', bool $withLabel = true): string
    {
        if ($at === null || $at === '') {
            return '—';
        }

        $text = self::toBusiness($at)->translatedFormat($format);

        return $withLabel ? $text.' '.self::label() : $text;
    }
}
```

**0.3 Blade directive** (di `AppServiceProvider::boot()`)

```php
Blade::directive('bizdt', fn ($expr) => "<?php echo e(\\App\\Support\\BusinessTime::format($expr)); ?>");
```

Pemakaian: `@bizdt($invoice->submitted_at)` atau `{{ BusinessTime::format($x, 'd M Y') }}`.

**0.4 Unit test helper** — `tests/Unit/BusinessTimeTest.php`: `now()`, `today()`, `toBusiness()`, `toStorage()`, `parseDate()`, `format()` (null → `—`), dan bahwa `travelTo()` memengaruhi `BusinessTime::now()`.

**0.5 Verifikasi environment**
- `config/database.php`: set `'timezone' => '+00:00'` pada koneksi MySQL agar `CURRENT_TIMESTAMP`/`useCurrent()` konsisten UTC.
- Jalankan `SELECT @@global.time_zone, @@session.time_zone;` di server dev dan produksi.
- Pastikan cron `* * * * * php artisan schedule:run` aktif (deploy memakai `.cpanel.yml`).

**Acceptance Fase 0:** `BusinessTimeTest` hijau; config dan `.env.example` ter-commit; tidak ada perubahan perilaku aplikasi.

---

### Fase 1 — Lapisan tampilan (±1,5–2 hari)

Ini fase pertama karena bug V1–V3 **terlihat setiap kali** halaman dibuka.

**1.1 Perbaiki label WIB hardcode (V1, V2)**

```blade
{{-- sebelum --}}
{{ $row->submitted_at?->format('H:i') }} WIB
{{-- sesudah --}}
@bizdt($row->submitted_at)
```

Pisahkan tanggal dan jam bila desain memerlukannya:
`{{ BusinessTime::format($row->submitted_at, 'd M Y', false) }}` dan `{{ BusinessTime::format($row->submitted_at, 'H:i') }}`.

**1.2 Sapu seluruh format datetime (V3)**

Inventarisasi awal (jalankan dan simpan hasilnya di PR):

```bash
grep -rnE "->format\('([^']*H:i[^']*)'\)" resources/views
grep -rnE "(created_at|updated_at|submitted_at|inspected_at|published_at|finalized_at|cashier_received_at|ready_to_pay_at|approved_at|paid_at)[^}]*->format" resources/views app
grep -rn "WIB" resources/views
```

Aturan pemilahan per kolom:

| Tipe kolom | Perlakuan |
|---|---|
| `datetime` / `timestamp` (`*_at`) | `BusinessTime::format()` / `@bizdt` |
| `date` (`due_date`, `scheduled_physical_delivery_date`, `deadline`, `estimated_arrival`, `valid_from`, `invoice_date`) | `->format('d M Y')` **langsung, tanpa konversi** |
| Nilai `created_at` yang hanya ditampilkan tanggalnya (mis. `po->created_at->format('d M Y')`) | tetap `BusinessTime::format($x, 'd M Y', false)`, karena tanggal UTC bisa berbeda dari tanggal WIB |

**1.3 Titik non-Blade yang juga harus disapu**
- DataTables server-side (`addColumn(... ->format(...))`): `ClaimController`, `MaterialClaimController`, dan controller lain yang diberi `yajra`.
- Export Excel (`app/Exports/*`): tulis waktu bisnis + label zona pada header kolom, mis. `Submitted At (WIB)`.
- PDF (`resources/views/pdf/*`, `finance/vouchers/print`, receipt QR).
- Notifikasi/email: seragamkan `NewDeviceLoginNotification` dan notifikasi invoice memakai `BusinessTime::format()`.
- Payload JSON untuk frontend (Alpine/JS): kirim ISO-8601 UTC, format di sisi server bila hanya untuk tampilan.

**Acceptance Fase 1:**
- `grep -rn "}} WIB" resources/views` mengembalikan **0** hasil.
- Tidak ada `->format(` pada kolom `*_at` di view/controller tanpa melalui `BusinessTime`.
- Test tampilan T6 (lihat §5) hijau.

> **Alternatif untuk memperkecil blast radius:** custom cast Eloquent (`BusinessDateTime`) yang mengembalikan Carbon berzona WIB untuk atribut `*_at`, sehingga view lama otomatis benar tanpa diedit. Trade-off: perilaku "ajaib" (mis. `toDateString()` berubah semantik) dan perlu regresi menyeluruh. **Tidak direkomendasikan sebagai default**; pertimbangkan hanya bila jumlah view yang harus disunting terlalu besar.

---

### Fase 2 — Logika finansial & penomoran (±1–1,5 hari)

**2.1 `due_date` (L1)** — `InvoicePhysicalReceiptService::recordReceipt`

```php
$receivedAt = now(); // tetap instant UTC untuk disimpan
$dueDate = BusinessTime::toBusiness($receivedAt)
    ->startOfDay()
    ->addDays($paymentTermDays)
    ->toDateString();
```

Catatan: `payment_term_days` dihitung dari **tanggal kalender WIB saat kasir menerima berkas**. Tuliskan aturan ini di `context.md` §5.4.

**2.2 Forecast (L2)** — `PaymentForecastService`

Prinsip: bangun semua batas periode dalam zona bisnis, lalu ubah ke UTC **hanya pada saat query**. Perbandingan `betweenIncluded()` pada koleksi aman karena Carbon membandingkan instant.

```php
private function businessRef(?CarbonInterface $d = null): Carbon
{
    return Carbon::instance(BusinessTime::toBusiness($d ?? now()));
}

// getAvailableMonths(), getWeeklyForecast(), aggregateMonthlyForecast():
$ref = $this->businessRef($referenceDate);

// input string 'Y-m' dari selector bulan:
$ref = Carbon::parse($month, BusinessTime::tz())->startOfMonth();

// query:
->whereBetween('ready_to_pay_at', [
    BusinessTime::toStorage($monthStart),
    BusinessTime::toStorage($monthEnd),
])
```

Ganti semua `Carbon::now()` menjadi `$this->businessRef()`; `Carbon::instance($month)` untuk input `CarbonInterface` juga dilewatkan `businessRef()`. Seluruh `whereBetween`, baik untuk `ready_to_pay_at` maupun `approved_at`, harus memakai `toStorage()`.

**2.3 Nomor urut tahunan (L5)** — `InvoiceSubmissionService::submit`

```php
$year = BusinessTime::now()->year;
```

**2.4 Generator nomor lain (L11)**: cari dan perbaiki dengan cara yang sama.

```bash
grep -rnE "document_sequences|local_invoice_sequences|REQ/|PO/|DRP-|voucher_number" app
```

Ganti `now()->format('m')`, `now()->year`, `Carbon::now()->...` pada pembuatan nomor menjadi `BusinessTime::now()->...`.

**Acceptance Fase 2:** test T1, T4, T5 hijau; test forecast yang ada (`PaymentForecastAndReportingTest`) tetap hijau.

---

### Fase 3 — Validasi, scheduler, dan `today()` sisa (±1 hari)

**3.1 Satukan validasi jadwal (L3, L4, L6, L12)**

Ekstrak satu kelas agar submit, resubmit, dan reschedule tidak lagi terduplikasi:

```php
// app/Services/LocalInvoice/DeliveryScheduleValidator.php
final class DeliveryScheduleValidator
{
    /** @throws ValidationException */
    public function assert(string $date, string $field = 'scheduled_physical_delivery_date'): void
    {
        $sched = BusinessTime::parseDate($date);

        if ($sched->isBefore(BusinessTime::today())) {
            throw ValidationException::withMessages([$field => 'Physical document delivery schedule cannot be in the past.']);
        }
        if ($sched->dayOfWeek !== CarbonImmutable::WEDNESDAY) {
            throw ValidationException::withMessages([$field => 'Physical document delivery schedule must be on a Wednesday.']);
        }
    }
}
```

Dipakai oleh `submit()`, `resubmit()`, dan `rescheduleDelivery()` (yang saat ini tidak mengecek masa lampau).

**3.2 FormRequest (L6)** — buka `StoreLocalInvoiceRequest`; bila memakai `after_or_equal:today`, ganti:

```php
'after_or_equal:'.BusinessTime::today()->toDateString(),
```

Terapkan juga pada FormRequest resubmit dan date-picker (`min` di sisi client harus berasal dari tanggal bisnis yang dikirim server, bukan `new Date()` browser).

**3.3 Scheduler (L7)**

```php
Schedule::command('local-invoices:send-delivery-reminders')
    ->dailyAt('08:00')
    ->timezone(config('app.business_timezone'))
    ->withoutOverlapping();
```

Terapkan `->timezone(...)` pada **semua** jadwal harian di file yang sama agar jam yang tertulis selalu berarti jam WIB (`model:prune`, `exports:cleanup` juga).

**3.4 Pengingat (L8)**

```php
$targetDateStart = BusinessTime::today();
$targetDateEnd   = $targetDateStart->addDays(3);
// ...->whereBetween('scheduled_physical_delivery_date', [$targetDateStart->toDateString(), $targetDateEnd->toDateString()])
```

**3.5 `today()` sisa (L9, L10)** — `LocalInvoice` (label urgensi) dan `PurchasingController`:

```php
$due->lt(BusinessTime::today()->addDays(7));          // due_date bertipe date: bandingkan sebagai tanggal
->whereDate('deadline', '<', BusinessTime::today()->toDateString())
```

**3.6 Sapu total**

```bash
grep -rnE "\btoday\(\)|Carbon::today\(|(Carbon::)?now\(\)->(year|month|day|toDateString|startOf|endOf|format)" app
```

Setiap hasil harus diperbaiki atau diberi komentar `// biz-time:ignore <alasan>` bila memang murni instant (mis. menulis `created_at`).

**Acceptance Fase 3:** T2, T3, T7, T8 hijau; hasil grep di atas kosong (selain yang di-ignore dengan alasan).

---

### Fase 4 — Guardrail & dokumentasi (±0,5 hari)

**4.1 Test arsitektur** agar regresi tertangkap otomatis di CI:

```php
// tests/Unit/Architecture/BusinessTimeGuardTest.php
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class BusinessTimeGuardTest extends TestCase
{
    private const FORBIDDEN_PHP = [
        '/\btoday\(\)/',
        '/Carbon::today\(/',
        '/(?:\bnow\(\)|Carbon::now\(\))->(?:year|month|day|toDateString|startOfDay|endOfDay|startOfMonth|endOfMonth|format)\b/',
    ];

    public function test_app_code_uses_business_time_for_calendar_logic(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            if (str_ends_with($file->getRealPath(), 'Support/BusinessTime.php')) {
                continue;
            }
            foreach (file($file->getRealPath()) as $i => $line) {
                if (str_contains($line, 'biz-time:ignore')) {
                    continue;
                }
                foreach (self::FORBIDDEN_PHP as $re) {
                    if (preg_match($re, $line)) {
                        $violations[] = $file->getRelativePathname().':'.($i + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Gunakan BusinessTime:\n".implode("\n", $violations));
    }

    public function test_views_do_not_hardcode_timezone_label(): void
    {
        $violations = [];
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            if (preg_match('/}}\s*WIB\b/', $file->getContents())) {
                $violations[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $violations, "Label WIB hardcode ditemukan:\n".implode("\n", $violations));
    }
}
```

**4.2 Dokumentasi** — tambahkan invariant ke `context.md` §10, `CLAUDE.md`, dan `AGENTS.md` agar AI agent dan developer baru mengikuti aturan yang sama:

> **11. Business Time Invariant**
> - Database dan `app.timezone` selalu **UTC**. Jangan ubah.
> - Logika kalender (hari ini, awal/akhir bulan/minggu, tahun/bulan nomor dokumen, umur/urgensi tanggal) **wajib** memakai `App\Support\BusinessTime` (zona `app.business_timezone`, default `Asia/Jakarta`).
> - Kolom bertipe `date` tidak pernah dikonversi zona. Kolom `datetime` ditampilkan hanya lewat `BusinessTime::format()` / `@bizdt`, dengan label zona.
> - Carbon yang di-bind ke query pada kolom timestamp wajib melalui `BusinessTime::toStorage()`.
> - Dilarang: `today()`, `Carbon::today()`, `now()->year|month|toDateString|format` di `app/` (dijaga `BusinessTimeGuardTest`).

**4.3 Kebijakan bisnis** (masukkan ke kebijakan portal / SOP):
- Zona acuan resmi seluruh dokumen dan tanggal jatuh tempo: **WIB**.
- Sumber waktu resmi: timestamp server.
- Tanggal yang diinput supplier impor (ETD/ETA, validity) diperlakukan sebagai **tanggal kalender apa adanya**, tanpa konversi zona.
- `payment_term_days` dihitung dari tanggal kalender WIB penerimaan fisik oleh kasir.

**Acceptance Fase 4:** guard test hijau di CI; invariant terdokumentasi.

---

### Fase 5 (Opsional) — Koreksi data development

Karena masih development, cara paling sederhana adalah **reset/reseed** data. Bila ada data uji yang ingin dipertahankan, gunakan command satu kali untuk menghitung ulang `due_date`:

```php
// php artisan local-invoices:recompute-due-dates --dry-run
LocalInvoice::whereNotNull('cashier_received_at')->whereNotNull('payment_term_days_snapshot')
    ->each(fn ($inv) => $inv->update([
        'due_date' => BusinessTime::toBusiness($inv->cashier_received_at)
            ->startOfDay()->addDays($inv->payment_term_days_snapshot)->toDateString(),
    ]));
```

Timestamp (`*_at`) tidak perlu dikoreksi karena sudah berupa instant UTC yang benar. Yang keliru hanyalah tanggal turunan (`due_date`, nomor dokumen yang terlanjur salah tahun/bulan).

---

## 5. Matriks Test

Semua test memakai `$this->travelTo(Carbon::parse('… UTC', 'UTC'))` (atau `Carbon::setTestNow`) dan berada di `tests/Feature/Timezone/`.

| ID | Instant (UTC) | Setara WIB | Skenario | Hasil yang diharapkan | Perilaku lama |
|---|---|---|---|---|---|
| T1 | 2026-10-13 23:30 | Rabu 14 Okt 06:30 | Kasir menerima berkas, term 30 hari | `due_date = 2026-11-13` | 2026-11-12 |
| T2 | 2026-10-14 20:00 | Kamis 15 Okt 03:00 | Submit dengan jadwal Rabu 14 Okt | **Ditolak** ("tidak boleh masa lampau") | Diterima |
| T3 | 2026-10-13 18:00 | Rabu 14 Okt 01:00 | Submit dengan jadwal Rabu 14 Okt | Diterima (guard regresi) | Diterima |
| T4 | 2026-12-31 18:00 | Jumat 1 Jan 2027 01:00 | Submit invoice | `SUB-2027-00001`, `TT-2027-00001` | `…2026…` |
| T5 | 2026-09-30 20:00 | Kamis 1 Okt 03:00 | Invoice `ready_to_pay_at` pada instant ini | Muncul di **Oktober, Minggu 1** | September, Minggu 5 |
| T6 | 2026-10-14 02:00 | Rabu 14 Okt 09:00 | Render tabel invoice yang di-submit pada instant ini | Menampilkan `09:00 WIB` | `02:00 WIB` |
| T7 | — | — | Event scheduler `send-delivery-reminders` | `timezone === 'Asia/Jakarta'`, `expression = 0 8 * * *` | Berjalan 15:00 WIB |
| T8 | 2026-10-14 20:00 | Kamis 15 Okt 03:00 | Command reminder, invoice dijadwalkan Rabu 14 Okt | **Tidak** dikirim (sudah lewat) | Dikirim |
| T9 | 2026-10-13 23:30 | Rabu 14 Okt 06:30 | Reschedule ke tanggal kemarin (WIB) | Ditolak | Diterima |
| T10 | — | — | `BusinessTimeGuardTest` | Tidak ada pelanggaran | — |

Test yang sudah ada dan wajib tetap hijau:

```bash
php artisan test --filter=LocalInvoiceTest
php artisan test --filter=CashierReceiptAndExpiryTest
php artisan test --filter=PaymentForecastAndReportingTest
php artisan test --filter=SendLocalInvoiceDeliveryRemindersTest
php artisan test --filter=InvoiceDeliveryScheduleValidationTest
php artisan test --filter=UnifiedPaymentEngineTest
```

> **Catatan:** beberapa test lama memakai `Carbon::now()->next(Carbon::WEDNESDAY)` sebagai jadwal. Jalankan test pada beberapa jam berbeda (atau kunci waktu dengan `travelTo`) agar tidak flaky di sekitar jendela 00:00–06:59 WIB.

---

## 6. Urutan PR

| PR | Isi | Risiko | Bisa dirilis terpisah |
|---|---|---|---|
| **PR-1** | Fase 0: config, `BusinessTime`, unit test, verifikasi DB/cron | Sangat rendah (tanpa perubahan perilaku) | Ya |
| **PR-2** | Fase 1.1 + 1.2 untuk layar utama: local invoice, receipt, verify, PO show, finance invoice | Rendah | Ya |
| **PR-3** | Fase 1.3: DataTables, export, PDF, notifikasi | Sedang (banyak file) | Ya |
| **PR-4** | Fase 2: `due_date`, forecast, penomoran | Sedang (menyentuh angka finansial) | Ya |
| **PR-5** | Fase 3: validator jadwal, FormRequest, scheduler, `today()` sisa | Rendah–sedang | Ya |
| **PR-6** | Fase 4: guard test + dokumentasi (+ Fase 5 bila perlu) | Rendah | Setelah PR-2..5 |

Rekomendasi: PR-2 dan PR-4 diprioritaskan lebih dulu (PR-2 untuk kebenaran tampilan yang selalu terlihat; PR-4 untuk angka finansial).

---

## 7. Risiko & Mitigasi

| Risiko | Mitigasi |
|---|---|
| Carbon berzona WIB di-bind langsung ke query → hasil salah 7 jam | Aturan D-5 dan helper `toStorage()`; ditutup test T5 |
| Kolom `date` ikut dikonversi → tanggal bergeser | Aturan D-3; tabel pemilahan kolom di Fase 1.2; review checklist di PR |
| `travelTo()` tidak memengaruhi `CarbonImmutable::now()` | `BusinessTime::now()` memakai `now()->toImmutable()`; ditutup unit test |
| Sapuan view terlewat | Grep inventarisasi dilampirkan pada PR + guard test label WIB |
| Test lama flaky pada jendela 00:00–06:59 WIB | Kunci waktu dengan `travelTo` |
| Hosting (cPanel) memakai timezone server berbeda | Tidak berpengaruh ke Laravel (memakai `app.timezone`), tetapi tetap verifikasi cron dan `@@session.time_zone` di Fase 0.5 |
| Perubahan sudah produksi kelak memerlukan migrasi data | Kerjakan sekarang selagi development, dan gunakan Fase 5 untuk koreksi `due_date` bila ada |

---

## 8. Definition of Done

- [ ] `BusinessTime`, config, `.env.example` tersedia dan diuji.
- [ ] Tidak ada label `WIB` hardcode di view (`grep "}} WIB"` = 0).
- [ ] Semua kolom `*_at` yang tampil ke user melalui `BusinessTime::format()`/`@bizdt`.
- [ ] `due_date`, forecast, penomoran (invoice lokal, PR/PO, DRP, voucher) memakai kalender WIB.
- [ ] Validasi jadwal terpusat di `DeliveryScheduleValidator` (submit, resubmit, reschedule, FormRequest).
- [ ] Semua jadwal scheduler eksplisit `->timezone(config('app.business_timezone'))`.
- [ ] Test T1–T10 hijau, dan test eksisting tidak regresi.
- [ ] `BusinessTimeGuardTest` aktif di CI.
- [ ] Invariant terdokumentasi di `context.md`, `CLAUDE.md`, `AGENTS.md`, dan kebijakan bisnis.

---

## 9. Poin yang Perlu Dikonfirmasi Sebelum Mulai

1. **D-1:** setuju dengan storage UTC + business timezone (rekomendasi), atau memilih alternatif `app.timezone = Asia/Jakarta`?
2. Apakah ada middleware/service provider yang mengubah timezone per request? (Belum ditemukan, tetapi belum disapu menyeluruh.)
3. Isi `StoreLocalInvoiceRequest` (rule `after_or_equal`) dan generator nomor `document_sequences`/DRP/voucher, karena keduanya masih berstatus 🔍.
4. Apakah invoice yang "missed Wednesday" memang direncanakan expire otomatis lewat job? Bila ya, tambahkan pekerjaan terpisah dengan aturan cut-off jam kasir.