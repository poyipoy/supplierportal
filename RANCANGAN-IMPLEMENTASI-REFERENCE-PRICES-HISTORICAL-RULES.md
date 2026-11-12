# Rancangan Implementasi — Reference Prices berbasis Rules & Historical PO

**Produk:** ADASI Supplier Portal  
**Repository:** `poyipoy/supplierportal`  
**Branch acuan audit:** `master`  
**Commit acuan:** `79b56a60149a44140ff3e810ad35d2de5a0ba449`  
**Tanggal audit:** 11 November 2026  
**Sumber kategori:** `Kategori Range.xlsx` — sheet `Kategori`  
**Status dokumen:** Implementation Design — belum ada perubahan kode/database  
**Tujuan dokumen:** Menjadi satu acuan bisnis dan teknis untuk breakdown, implementasi, migrasi, dan acceptance testing.

---

## Daftar Isi

1. Ringkasan Eksekutif
2. Tujuan dan Prinsip Desain
3. Keputusan Bisnis yang Sudah Final
4. Scope dan Non-Scope
5. Audit Kondisi Repository Saat Ini
6. Definisi Domain dan Terminologi
7. Aturan Bisnis Reference Price
8. Spesifikasi Import Excel dan Versioning Rules
9. Alur Proses End-to-End
10. Desain Perhitungan Historical Reference Price
11. Desain Tampilan dan UX
12. Desain Arsitektur Aplikasi
13. Desain Database dan Migration Plan
14. Integrasi dengan PO Finalization dan Immutable Snapshot
15. Authorization, Security, dan Auditability
16. Performance, Consistency, dan Observability
17. Strategi Migrasi dan Rollout
18. Implementation Breakdown per Tahap
19. Acceptance Criteria
20. Test Plan
21. Risiko dan Mitigasi
22. Preflight Checklist untuk Implementasi
23. Definition of Done
24. Referensi Repository

---

# 1. Ringkasan Eksekutif

Reference Prices saat ini merupakan **master harga manual**. Admin/Purchasing memasukkan material, shape, rentang dimensi, currency, harga per KG, masa berlaku, dan catatan. Sistem kemudian mencocokkan harga manual tersebut dengan dimensi penawaran supplier.

Kebutuhan baru mengubah pendekatan tersebut menjadi **Rules-Based Historical Price Benchmark**:

1. Admin/Purchasing menetapkan Rules kategori rentang dimensi berdasarkan Excel.
2. Sistem membaca transaksi Purchase Order (PO) historis yang memenuhi syarat.
3. Setiap transaksi historis diklasifikasikan berdasarkan Material Master, Shape, dan Rule kategori dimensi.
4. Sistem menghitung Weighted Average Price per KG secara terpisah untuk setiap tahun kalender.
5. Benchmark utama adalah hasil tahun berjalan (Year-to-Date/YTD); tahun-tahun sebelumnya menjadi informasi histori.
6. Reference Price digunakan pada Review Quotation, Inter-Supplier Comparison, dan Historical Price. Untuk tab Price Comparison, hanya Inter-Supplier dan Historical yang termasuk scope; Vs-Best tidak termasuk.
7. Selama proses belum menjadi PO, hasil kalkulasi bersifat dinamis. Ketika PO difinalisasi, hasil yang digunakan disimpan sebagai snapshot immutable.
8. Data Reference Price manual lama tetap dipertahankan sebagai Legacy Archive dan tidak dipakai dalam benchmark otomatis.

**Rekomendasi arsitektur utama:** gunakan ulang `PurchaseHistoryPriceService` dan `AnnualExchangeRateResolver` sebagai fondasi, tetapi tambahkan resolver Rules dan service benchmark yang memiliki kontrak hasil sendiri. Jangan memperlakukan hasil otomatis sebagai baris harga manual baru pada `material_reference_prices`.

---

# 2. Tujuan dan Prinsip Desain

## 2.1 Tujuan

- Menghilangkan ketergantungan pada input harga referensi manual sebagai sumber benchmark utama.
- Mengelompokkan histori pembelian berdasarkan kategori dimensi yang konsisten.
- Menyajikan benchmark yang dapat ditelusuri sampai PO dan item transaksi sumber.
- Membuat hasil per tahun sehingga perubahan harga dari waktu ke waktu dapat dianalisis.
- Menjaga konsistensi antara harga yang ditampilkan sebelum PO dan harga yang dibekukan ketika PO dibuat.
- Mempertahankan data, route, dan snapshot lama tanpa merusak kompatibilitas.

## 2.2 Prinsip Desain

1. **Rules tidak menyimpan harga.** Rules hanya mengatur klasifikasi dimensi.
2. **PO adalah sumber harga aktual.** Quotation yang belum menjadi PO tidak boleh menjadi sumber benchmark utama.
3. **Material Master adalah identitas utama material.** Jangan menggabungkan material hanya karena nama teksnya mirip.
4. **PR adalah basis benchmark utama untuk Inquiry aktif.** Dimensi supplier dapat menghasilkan benchmark alternatif jika masuk kategori Rule yang berbeda.
5. **Weighted Average dihitung per tahun kalender.** Jangan mencampur transaksi antar-tahun menjadi satu average lintas tahun.
6. **Tahun berjalan menjadi benchmark utama.** Tahun sebelumnya tetap tersedia sebagai histori.
7. **Live calculation bersifat read-only.** Tidak membuat record kalkulasi permanen setiap kali halaman dibuka.
8. **Snapshot PO bersifat immutable.** Perubahan PO historis atau Rules berikutnya tidak boleh mengubah hasil snapshot PO yang sudah dibuat.
9. **Satu kalkulasi harus bisa dijelaskan.** Simpan metadata, versi Rule, parameter, angka agregat, dan sumber transaksi yang membentuk snapshot.
10. **Tidak boleh membocorkan data pembelian internal kepada Supplier.** Pembatasan harus berlaku pada UI, route, response JSON, export, dan snapshot yang dapat diakses Supplier.

---

# 3. Keputusan Bisnis yang Sudah Final

Bagian ini menjadi kontrak kebutuhan. Keputusan berikut berasal dari jawaban user pada interview Reference Prices.

| ID | Area | Keputusan final |
|---|---|---|
| RP-01 | Cakupan Rule | Semua Shape menggunakan 11 kategori range yang sama. |
| RP-02 | Dimensi desimal | Nilai dimensi dibulatkan ke atas sebelum pencocokan kategori. |
| RP-03 | Pengelolaan Rule | Rules dapat dikelola melalui aplikasi dan mendukung import Excel. |
| RP-04 | Sumber historis | PO yang sudah difinalisasi/diterbitkan dapat digunakan, dengan pengecualian berdasarkan status dan kondisi transaksi. |
| RP-05 | Matching histori | Benchmark utama memakai material dan kategori range yang sama. Kategori lain boleh ditampilkan sebagai informasi pembanding, tetapi tidak dicampurkan ke perhitungan utama. |
| RP-06 | Metode harga | Weighted Average berdasarkan KG. |
| RP-07 | Periode histori | Perhitungan dikelompokkan per tahun; setiap tahun memiliki average sendiri. |
| RP-08 | Lokasi tampilan | Reference Price dipakai pada Review Quotation dan Price Comparison. |
| RP-09 | Basis dimensi | Dimensi PR adalah benchmark utama. Dimensi yang ditawarkan Supplier dapat menghasilkan benchmark tambahan bila berbeda kategori. |
| RP-10 | Waktu pembekuan | Hasil tetap dinamis selama proses berjalan dan dibekukan saat PO difinalisasi. |
| RP-11 | Benchmark utama | Tahun berjalan/YTD menjadi benchmark utama. Tahun sebelumnya hanya histori pembanding. |
| RP-12 | Mode import | Import menyediakan mode Replace dan Merge. |
| RP-13 | Cakupan Price Comparison | Tampilkan pada Inter-Supplier Comparison dan Historical Price saja. Vs-Best tidak termasuk. |
| RP-14 | Finalisasi evaluasi | Tidak diperlukan tombol Finalize Evaluation. Snapshot dibuat ketika PO difinalisasi. |
| RP-15 | Data manual lama | Tetap disimpan sebagai Legacy dan tidak digunakan lagi sebagai sumber benchmark otomatis. |

## 3.1 Interpretasi cakupan tampilan

Keputusan RP-08 menyebut Review Quotation dan Price Comparison. Keputusan RP-13 kemudian mempersempit bagian Price Comparison menjadi Inter-Supplier Comparison dan Historical Price. Oleh karena itu rancangan ini menetapkan lokasi tampilan sebagai:

- **Review Quotation:** tetap menampilkan Reference Price per item quotation.
- **Price Comparison → Inter-Supplier:** menampilkan benchmark per PR item dan alternatif berdasarkan spesifikasi supplier bila berbeda kategori.
- **Price Comparison → Historical:** menampilkan tren benchmark tahunan dan histori sumber.
- **Price Comparison → Vs-Best:** tidak menampilkan Reference Price baru.
- **Create PR:** tidak menampilkan Reference Price pada form Create PR.

Interpretasi ini mempertahankan jawaban RP-08 sekaligus menerapkan pembatasan RP-13 pada tab Price Comparison.

## 3.2 Rekomendasi teknis yang bukan keputusan eksplisit user

Hal berikut adalah rekomendasi desain untuk menjaga konsistensi. Verifikasi implementasinya pada tahap preflight bila kondisi repository/data aktual berbeda.

| ID | Rekomendasi | Alasan |
|---|---|---|
| REC-01 | Gunakan semua Supplier sebagai populasi benchmark perusahaan. | Benchmark harus menjadi acuan perusahaan, bukan benchmark supplier tertentu. |
| REC-02 | Tentukan tahun transaksi dari tanggal PO (`purchase_orders.created_at`) menurut business timezone. | Selaras dengan histori PO yang sudah ada. |
| REC-03 | Gunakan Annual Exchange Rate berdasarkan tahun PO untuk normalisasi currency. | Selaras dengan `AnnualExchangeRateResolver`. |
| REC-04 | Jika tahun berjalan belum memiliki histori yang eligible, tampilkan Not Available; jangan otomatis menggantinya dengan harga tahun sebelumnya. | Menghindari harga lama dianggap harga saat ini. |
| REC-05 | Untuk transaksi historis, gunakan dimensi penawaran yang menjadi spesifikasi barang pada PO; fallback ke dimensi PR untuk data legacy yang tidak memiliki dimensi penawaran. | Mendekati spesifikasi pembelian yang benar-benar dipilih. |
| REC-06 | Gunakan `material_master_id` sebagai matching key. Data legacy yang belum termapping harus direkonsiliasi, bukan dicocokkan longgar berdasarkan `material_name`. | Mencegah penggabungan material berbeda. |
| REC-07 | Data transaksi dengan kurs tahunan yang tidak tersedia dikecualikan dari benchmark target currency dan dilaporkan. | Tidak boleh mengasumsikan kurs 1 atau 0. |
| REC-08 | Benchmark Historical Price tetap company-wide walaupun user memilih filter Supplier untuk melihat histori supplier tertentu. | Memisahkan benchmark perusahaan dari analisis satu supplier. |

---

# 4. Scope dan Non-Scope

