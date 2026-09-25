# ADASI Supplier Portal — Implementation Plan

## Finance Dashboard — Ready-to-Pay Payment Forecasting & Accumulation

Repository:

```text
https://github.com/poyipoy/supplierportal
```

Scope:

```text
Finance Dashboard
Payment Forecast
Ready-to-Pay accumulation
Weekly / Monthly aggregation
Dashboard metric & visualization
```

---

# 1. Objective

Menyempurnakan fitur forecasting pada Finance Dashboard agar mengikuti definisi bisnis berikut:

> Invoice mulai masuk ke forecasting ketika statusnya menjadi `READY_TO_PAY`. Nilai invoice tersebut dicatat berdasarkan `ready_to_pay_at`, kemudian dikelompokkan per minggu atau per bulan dan diakumulasikan secara cumulative.

Flow utama:

```text
Invoice
   ↓
READY_TO_PAY
   ↓
ready_to_pay_at
   ↓
Net Payable
   ↓
Weekly / Monthly Bucket
   ↓
Period Amount
   ↓
Cumulative Amount
   ↓
Finance Dashboard
```

Forecasting ini bukan predictive forecasting atau machine learning.

Metric ini merupakan **payment pipeline accumulation** berdasarkan invoice yang sudah lolos hingga `READY_TO_PAY`.

---

# 2. Current State

Repository saat ini sudah memiliki:

```text
app/Services/Payment/PaymentForecastService.php
app/Http/Controllers/Finance/FinanceDashboardController.php
resources/views/finance/dashboard.blade.php
tests/Feature/PaymentForecastAndReportingTest.php
```

Existing `PaymentForecastService` saat ini mencampur beberapa sumber:

```text
Active DRP
READY_TO_PAY Invoice
GA Claim
due_date
transfer_date
```

dan menggunakan precedence antara DRP dan due date.

Definisi tersebut perlu disesuaikan dengan requirement baru.

`LocalInvoice` sudah mempunyai:

```text
ready_to_pay_at
```

dan workflow verification mengisinya ketika invoice berubah menjadi:

```text
READY_TO_PAY
```

Karena itu `ready_to_pay_at` menjadi event timestamp utama forecasting baru.

---

# 3. Business Definition

## 3.1 Forecast Entry Event

Invoice masuk forecast ketika:

```text
status = READY_TO_PAY
```

dengan event:

```text
ready_to_pay_at
```

Contoh:

```text
Invoice A
READY_TO_PAY
21/09/2026
Rp100.000.000
```

maka invoice tersebut masuk bucket minggu yang mencakup 21/09/2026.

---

# 4. Period Amount

Nilai setiap periode:

```text
Period Amount
=
SUM(Net Payable invoice
    yang ready_to_pay_at berada dalam periode tersebut)
```

Contoh:

| Periode | Invoice | Nilai Periode |
| ------- | ------: | ------------: |
| Week 1  |       5 | Rp100.000.000 |
| Week 2  |       3 |  Rp75.000.000 |
| Week 3  |       4 | Rp120.000.000 |

---

# 5. Cumulative Amount

Nilai cumulative:

```text
Cumulative[n]
=
Cumulative[n-1] + Period[n]
```

Contoh:

| Periode | Nilai Periode | Akumulasi |
| ------- | ------------: | --------: |
| Week 1  |      Rp100 jt |  Rp100 jt |
| Week 2  |       Rp75 jt |  Rp175 jt |
| Week 3  |      Rp120 jt |  Rp295 jt |

Cumulative amount menjadi salah satu metric utama pada dashboard.

---

# 6. Historical vs Current Metric

Keduanya harus dipisahkan.

## Historical Forecast

Menjawab:

> Berapa nilai invoice yang sudah masuk payment pipeline pada setiap minggu/bulan?

Gunakan:

```text
ready_to_pay_at
```

Invoice yang kemudian menjadi:

```text
DRP
PAID
COMPLETED
```

tetap tercatat dalam historical accumulation berdasarkan tanggal saat pertama kali Ready to Pay.

## Current Ready-to-Pay

Menjawab:

> Berapa invoice yang saat ini masih berada di antrean Ready to Pay?

Metric ini hanya menghitung invoice yang masih outstanding.

Contoh:

```text
Ready to Pay Saat Ini

12 Invoice
Rp425.000.000
```

