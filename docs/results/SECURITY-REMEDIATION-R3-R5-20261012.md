# Hasil remediasi R3–R5 — 12 Oktober 2026

Implementasi mengikuti [rencana pelaksanaan](../plans/SECURITY-REMEDIATION-R3-R5-20261012.md), dengan mempertahankan perubahan R1/R2 yang sudah ada. Saat fase remediasi, tidak ada commit, push, reset, clean, atau migrasi aplikasi. Atas instruksi lanjutan, ketiga migrasi R3–R5 kemudian diterapkan hanya ke database lokal. Suite yang memakai `RefreshDatabase` membuat skema dan fixture pada database tes `adasi_portal_test`, dengan proses tes dijalankan serial.

## Perubahan perilaku

**R3:** Hashids memakai salt eksplisit dan menolak salt kosong/placeholder di produksi; default akun baru menjadi PENDING melalui migrasi default tanpa backfill. Seeder akun internal menetapkan ACTIVE secara eksplisit. Controller/view yang tidak memiliki route dan route pembayaran tanpa action dihapus. Dokumentasi policy dan presisi quantity GR diselaraskan. Guard CI menolak arsip ZIP dan data private yang terlacak; scaffold `.gitignore` private tetap diperbolehkan.

**R4 (revisi keputusan):** Satu Finance boleh menerima fisik, memverifikasi Section A/B, Lock & Approve Ready to Pay, membuat/finalize batch supplier/GA, membuat voucher dan melakukan settlement/koreksi tanpa override admin karena kesamaan aktor. Form tahap Finance tidak lagi meminta alasan override maker-checker. Flag Finance mengatur kelengkapan aktor; historis tanpa aktor tetap diaudit dan baris baru dengan aktor hilang tetap ditolak. Basic verification GA tetap mengikuti role GA, berbeda dari submitter dengan override admin beralasan; resubmit mengikuti ownership policy. Perubahan ini tidak memberi role Finance izin melakukan basic verification GA.

Rekening employee dibekukan ketika ada klaim aktif yang sudah diverifikasi, perubahan normal/import memakai lock dan audit. Finance menyimpan snapshot/hash bank GA yang diperiksa kembali saat pembuatan/finalisasi DRP; klaim historis tanpa snapshot membutuhkan reverify sebelum masuk DRP. Batas nominal GA/fee wajib eksplisit di produksi dan memakai `Money`; fee GA nol. Proposal bank supplier membutuhkan dokumen deklarasi bank tertentu milik supplier yang lolos akses berkas, ditampilkan kepada reviewer, dan approval diaudit.

Export transfer hanya menerima DRP FINALIZED/PARTIALLY_PAID. Group PAID dilewati; group dengan settlement parsial ditolak untuk mencegah pembayaran ulang. Permintaan export dicatat dan mencegah cancel, penghapusan item, serta perubahan fee berikutnya. Workbook mempertahankan lima sheet, ID deterministik dan Eff. Date kosong. Admin create/reset password memerlukan password confirmation, mencabut sesi, mengirim notifikasi tanpa password, dan mewajibkan pengguna mengganti password setelah MFA.

**R5:** Aset CDN aktif dipin ke versi eksak dengan SRI. CSP enforce aktif secara default; `unsafe-inline` dan `unsafe-eval` tetap dipertahankan sesuai keputusan kompatibilitas Alpine. Limiter operasional per pengguna membatasi upload, dispatch export, PDF, search/broadcast; polling tetap terpisah. Composer memperbarui tiga paket tanpa perubahan constraint; npm memperbarui dependensi kompatibel tanpa upgrade paksa.

Import biasa menjalankan preflight 1.000 baris data × 64 kolom dan read filter sebelum full load. Batas ekspansi Office biasa 128 MiB; streaming PO/GR 512 MiB, mempertahankan kontrak 70.000 baris XLSX/CSV dan 65.535 XLS. CSV membatasi record 1 MiB/cell 64 KiB dan menghitung baris kosong. GET export hanya menampilkan konfirmasi; dispatch memakai POST/CSRF. Unique generated key menjaga satu audit aktif per supplier, dengan preflight MySQL/duplikat. Policy, orphan inspection, upload shipment final, attachment membership/scope, return URL, kurs efektif, reviewer notes, payload jawaban audit dan eligibility vendor Purchasing diperketat.

Fixture lama disesuaikan dengan kontrak aktor, snapshot bank, password confirmation, dokumen native valid dan export POST. Kegagalan copy ledger diperbaiki dengan menyegarkan lokasi source/key; batas dictionary JavaScript tetap 16 KiB per locale. Kontrol keamanan tidak dilonggarkan agar fixture lama lulus.

## Dieksekusi

