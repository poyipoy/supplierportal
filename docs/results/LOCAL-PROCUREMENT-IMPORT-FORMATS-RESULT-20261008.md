# Hasil dukungan CSV/XLS dan penghapusan Import PO + GR

Tanggal: 8 Oktober 2026. Branch: `master`. HEAD awal dan akhir: `33258819c6283de284eb9201a446caaadb7484a5`.

Implementasi tersedia sebagai perubahan lokal **unstaged**. Tidak ada staging, commit, push, pergantian branch, reset, stash, atau clean pada task ini. Perubahan pengguna dan pekerjaan sebelumnya tetap dipertahankan. Snapshot status lengkap tersedia di [working-tree status](LOCAL-PROCUREMENT-IMPORT-FORMATS-WORKTREE-20261008.txt).

## Implementasi

- Import PO dan GR menerima XLSX, CSV, dan XLS biner, maksimum 50 MiB. Pemeriksaan ekstensi dan isi dipusatkan pada `LocalProcurementStreamingReader`; file ZIP/HTML/PDF yang menyamar, XLS rusak/terenkripsi, dan arsip XLSX tidak valid ditolak.
- XLSX memakai OpenSpout existing. CSV memakai `fgetcsv`, deteksi koma/titik koma/tab dari maksimal 20 record, dan konversi stream untuk UTF-16 BOM/Windows-1252. UTF-8 dengan/tanpa BOM didukung. Fallback Windows-1252 memberi peringatan preview; delimiter ambigu ditolak.
- XLS memakai PhpSpreadsheet existing: worksheet pertama, filter kolom yang diperlukan dan jendela 2.000 baris data. Workbook dilepas setiap jendela; kalender 1900/1904 dipertahankan lalu state library dipulihkan. Formula dibaca sebagai formula, tanpa kalkulasi ataupun penggunaan cached result untuk melewati validasi.
- Posisi Infor dipertahankan, termasuk kolom kosong dan unit GR pada **Q/index 16**. Identifier teks berawalan nol, angka desimal titik, whitelist UOM, batas tiga desimal, dan larangan formula tetap berlaku. Tidak ada konversi angka regional yang ambigu.
- Batas XLSX/CSV adalah 70.000 baris data; XLS 65.535 baris data dan satu header. Staging tetap flush 500 baris, preview 100 record per halaman, private upload, checksum, ownership/token, locale EN/ID, dan job scalar pada queue `imports`.
- Confirm memvalidasi kembali data master dan otorisasi di bawah row lock. Seluruh master dan audit dalam satu transaksi file; guard transaksi **60 detik tetap digunakan**. Snapshot invoice existing tidak diubah.
- Lookup PO memakai unique index existing secara eksplisit. EXPLAIN yang direkam menunjukkan optimizer memilih full scan untuk IN-list besar, sedangkan hint memakai range pada `local_purchase_orders_po_number_unique`. Tidak ada indeks/migrasi atau perubahan konfigurasi MySQL baru.
- Jenis aktif hanya `PO` dan `GR`. Guard umum membatalkan pekerjaan historis dengan jenis unsupported. Status historis tidak menawarkan token/URL confirm; master, invoice, attachment dan audit bisnis tidak dihapus untuk pencabutan fitur.

Tidak ada package atau schema baru **untuk revisi format/penghapusan ini**. OpenSpout dan migrasi `2026_10_08_000001_create_local_procurement_import_staging.php` berasal dari pekerjaan import besar sebelumnya yang sudah ada dalam working tree. Migrasi registration/compliance yang juga ada dalam working tree bukan bagian revisi ini.

## Kode dan route

Reader baru: `LocalProcurementCsvReader`, `LocalProcurementXlsReader`. Reader bersama, `LocalProcurementImportService`, `LocalProcurementImport`, `LocalProcurementImportController`, `CleanupLocalProcurementImports`, bulk master service, PO/GR validators dan upload requests diperbarui. Modal PO/GR, JS import, terjemahan EN/ID dan panduan operasional diselaraskan.

Dihapus: `LocalPoGrImport`, `LocalPoGrImportService`, `LocalPoGrImportTemplateExport`, action controller `template`/`preview`/`confirm` gabungan, modal/config/renderer gabungan dari pekerjaan sebelumnya, dan `LocalPoGrQuantityTemplateTest`. Tes bisnis yang relevan menggunakan service PO dan GR terpisah. Orchestration tidak lagi mengiterasi dua jenis dalam satu file atau menyimpan field `gr_remarks` khusus gabungan.

Enam route yang dihapus:

| Prefix | Method | URI suffix | Route suffix |
|---|---|---|---|
| finance.local-procurement | GET | /import/template | import.template |
| finance.local-procurement | POST | /import/preview | import.preview |
| finance.local-procurement | POST | /import/confirm | import.confirm |
| purchasing.local-procurement | GET | /import/template | import.template |
| purchasing.local-procurement | POST | /import/preview | import.preview |
| purchasing.local-procurement | POST | /import/confirm | import.confirm |