Historical cumulative tidak boleh disamakan dengan current outstanding amount.

---

# 7. Anti Double Counting

Invoice hanya menghasilkan satu event forecast:

```text
READY_TO_PAY
```

Contoh:

```text
21 Sep
Invoice → READY_TO_PAY
        ↓
masuk Week 1

25 Sep
Invoice → DRP

30 Sep
Invoice → PAID
```

Forecast historical tetap:

```text
Week 1 = invoice tersebut
```

Tidak boleh muncul lagi karena:

```text
DRP
PAID
```

Tidak boleh memakai DRP sebagai event forecast kedua.

---

# 8. Supplier Invoice Scope

Metric baru difokuskan pada:

```text
LocalInvoice
```

Jangan mencampurkan:

```text
GaClaim
```

ke metric Ready-to-Pay invoice ini.

GA dapat memiliki reporting sendiri jika dibutuhkan kemudian.

---

# 9. Amount Source

Nominal harus mengikuti basis nominal yang sama dengan payment engine.

Gunakan authoritative net payable yang digunakan ketika invoice masuk DRP.

Current implementation memiliki:

```text
LocalInvoiceVerification::netPayableExact()
```

sebagai basis authoritative net payable.

Karena itu:

```text
Forecast Amount
=
Authoritative Net Payable
```

Jangan membuat formula forecasting berbeda.

Jangan menggunakan:

```text
invoice_amount saja
invoice_amount + tax_amount secara manual
PaymentBatch.total_subtotal
PaymentBatch.total_net_amount
```

sebagai formula alternatif.

Tujuannya:

```text
Forecast
    =
basis nominal payment engine
```

---

# 10. Weekly Aggregation

Weekly bucket menggunakan:

```text
ready_to_pay_at
```

Minggu:

```text
Monday → Sunday
```

Gunakan timezone aplikasi Laravel.

Contoh:

```text
21 Sep 2026 – 27 Sep 2026
```

Label dashboard:

```text
Minggu 39
21 Sep – 27 Sep 2026
```

Period yang tidak memiliki invoice tetap harus tersedia:

```text
count = 0
period_amount = 0
cumulative = previous cumulative
```

Tujuannya agar grafik tidak kehilangan periode kosong.

---

# 11. Monthly Aggregation

Monthly bucket menggunakan:

```text
ready_to_pay_at
```

Contoh:

```text
September 2026
October 2026
November 2026
```

Gunakan Indonesian-first month labels.

Period kosong tetap harus tersedia.

---

# 12. Reporting Window

Recommended default:

```text
Weekly  = last 12 weeks
Monthly = last 6 months
```

Current week/month harus ikut.

Window harus bounded.

Jangan query all-time data hanya untuk kebutuhan dashboard.

Jika kemudian business meminta window berbeda, ubah sebagai configuration/parameter yang jelas, bukan membuat query baru yang terpisah.

---

# 13. Current Ready-to-Pay Metric

Tambahkan summary:

```text
Ready to Pay Saat Ini
```

Minimal:

```text
Invoice Count
Current Net Payable
```

Contoh:

```text
Ready to Pay Saat Ini
12 invoice
Rp425.000.000
```

Gunakan invoice yang saat ini masih berada pada payment pipeline Ready-to-Pay.

Invoice yang sudah:

```text
PAID
COMPLETED
```

tidak masuk metric current outstanding.

---

# 14. Dashboard Structure

Forecast harus menjadi satu **coherent dashboard module**, bukan tambahan banyak KPI card yang terpisah.

Recommended layout:

```text
┌────────────────────────────────────────────────────────────┐
│ PAYMENT FORECAST                                           │
│ Akumulasi Invoice Ready to Pay                             │
│                                                            │
│  Total Akumulasi        Ready to Pay Saat Ini              │
│  Rp1.245.000.000        12 invoice                         │
│                         Rp425.000.000                      │
│                                                            │
│  Invoice Masuk Periode                                      │
│  8 invoice                                                  │
│                                                            │
│  [ Mingguan ] [ Bulanan ]                                   │
│                                                            │
│  ┌────────────────────────────────────────────────────────┐ │
│  │        Period Amount + Cumulative Chart               │ │
│  └────────────────────────────────────────────────────────┘ │
│                                                            │
│  Periode | Invoice | Nilai Periode | Akumulasi             │
│  --------------------------------------------------------- │
│  Week 1  | 5       | Rp100 jt      | Rp100 jt              │
│  Week 2  | 3       | Rp75 jt       | Rp175 jt              │
│  Week 3  | 4       | Rp120 jt      | Rp295 jt              │
└────────────────────────────────────────────────────────────┘
```