| Pemeriksaan | Hasil |
|---|---|
| Guard database tes | 2 passed |
| Migrasi lokal R3–R5 | 3 applied: batch 38–40; schema probe memastikan default `PENDING` dan 3 migration tercatat |
| Baseline unit sebelum implementasi | 217 passed, 1 failed; baseline penuh dihentikan setelah kegagalan dan tidak diklaim hijau |
| Suite terfokus Finance/GA/vendor setelah integrasi | 302 passed, 6.608 assertions |
| Regresi seluruh kasus yang sebelumnya gagal | 97 passed, 2.296 assertions |
| Suite PHP akhir, tanpa benchmark yang sudah dieksekusi | 2.048 passed, 215.024 assertions, 0 errors/failures/skipped; 1.145,75 detik |
| Benchmark CSV 50.000 baris dari run penuh sebelumnya | Passed: 100 chunks, 655,26 detik, peak 310.378.496 byte (~296 MiB), file 2.327.824 byte, driver sync |
| JavaScript akhir | 177 passed, 0 failed/skipped |
| Sintaks PHP seluruh file berubah/baru | 195 file, 0 gagal |
| Pint pada file PHP berubah/baru | Passed |
| Build Vite | Passed, 25 modules |
| Blade view cache dan route list | Passed; route mati tidak ada, route reverify dan GET/POST export tersedia |
| Reconcile invoice read-only | 0 issues |
| Audit Composer | 0 advisory |
| Audit npm | 7 paket rentan: 2 moderate, 5 high |
| SRI aset CDN | 13 hash SHA-384 cocok dengan byte sumber publik |
| Guard tracked artifacts | Failed sesuai desain: tiga ZIP yang sudah terlacak |
| `git diff --check` | Passed |

Run penuh sebelum perbaikan fixture mencakup 2.047 tes: 2.015 passed, 16 errors, 16 failures. Ke-32 kasus tersebut diperbaiki dan diuji ulang; dua regresi tambahan meningkatkan jumlah suite saat ini menjadi 2.049. Benchmark tidak diulang pada run akhir karena jalur tersebut tidak berubah setelah pengukuran dan memerlukan lebih dari sepuluh menit. Hasil terfokus di atas bukan jumlah tes unik yang boleh dijumlahkan.

Seluruh 2.049 tes tercakup dan lulus melalui partisi serial: 2.048 pada run akhir ditambah satu benchmark pada run penuh sebelumnya. Ini bukan klaim bahwa 2.049 tes dijalankan kembali dalam satu run terakhir. Tes JavaScript akhir sempat ditolak sandbox (`spawn EPERM`); eksekusi ulang yang diizinkan menghasilkan 177 passed. Reconcile read-only diulang setelah suite PHP selesai dan tetap menunjukkan 0 issues.

Perintah suite akhir: `php vendor/bin/phpunit --debug --do-not-cache-result --filter '^(?!.*AdvancedExportPerformanceTest).*' --log-junit security-review/_r3-r5-final-junit.xml`. Log lokal ada di `security-review/_r3-r5-final-suite.txt`; bukti run penuh sebelumnya di `security-review/_r3-r5-junit.xml`, dan hasil JavaScript di `security-review/_r3-r5-final-js.txt`. Folder tersebut tetap merupakan bukti lokal yang di-ignore. Pemeriksaan lain memakai `php -l`, `php vendor/bin/pint --test`, `node --test` dengan daftar eksplisit file `tests/js/*.test.mjs`, `npm.cmd run build`, `php artisan view:cache`, `php artisan route:list`, `php artisan local-invoices:reconcile --json`, `composer audit --locked --no-interaction`, `npm.cmd audit --package-lock-only --json`, `php scripts/check-tracked-artifacts.php`, dan `git diff --check`.

## Verifikasi setelah revisi Lock & Approve

Keputusan pengguna opsi 2 diterapkan: satu Finance dapat mencatat penerimaan fisik, memverifikasi A/B dan melakukan approval. Regresi pada revisi invoice sebelumnya, `FinancialSecurityControlsTest`, `FinanceVerificationV2Test` dan `LocalInvoiceTaxConsistencyTest`: **48 passed, 231 assertions**. Cakupan mencakup POST approval oleh aktor yang sama tanpa override, workflow penerimaan fisik sampai approval, form terkunci setelah approval, dan aktor baru yang hilang ditolak. Keputusan berikutnya memperluas kesamaan aktor ke seluruh tahap Finance termasuk DRP/voucher/settlement; aturan perbedaan aktor DRP/voucher sebelumnya digantikan oleh keputusan tersebut.

Pemeriksaan copy inventory dan terjemahan: **47 passed, 179.282 assertions**; ledger lokasi source disegarkan. Sintaks service/controller, scoped Pint, Blade view cache dan `git diff --check` lulus. Run pertama tes memberi warning cache PHPUnit tidak writable, dengan exit code 0; pemeriksaan berikutnya menonaktifkan result cache. Dua deprecation metadata PHPUnit sudah ada. Suite penuh sebelumnya tidak diulang untuk revisi ini; QA browser belum dilakukan. Tidak ada migrasi baru untuk revisi aturan ini.