Route template/preview/confirm PO dan GR terpisah dipertahankan. Sepuluh route status/history/records/errors/cancel (lima per prefix) berasal dari implementasi background sebelumnya; dukungan format baru tidak menambah endpoint format tersendiri. Pemeriksaan route menemukan 46 route Local Procurement, tanpa keenam nama lama; tes HTTP membuktikan keenam URI lama menghasilkan 404.

## Verifikasi eksekusi

PHP 8.2.30; MySQL 8.0.30. `.env.testing` dan konfigurasi PHPUnit diinspeksi, serta database aktif diperiksa. Pengujian dan benchmark database dijalankan serial.

| Pemeriksaan | Hasil |
|---|---|
| Suite LocalInvoice + reader + translation parity + BusinessTime pada `adasi_import_formats_test` | **192 tes, 41.026 assertion, lolos** |
| `LocalInvoiceMigrationTest` pada database yang dipersyaratkan | 1 tes, 10 assertion, lolos |
| `LocalInvoiceConcurrencyTest`, proses tersendiri | 2 tes, 17 assertion, lolos |
| `LocalGrReservationConcurrencyTest`, proses tersendiri | 1 tes, 7 assertion, lolos |
| `LegacyDatabaseUpgradeTest` dan database safety | Lolos pada run serial database bersama; safety 2 tes/4 assertion |
| `RouteContractTest` | 7 tes, 18 assertion, lolos |
| `node --test tests/js/local-po-preview-localization.test.mjs` | 5 tes EN/ID PO/GR, lolos |
| PHP lint | 24 file terkait lolos; file yang diformat dicek ulang |
| Scoped Pint | Lolos |
| `npm.cmd run build` | Lolos; warning existing logo runtime asset |
| `php artisan view:cache`, `route:cache` dan pemeriksaan route | Lolos; route contract dicek ulang setelah cache |
| `php artisan local-invoices:reconcile` | Tidak ada issue ditemukan pada data lokal |
| Scoped `git diff --check` | Lolos |
| Global `git diff --check` | Ada trailing whitespace existing pada `context.md:3,5,6`; tidak diubah |

Coverage mencakup 2.001 baris untuk PO/GR pada tiga format, hasil master/audit setara, CSV delimiter/BOM/encoding/quoted multiline, blank Q heading, leading zero, shared-string XLS lintas jendela, cached formula XLS, tanggal 1904, worksheet tambahan, file palsu/rusak/terenkripsi, mixed UOM lintas batch, ownership/pagination, stale delivery fencing, rollback setelah write batch pertama, supplier/master berubah, dan admission pause.

Catatan run gagal yang tidak disembunyikan: run awal memasukkan empat tes yang guard-nya mengharuskan nama `adasi_portal_test`; mereka menolak schema alternatif. Guard tidak dilonggarkan. Run gabungan pada database bersama kemudian mengalami tabel/kolom yang hilang saat eksekusi. Tes migration/concurrency tersebut **lolos ketika dijalankan terpisah** pada database yang diwajibkan. Karena itu hasil ini bukan klaim bahwa satu run gabungan seluruh suite proyek hijau. Konfigurasi sementara dan storage test alternatif berada dalam direktori testing yang di-ignore; `.env.testing` dan `phpunit.xml` proyek tidak diubah.

Perintah utama:

```text
php vendor/bin/phpunit -c storage/framework/testing/import-format-isolation/phpunit-import-formats.xml --do-not-cache-result --colors=never
php artisan test tests/Feature/LocalInvoice/LocalInvoiceMigrationTest.php --compact --do-not-cache-result
php artisan test tests/Feature/LocalInvoice/LocalInvoiceConcurrencyTest.php --compact --do-not-cache-result
php artisan test tests/Feature/LocalInvoice/LocalGrReservationConcurrencyTest.php --compact --do-not-cache-result
php artisan test tests/Feature/RouteContractTest.php --compact --do-not-cache-result
node --test tests/js/local-po-preview-localization.test.mjs
php vendor/bin/pint --test <file import terkait>
npm.cmd run build
php artisan view:cache
php artisan route:cache
php artisan local-invoices:reconcile
git diff --check
```

## Bukti kapasitas

Semua kasus sukses berikut mencapai `COMPLETED`, satu delivery preview dan satu delivery confirm. Matriks dikumpulkan selama perbaikan dan optimasi import; pengujian ulang sesudah cleanup orchestration akhir dicatat di bawah tabel. Jumlah master dan audit penciptaan sama dengan jumlah data. Memory adalah working set proses OS yang disampling; writer fixture XLS berjalan pada subprocess terpisah, dengan heap fixture lebih besar yang **bukan limit worker aplikasi**. Payload job 897 byte. Batas aplikasi/worker dan guard transaksi tidak dinaikkan.