## 4.1 In Scope

- Reference Price Rules dan versioning konfigurasi.
- Import Excel menggunakan format workbook kategori yang disediakan.
- Preview import dengan mode Replace dan Merge.
- CRUD/maintenance Rules melalui aplikasi.
- Matching kategori berdasarkan Shape dan dimensi kunci.
- Historical PO eligibility dan material matching.
- Weighted Average tahunan, tahun berjalan YTD sebagai benchmark utama.
- Annual currency conversion.
- Informasi harga terendah, tertinggi, harga terakhir, jumlah PO, jumlah baris, dan total KG.
- Benchmark utama berdasarkan dimensi PR.
- Benchmark alternatif berdasarkan dimensi Supplier apabila kategori Rule berbeda.
- Integrasi Review Quotation, Inter-Supplier Comparison, dan Historical Price.
- Immutable PO benchmark snapshot saat PO difinalisasi.
- Detail sumber transaksi untuk live benchmark dan snapshot.
- Legacy Archive untuk master harga manual lama.
- Perbaikan authorization Admin/Purchasing sesuai policy.
- Regression test untuk Supplier data isolation.

## 4.2 Out of Scope

- Menampilkan Reference Price pada Create PR.
- Menampilkan Reference Price pada Vs-Best tab.
- Menggunakan quotation yang belum menjadi PO sebagai sumber benchmark utama.
- Mengubah harga PO atau quotation historis.
- Mengubah atau menghapus snapshot PO lama.
- Mengubah hasil benchmark lama yang sudah dibekukan.
- Menggunakan harga manual Legacy sebagai fallback otomatis saat histori PO kosong.
- Mengganti keseluruhan fitur Historical Price yang sudah ada.
- Mengubah proses awarding supplier kecuali titik integrasi snapshot yang diperlukan.
- Mengubah metode kalkulasi harga komersial PO.

---

# 5. Audit Kondisi Repository Saat Ini

Audit dilakukan secara read-only pada `master`, commit `79b56a60149a44140ff3e810ad35d2de5a0ba449`.

## 5.1 Komponen yang sudah tersedia

| Komponen | Kondisi saat ini | Dampak terhadap implementasi |
|---|---|---|
| `MaterialReferencePrice` | Menyimpan harga manual per Material Master, Shape, range dimensi, Currency, dan validity. | Dipertahankan sebagai Legacy; bukan sumber benchmark otomatis. |
| `MaterialReferencePriceService` | Create, supersede, void, notes; mencegah overlap harga manual. | Tidak digunakan untuk membuat benchmark historis baru. |
| `ReferencePriceResolver` | Mencocokkan `MaterialReferencePrice` berdasarkan material, shape, dimensi yang ditawarkan, validity dan currency. | Perlu resolver baru atau adapter yang tidak lagi menganggap model manual sebagai output utama. |
| `PurchaseHistoryPriceService` | Membaca baris PO eligible; menghitung nilai agregat, weighted average, min/max, PO count, total KG, dan konversi currency. | Fondasi utama untuk mesin benchmark historis. Perlu filter Rule dan material ID yang strict. |
| `AnnualExchangeRateResolver` | Mendukung kurs per tahun dan revisi tahunan. | Digunakan untuk membandingkan transaksi pada tahun masing-masing. |
| `HistoricalPriceQueryService` | Membangun data Historical Price berdasarkan material, shape, dimensi, supplier, dan periode. | Tambahkan benchmark company-wide tanpa terpengaruh filter supplier. |
| `QuotationListController` | Menghitung Reference Price pada halaman detail Quotation dan mengirimkannya ke view. | Ganti resolver agar benchmark utama memakai dimensi PR, dengan alternatif supplier bila perlu. |
| `InterSupplierComparisonController` | Membuat matrix supplier, indikator histori, dan Reference Price per item. | Benchmark harus dipisahkan dari harga Supplier terbaik; main basis tetap PR. |
| `PurchaseOrderGenerationService` | Membuat PO per Supplier dan membuat snapshot Reference Price per Award. | Perlu precompute benchmark seluruh Awards sebelum PO pertama ditulis. |
| `AwardReferencePriceSnapshot` | Snapshot satu baris per Award, immutable; saat ini mengacu pada master harga manual. | Perlu dukungan sumber Historical Rules dan metadata perhitungan. |
| `MaterialReferencePricePolicy` | Mengizinkan Admin dan Purchasing. | Route saat ini belum sejalan dengan policy. |
| `routes/web.php` | Route Reference Prices berada dalam middleware `role:purchasing`. | Perlu konsistensi akses Admin/Purchasing. |
| Sidebar | Reference Prices tampil pada sidebar Purchasing di Master Data. | Pertahankan entry Purchasing; tambahkan entry Admin bila akses Admin memang diaktifkan sesuai policy. |

## 5.2 Temuan teknis yang harus ditangani

### A. Resolver yang sekarang menggunakan dimensi Supplier

`ReferencePriceResolver` saat ini membaca `available_{dimension_field}` dari `QuotationItem`. Kebutuhan baru menetapkan dimensi PR sebagai benchmark utama.

Perubahan harus membuat dua basis yang berbeda secara eksplisit:

- `requested`: dimensi dari `PrItem` (benchmark utama).
- `supplier_offered`: dimensi dari `QuotationItem.available_*` (benchmark alternatif).

### B. PurchaseHistoryPriceService sudah punya fondasi weighted average

Service ini memiliki definisi harga dan KG yang memperhatikan nilai frozen/selected/legacy. Gunakan logika ordered line yang sudah tersedia, jangan membuat perhitungan KG kedua yang berbeda.

Namun, query baru perlu:

- Menerapkan klasifikasi `CEIL(key_dimension)` sebelum matching kategori.
- Menggunakan `material_master_id` secara strict saat tersedia.
- Memastikan status PO eligible dengan whitelist status kanonis yang sudah diverifikasi.
- Menghasilkan per-year summary, bukan satu total yang menggabungkan semua tahun.
- Menghasilkan detail sumber transaksi yang sama dengan baris yang masuk ke pembilang/penyebut.

### C. Historical Price sudah mempunyai supplier filter

`HistoricalPriceComparisonController` dapat memfilter histori berdasarkan Supplier. Benchmark perusahaan harus dihitung tanpa filter Supplier tersebut. Tampilkan sebagai komponen terpisah dan beri label **Company-wide Reference Price** agar tidak tertukar dengan histori supplier yang sedang dipilih.

### D. PO status harus ditangani dengan whitelist

`PurchaseOrderStatus` memiliki status kanonis `active`, `overdue`, `waiting_qc`, `claim_needed`, `completed`, dan `cancelled`. Untuk benchmark, gunakan status PO yang secara bisnis menandakan PO telah diterbitkan/diakui sebagai transaksi, dan kecualikan `cancelled`.

Lakukan audit nilai mentah status PO di database terlebih dahulu karena enum memiliki pemetaan legacy (`draft`, `open`, `issued`). Jangan sekadar mengganti query menjadi `status != cancelled` tanpa mengecek status historis yang benar-benar tersimpan.

### E. Snapshot saat ini terkait langsung ke manual Reference Price

`award_reference_price_snapshots` saat ini memiliki constraint bahwa snapshot `matched` harus memiliki `material_reference_price_id`. Snapshot Historical Rules tidak memiliki record master harga manual. Constraint tersebut harus diperluas secara additive, bukan dengan mengubah migration lama.

### F. Route dan policy belum konsisten

Policy menyebut Admin dan Purchasing; route berada di grup `role:purchasing`. Akses Admin perlu disejajarkan melalui pola route Admin/Purchasing yang dipakai untuk Master Data lain. Uji akses langsung pada seluruh endpoint, bukan hanya sidebar.

---

# 6. Definisi Domain dan Terminologi

| Istilah | Definisi |
|---|---|
| Reference Price Rule | Aturan yang menetapkan kelompok range dimensi. Tidak menyimpan nilai harga. |
| Rule Set | Satu versi konfigurasi yang berisi sekumpulan Rules. Hanya satu Rule Set yang aktif. |
| Rule Category | Label kategori, contoh `16-50`, dengan batas minimum dan maksimum inklusif. |
| Key Dimension | Dimensi yang digunakan untuk mengelompokkan material berdasarkan Shape. |
| Historical PO Line | Baris Quotation Item yang benar-benar menjadi bagian PO yang eligible. |
| Historical Benchmark | Hasil hitung harga historis berdasarkan material dan kategori Rule tertentu. |
| Current-Year Benchmark | Weighted Average tahun kalender berjalan sampai tanggal evaluasi (YTD). |
| Historical Year Benchmark | Weighted Average untuk satu tahun kalender sebelumnya. |
| Main Basis | Basis dimensi PR untuk material yang sedang dievaluasi. |
| Supplier Alternative Basis | Basis dimensi yang ditawarkan Supplier bila kategori Rule berbeda dari Main Basis. |
| Live Calculation | Kalkulasi saat halaman dibuka; tidak disimpan permanen. |
| Calculation Snapshot | Salinan immutable dari satu hasil kalkulasi dan sumber datanya pada waktu PO dibuat. |
| Award Snapshot | Hubungan antara Award/PO dengan Calculation Snapshot yang digunakan. |
| Legacy Reference Price | Record manual lama pada `material_reference_prices`. |

---

# 7. Aturan Bisnis Reference Price

## 7.1 Mapping Shape ke Key Dimension

Gunakan mapping yang konsisten dengan `MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE` dan `PrItem`:

| Shape | Key dimension | Source Inquiry | Source Supplier |
|---|---|---|---|
| Flat | `thickness` | `pr_items.thickness` | `quotation_items.available_thickness` |
| Round | `d_outer` | `pr_items.d_outer` | `quotation_items.available_d_outer` |
| Hollow | `d_outer` | `pr_items.d_outer` | `quotation_items.available_d_outer` |

Hollow tetap menggunakan Outer Diameter sebagai key dimension. Inner Diameter, Width, dan Length bukan key dimension untuk Rule Reference Price versi ini.

## 7.2 Normalisasi Dimensi

- Dimensi harus berupa angka positif.
- Simpan nilai aktual/raw dengan presisi yang sudah digunakan aplikasi (maksimum 4 desimal).
- Nilai pencocokan adalah pembulatan ke atas ke integer terdekat.
- Gunakan decimal-safe calculation/BCMath di backend; jangan menggunakan floating point browser sebagai sumber kebenaran.

Contoh:

| Raw dimension | Matched dimension (`CEIL`) | Category |
|---:|---:|---|
| 15.0000 | 15 | 1–15 |
| 15.5000 | 16 | 16–50 |
| 45.0000 | 45 | 16–50 |
| 50.5000 | 51 | 51–100 |
| 100.2000 | 101 | 101–150 |
| 700.0000 | 700 | 601–700 |
| 700.5000 | 701 | Not Covered |

`CEIL` hanya diterapkan pada nilai dimensi yang akan dicocokkan. Nilai raw tetap disimpan/ditampilkan sebagai informasi spesifikasi.

## 7.3 Material Matching

Urutan matching:

1. Gunakan `material_master_id` dari PR item.
2. Cari transaksi historis dengan `pr_items.material_master_id` yang sama.
3. Gunakan Shape yang sama.
4. Gunakan Rule Category yang sama.
5. Jika material belum terhubung ke Material Master, hasil benchmark berstatus `material_unmapped` atau `historical_material_unmapped`.

Jangan melakukan `OR material_name = ...` pada benchmark otomatis ketika `material_master_id` sudah tersedia. Nama material hanya dapat digunakan dalam proses rekonsiliasi legacy yang eksplisit, melalui Material Alias/Material Resolver, dengan hasil mapping yang dapat diaudit.

## 7.4 Dimensi Historis yang Digunakan

Untuk satu baris PO historis:

1. Gunakan dimensi yang ditawarkan supplier jika tersimpan dan menjadi spesifikasi yang digunakan PO.
2. Jika data penawaran dimensi tidak tersedia (misalnya PO legacy), gunakan dimensi PR.
3. Terapkan `CEIL` pada hasil dimensi tersebut.
4. Tentukan Rule Category aktif yang menutup nilai itu.

Jika kedua sumber dimensi tidak tersedia, baris tidak dapat dikelompokkan dan tidak boleh masuk benchmark kategori tertentu.

## 7.5 Historical PO Eligibility

Kriteria minimum:

- PO telah diterbitkan/finalized dan masuk daftar status yang diakui sebagai PO valid.
- PO tidak dibatalkan.
- PO tidak soft-deleted.
- Quotation tidak soft-deleted.
- Quotation Item available.
- Harga per KG positif dan valid.
- Ordered KG positif dan valid.
- Baris merupakan Award untuk PO tersebut; untuk PO legacy tanpa Award, gunakan mekanisme fallback histori legacy yang sudah ada.

Jangan memasukkan:

- Quotation yang belum menjadi PO.
- PO cancelled.
- PO soft-deleted.
- Baris unavailable.
- Harga kosong/nol/negatif.
- KG kosong/nol/negatif.
- Duplikasi baris karena join.

**Catatan status:** daftar status yang eligible harus diselaraskan dengan `PurchaseOrderStatus` dan hasil audit status mentah. `active`, `overdue`, `waiting_qc`, `claim_needed`, dan `completed` merupakan status PO kanonis non-cancelled. Status legacy harus diperiksa sebelum dipetakan agar record draft yang benar-benar belum terbit tidak ikut dihitung.

## 7.6 Cakupan Supplier Historis

Rekomendasi default: seluruh PO eligible dari semua Supplier menjadi populasi benchmark perusahaan.

Filter Supplier di Historical Price hanya memengaruhi histori supplier yang sedang dilihat. Filter tersebut tidak mengubah Company-wide Reference Price.

## 7.7 Matching Kategori Utama dan Alternatif

- Main Basis selalu menggunakan kategori yang diperoleh dari dimensi PR.
- Supplier Alternative Basis hanya dihitung bila dimensi yang ditawarkan tersedia dan masuk kategori berbeda dari PR.
- Jika dimensi Supplier dan PR berada dalam kategori sama, gunakan benchmark utama yang sama; tidak perlu membuat benchmark alternatif duplikat.
- Jika kategori supplier berbeda, hitung benchmark alternatif dari material, Shape, dan kategori yang ditawarkan itu.
- Benchmark alternatif tidak mengganti benchmark utama dan tidak boleh diam-diam memengaruhi delta utama.
- Jika dimensi yang ditawarkan kosong, tampilkan alasan bahwa alternatif tidak dapat dihitung; benchmark utama tetap tersedia dari PR.

## 7.8 Periode

- Tahun mengikuti tahun kalender PO (`purchase_orders.created_at`) menurut business timezone aplikasi.
- Tahun berjalan dihitung sampai tanggal evaluasi (YTD).
- Setiap tahun dihitung secara terpisah.
- Tahun-tahun sebelumnya tetap dapat ditampilkan.
- Benchmark utama adalah current-year YTD.
- Jika tahun berjalan tidak memiliki baris eligible, tampilkan `Not Available`; jangan otomatis mengambil tahun sebelumnya sebagai current benchmark.

## 7.9 Currency

- Historical benchmark dapat ditampilkan dalam currency analisis yang dipilih/ditetapkan pada halaman.
- Gunakan Annual Exchange Rate berdasarkan tahun transaksi PO, bukan satu kurs terkini untuk seluruh histori.
- Untuk setiap transaksi, konversikan harga dari currency PO ke currency analisis menggunakan Annual Exchange Rate pada tahun PO.
- Jika salah satu kurs yang diperlukan tidak tersedia, transaksi dikecualikan dari numerator dan denominator benchmark currency tersebut, serta dilaporkan pada excluded metrics.
- Jangan pernah memakai kurs 1 atau 0 sebagai fallback kecuali currency yang sama (identity) atau aturan eksplisit IDR=1 pada resolver.
- Simpan metadata rate year, currency, rate, dan revision yang dipakai.

## 7.10 Rumus Harga

Untuk tahun `y` dan target currency `C`:

`Reference Price(y, C) = SUM(Converted PO Price per KG_i × Ordered KG_i) / SUM(Ordered KG_i)`

Hanya transaksi yang berhasil dikonversi ke `C` menjadi bagian numerator dan denominator.

Informasi tambahan yang direkomendasikan:

- Last Purchase Price (harga per KG dari PO eligible paling baru pada tahun/populasi yang ditampilkan).
- Lowest Price.
- Highest Price.
- PO Count (distinct PO).
- Line Count.
- Total Ordered KG.
- Latest PO Date.
- Excluded Line Count/KG/Currency karena kurs atau data tidak valid.

Harga per KG untuk perhitungan harus mengikuti ordered-line precedence yang sudah dipakai `PurchaseHistoryPriceService`/`OrderedLine`, bukan rumus baru di view.

---

# 8. Spesifikasi Import Excel dan Versioning Rules

## 8.1 Struktur File Sumber yang Diberikan

File `Kategori Range.xlsx`:

- Worksheet: `Kategori`
- Header berada pada baris 3.
- Kolom B berjudul `Thick/Dia (mm)`.
- Kolom C berjudul `Kategori`.
- Kolom B berisi angka integer 1 sampai 700 secara berurutan.
- Kolom C hanya mengisi label kategori pada baris awal kelompok, bukan pada setiap baris.
- Data berakhir pada baris 703.

Kelompok yang teridentifikasi:

| Category Key | Min | Max | Baris awal data Excel |
|---|---:|---:|---:|
| 1-15 | 1 | 15 | 4 |
| 16-50 | 16 | 50 | 19 |
| 51-100 | 51 | 100 | 54 |
| 101-150 | 101 | 150 | 104 |
| 151-200 | 151 | 200 | 154 |
| 201-250 | 201 | 250 | 204 |
| 251-300 | 251 | 300 | 254 |
| 301-400 | 301 | 400 | 304 |
| 401-500 | 401 | 500 | 404 |
| 501-600 | 501 | 600 | 504 |
| 601-700 | 601 | 700 | 604 |

Parser wajib memahami label kategori yang hanya muncul pada baris pertama tiap grup.

## 8.2 Import Parser

Parser harus:

1. Membaca hanya worksheet `Kategori`.
2. Mendeteksi header `Thick/Dia (mm)` dan `Kategori` tanpa mengandalkan posisi kolom yang selalu B/C jika header dapat ditemukan secara aman.
3. Mengabaikan baris kosong di area pembuka/akhir.
4. Membaca nilai dimensi numerik.
5. Saat menemukan label kategori baru, parse `min-max` dari label tersebut.
6. Mengaitkan baris dimensi berikutnya ke label aktif sampai label berikutnya ditemukan.
7. Memastikan setiap grup berisi nilai integer berurutan dari min sampai max, tanpa duplikasi, gap, atau nilai di luar batas label.
8. Memastikan tidak ada kategori ganda.
9. Menghasilkan daftar normalized rules: `category_key`, `category_name`, `dimension_min`, `dimension_max`, `sort_order`.
10. Menolak file jika worksheet/header/range tidak dapat dipastikan.

Jangan menganggap blank pada kolom kategori sebagai kategori kosong; pada workbook sumber, blank tersebut berarti masih bagian dari grup label sebelumnya.

## 8.3 Mode Replace

- Rules hasil file menjadi seluruh kandidat Rule Set baru.
- Rules aktif yang tidak ada dalam file tidak dibawa ke set baru.
- Rule Set lama tidak dihapus; statusnya menjadi archived setelah versi baru diaktifkan.
- Preview harus menunjukkan kategori yang ditambah, berubah, dihapus/dinonaktifkan, dan tidak berubah.
- Aktivasi harus atomik sehingga selalu hanya ada satu Rule Set aktif.

## 8.4 Mode Merge

- Clone Rule Set aktif menjadi kandidat Rule Set baru.
- Upsert kategori dari file berdasarkan `category_key` yang dinormalisasi.
- Kategori yang tidak ada dalam file dipertahankan.
- Kategori dengan key yang sama dan range berbeda diperbarui di kandidat.
- Kategori baru ditambahkan.
- Bila hasil Merge membuat overlap antar-rule aktif, preview menolak aktivasi sampai konflik dibereskan.
- File yang memiliki `16-45` tidak boleh diam-diam mempertahankan `16-50` jika keduanya overlap; preview harus menunjukkan konflik.

## 8.5 Preview Import

Preview menampilkan:

- File dan worksheet yang dibaca.
- Mode Replace/Merge.
- Jumlah kategori yang dibaca.
- Kategori baru.
- Kategori yang berubah.
- Kategori yang tidak berubah.
- Kategori yang akan hilang/nonaktif (Replace).
- Konflik overlap/gap/duplikasi.
- Jumlah dan daftar warning.

Tombol Apply/Confirm hanya aktif bila hasil valid.

## 8.6 Versioning dan Pengelolaan Manual

Rekomendasi:

- Setiap Apply import membuat Rule Set version baru.
- Edit/menambah/nonaktifkan Rule melalui UI juga membentuk kandidat versi baru, bukan mengubah Rule Set aktif secara in-place.
- User dapat preview perubahan sebelum menerapkannya.
- Rule Set lama disimpan untuk audit dan snapshot.
- Satu Rule Set aktif ditunjuk oleh pointer konfigurasi aktif.
- Penghapusan fisik Rule Set/Rule yang sudah dipakai snapshot dilarang.
- Nonaktifkan Rule berarti rentang tersebut tidak memiliki benchmark; UI harus menampilkan coverage gap dengan jelas.

---

# 9. Alur Proses End-to-End

## 9.1 Admin/Purchasing Mengelola Rules

1. Buka Reference Prices → Rules.
2. Lihat Rule Set aktif dan kategori.
3. Buat perubahan melalui form atau upload Excel.
4. Pilih Replace atau Merge bila import.
5. Sistem melakukan validasi.
6. User memeriksa preview.
7. User mengonfirmasi Apply.
8. Sistem membuat versi Rule Set baru dan mengaktifkannya secara atomik.
9. Versi sebelumnya diarsipkan; snapshot lama tetap merujuk versi lama.

## 9.2 Review Quotation