Hierarchy:

```text
1. Total cumulative
2. Current Ready-to-Pay
3. Period movement
4. Cumulative trend
5. Detail table
```

---

# 15. Main Metric

Primary metric:

```text
Total Akumulasi Periode
```

Value:

```text
Rp xxx.xxx.xxx
```

Definition:

```text
Cumulative amount pada periode terakhir dalam selected window.
```

Misalnya:

```text
Total Akumulasi Periode
Rp 1.245.000.000
```

Jangan menampilkan total historical all-time jika window menggunakan rolling period.

---

# 16. Supporting Metrics

Secondary metrics:

```text
Ready to Pay Saat Ini
```

dan:

```text
Invoice Masuk Periode
```

Contoh:

```text
Ready to Pay Saat Ini
12 invoice
Rp425.000.000

Invoice Masuk Periode
8 invoice
```

Supporting metrics harus lebih subordinate dibanding cumulative metric.

---

# 17. Weekly / Monthly Toggle

Gunakan:

```text
[ Mingguan ] [ Bulanan ]
```

Default:

```text
Mingguan
```

Ketika user switch:

```text
chart
table
period labels
period count
period amount
cumulative
```

semuanya mengikuti mode baru.

Tidak perlu membuat halaman baru.

---

# 18. Chart

Gunakan Chart.js existing project.

Recommended:

```text
Bar
=
Period Amount

Line
=
Cumulative Amount
```

Tujuan:

```text
Bar
→ berapa yang masuk pada tiap periode?

Line
→ bagaimana akumulasi berkembang?
```

Tidak menggunakan:

```text
pie chart
donut chart
gauge
```

karena data merupakan time-series, bukan categorical distribution.

---

# 19. Chart Tooltip

Tooltip:

```text
Periode
Invoice
Nilai Periode
Akumulasi
```

Contoh:

```text
Minggu 39
Invoice: 5
Nilai Periode: Rp100.000.000
Akumulasi: Rp295.000.000
```

Gunakan formatting Rupiah existing project.

---

# 20. Table

Tambahkan table sebagai detail/verification layer:

| Periode | Invoice Ready to Pay | Nilai Periode | Akumulasi |
| ------- | -------------------: | ------------: | --------: |
| Week 1  |                    5 |      Rp100 jt |  Rp100 jt |
| Week 2  |                    3 |       Rp75 jt |  Rp175 jt |
| Week 3  |                    4 |      Rp120 jt |  Rp295 jt |

Table harus menggunakan dataset yang sama dengan chart.

Jangan menghitung ulang data di Blade.

---

# 21. Accessibility

Chart tidak boleh menjadi satu-satunya tempat data tersedia.

Implement:

```text
aria-label
semantic heading
accessible toggle
tabular data fallback
keyboard-operable controls
```

Contoh:

```text
Grafik akumulasi invoice Ready to Pay per minggu.
```

---

# 22. Service Design

Pertahankan:

```text
PaymentForecastService
```

sebagai owner business calculation.

Recommended methods:

```php
getWeeklyForecast()
getMonthlyForecast()
getCurrentReadyToPaySummary()
```

Jika current method names sudah dipakai controller/API, pertahankan backward compatibility.

Jangan memindahkan calculation ke controller atau Blade.

---

# 23. Canonical Aggregation

Weekly dan monthly harus menggunakan satu sumber data dan satu definisi:

```text
READY_TO_PAY invoices
        ↓
ready_to_pay_at
        ↓
authoritative net payable
```

Kemudian:

```text
        ├── weekly buckets
        └── monthly buckets
```

Jangan membuat dua formula terpisah.

---

# 24. Query Strategy

Forecast adalah dashboard query sehingga harus efisien.

Gunakan bounded query berdasarkan:

```text
ready_to_pay_at
```

Jangan load seluruh `LocalInvoice` ke PHP bila aggregation dapat dilakukan langsung di database.

Konsep:

```text
WHERE ready_to_pay_at IS NOT NULL
AND ready_to_pay_at BETWEEN from AND to
```

