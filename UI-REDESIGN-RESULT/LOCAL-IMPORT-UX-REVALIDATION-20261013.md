# Modal impor PO/GR dan pemeriksaan ulang file

## Hasil

Modal memakai alur Pilih file → Periksa data → Simpan, dengan konteks/progress di header, ringkasan dan error sebelum preview, serta aksi yang sesuai status. Perbarui status hanya membaca server. Tutup/Escape tidak membatalkan proses. Sukses tidak lagi menutup modal atau me-reload otomatis; user memilih Lihat hasil impor.

Proses impor Anda dibagi menjadi Sedang diproses dan Perlu tindak lanjut. READY tetap memakai slot; file gagal tidak memakai slot sampai diperiksa ulang. Daftar gagal masih tersedia selama masa preview belum lewat. Counter hanya menghitung status aktif; daftar tetap dibatasi 100 dengan prioritas proses aktif.

Pemilik dapat memilih Periksa ulang file tersimpan setelah memperbaiki PO/GR/master, tanpa memilih file browser lagi. Jika spreadsheet komputer diubah, pilih Ganti file. Salinan private dan identitas import tetap, job/attempt staging baru dibuat, konfirmasi lama dibatalkan. File cancelled/completed/expired/cleaned/tidak valid tidak bisa diperiksa ulang. Expiry tetap 24 jam dari upload, tidak diperpanjang setelah validasi.

## Backend, keamanan, dan performa

- Dua route POST revalidate Finance/Purchasing memakai binding hashed, auth/role, ownership policy, current eligibility, CSRF dan throttle 15/menit. Request hanya menerima expected_attempt; client tidak mengirim ulang isi spreadsheet.
- User → Import → PO/GR lock order diselaraskan pada revalidate, confirm, commit, cancel, dan final audit preview. Queue database existing memakai same connection/after_commit=false; status/token/job dibentuk atomik dan rollback bersama saat handoff gagal.
- Generation token memakai owner job key baru. Legacy token tetap berlaku untuk import existing sampai pertama revalidation; token lama tidak dapat menyimpan hasil review yang telah diganti. Worker lama dibatasi owner/attempt, respons client lama dibatasi generation/attempt/sequence.
- File metadata diperiksa melalui relasi inspection; request revalidate memeriksa readability/integrity dan worker membaca ulang checksum. Status/list tidak mem-publish storage path, inspection/hash, atau owner-job key. Light polling tetap bebas pagination preview dan tidak melakukan hashing berkas setiap tiga detik.
- Summary NEW hanya menghitung record valid, invalid memakai source_row unik. Error kode/context disimpan saat validasi, dengan link perbaikan role-aware melalui lookup batch halaman, bukan parsing teks atau N+1.
- Query guard membandingkan 1 dengan 150 error, memastikan query tumbuh maksimal dua, halaman berisi maksimal 100 item, dan payload <128 KiB. Tes ini bukan benchmark kapasitas cPanel/produksi.
- Seluruh master tetap add-only dan transaksi seluruh file; conflict saat commit mengembalikan validasi gagal tanpa pengajuan parsial. UI menandai hasil preview lama sebagai perlu diperiksa ulang bila live referensi berubah saat commit.

## Tampilan dan aksesibilitas

ID/EN, Inter/tokens/Lucide/Bootstrap existing dipertahankan. Kolom error diterjemahkan, penyebab dan solusi ditampilkan berdekatan, link dibuka di tab baru dengan label yang menjelaskan. Nama file/nomor/data user memakai textContent/Blade escaping. Nominal tetap App\Support\Money.

Ringkasan error mendapat fokus; daftar panjang tidak dibacakan ulang setiap polling. Tab/Shift+Tab dibatasi modal, Escape tersedia, focus tracking daftar tetap stabil, ID deskripsi unik antar kelompok. Aksi utama minimal 44px, error tidak bergantung hanya warna. Preview table memakai warna token agar teks dark mode terbaca; scroll horizontal dibatasi tabel. Recheck disajikan sebagai aksi utama ketika data bermasalah; Periksa file awal disembunyikan ketika ada salinan server yang dapat diperiksa ulang.

## Verifikasi dieksekusi

Semua suite database dijalankan serial di adasi_portal_test. Worker proses terpisah dipakai di dalam satu test concurrency untuk membuktikan race, bukan menjalankan suite paralel.