1. Purchasing membuka detail quotation.
2. Sistem mengambil PR item, Material Master, Shape, dan dimensi PR.
3. Rule Resolver menentukan kategori PR.
4. Historical Benchmark Service mengambil PO eligible untuk material dan kategori tersebut.
5. Sistem menghitung current-year YTD dan histori per tahun.
6. Reference Price utama ditampilkan di baris material.
7. Jika Supplier menawarkan dimensi yang masuk kategori berbeda, benchmark alternatif juga dihitung dan ditampilkan terpisah.
8. Supplier tidak pernah menerima data Reference Price pada halaman Supplier atau endpoint Supplier.

## 9.3 Inter-Supplier Comparison

1. Purchasing memilih PR untuk dibandingkan.
2. Sistem memuat seluruh PR item dan quotation supplier.
3. Sistem menghitung benchmark utama per PR item, bukan berdasarkan supplier termurah.
4. Benchmark utama yang sama ditampilkan sebagai patokan bersama.
5. Untuk setiap supplier yang memiliki dimensi penawaran berbeda kategori, sistem menampilkan benchmark alternatif kategori tersebut.
6. Delta utama selalu dihitung terhadap benchmark PR.
7. Informasi alternatif diberi label jelas dan tidak menggantikan nilai utama.

## 9.4 Historical Price

1. Purchasing membuka Historical Price.
2. User memilih material dan Shape.
3. Sistem menentukan Material Master dan Rule Category berdasarkan key dimension/filter.
4. Sistem menampilkan company-wide Reference Price tahunan.
5. Jika user memilih Supplier, histori khusus Supplier tetap difilter sesuai supplier, tetapi panel Reference Price perusahaan tidak ikut difilter.
6. User dapat membuka rincian PO pembentuk benchmark.

## 9.5 PO Finalization

1. Proses PO dimulai dari Award yang valid.
2. Setelah lock dan revalidation Award selesai, sistem menetapkan satu `history_as_of_at` untuk batch tersebut.
3. Sistem menghitung semua benchmark utama dan alternatif yang dibutuhkan seluruh Award **sebelum PO pertama pada batch dibuat**.
4. Hasil perhitungan dikumpulkan/deduplicated berdasarkan key kalkulasi yang sama.
5. Sistem membuat PO per Supplier.
6. Sistem membuat immutable calculation snapshot dan Award snapshot yang merujuk pada kalkulasi tersebut.
7. Seluruh PO dan snapshot ditulis di transaksi database yang sama.
8. Jika pembuatan PO gagal, seluruh perubahan snapshot dalam transaksi ikut rollback.

---

# 10. Desain Perhitungan Historical Reference Price

## 10.1 Input Calculation

Minimal input ke service:

```text
material_master_id
shape
basis_type: requested | supplier_offered
raw_dimension
normalized_dimension = CEIL(raw_dimension)
active_rule_set_id
rule_id
benchmark_year (current year atau tahun histori tertentu)
target_currency
history_as_of_at (opsional; wajib untuk PO snapshot)
```

## 10.2 Calculation Key

Agar hasil yang sama tidak dihitung berulang untuk setiap Supplier/Award, gunakan calculation key yang deterministik, minimal terdiri dari:

```text
rule_set_id
material_master_id
shape
rule_id
benchmark_year
target_currency
basis_type
history_as_of_at (untuk snapshot)
```

Untuk live request, cache hanya dalam scope request terlebih dahulu. Jangan mengaktifkan cache lintas request yang berisiko menyajikan data lama sebelum mekanisme invalidasi PO dan Rule terbukti benar.

## 10.3 Query Historical Source

Gunakan `PurchaseHistoryPriceService::eligibleLines()` sebagai fondasi, lalu tambahkan kemampuan filter:

- `material_master_id` strict.
- `shape` strict.
- `rule dimension field` berdasarkan Shape.
- `rule min/max` berdasarkan nilai `CEIL(COALESCE(offered_dimension, requested_dimension))`.
- `po_year`.
- `target currency`.
- `history_as_of_at` untuk snapshot.
- `exclude_po_ids` sebagai guard tambahan bila diperlukan.

Jangan memakai `material_name OR material_master_id` untuk benchmark utama bila Material Master ID sudah ada. Bila histori legacy belum termapping, proses resolusi harus eksplisit dan dilaporkan.

## 10.4 Query Math

Untuk setiap tahun dan currency sumber, query dapat mengagregasi:

```text
S = SUM(price_per_kg × ordered_kg)
K = SUM(ordered_kg)
N = COUNT(eligible line)
PO_COUNT = COUNT(DISTINCT purchase_order_id)
MIN_PRICE = MIN(price_per_kg)
MAX_PRICE = MAX(price_per_kg)
LAST_PRICE = price transaksi terbaru berdasarkan PO date + stable tie-breaker
```

Konversi setiap agregat currency ke target currency menggunakan Annual Exchange Rate tahun transaksi. Setelah currency yang tidak memiliki kurs dikecualikan:

```text
weighted_average = SUM(converted_S_by_currency) / SUM(K_for_currencies_with_rate)
```

K dan S yang dikecualikan karena kurs hilang tidak masuk denominator. Excluded KG dan line count harus dilaporkan.

## 10.5 Hasil Calculation DTO

Kontrak hasil yang direkomendasikan:

```text
status: matched | not_available
reason_code: nullable
material_master_id
shape
dimension_field
raw_dimension
rounded_dimension
rule_set_id
rule_id
category_key
benchmark_year
is_current_year
currency
calculation_method = weighted_average_kg
weighted_average_price_per_kg
lowest_price_per_kg
highest_price_per_kg
last_purchase_price_per_kg
latest_po_at
line_count
po_count
total_kg
excluded_line_count
excluded_total_kg
excluded_currencies
history_as_of_at
rate_metadata
source_lines (untuk drilldown; jangan expose ke Supplier)
```

Reason code minimum:

- `material_unmapped`
- `shape_unsupported`
- `dimension_missing`
- `dimension_out_of_range`
- `rule_not_found`
- `rule_inactive`
- `no_historical_po`
- `no_eligible_historical_line`
- `exchange_rate_unavailable`
- `insufficient_convertible_history`
- `ambiguous_rule_configuration`

## 10.6 Year Selection

- Current Year: benchmark utama YTD.
- Previous Years: chart/table historis.
- User dapat memilih tahun historis untuk detail, tetapi pemilihan tahun lama tidak menjadikan tahun lama benchmark utama secara otomatis.
- Bila current year kosong, panel utama menampilkan Not Available dan panel histori menampilkan tahun terakhir yang mempunyai data sebagai informasi historis.

## 10.7 Min/Max/Last Semantics

- Lowest/Highest: harga per KG setelah normalisasi ke target currency menggunakan annual rate yang sama dengan transaksi.
- Last: transaksi dengan PO date paling baru pada tahun/populasi yang sedang diringkas; stable tie-breaker menggunakan PO ID dan Quotation Item ID.
- Jangan menghitung min/max/last dari harga yang gagal dikonversi.

---

# 11. Desain Tampilan dan UX

## 11.1 Menu Reference Prices

Menu tetap berada di Master Data dan mengarahkan ke halaman dengan tiga area/tab:

1. **Rules** — konfigurasi range dan import.
2. **Historical Prices** — hasil kalkulasi otomatis dan detail PO pembentuknya.
3. **Legacy Archive** — data manual lama, read-only.

Tidak ada lagi tombol Add Manual Reference Price sebagai alur utama.

### Rules Page

Informasi:

- Rule Set version aktif.
- Tanggal aktivasi.
- Pengguna yang mengaktifkan.
- Daftar kategori.
- Min/Max.
- Status active/inactive.
- Riwayat versi.
- Tombol Import Excel.
- Mode Replace/Merge.
- Preview import.
- Manual add/edit/deactivate melalui mekanisme versi Rule Set.

### Historical Prices Page

Filter:

- Material (berdasarkan Material Master).
- Shape.
- Rule Category.
- Year.
- Currency analisis.

Kolom/ringkasan:

- Current Year YTD Reference Price.
- Tahun histori dan nilai average setiap tahun.
- Lowest / Highest / Last.
- PO Count.
- Line Count.
- Total KG.
- Latest PO Date.
- Excluded transaction/rate notice.
- Tombol/view detail sumber transaksi.

Reference Price harus menampilkan badge/label **Company-wide (All Suppliers)**.

### Legacy Archive

- Read-only.
- Menampilkan data manual lama dari `material_reference_prices`.
- Tidak digunakan oleh resolver baru.
- Snapshot lama yang merujuk record manual tetap dapat ditelusuri.

## 11.2 Review Quotation

Saat ini `QuotationListController::show()` mengirim `referencePrices` ke `quotations/_items_readonly_table`. Pertahankan area Reference Price pada halaman Review Quotation sesuai RP-08, tetapi ubah basis datanya:

- Main Reference Price = PR dimension + category PR.
- Supplier alternative = available dimension + category supplier, hanya bila kategorinya berbeda.
- Tampilkan tahun YTD, currency, range, PO count, total KG, dan sumber histori.
- Selisih harga supplier dibandingkan dengan Main Reference Price.
- Jangan tampilkan semua detail PO secara default; detail dapat dibuka melalui expandable panel/modal.

Jika supplier tidak memberikan dimensi kunci, main benchmark tetap dihitung dari PR; alternatif supplier tidak tersedia.

## 11.3 Inter-Supplier Comparison

Pada level PR item:

- Tampilkan satu Main Reference Price untuk seluruh penawaran supplier.
- Tampilkan kategori Rule dan dimensi PR.
- Tampilkan per supplier: harga penawaran, delta terhadap Main Reference Price, dan indikator alternatif bila penawaran masuk kategori berbeda.
- Jika kategori penawaran sama dengan kategori PR, tampilkan bahwa benchmark sama; jangan menampilkan nilai benchmark duplikat.
- Jangan memilih benchmark dari Supplier termurah. Main benchmark tidak boleh bergantung pada ranking penawaran.

## 11.4 Historical Price

Tambahkan komponen Company-wide Reference Price pada halaman Historical Price:

- Grafik tahunan per Rule Category.
- Current Year YTD ditonjolkan sebagai benchmark saat ini.
- Tahun-tahun sebelumnya sebagai histori.
- Detail transaksi PO pembentuk setiap titik tahun.
- Filter Supplier yang sudah ada tetap berlaku untuk histori supplier, bukan untuk Company-wide Reference Price.
- Bila halaman berada pada PR-context mode, gunakan Material Master dan dimensi PR yang terkait sebagai Main Basis.

## 11.5 Vs-Best

Tidak ada integrasi Reference Price baru pada Vs-Best. Jangan menambahkan resolver/call benchmark ke controller atau blade Vs-Best.

---

# 12. Desain Arsitektur Aplikasi

Nama berikut adalah rancangan komponen baru. Saat implementasi, cek konvensi namespace dan hindari duplikasi dengan class yang sudah ada.

## 12.1 Models

- `ReferencePriceRuleSet`
- `ReferencePriceRule`
- `ReferencePriceSetting` (singleton/pointer Rule Set aktif)
- `ReferencePriceImport` (audit file/preview/commit)
- `ReferencePriceCalculationSnapshot`
- `ReferencePriceCalculationSource`
- `AwardReferencePriceSnapshot` (perluas model saat ini)

## 12.2 Services

