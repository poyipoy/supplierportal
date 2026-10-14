# Hasil revisi registrasi supplier

## Perilaku akhir

Registrasi baru yang berhasil commit menampilkan nomor registrasi dan kode akses satu kali. User dapat menyalin keduanya atau mengunduh file TXT, lalu memakai pasangan tersebut untuk mengakses status. Akses email/password tetap tersedia. Controller mengambil dan menghapus flash receipt saat halaman sukses dibaca; refresh/direct visit mengarah ke akses status. Respons sukses private/no-store, respons POST hanya mengirim URL redirect, dan database menyimpan hash kode akses. Tidak ada pengiriman email, endpoint download baru, plaintext dalam URL/log/localStorage, package baru atau migrasi baru.

Email, NIB dan NPWP duplikat ditolak dengan error kolom yang jelas. Pengajuan existing, password, access record dan dokumen tidak diubah. Ini menggantikan keputusan R2 sebelumnya yang menyamakan hasil sukses dengan hasil duplikat; pesan publik sekarang mengungkap identitas mana yang sudah digunakan sesuai keputusan pengguna. Isolasi dokumen antar siklus registrasi, review akun dan rate limit tetap berlaku. Revisi password komposisi-only dan CAPTCHA opsional ketika kedua key kosong juga dipertahankan.

Initial submit memakai paling banyak tiga percobaan transaksi untuk deadlock. Sebelum retry, file dari percobaan yang sudah rollback dibersihkan. Benturan constraint email/NIB/NPWP dipetakan ke field yang sama dengan validasi duplikat. Failure tidak menghasilkan receipt sukses atau pengajuan parsial.

## Hasil investigasi daftar reviewer

Kode sebelumnya membuat reference/access key pada service, tetapi controller membuangnya. Controller juga menangkap `DuplicateSupplierRegistration` dan tetap menampilkan halaman diterima; pengajuan baru tidak tersimpan ketika duplikat. Kedua perilaku tersebut diperbaiki.

Untuk laporan produksi dengan identitas semuanya baru, penyebab spesifik belum terbukti. Jalur daftar reviewer diperiksa: query attempt, status/search, pagination dan fragment tab. Regresi HTTP membuktikan POST registrasi menghasilkan satu attempt PENDING yang terlihat pada Semua/Pending, pencarian nama perusahaan/reference, dan fragment tab untuk Admin/Finance/Purchasing. Role supplier/QC/GA tetap forbidden. Tidak ditemukan cacat filter pada jalur yang diuji, sehingga query daftar tidak diubah secara spekulatif.

Regresi dua subprocess mengungkap deadlock MySQL ketika dua pengajuan bersamaan melewati validasi identitas; retry terbatas memperbaiki hasil agar satu accepted dan satu error duplicate, tanpa dua pengajuan. Ini bukti lokal dari database tes, bukan bukti akar kasus produksi.

## Verifikasi yang dieksekusi

- Guard database tes: 2 passed/4 assertions pada `adasi_portal_test`.
- Suite final `tests/Feature/SupplierRegistration`: 90 passed/729 assertions, termasuk race dua proses untuk email/NIB/NPWP, rollback, pemetaan unique constraint, receipt sekali tampil, autentikasi kode dan daftar reviewer. Semua proses suite MySQL berjalan serial.
- JavaScript receipt dan async forms: 7 passed; salin dan TXT memakai reference/key yang sama, clipboard ditolak memberi panduan manual, blob dibersihkan, error 413 dan duplikat 422 mempertahankan berkas.
- Terjemahan/copy ledger: 48 passed/180.366 assertions. Dua warning deprecation metadata PHPUnit existing pada run ini; suite registrasi final melaporkan OK.
- Build Vite: passed, 26 modules; manifest memasukkan entry `resources/js/registration-receipt.js`.
- PHP lint enam file, scoped Pint, sintaks JS, Blade view cache dan `git diff --check` lulus. Copy ledger source disegarkan.

## Deployment dan batas verifikasi

Upload controller/service, halaman receipt, pasangan lang EN/ID, serta hasil build lengkap dan manifest. Key akses lama tetap didukung; plaintext key lama tidak dapat dipulihkan dari hash database. Pengajuan baru yang sukses mendapatkan receipt. Tidak ada backfill atau perubahan data aplikasi/produksi yang dilakukan.

Browser nyata, hosting/cached config/DB produksi, dan kasus pengajuan hilang di produksi belum diverifikasi karena sesi ini tidak memiliki koneksi SSH/database/browser produksi. Jangan mengklaim kasus hosting selesai sebelum mengecek akun/attempt dan tampilan reviewer di server yang sama.

Pemeriksaan read-only lewat phpMyAdmin untuk kasus produksi (ganti EMAIL_PEMOHON secara privat; tidak perlu membagikan password atau key):

```sql
SELECT DATABASE() AS database_name;

SELECT u.id, u.account_status, u.is_active,
       a.id AS attempt_id, a.attempt_number, a.status,
       a.submitted_at
FROM users u
LEFT JOIN supplier_registration_attempts a ON a.user_id = u.id
WHERE u.email = 'EMAIL_PEMOHON'
ORDER BY a.attempt_number DESC;
```

Bandingkan database yang diperiksa dengan koneksi efektif aplikasi, buka Supplier Registration pada tab Semua/Pending tanpa pencarian dan halaman pertama, lalu cari nomor registrasi dari receipt. Jika akun/attempt ada tetapi daftar tidak menunjukkan hasil, periksa request list/filter serta error log pada deploy yang sama. Jika keduanya tidak ada, periksa respons submit dan koneksi/release aktual; setelah revisi ini, duplikat tidak lagi memberikan sukses palsu. Tidak menjalankan insert ulang, reset, atau pembersihan database untuk mendiagnosis kasus tersebut.