Kemudian lakukan period grouping dan cumulative calculation pada result set kecil.

Current summary dapat menjadi query terpisah karena definisinya berbeda.

---

# 25. Legacy Data Audit

Sebelum implementasi, audit kombinasi:

```text
READY_TO_PAY + ready_to_pay_at
PAID + ready_to_pay_at
COMPLETED + ready_to_pay_at
APPROVED + ready_to_pay_at
PAYMENT_SCHEDULED + ready_to_pay_at
```

Pastikan tidak ada legacy state yang membuat timestamp Ready-to-Pay tidak valid.

Jangan melakukan data migration otomatis hanya demi dashboard ini.

Jika ditemukan data historis anomali:

```text
document handling
```

dan jangan silent correction.

---

# 26. Controller

`FinanceDashboardController` tetap tipis.

Tanggung jawab:

```text
call PaymentForecastService
prepare dashboard data
return view
```

Tidak boleh melakukan:

```text
weekly grouping
monthly grouping
cumulative calculation
money calculation
```

di controller.

---

# 27. Existing JSON Forecast Endpoint

Route existing:

```text
finance.forecast
```

tetap diaudit.

Pastikan endpoint tersebut menggunakan canonical forecast service yang sama.

Sebelum mengubah response structure:

```text
search consumers
```

Jika tidak ada consumer lain, response dapat diselaraskan dengan struktur baru.

Jangan menghapus route tanpa evidence bahwa memang aman.

---

# 28. Performance

Target:

```text
no N+1
bounded query
minimal model hydration
single calculation source
```

Jangan:

```text
query per invoice
query per week
query per month
```

Bila weekly dan monthly dipanggil pada request dashboard yang sama, hindari melakukan scan tabel besar berulang jika dapat dioptimalkan dengan pendekatan yang sederhana dan tetap maintainable.

Caching bukan requirement.

Tambahkan cache hanya bila profiling menunjukkan query memang material dan existing caching pattern cocok.

---

# 29. UI Design Rules

Gunakan existing ADASI design system.

Reuse:

```text
<x-ui.card>
<x-ui.metric-card>
existing Chart.js
existing icons
existing spacing/tokens
```

Jangan menambahkan:

```text
gradient
glassmorphism
oversized hero
decorative dashboard
large KPI wall
new frontend framework
```

Finance Dashboard harus tetap terlihat seperti operational enterprise dashboard.

---

# 30. Tests

Update:

```text
tests/Feature/PaymentForecastAndReportingTest.php
```

Test lama yang memverifikasi precedence:

```text
DRP transfer date
vs
READY_TO_PAY due date
vs
GA
```

harus dievaluasi ulang karena definisi business sudah berubah.

Test behavior baru:

### Weekly

```text
invoice A → Week 1
invoice B → Week 2
invoice C → Week 3
```

assert:

```text
count
period_amount
```

### Cumulative

assert:

```text
W1 cumulative = W1
W2 cumulative = W1 + W2
W3 cumulative = W1 + W2 + W3
```

### Monthly

Lakukan hal yang sama untuk month bucket.

### Historical Paid

Invoice:

```text
ready_to_pay_at = historical date
status = PAID
```

tetap masuk historical accumulation.

### Current Ready-to-Pay

Invoice:

```text
status = READY_TO_PAY
```

masuk current summary.

Invoice:

```text
status = PAID
```

tidak masuk current summary.

### Due Date Independence

Buat invoice dengan:

```text
same due_date
different ready_to_pay_at
```

Pastikan bucket mengikuti:

```text
ready_to_pay_at
```

### DRP Double Counting

Test:

```text
READY_TO_PAY
→ DRP
→ PAID
```

Invoice tetap dihitung satu kali pada historical Ready-to-Pay bucket.

### Under Verification

Tidak memiliki valid Ready-to-Pay event.

Harus excluded.

### Period Boundary

Test:

```text
Sunday → Monday
month end → next month
year end → next year
```

### Empty Period

Period tanpa invoice:

```text
count = 0
amount = 0
cumulative = previous value
```

---

# 31. Dashboard View Tests

Verify dashboard exposes:

```text
Payment Forecast
Akumulasi Invoice Ready to Pay
Total Akumulasi
Ready to Pay Saat Ini
Invoice Masuk Periode
Mingguan
Bulanan
Periode
Nilai Periode
Akumulasi
```