### `ReferencePriceRuleImportService`

- Parse workbook.
- Normalize categories.
- Validate format and ranges.
- Build Replace/Merge candidate.
- Generate preview diff.
- Commit candidate as a new Rule Set.

### `ReferencePriceRuleResolver`

- Resolve key dimension by Shape.
- Normalize raw dimension using CEIL.
- Find active Rule for the integer dimension.
- Return matched Rule or reason code.

### `HistoricalReferencePriceService`

- Build filters for material, Shape, Rule, year, currency and cutoff.
- Reuse `PurchaseHistoryPriceService` ordered price/KG rules.
- Calculate weighted average per year.
- Convert currencies with `AnnualExchangeRateResolver`.
- Return metrics and detail source lines.
- Support batch resolution and request-scoped memoization.

### `ReferencePriceBenchmarkResolver`

Orchestrate:

1. PR Main Basis.
2. Optional Supplier Alternative Basis.
3. Rule resolution.
4. Historical price calculation.
5. Delta/percentage calculation.
6. Reason code and metadata.

Do not keep `ReferencePriceResolver` as the only type if it can only return `MaterialReferencePrice`. Prefer a new DTO such as `ReferencePriceBenchmarkResult`; optionally keep an adapter for Legacy display only.

### `ReferencePriceSnapshotService`

- Accept resolved main/alternative benchmark payloads.
- Deduplicate common calculation keys within a PO batch.
- Persist immutable calculation snapshots and source rows.
- Attach calculation snapshot IDs to Award snapshots.
- Enforce `history_as_of_at` and batch self-exclusion.

## 12.3 DTO / Result Object

Create a dedicated DTO instead of overloading `ReferencePriceResult` whose `reference` field currently points to `MaterialReferencePrice`.

Result should expose:

- `main` benchmark result.
- `supplier_alternative` optional result.
- `main_delta` / `main_delta_percent`.
- `alternative_delta` / `alternative_delta_percent` where applicable.
- `reason` and `metadata`.

All monetary arithmetic remains server-side using BCMath/`Money` utilities.

## 12.4 Controllers / Endpoints

Target route capabilities:

| Method | Purpose |
|---|---|
| GET | Reference Prices landing page (Rules, Historical Prices, Legacy Archive) |
| GET | Rules list / version history |
| POST | Import preview (temporary/private upload; no activation yet) |
| POST | Confirm import and activate a new Rule Set |
| POST | Create category in a candidate/new Rule Set |
| PUT/PATCH | Update category in a candidate/new Rule Set |
| PATCH | Activate/deactivate a category through a new Rule Set version |
| GET | Historical benchmark result/data endpoint |
| GET | Drilldown source PO lines for a benchmark |
| GET | Legacy Archive list/detail |

Existing manual price write routes (`store`, `supersede`, `void`, `notes`) should be disabled for new business data after cutover or restricted to an explicitly labelled Legacy administration process. Do not silently write new manual rows into the automatic benchmark path.

## 12.5 Existing Files to Review/Modify

| File | Planned responsibility |
|---|---|
| `app/Models/MaterialReferencePrice.php` | Keep as Legacy model. |
| `app/Models/AwardReferencePriceSnapshot.php` | Extend links to automatic calculation snapshots and preserve immutability. |
| `app/Services/Pricing/PurchaseHistoryPriceService.php` | Reuse eligibility, ordered price/KG, annual grouping; add strict filter/CEIL/cutoff capabilities or expose a safe query API. |
| `app/Services/Pricing/AnnualExchangeRateResolver.php` | Reuse; add metadata needed for full source reproducibility if required. |
| `app/Services/Pricing/ReferencePriceResolver.php` | Replace usages or retain legacy adapter; do not let old manual resolver remain the source of automatic benchmark. |
| `app/Services/Pricing/HistoricalPriceQueryService.php` | Add company-wide benchmark query independent of supplier filter. |
| `app/Http/Controllers/Purchasing/MaterialReferencePriceController.php` | Rules/import/history/Legacy Archive actions. |
| `app/Http/Controllers/Purchasing/QuotationListController.php` | Resolve Main PR benchmark and Supplier alternative for Review Quotation. |
| `app/Http/Controllers/Purchasing/InterSupplierComparisonController.php` | Batch main/alternative benchmark per PR item and offer. |
| `app/Http/Controllers/Purchasing/HistoricalPriceComparisonController.php` | Add annual Reference Price series/panel. |
| `app/Services/PurchaseOrderGenerationService.php` | Precompute all calculation payloads before first PO insert; persist snapshots in same transaction. |
| `routes/web.php` | Add/align Admin and Purchasing route access and endpoints. |
| `resources/views/partials/sidebar.blade.php` | Keep Purchasing link and expose Admin entry if Admin management access is retained. |
| `config/page_registry.php` | Register canonical route keys where required. |
| `resources/views/purchasing/reference-prices/index.blade.php` | Rework from manual price table to Rules/Historical/Legacy sections. |
| `resources/views/quotations/_items_readonly_table.blade.php` | Render PR main benchmark and optional Supplier alternative. |
| `resources/views/purchasing/comparison/_inter_supplier_content.blade.php` | Render benchmark per PR item and alternatives per Supplier. |
| `resources/views/purchasing/comparison/_historical_content.blade.php` | Add annual Company-wide Reference Price panel. |
| Localization files (`lang/en`, `lang/id`) | New labels, reasons, import errors, metric labels. |
| Feature/Unit tests | Rules import, calculation, snapshot, access and regression. |

---

# 13. Desain Database dan Migration Plan

Migration harus additive. Jangan mengubah migration lama yang telah dijalankan pada environment lain.

## 13.1 `reference_price_rule_sets`

Purpose: menyimpan versi konfigurasi Rule.

Kolom yang direkomendasikan:

| Column | Type/Constraint | Notes |
|---|---|---|
| id | PK | |
| version_no | unsigned int | Unique; incrementing version. |
| status | string/enum | `active`, `archived` (opsional `draft` jika workflow draft dipakai). |
| import_mode | nullable string | `replace`, `merge`, `manual`. |
| source_file_name | nullable string | Original file name only. |
| source_file_hash | nullable string | SHA-256 checksum. |
| source_sheet | nullable string | Expected `Kategori`. |
| created_by | FK users | |
| activated_by | nullable FK users | |
| activated_at | nullable timestamp | |
| created_at / updated_at | timestamps | |

Satu Rule Set aktif harus dijamin lewat row pointer/lock pada `reference_price_settings`, bukan hanya validasi UI.

## 13.2 `reference_price_settings`

Purpose: single active Rule Set pointer.

| Column | Type/Constraint | Notes |
|---|---|---|
| id | PK/singleton | Aplikasi mempertahankan satu row. |
| active_rule_set_id | FK reference_price_rule_sets | Set aktif saat ini. |
| updated_by | nullable FK users | |
| timestamps | timestamps | |

Update active Rule Set dilakukan dalam transaction dengan `lockForUpdate()` pada row singleton. Rule Set lama diarsipkan dan pointer diganti atomically.

## 13.3 `reference_price_rules`

Purpose: kategori range pada satu Rule Set.

| Column | Type/Constraint | Notes |
|---|---|---|
| id | PK | |
| rule_set_id | FK reference_price_rule_sets | Restrict delete jika direferensikan. |
| category_key | string | Normalized key, contoh `16-50`. |
| category_name | string | Label display. |
| dimension_min | unsigned integer | Inclusive. |
| dimension_max | unsigned integer | Inclusive. |
| sort_order | unsigned small/int | Urutan tampilan. |
| status | string | `active`, `inactive`. |
| created_by / updated_by | nullable FK users | Audit. |
| timestamps | timestamps | |

Constraint/index:

- Unique `(rule_set_id, category_key)`.
- Index `(rule_set_id, status, dimension_min, dimension_max)`.
- Validasi `dimension_min > 0` dan `dimension_max >= dimension_min`.
- Overlap aktif divalidasi di service dalam transaction.
- Rules boleh memiliki gap bila kategori dinonaktifkan atau konfigurasi memang tidak mencakup dimensi tertentu; gap harus terlihat di UI.

## 13.4 `reference_price_imports`

Purpose: audit proses import dan preview.

Kolom minimal:

- id
- uploaded_by
- source_file_name
- private_storage_path (bukan public URL)
- source_file_hash
- source_sheet
- import_mode
- status (`uploaded`, `previewed`, `confirmed`, `rejected`, `failed`)
- parsed_count
- preview_diff JSON
- validation_errors JSON
- confirmed_rule_set_id nullable FK
- confirmed_at
- timestamps

File upload harus disimpan di private storage. Validasi MIME/extension dan inspeksi workbook harus dilakukan sebelum parsing.

## 13.5 `reference_price_calculation_snapshots`

Purpose: immutable snapshot satu kalkulasi benchmark historis. Satu hasil kalkulasi dapat dipakai beberapa Award bila parameter/key kalkulasinya sama.

Kolom minimal:

| Column | Notes |
|---|---|
| id | PK |
| calculation_key | Unique hash dari parameter kalkulasi/cutoff |
| status | `matched`, `not_available` |
| reason_code | Nullable |
| source_type | `historical_rules` untuk hasil otomatis |
| rule_set_id | FK versi Rule yang digunakan |
| rule_id | FK Rule yang digunakan |
| material_master_id | FK material |
| shape | Flat/Round/Hollow |
| dimension_field | thickness/d_outer |
| basis_type | `requested` / `supplier_offered` |
| raw_dimension | decimal(10,4) |
| rounded_dimension | unsigned integer |
| category_key_snapshot | string copy untuk display/audit |
| benchmark_year | integer |
| is_current_year | boolean |
| target_currency | currency hasil kalkulasi |
| calculation_method | `weighted_average_kg` |
| weighted_average_price | decimal(15,4), nullable |
| lowest_price | decimal(15,4), nullable |
| highest_price | decimal(15,4), nullable |
| last_purchase_price | decimal(15,4), nullable |
| latest_po_at | timestamp, nullable |
| line_count | integer |
| po_count | integer |
| total_kg | decimal(18,4) |
| excluded_line_count | integer |
| excluded_total_kg | decimal(18,4) |
| excluded_currencies | JSON |
| history_as_of_at | timestamp, nullable for live-only results; required for PO snapshot |
| rate_metadata | JSON (annual rate/revision detail) |
| created_at | timestamp |

Snapshot calculation rows tidak boleh di-update/delete setelah digunakan. Gunakan model guards dan database FK `restrictOnDelete`.

## 13.6 `reference_price_calculation_sources`

Purpose: detail baris PO yang membentuk calculation snapshot.

Kolom minimal:

- id
- calculation_snapshot_id FK
- purchase_order_id FK
- quotation_item_id FK
- po_created_at snapshot
- source_currency
- source_price_per_kg
- ordered_kg
- converted_price_per_kg
- converted_weighted_amount (price × KG)
- annual_rate_year
- source_rate_value / source_rate_revision
- target_rate_value / target_rate_revision
- timestamps/created_at

Unique `(calculation_snapshot_id, quotation_item_id)` untuk mencegah duplikasi.