## Revisi seluruh tahap Finance oleh satu pengguna

Perbedaan aktor dihapus untuk finalize DRP serta voucher/settlement pada semua jalur: voucher invoice, voucher group GA, primary transfer, correction transfer, pembayaran group legacy/GA dan pembayaran seluruh batch. Pemeriksaan aktor yang wajib tercatat tetap berjalan melalui `assertActorsRecorded`; role, status, snapshot, amount dan transaksi tetap mengikuti guard masing-masing. Form override maker-checker pada halaman DRP, Paid dan koreksi voucher dihapus. Basic verification role GA masih memakai maker-checker; hak Finance tidak diperluas ke aksi GA.

Regresi serial `FinancialSecurityControlsTest`, `PaymentBatchSettlementTest`, `UnifiedPaymentEngineTest`, `FinanceDrpPaidTest`, `LocalSupplierWholeGrSettlementTest`: **59 passed, 387 assertions**. Cakupan memasukkan POST finalize oleh maker yang sama, GA voucher/group settlement oleh finalizer yang sama, batch supplier BCA/non-BCA oleh satu Finance, primary/bulk settlement dan subsequent correction; missing actor metadata tetap ditolak. Copy inventory/parity/content: **47 passed, 179.279 assertions**. Lint pada delapan file PHP, scoped Pint, Blade view cache dan `git diff --check` lulus. Dua deprecation metadata PHPUnit merupakan warning existing. Suite penuh serta QA browser tidak diulang; tidak ada migrasi baru atau perubahan data aplikasi untuk revisi ini.

## Hanya diinspeksi

- Routes, middleware, FormRequest, policy, model events, transaksi/lock, service, export worker/renderer, konfigurasi, migrasi dan caller terkait.
- Sebelum migrasi: query read-only menunjukkan MySQL 8.0.30, default `users.account_status` ACTIVE, dan ketiga migrasi belum tercatat. Sesudah migrasi: schema probe read-only mengonfirmasi default PENDING dan ketiganya tercatat. Ini bukan bukti penerapan pada staging/produksi.
- Release notes resmi [Laravel 12.69.3](https://github.com/laravel/framework/releases/tag/v12.69.3), [Flysystem 3.35.3](https://github.com/thephpleague/flysystem/blob/3.35.3/CHANGELOG.md) dan [CommonMark 2.10.3](https://github.com/thephpleague/commonmark/releases/tag/2.10.3).
- Kontrak transfer yang tersedia adalah XLSX; tidak ditemukan endpoint CSV transfer untuk dimodifikasi.

## Masih terbuka dan belum diverifikasi

1. **Database staging/produksi:** belum dimigrasi. Sebelum deploy, review/rehearsal harus menilai data audit aktif duplikat, versi MySQL target, rollback, dan urutan deploy. Migrasi lokal pertama hanya mengubah default kolom; data akun lama tidak di-backfill.
2. **Konfigurasi produksi:** verifikasi salt Hashids persisten, dua batas Finance positif eksplisit, CSP, proxy dan konfigurasi auth. Perubahan salt mengubah seluruh URL hash yang sudah dibagikan. Guard produksi sengaja menolak konfigurasi yang tidak memenuhi syarat.
3. **Dependency npm:** tujuh paket rentan masih berada pada rangkaian dependensi Tailwind 3; opsi otomatis menawarkan upgrade major Tailwind 4. Upgrade tersebut belum diterapkan. Job dependency CI akan tetap gagal sampai advisory ditangani melalui pekerjaan terpisah.
4. **Arsip terlacak:** `.agents/skills/boost.zip`, `config (2).zip`, `config.zip` masih dilacak. Guard tidak memberi pengecualian. Penghapusan tracking/penanganan riwayat merupakan tindakan pemilik repositori dan tidak dilakukan di sini; job guard CI masih gagal.
5. **Waktu export besar:** pengukuran CSV sync 655,26 detik melewati timeout job 600 detik pada `ProcessExportJob`/`GenerateWorkbookJob`. Tes hanya membuktikan output dan batas memori; keberhasilan worker nyata dengan timeout tersebut belum terbukti. Performa staging, retry/idempotency setelah timeout, concurrency dan anggaran host perlu diverifikasi sebelum mengaktifkan export besar di produksi.
6. **QA manual:** browser multi-role, password/MFA, override admin, proposal bank dan error upload; worker nyata; staging/produksi; serta penerimaan workbook oleh Excel/bank belum diuji. Tes workbook otomatis tidak membuktikan penerimaan bank.
7. **CI dan histori:** workflow hanya dibuat secara lokal; tidak dipublish/dieksekusi di GitHub. Tidak dilakukan pembersihan riwayat atau rotasi credential. CSP nonce/removal inline/eval serta backlog di luar R3–R5 tetap pekerjaan terpisah.