Jangan membuat test yang bergantung pada struktur HTML yang terlalu rapuh.

---

# 32. Chart Data Contract

Verify:

```text
labels.count == period_amount.count
labels.count == cumulative.count
```

dan:

```text
cumulative[n] >= cumulative[n-1]
```

untuk nominal non-negative.

Tidak perlu testing internal behavior Chart.js.

---

# 33. Security

Finance dashboard existing route:

```text
auth
role:finance,admin
```

tetap dipertahankan.

Forecast read-only.

Tidak boleh:

```text
update invoice
update payment
update DRP
```

melalui forecast.

Tidak membuat endpoint baru yang membuka forecast kepada role lain.

---

# 34. No Browser QA

Jangan menggunakan Browser QA.

Verifikasi UI menggunakan:

```text
Blade render tests
view assertions
chart data contract
npm build
```

Fokus utama:

```text
business correctness
query correctness
view correctness
```

---

# 35. Verification

Run focused test:

```bash
php artisan test --filter=PaymentForecastAndReportingTest
```

Kemudian relevant Finance/payment tests.

Karena Blade/Chart.js disentuh:

```bash
npm run build
```

Formatting:

```bash
vendor/bin/pint --test
```

Kemudian:

```bash
composer test
```

Ikuti verification guidance pada `CLAUDE.md`.

Jangan menggunakan stale:

```text
phpunit-results.log
test-results.log
```

sebagai bukti keberhasilan.

---

# 36. Regression Requirements

Tidak boleh mengubah behavior:

```text
Invoice Verification
READY_TO_PAY transition
DRP creation
PaymentGroup calculation
Voucher
DRP Paid
Settlement
Overpayment
```

Feature ini adalah:

```text
reporting / analytics change
```

bukan payment workflow change.

---

# 37. Acceptance Criteria

```text
✓ Forecast dimulai saat invoice READY_TO_PAY
✓ ready_to_pay_at menjadi event timestamp
✓ Weekly aggregation benar
✓ Monthly aggregation benar
✓ Period amount benar
✓ Cumulative amount benar
✓ Current Ready-to-Pay summary tersedia
✓ Historical paid invoices tetap tercatat
✓ DRP tidak menghasilkan double counting
✓ Due date bukan bucket utama
✓ GA tidak tercampur dalam metric invoice
✓ Empty periods tetap terlihat
✓ Weekly/Monthly toggle tersedia
✓ Chart menggunakan Period + Cumulative
✓ Table mencerminkan data chart
✓ Dashboard menggunakan existing design system
✓ Tidak ada KPI wall berlebihan
✓ Tidak menggunakan Browser QA
✓ Tidak mengubah payment workflow
✓ Focused tests pass
✓ Finance/payment regression pass
✓ npm build pass
✓ Full verification selesai atau pre-existing failures dipisahkan
```

---

# 38. Final Dashboard Concept

Struktur final:

```text
Finance AP Dashboard
│
├── Existing KPI Summary
│
├── Payment Forecast
│   │
│   ├── Total Akumulasi Periode
│   │
│   ├── Ready to Pay Saat Ini
│   │
│   ├── Invoice Masuk Periode
│   │
│   ├── [ Mingguan ] [ Bulanan ]
│   │
│   ├── Chart
│   │     ├── Period Amount
│   │     └── Cumulative Amount
│   │
│   └── Detail Table
│         ├── Periode
│         ├── Invoice
│         ├── Nilai Periode
│         └── Akumulasi
│
├── Recent DRP
│
└── Recent Invoices
```

---

# 39. Non-Negotiable Business Rule

```text
Invoice mencapai READY_TO_PAY
        ↓
ready_to_pay_at dicatat
        ↓
nominal masuk forecast
        ↓
bucket berdasarkan minggu/bulan
        ↓
period amount
        ↓
cumulative amount
```

Satu invoice:

```text
READY_TO_PAY → DRP → PAID
```

tetap menghasilkan **satu historical forecasting event**.

Forecast menjawab:

> “Berapa nilai invoice yang sudah masuk payment pipeline dari minggu/bulan ke minggu/bulan?”

Bukan:

> “Berapa invoice yang akan jatuh tempo?”

Dan bukan:

> “Berapa yang diperkirakan akan dibayar berdasarkan DRP?”