Detail source wajib immutable. Jika retensi data detail menjadi isu, evaluasi ukuran berdasarkan volume aktual sebelum membuat strategi retensi; jangan menghapus source yang diperlukan untuk audit PO.

## 13.7 Perluasan `award_reference_price_snapshots`

Pertahankan tabel existing dan kolom legacy. Tambahkan:

- `main_calculation_snapshot_id` nullable FK ke `reference_price_calculation_snapshots`.
- `supplier_alternative_calculation_snapshot_id` nullable FK.
- `reference_price_source` dengan nilai `legacy_manual` atau `historical_rules`.
- `history_as_of_at` atau gunakan timestamp dari calculation snapshot.
- Kolom copy/summary tambahan hanya jika dibutuhkan oleh export/view lama.

Aturan koherensi status perlu diperbarui:

- Legacy `matched`: harus mempunyai `material_reference_price_id`.
- Historical Rules `matched`: harus mempunyai `main_calculation_snapshot_id` yang statusnya matched.
- `not_available`: harus mempunyai `reason_code` atau calculation snapshot not_available.
- Untuk historical automatic, `material_reference_price_id` boleh NULL.

Pertahankan `pr_item_award_id` unique jika satu parent Award snapshot tetap menyimpan pointer main + optional alternative. Alternatif tidak dibuat sebagai parent snapshot kedua.

## 13.8 Immutability

- `AwardReferencePriceSnapshot` tetap melarang update/delete.
- `ReferencePriceCalculationSnapshot` melarang update/delete setelah dibuat.
- `ReferencePriceCalculationSource` melarang update/delete.
- Rule Set archived tidak boleh diubah.
- Rule Set yang sedang aktif tidak diedit langsung; buat versi baru.
- FK memakai restrict agar histori tidak hilang karena record sumber dihapus.

## 13.9 Indeks dan Database Constraints

Index awal yang perlu dievaluasi dengan `EXPLAIN`:

- Reference rules `(rule_set_id, status, dimension_min, dimension_max)`.
- Snapshot `(rule_set_id, material_master_id, shape, benchmark_year)`.
- Calculation sources `(calculation_snapshot_id, purchase_order_id)`.
- Purchase history join/filter indexes yang relevan pada `purchase_orders`, `po_quotations`, `quotation_items`, `pr_items`, dan `pr_item_awards`.

Jangan menambah index besar sebelum melihat query plan dan cardinality database aktual.

---

# 14. Integrasi dengan PO Finalization dan Immutable Snapshot

## 14.1 Persyaratan Kritis: Hindari Self-Inclusion

`PurchaseOrderGenerationService` saat ini membuat snapshot dalam proses generate PO. Bila calculation baru membaca PO historis di tengah loop pembuatan beberapa PO, PO yang baru dibuat lebih awal dalam batch dapat terbaca sebagai histori saat sistem menghitung snapshot PO berikutnya.

Karena itu:

**Semua benchmark untuk seluruh Award pada satu batch PO harus dihitung sebelum PO pertama dibuat.**

Urutan yang diwajibkan:

1. Jalankan kill-switch/feature guard yang sudah ada.
2. Lock/revalidate PR, PR Items, Quotations, Quotation Items, dan Awards sesuai lock order repository saat ini.
3. Pastikan seluruh Award eligible.
4. Ambil satu `history_as_of_at` untuk batch.
5. Resolve main/alternative benchmark untuk semua Award (batch/bulk).
6. Deduplicate calculation key yang sama.
7. Simpan atau siapkan calculation payload.
8. Baru buat PO per Supplier.
9. Buat Award snapshot yang merujuk calculation snapshot main dan optional alternative.
10. Commit dalam satu transaksi.

Jika generate PO gagal, calculation snapshot yang dibuat khusus transaksi itu dan Award snapshot harus rollback bersama PO.

## 14.2 Basis Snapshot

- Main snapshot selalu berbasis dimensi PR.
- Supplier alternative snapshot hanya ada bila offered category berbeda dari PR category.
- Bila category sama, pointer alternative boleh NULL; UI dapat menunjukkan `same_category` dan menggunakan main benchmark.
- Bila alternative category berbeda tetapi tidak memiliki histori, simpan status Not Available dan reason code untuk alternatif.
- Snapshot utama dan alternatif harus menggunakan target currency PO.

## 14.3 Currency pada Snapshot Agregat

Benchmark weighted average dapat berasal dari beberapa source currency. Karena tidak ada satu source currency/rate tunggal:

- `target_currency`/`po_currency` menjadi currency hasil benchmark.
- `weighted_average_price` disimpan dalam target currency.
- Jangan memalsukan satu `reference_rate_id` tunggal untuk gabungan beberapa currency.
- Simpan rate metadata setiap source pada `reference_price_calculation_sources` dan/atau metadata calculation snapshot.

## 14.4 Snapshot Lama

- Snapshot lama tetap menggunakan `material_reference_price_id`.
- Tidak dilakukan backfill kalkulasi otomatis ke PO lama.
- PO lama tanpa snapshot tetap menampilkan status Not Captured sesuai kontrak lama.
- Jangan menjalankan resolver baru secara retroaktif untuk mengisi snapshot PO lama.

---

# 15. Authorization, Security, dan Auditability

## 15.1 Role

- Admin dan Purchasing: dapat mengelola Rules, import, melihat Historical Price dan Legacy Archive.
- Supplier: tidak boleh mengakses menu, route, JSON, export, source PO, Rule internal, atau snapshot Reference Price internal.
- Role lain: deny by default.

## 15.2 Route/Policy

Saat ini `MaterialReferencePricePolicy` memperbolehkan Admin dan Purchasing, tetapi route Reference Prices ada dalam grup `role:purchasing`.

Implementasi harus menyelaraskan keduanya. Rekomendasi:

- Ikuti pola dual Admin/Purchasing routes yang sudah digunakan Master Data lain, atau gunakan middleware multi-role yang memang telah didukung repository.
- Pertahankan route name yang dipakai Purchasing bila memungkinkan untuk kompatibilitas.
- Buat route alias Admin bila perlu.
- Controller tetap memanggil Policy/Gate untuk setiap operasi.
- Jangan menganggap menu tersembunyi sebagai authorization.

## 15.3 Import File

- Upload hanya file `.xlsx` yang diterima parser.
- Validasi MIME, size, worksheet, header, dan isi workbook.
- Simpan sementara di private storage.
- Validasi formula/cell values dengan library yang aman; jangan mengeksekusi macro.
- Batasi ukuran file dan jumlah baris.
- Preview token/import ID harus terikat ke actor, file hash, dan mode.
- Commit hanya dapat dilakukan oleh actor/role yang memiliki permission.

## 15.4 Data Leakage

- Query benchmark harus dipanggil hanya pada request internal Purchasing/Admin.
- Supplier quotation view tidak boleh menerima prop/variable `referencePrices` baru.
- Supplier API/JSON response tidak boleh mengandung benchmark, rule key internal, source PO, price source, atau reason detail internal.
- Export Supplier tidak boleh menambahkan kolom Reference Price.
- Tes `assertDontSee`/JSON structure harus memastikan hal ini.

## 15.5 Audit Log

Catat:

- Upload file, checksum, import mode.
- Preview outcome dan warning.
- Actor yang mengaktifkan Rule Set.
- Rule Set versi lama dan baru.
- Perubahan tiap category/range/status.
- Waktu aktivasi.
- Snapshot calculation metadata.

Gunakan `MasterDataAuditLog` bila model tersebut dapat mendukung entity baru tanpa mengubah kontrak. Jika tidak, buat audit table khusus.

---

# 16. Performance, Consistency, dan Observability

## 16.1 Batch Calculation

Jangan resolve benchmark satu per Supplier line menggunakan query baru setiap kali.

Di Inter-Supplier/Review Quotation:

- Ambil seluruh PR item pada context.
- Bentuk set unik calculation keys (material, shape, rule, year, currency, basis).
- Hitung main benchmark secara batch.
- Hitung alternative hanya untuk kategori supplier yang berbeda dan unique.
- Map kembali ke view model per PR item/quotation item.

## 16.2 Caching

Tahap pertama:

- Request-scoped memoization untuk hasil key yang sama.
- Tidak ada cross-request cache untuk live benchmark sampai invalidation terbukti andal.

Jika diperlukan kemudian, cache key harus mencakup active Rule Set version, material, shape, rule, year, currency, source eligibility version, dan cutoff/evaluation date. Cache invalidation diperlukan pada PO create/cancel/delete/restore atau perubahan yang memengaruhi eligibility, serta aktivasi Rule Set.

## 16.3 Query Accuracy

- Gunakan BCMath/`Money` untuk monetary calculation.
- Jangan menghitung KG ulang dengan formula view.
- Samakan price/kg dan ordered KG dengan `PurchaseHistoryPriceService`/`OrderedLine` precedence.
- Pastikan satu PO line hanya dihitung satu kali meski query join awards/po_quotations.
- Gunakan `COUNT(DISTINCT po.id)` untuk PO count.
- Uji `EXPLAIN` untuk query benchmark utama pada dataset besar.

## 16.4 Logging dan Metrics

Log warning untuk:

- Tidak ada Rule aktif yang cocok.
- Material belum ter-map.
- Historical line gagal dikelompokkan karena dimensi kosong.
- Kurs tahunan tidak tersedia.
- Duplicate/ambiguous Rule configuration.
- Snapshot source mismatch.
- Calculation source count tidak sesuai aggregate count.

Jangan memasukkan harga atau identitas Supplier sensitif pada log umum bila tidak diperlukan.

---

# 17. Strategi Migrasi dan Rollout

## 17.1 Sebelum Migration

Lakukan read-only report:

- Jumlah `material_reference_prices` manual.
- Jumlah `award_reference_price_snapshots` lama per status.
- Jumlah PO menurut raw status.
- Jumlah PO yang cancelled/soft-deleted.
- Jumlah historical PO item dengan `material_master_id` null.
- Jumlah historical item dengan key dimension null.
- Jumlah currency/year yang belum memiliki Annual Exchange Rate.
- Jumlah duplicate material name yang mengarah ke Master berbeda.

Jangan mulai cutover sebelum kualitas data ini diketahui.

## 17.2 Migration

1. Tambah tabel Rule Set, settings, rules, imports.
2. Tambah tabel calculation snapshots dan source details.
3. Tambah nullable FK/metadata pada `award_reference_price_snapshots`.
4. Backfill `reference_price_source = legacy_manual` untuk snapshot lama (default additive yang aman).
5. Pertahankan `material_reference_prices` apa adanya.
6. Seed initial Rule Set dari `Kategori Range.xlsx` sebagai versi 1.
7. Verifikasi 11 kategori dan coverage 1–700.
8. Aktifkan initial Rule Set setelah validasi.

## 17.3 Cutover

- Resolver baru mulai digunakan pada Review Quotation, Inter-Supplier, Historical Price.
- Resolver manual tidak lagi dipakai sebagai benchmark current.
- Menu Legacy Archive hanya read-only.
- Snapshot PO baru menggunakan Historical Rules.
- Snapshot PO lama tetap menggunakan manual legacy reference.

## 17.4 Rollback

