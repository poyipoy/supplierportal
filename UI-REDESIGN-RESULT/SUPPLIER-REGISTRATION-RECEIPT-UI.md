# Halaman hasil registrasi dengan kode akses

Halaman sukses kini menampilkan nomor registrasi dan kode akses pada input read-only berlabel, dengan pilihan Salin keduanya, Unduh detail akses (.txt), dan Periksa Status Pendaftaran. Teks EN/ID menjelaskan pengajuan sudah tersimpan dan menunggu review. Kode hanya tampil sekali; email/password menjadi fallback setelah refresh. Error clipboard menyertakan panduan salin manual tanpa menyembunyikan kode.

Regresi JavaScript salin/download dan validasi form async: 7 passed, termasuk error duplikat 422 yang mempertahankan berkas. Build Vite lulus. Verifikasi backend/privasi/transaksi tercatat pada [laporan hasil](../docs/results/SUPPLIER-REGISTRATION-RECEIPT-RESULT.md). Browser desktop/mobile dan hosting belum diverifikasi; DOM harness bukan bukti visual browser.