| Jenis/format | Baris | Preview detik | Confirm detik | Peak OS MiB |
|---|---:|---:|---:|---:|
| PO XLSX | 20.000 | 14,018 | 33,984 | 75,44 |
| GR XLSX | 20.000 | 13,270 | 11,603 | 79,88 |
| PO CSV | 20.000 | 7,212 | 37,448 | 74,19 |
| GR CSV | 20.000 | 17,356 | 8,803 | 77,28 |
| PO XLS | 20.000 | 12,312 | 34,299 | 92,04 |
| GR XLS | 20.000 | 15,018 | 5,612 | 120,59 |
| PO XLSX | 70.000 | 58,336 | 43,216 | 76,59 |
| GR XLSX | 70.000 | 59,580 | 45,840 | 82,24 |
| PO CSV | 70.000 | 29,235 | 24,267 | 75,30 |
| GR CSV | 70.000 | 26,654 | 31,096 | 78,96 |
| PO XLS | 65.535 | 52,654 | 18,110 | 132,66 |
| GR XLS | 65.535 | 139,273 | 42,986 | 201,92 |

Setelah cleanup orchestration akhir, PO CSV 20.000 baris pada schema alternatif: preview 7,244 detik, confirm 6,325 detik, peak OS 74,21 MiB; tepat 20.000 master dan 20.000 audit. Confirm mencatat 499 query, termasuk 40 batch insert master dan 41 batch insert audit, sehingga tidak menulis satu SQL insert per record.

PO/GR CSV dan XLSX **70.001 baris** pada schema alternatif mencapai `VALIDATION_FAILED` dengan pesan batas 70.000, **nol master dan nol audit penciptaan**. Preview masing-masing 7,145 / 5,461 / 15,400 / 19,393 detik. Percobaan GR CSV 70.001 sebelumnya pada database bersama terputus ketika tabel staging hilang; artefaknya dipertahankan sebagai run gagal, bukan bukti sukses.

Data mentah, durasi/delivery, SQL shapes dan sampling OS: [benchmark formats](local-import-formats-benchmarks-20261008/). Benchmark ini lokal sintetis dengan zero historical fixture rows, bukan SLA produksi atau pengujian 500.000 historical rows. Artefak benchmark gabungan dari fase lama tetap dipertahankan sebagai sejarah dan tidak merepresentasikan workflow aktif.

## Worker, sisa referensi, dan batas verifikasi

Admission dijeda melalui cache internal, antrean dikonfirmasi kosong, cleanup existing dijalankan, dan worker direstart dengan signal graceful. PID lama 29424 keluar; satu worker pengganti PID 29056 berjalan memakai argumen existing `database --queue=imports --sleep=1 --tries=3 --timeout=300 --memory=384`. Admission dipulihkan. Preflight akhir: nol pekerjaan aktif, nol pending job imports, nol unsupported active. Tidak ada worker duplikat atau konfigurasi MySQL yang diubah. Tidak ada perubahan master/invoice/audit aplikasi untuk verifikasi kapasitas.

Search aktif terhadap nama class/service/template/route/selector gabungan tidak menemukan workflow tersisa. `COMBINED` pada fixture regression dipertahankan untuk membuktikan penolakan/preservasi histori. Kolom `kind` staging tetap bertipe string agar histori tidak dihapus; guard membatasi jenis aktif. Dokumen plan/audit/result terdahulu adalah sejarah; panduan deployment yang masih operasional diperbarui melalui hunk import terkait. `combinedDescription`, konsolidasi PR, ikon `chart-no-axes-combined`, dan mapping PO-ke-GR pada form invoice adalah fungsi lain yang tetap valid.

Belum diverifikasi: browser upload melalui web-SAPI, restored staging/production, contention multi-operator, dan kapasitas dengan historical dataset besar untuk revisi format ini. PHP CLI yang sama memiliki upload/post limit 2G; batas efektif web/proxy tetap perlu diperiksa saat deployment. Windows tidak memiliki PCNTL sehingga timeout keras membutuhkan supervision/watchdog deployment. Pengujian capacity di atas mencapai terminal melalui worker Laravel sebenarnya pada database test; bukan klaim QA browser atau deployment produksi.

Unrelated dirty work yang tetap ada meliputi presentasi Excel, account settings/async forms, registrasi/compliance/company profile, invoice submission UI, dokumentasi AGENTS/CLAUDE/context, dan copy-audit UI. Root implementation plan, `PNR261178.pdf`, dan `skills-lock.json` dipertahankan. Detail file tercantum pada snapshot working tree; index Git tetap kosong.