- Jangan rollback/drop tabel Legacy.
- Jika fitur baru dinonaktifkan, route/UI baru dapat dimatikan melalui feature flag.
- `material_reference_prices` dan snapshot lama tetap utuh.
- Rule Set baru tetap tersimpan untuk analisis; jangan drop karena mungkin sudah direferensikan snapshot.
- PO yang sudah dibuat dengan snapshot baru tidak boleh diubah.

---

# 18. Implementation Breakdown per Tahap

Urutan berikut adalah urutan implementasi yang direkomendasikan. Ini bukan implementasi kode dan bukan deployment plan.

## Tahap 0 — Repository Preflight dan Contract Check

**Aktivitas:**

- Baca `AGENTS.md`, project cognitive framework, `DESIGN.md`, coding/testing conventions.
- Periksa status Git lokal, branch, dirty/untracked files, dan jangan menimpa perubahan lokal.
- Audit ulang commit aktif dan file terkait Reference Prices.
- Audit semua consumer `ReferencePriceResolver`, `ReferencePriceResult`, `material_reference_prices`, `award_reference_price_snapshots`.
- Audit status PO mentah dan definisi status kanonis.
- Audit material mapping coverage dan annual FX coverage.
- Identifikasi current tests dan fixtures yang harus dipertahankan.

**Output:** Preflight report dan exact file impact list.

**Acceptance:** Tidak ada implementasi sebelum dependency/contract diketahui.

## Tahap 1 — Rules Data Model, CRUD, dan Import

**Aktivitas:**

- Buat migration Rule Set, setting pointer, rules, import audit.
- Buat model dan policy.
- Implement parser workbook asli `Kategori`.
- Implement Replace/Merge.
- Implement preview diff dan validation.
- Implement activation transaction dan version archive.
- Implement UI Rules, version history, import preview.
- Seed initial 11 ranges.

**Output:** Admin/Purchasing dapat mengelola Rule Set dan import kategori.

**Acceptance:** Semua 11 kategori benar, versi lama aman, konflik overlap menolak aktivasi.

## Tahap 2 — Rule Resolver dan Historical Calculation Engine

**Aktivitas:**

- Buat `ReferencePriceRuleResolver`.
- Implement mapping Shape → key dimension.
- Implement CEIL dengan decimal-safe logic.
- Tambah strict Material Master filter.
- Tambah Rule Category filter pada historical PO source.
- Reuse ordered line price/KG logic.
- Implement grouping per tahun kalender.
- Implement annual currency conversion.
- Implement metrics dan reason code.
- Implement detail source PO lines.
- Buat unit tests calculation.

**Output:** Benchmark tahunan dapat dihitung untuk material/rule/currency tertentu.

**Acceptance:** Hasil cocok dengan perhitungan manual di fixture test; transaksi invalid dan currency unavailable ditangani.

## Tahap 3 — Review Quotation dan Inter-Supplier Comparison

**Aktivitas:**

- Ganti pemakaian legacy resolver pada Review Quotation.
- Hitung Main Basis dari PR dimension.
- Hitung supplier alternative bila category berbeda.
- Batch calculations pada halaman Inter-Supplier.
- Perbarui price delta dan label.
- Tambahkan source drilldown.
- Pastikan supplier UI/JSON tidak mendapatkan data internal.

**Output:** Purchasing menggunakan benchmark otomatis saat review dan membandingkan quotation.

**Acceptance:** Semua Supplier dibandingkan dengan Main PR benchmark yang sama; alternative tidak menggantikan baseline.

## Tahap 4 — Historical Price Integration

**Aktivitas:**

- Tambahkan Company-wide Reference Price panel.
- Tambahkan filter category dan yearly summary.
- Tambahkan current-year YTD highlight.
- Tambahkan histori tahun sebelumnya.
- Tambahkan PO source drilldown.
- Pisahkan benchmark seluruh Supplier dari filter histori supplier.
- Support PR-context mode jika material/specification tersedia.

**Output:** Purchasing dapat melihat pergerakan benchmark tahunan dan menelusuri sumber.

**Acceptance:** Filter Supplier tidak mengubah angka company-wide benchmark.

## Tahap 5 — Snapshot dan PO Finalization Integration

**Aktivitas:**

- Buat calculation snapshot dan source detail migrations/models.
- Perluas `award_reference_price_snapshots`.
- Implement batch precompute seluruh Award sebelum PO pertama dibuat.
- Deduplicate calculation keys.
- Persist main dan alternative snapshot pointer.
- Simpan history_as_of_at, Rule Set version, source metrics, FX metadata.
- Pastikan semua insert berada dalam transaksi PO.
- Pertahankan legacy snapshots.

**Output:** PO baru menyimpan bukti benchmark immutable.

**Acceptance:** Snapshot PO tidak berubah setelah histori/Rules berubah dan PO batch tidak saling masuk sebagai histori.

## Tahap 6 — Legacy Archive, Authorization, dan Regression

**Aktivitas:**

- Buat Legacy Archive read-only.
- Disable/guard manual write routes untuk alur baru.
- Samakan route, sidebar, PageRegistry, policy Admin/Purchasing.
- Jalankan seluruh test suite terkait procurement/pricing/master data.
- Jalankan permission/data leakage tests.
- Uji migration pada database yang berisi data legacy.
- Susun final implementation report.

**Output:** Feature siap untuk review dan deployment terpisah.

**Acceptance:** Tidak ada regression pada data manual, snapshot lama, histori PO, Supplier access, atau PO generation.

---

# 19. Acceptance Criteria

## 19.1 Rules

- [ ] Import `Kategori Range.xlsx` menghasilkan tepat 11 kategori.
- [ ] Parser memahami label kategori hanya pada baris pertama kelompok.
- [ ] Range aktif tidak boleh overlap.
- [ ] Replace benar-benar mengganti seluruh kandidat Rule Set.
- [ ] Merge mempertahankan kategori yang tidak ada dalam file.
- [ ] Konflik Merge ditampilkan sebelum aktivasi.
- [ ] Rule Set lama tetap tersedia dan tidak dapat diedit setelah archived.
- [ ] Hanya satu Rule Set aktif.
- [ ] Nilai dimensi di luar 1–700 menghasilkan Not Covered.

## 19.2 Dimension Matching

- [ ] Flat menggunakan thickness.
- [ ] Round menggunakan d_outer.
- [ ] Hollow menggunakan d_outer.
- [ ] 15.5 menjadi 16 dan masuk 16–50.
- [ ] 50.5 menjadi 51 dan masuk 51–100.
- [ ] 700.5 menjadi 701 dan tidak memiliki Rule.
- [ ] Nilai raw tetap tersedia untuk display/audit.

## 19.3 Historical PO Source

- [ ] Hanya PO eligible digunakan.
- [ ] PO cancelled dikecualikan.
- [ ] PO soft-deleted dikecualikan.
- [ ] Quotation soft-deleted dikecualikan.
- [ ] Unavailable lines dikecualikan.
- [ ] Harga nol/kosong/negatif dikecualikan.
- [ ] KG nol/kosong/negatif dikecualikan.
- [ ] PO Award baru memakai frozen/selected ordered values.
- [ ] PO legacy tanpa Award tetap menggunakan fallback existing.
- [ ] Material Master ID tidak tercampur melalui nama material.
- [ ] Satu line PO tidak dihitung lebih dari sekali.

## 19.4 Calculation

- [ ] Weighted Average menggunakan KG.
- [ ] Average dihitung terpisah per tahun.
- [ ] Current Year YTD menjadi Main Benchmark.
- [ ] Tahun sebelumnya hanya histori.
- [ ] Tidak ada current-year data berarti Not Available, bukan fallback diam-diam ke tahun lalu.
- [ ] Annual Exchange Rate memakai tahun PO.
- [ ] Currency dengan kurs tidak tersedia dikecualikan dan dilaporkan.
- [ ] Lowest, Highest, Last, PO Count, Line Count, Total KG akurat.
- [ ] Semua arithmetic menggunakan BCMath/`Money` dan tidak bergantung pada float di browser.

## 19.5 PR dan Supplier Basis

- [ ] Main benchmark selalu menggunakan PR dimension.
- [ ] Supplier dimension dalam kategori sama menggunakan Main benchmark.
- [ ] Supplier dimension kategori berbeda menghasilkan alternative benchmark.
- [ ] Alternative tidak mengubah main delta.
- [ ] Dimensi supplier kosong tidak menghilangkan main benchmark.

## 19.6 UI

- [ ] Review Quotation menampilkan main benchmark dan alternative bila relevan.
- [ ] Inter-Supplier menampilkan benchmark bersama per PR item.
- [ ] Historical menampilkan current-year dan histori per tahun.
- [ ] Company-wide benchmark tidak terpengaruh filter Supplier.
- [ ] Vs-Best tidak diubah.
- [ ] Create PR tidak menampilkan Reference Price.
- [ ] Legacy Archive read-only.

## 19.7 Snapshot

- [ ] Snapshot dibuat ketika PO difinalisasi.
- [ ] Snapshot menggunakan benchmark Main PR basis.
- [ ] Alternative snapshot tersimpan bila kategori supplier berbeda.
- [ ] Semua benchmark batch dihitung sebelum PO pertama dibuat.
- [ ] Snapshot mereferensikan Rule Set/Rule version.
- [ ] Snapshot menyimpan history cutoff, metrics, dan source details.
- [ ] Snapshot tidak berubah setelah PO historis atau Rules berubah.
- [ ] Snapshot manual lama tetap utuh.
- [ ] PO lama tanpa snapshot tetap Not Captured; tidak di-backfill.

## 19.8 Security

- [ ] Admin dan Purchasing dapat mengelola sesuai policy.
- [ ] Supplier tidak dapat membuka endpoint Rules.
- [ ] Supplier tidak dapat mengakses Reference Price endpoint.
- [ ] Supplier quotation page/JSON/export tidak mengandung Reference Price.
- [ ] Direct URL dan JSON route diuji, bukan hanya visibility sidebar.

---

# 20. Test Plan

## 20.1 Unit Tests — Rule Resolver

1. Flat 15 mm → 1–15.
2. Flat 15.5 mm → 16–50.
3. Round diameter 50.5 mm → 51–100.
4. Hollow outer diameter 100.2 mm → 101–150.
5. Inner Diameter tidak menjadi key dimension Hollow.
6. Unsupported Shape → reason code.
7. Missing dimension → reason code.
8. Dimension 701 → not covered.
9. Duplicate/overlapping active rule → reject/ambiguous guard.

## 20.2 Unit Tests — Historical Calculation

1. Tiga PO dengan harga/volume berbeda menghasilkan Weighted Average yang benar.
2. Baris dari tahun berbeda tidak tercampur.
3. Current-year YTD terpisah dari previous year.
4. Material ID berbeda tidak tercampur meskipun material_name sama.
5. Shape berbeda dikecualikan.
6. Category berbeda tidak masuk main calculation.
7. Decimal key dimension menggunakan CEIL.
8. Kurs tahunan source/target tersedia.
9. Kurs tahunan source/target tidak tersedia.
10. Excluded KG/line/currency dihitung.
11. Last price tie-breaker deterministik.
12. Tidak ada histori menghasilkan `no_historical_po`.

## 20.3 Feature Tests — Import