- Safety: 2 lulus/4 assertions.
- RED revalidation: 20 gagal karena endpoint/method belum tersedia dan expiry diperpanjang. RED informasi: 2 gagal/1 lulus menunjukkan NEW salah menghitung baris bermasalah dan invalid menghitung pesan. RED tambahan JS: 4 gagal/68 lulus sebelum kemampuan baru dibuat.
- Gate gabungan final: **149 tes PHP lulus / 185.964 assertions**, mencakup revalidate, metadata/counter/query guards, background import, cancelled guards, advisory progress, HTTP role/ownership, route/rendered fixtures, FrontendAssetLoading, LocalizationPerformance, EN/ID parity/content/copy inventory.
- Sesudah gate: pemeriksaan expiry gagal/missing/cleaned/tampered tambahan lulus **1 tes/13 assertions**, termasuk status penuh dan progress-only EXPIRED. Pengujian FAILED owner Finance/Admin/Purchasing serta cleanup race termasuk gate final.
- JavaScript final: **88 lulus**, termasuk 75 tes modal, GR recovery dan batas file. Memeriksa no-file recheck, token clearing, attempt baru dengan status sama, late response, pagination lama, failed recheck, grouping/slots/descriptions, no auto-reload, file preservation, confirmation gating dan safe text/URLs.
- Chrome headless: **16 skenario lulus** EN/ID × light/dark ×375/1440 ×PO/GR. **8 skenario build final lulus** dengan tambahan kontras/reduced motion. Konten berasal dari view Laravel dan built assets asli; status/commit HTTP feed dikontrol fixture. Persistence/authorization/concurrency dibuktikan terpisah oleh HTTP/backend tests.
- Reflow 400%: 360×237 tanpa overflow horizontal modal. Kontras summary 5,68 (light) /6,64 (dark); teks tabel 17,06 /14,83. Dialog name/role, keyboard confinement, error-summary focus, external-tab labels, unknown queue progress, saving percentage, completed without reload, dan reduced-motion transition 0s diverifikasi.
- Hasil/screenshot final: storage/framework/testing/import-ui/ux-browser/result.json dan *.png. Script: tests/Support/local-import-ux-browser-check.mjs. CDN assets diperiksa terhadap SRI resmi dan dicache untuk QA; bukan dependency baru aplikasi.
- Pint terarah, sintaks PHP/JS, Blade cache, build 27 modul, route middleware, scoped diff dan manifest referensi diperiksa. Reconcile read-only lokal: 0 masalah.

Kegagalan awal verifier ditelusuri: fokus Tab keluar dialog, race test browser menekan Simpan saat request belum selesai, serta warna tabel dark mode. Perbaikan diuji ulang. Fixture queue awal memakai QueueManager tanpa connectors; fixture diperbaiki dan dua pengujian handoff database queue lulus. Tidak mengklaim setiap percobaan awal berhasil.

## Hanya diinspeksi / belum diverifikasi

Diinspeksi: policy/schema/FK, hashid/middleware/CSRF/throttle, token generation dan legacy compatibility, private inspection guard, cleanup/lease fencing, lock order, query shape, whole-file transaction dan failure path. Review keamanan fokus source pada boundary yang berubah; bukan full-codebase pen-test.

Belum diverifikasi: deployment/worker/cache/cron produksi, transaksi produksi nyata, load/70.000-row benchmark hosting, full suite seluruh portal, screen reader manual/NVDA, pengukuran coverage instrumentation, PDF/Excel acceptance eksternal. Tidak memasang package atau migrasi, tidak menjalankan migrasi DB aplikasi/cleanup produksi/worker restart, dan tidak commit/push/merge/reset/clean.

## File runtime untuk penerapan

1. app/Http/Requests/LocalInvoice/RevalidateLocalProcurementImportRequest.php (baru)
2. app/Http/Controllers/Finance/LocalProcurementImportController.php
3. app/Policies/LocalProcurementImportPolicy.php
4. app/Models/Attachment.php — relasi inspection untuk eager loading
5. app/Services/LocalInvoice/LocalProcurementImportService.php
6. app/Services/LocalInvoice/LocalPoImportService.php
7. app/Services/LocalInvoice/LocalGrImportService.php
8. app/Console/Commands/CleanupLocalProcurementImports.php — generation token saat job recovery
9. routes/web.php
10. lang/en/js.php dan lang/id/js.php
11. lang/en/local_procurement.php dan lang/id/local_procurement.php
12. resources/views/finance/local-procurement/_import_po_modal.blade.php
13. resources/views/finance/local-procurement/_import_gr_modal.blade.php
14. resources/views/finance/local-procurement/_import_recovery.blade.php
15. resources/views/finance/local-procurement/_import_workflow.blade.php (baru)
16. resources/views/finance/local-procurement/_import_review_banner.blade.php (baru)
17. resources/js/local-procurement-import.js dan resources/css/app.css bila membangun aset di server
18. Seluruh public/build/ beserta manifest dan chunks rujukannya

AGENTS.md, docs/plans/LOCAL-IMPORT-UX-REVALIDATION-20261013.md, docs/guides/LOCAL-PROCUREMENT-IMPORTS.md, copy ledger, laporan ini, dan tests adalah dokumentasi/verifikasi. Deploy runtime sebagai satu versi dan ikuti prosedur drain/cache/worker restart existing. Working tree sebelum task sudah memiliki 318 perubahan; baseline lokal 20 file disimpan di storage/framework/testing/import-ux-baseline-20261013 untuk membedakan pekerjaan ini dari perubahan lama.