1. Import workbook asli berhasil.
2. Header missing ditolak.
3. Sheet missing ditolak.
4. Kategori tidak valid ditolak.
5. Label tidak sesuai nilai numeric range ditolak.
6. Duplicate numeric values ditolak.
7. Numeric gap dalam grup ditolak.
8. Replace menghasilkan kategori persis file.
9. Merge mempertahankan kategori non-file.
10. Merge overlap menolak activation.
11. File yang sama/cek checksum tidak membuat perubahan ganda tanpa konfirmasi.
12. Hanya Admin/Purchasing dapat preview/commit.

## 20.4 Feature Tests — UI Integration

1. Review Quotation baseline berasal dari PR dimension.
2. Offer same category tidak membuat alt benchmark duplicate.
3. Offer different category membuat alternative.
4. Inter-Supplier semua supplier dibandingkan pada satu main baseline.
5. Supplier best price tidak menentukan baseline.
6. Historical page menampilkan yearly company benchmark.
7. Supplier filter tidak mengubah company benchmark.
8. Vs-Best tidak memiliki reference price output.
9. Create PR tidak memuat Reference Price.

## 20.5 Feature Tests — Snapshot

1. Snapshot main dibuat untuk setiap Award/PO line.
2. Alternative snapshot dibuat bila kategori beda.
3. Semua calculation precompute sebelum PO insert.
4. Batch dengan dua supplier tidak self-include.
5. PO generation failure rollback snapshot/calculation data.
6. Rule Set berubah setelah PO: snapshot tetap sama.
7. PO historis baru ditambahkan setelah snapshot: snapshot tetap sama.
8. Manual legacy snapshot tidak berubah.
9. Update/delete snapshot gagal.
10. PO lama tanpa snapshot tetap Not Captured.

## 20.6 Regression Tests

- `ReferencePriceTest`
- `ReferencePriceConcurrencyTest`
- `ReferencePriceVersionResolutionTest`
- `PurchaseHistoryPriceService` tests
- `HistoricalPriceQueryService` tests
- `PurchaseOrderGenerationService` tests
- Supplier quotation page authorization tests
- Master Data policy/routes tests

Daftar test aktual harus diverifikasi saat preflight karena nama/coverage branch dapat berubah.

---

# 21. Risiko dan Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Historical PO belum punya Material Master ID | Histori tidak masuk benchmark | Data-quality report dan material alias reconciliation sebelum cutover. |
| PO status legacy ambigu | Draft dapat masuk benchmark atau PO valid terbuang | Audit raw status dan mapping ke PurchaseOrderStatus sebelum query whitelist ditetapkan. |
| FX rate tahunan tidak lengkap | Sebagian transaksi dikecualikan | Tampilkan excluded count/KG/currency; jangan pakai kurs palsu. |
| Rule import mengubah kategori aktif | Benchmark live bisa berubah | Versioned Rule Set, preview, audit, snapshot immutable. |
| Merge menyebabkan overlap | Hasil resolver ambigu | Validasi overlap sebelum activation. |
| Offer dimension berbeda dari PR | Baseline tidak konsisten | Main PR basis + separate supplier alternative. |
| PO batch self-inclusion | Snapshot bias | Precompute semua calculation sebelum PO pertama dibuat. |
| Query N+1 di Inter-Supplier | Halaman lambat | Batch resolver dan request memoization. |
| Material name duplikat | Histori material salah | Strict `material_master_id`; legacy mapping terkontrol. |
| Supplier memperoleh harga internal | Kebocoran data komersial | Policy, route middleware, JSON/export tests. |
| Snapshot aggregate sulit diaudit | Harga tidak dapat direkonstruksi | Calculation snapshot + source detail + FX metadata. |
| Data manual lama dianggap automatic | Benchmark membingungkan | Legacy Archive read-only dan source label. |

---

# 22. Preflight Checklist untuk Implementasi

Hal ini harus diperiksa sebelum mulai coding. Jika hasilnya berbeda dari asumsi dokumen, update plan/contract sebelum mengubah data.

## 22.1 Repository

- [ ] Branch dan HEAD terbaru.
- [ ] `AGENTS.md`/project instructions.
- [ ] `DESIGN.md` dan cognitive framework.
- [ ] Dirty/untracked changes.
- [ ] Seluruh consumer `ReferencePriceResolver`.
- [ ] Seluruh consumer `ReferencePriceResult`.
- [ ] Semua route names dan PageRegistry keys.
- [ ] Semua UI consumers `referencePrices`.
- [ ] Test suite terkait.

## 22.2 Database/Data

- [ ] Raw Purchase Order status distribution.
- [ ] Cancelled/soft-deleted PO counts.
- [ ] PO lines dengan award dan tanpa award.
- [ ] Data `material_master_id` coverage.
- [ ] Data key dimension coverage per Shape.
- [ ] Historical item offered dimension vs PR dimension.
- [ ] Currency per tahun dan rate coverage.
- [ ] Snapshot lama dan Legacy manual count.

## 22.3 Business/Calculation

- [ ] Konfirmasi semua PO status yang berarti issued.
- [ ] Pastikan business timezone yang berlaku.
- [ ] Pastikan target currency default pada masing-masing tampilan.
- [ ] Pastikan definisi last purchase ketika timestamp sama.
- [ ] Pastikan volume histori cukup untuk performance plan.
- [ ] Audit apakah semua Supplier memang masuk populasi company-wide benchmark.

## 22.4 Import

- [ ] Konfirmasi file template yang dipakai user adalah format resmi.
- [ ] Pastikan apakah kategori custom yang ditambahkan harus berisi semua integer di range seperti workbook sumber.
- [ ] Pastikan policy upload size/type.
- [ ] Uji parser workbook aktual di feature test.

---

# 23. Definition of Done

Fitur dinyatakan selesai jika:

1. Rules dapat dibuat/diubah/dinonaktifkan melalui versi konfigurasi.
2. Import Excel mendukung Replace dan Merge disertai preview dan validasi.
3. Semua Rule awal sesuai 11 kategori di workbook.
4. Benchmark membaca PO historis eligible berdasarkan material, Shape, dan kategori.
5. Weighted Average dihitung per tahun dan current-year YTD menjadi nilai utama.
6. Currency dinormalisasi berdasarkan Annual Exchange Rate pada tahun transaksi.
7. Review Quotation dan Inter-Supplier memakai PR sebagai baseline; alternative Supplier tampil bila kategori berbeda.
8. Historical Price menampilkan benchmark company-wide dan histori tahunan tanpa ikut terfilter Supplier.
9. Vs-Best dan Create PR tidak menerima integrasi baru.
10. PO finalization menyimpan snapshot immutable untuk main dan alternative yang relevan.
11. Batch PO tidak memasukkan PO yang sedang dibuat ke dalam sumber perhitungannya.
12. Data manual lama dan snapshot lama tetap tersedia sebagai Legacy.
13. Admin/Purchasing authorization konsisten; Supplier tidak dapat mengakses data internal.
14. Unit/feature/regression tests lulus.
15. Migration berjalan pada database yang berisi data legacy tanpa kehilangan data.
16. Antigravity/implementer memberikan daftar file berubah, migration, test result, known limitations, dan bukti bahwa tidak ada deployment/commit tanpa instruksi.

---

# 24. Referensi Repository

**Repository audit reference:** `master` at commit `79b56a60149a44140ff3e810ad35d2de5a0ba449`.

## Core pricing and history

- [MaterialReferencePrice model](https://github.com/poyipoy/supplierportal/blob/master/app/Models/MaterialReferencePrice.php)
- [MaterialReferencePriceService](https://github.com/poyipoy/supplierportal/blob/master/app/Services/Pricing/MaterialReferencePriceService.php)
- [ReferencePriceResolver](https://github.com/poyipoy/supplierportal/blob/master/app/Services/Pricing/ReferencePriceResolver.php)
- [PurchaseHistoryPriceService](https://github.com/poyipoy/supplierportal/blob/master/app/Services/Pricing/PurchaseHistoryPriceService.php)
- [HistoricalPriceQueryService](https://github.com/poyipoy/supplierportal/blob/master/app/Services/Pricing/HistoricalPriceQueryService.php)
- [AnnualExchangeRateResolver](https://github.com/poyipoy/supplierportal/blob/master/app/Services/Pricing/AnnualExchangeRateResolver.php)
- [EvaluationContext](https://github.com/poyipoy/supplierportal/blob/master/app/Support/Pricing/EvaluationContext.php)

## PO and snapshot

- [PurchaseOrderGenerationService](https://github.com/poyipoy/supplierportal/blob/master/app/Services/PurchaseOrderGenerationService.php)
- [AwardReferencePriceSnapshot model](https://github.com/poyipoy/supplierportal/blob/master/app/Models/AwardReferencePriceSnapshot.php)
- [Reference Price table migration](https://github.com/poyipoy/supplierportal/blob/master/database/migrations/2026_10_14_000006_create_material_reference_prices_table.php)
- [Award Reference Price snapshot migration](https://github.com/poyipoy/supplierportal/blob/master/database/migrations/2026_10_14_000007_create_award_reference_price_snapshots_table.php)
- [PurchaseOrderStatus enum](https://github.com/poyipoy/supplierportal/blob/master/app/Enums/PurchaseOrderStatus.php)
- [OrderedLine](https://github.com/poyipoy/supplierportal/blob/master/app/Support/Procurement/OrderedLine.php)

## UI, routes, and authorization

- [MaterialReferencePriceController](https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Purchasing/MaterialReferencePriceController.php)
- [QuotationListController](https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Purchasing/QuotationListController.php)
- [InterSupplierComparisonController](https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Purchasing/InterSupplierComparisonController.php)
- [HistoricalPriceComparisonController](https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Purchasing/HistoricalPriceComparisonController.php)
- [ReferencePrice policy](https://github.com/poyipoy/supplierportal/blob/master/app/Policies/MaterialReferencePricePolicy.php)
- [Routes](https://github.com/poyipoy/supplierportal/blob/master/routes/web.php)
- [Reference Prices view](https://github.com/poyipoy/supplierportal/blob/master/resources/views/purchasing/reference-prices/index.blade.php)
- [Quotation item table](https://github.com/poyipoy/supplierportal/blob/master/resources/views/quotations/_items_readonly_table.blade.php)
- [Inter-Supplier view](https://github.com/poyipoy/supplierportal/blob/master/resources/views/purchasing/comparison/_inter_supplier_content.blade.php)
- [Historical Price view](https://github.com/poyipoy/supplierportal/blob/master/resources/views/purchasing/comparison/_historical_content.blade.php)

## Existing HS Code Rules pattern

- [HsCodeRule model](https://github.com/poyipoy/supplierportal/blob/master/app/Models/HsCodeRule.php)
- [HsCodeResolver](https://github.com/poyipoy/supplierportal/blob/master/app/Services/Materials/HsCodeResolver.php)
- [HsCodeRuleConflictDetector](https://github.com/poyipoy/supplierportal/blob/master/app/Services/Materials/HsCodeRuleConflictDetector.php)
- [HsCodeRuleController](https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Admin/HsCodeRuleController.php)

---

**End of document.**  
Dokumen ini adalah rancangan implementasi. Belum ada kode, migration, konfigurasi, atau data repository yang diubah.
