# Pemulihan preview import PO/GR

## Masalah dan hasil

Menutup modal membersihkan state browser, tetapi import READY tetap memakai satu dari dua slot aktif bersama PO/GR. Saat kedua slot terisi, upload baru ditolak dan preview lama tidak memiliki pilihan untuk dibuka kembali.

Modal kini menampilkan panel **Import belum selesai**. User bisa melanjutkan preview secara eksplisit, membatalkan import yang belum masuk tahap IMPORTING, atau memuat ulang daftar. Import PO dapat dilanjutkan dari modal GR dan sebaliknya melalui perpindahan ke modal yang sesuai. File/token/preview tetap kosong pada pembukaan awal. Konfirmasi penyimpanan tetap memerlukan submit user. Nama file dirender dengan textContent; generation guards menolak respons lama setelah reset/tutup modal.

Daftar memakai endpoint existing dengan filter opsional `active=1`, scope pemilik dan kind PO/GR. Expired QUEUED/READY serta status terminal tidak ditampilkan. Endpoint default tetap mendukung daftar recent existing. Status memuat nama file agar preview yang dipilih dapat dikenali. Policy, CSRF, route names, batas dua slot, worker dan skema database dipertahankan. Tidak ada perubahan langsung pada data aplikasi atau produksi.

## Verifikasi

- Dieksekusi: guard database tes 2 passed; `LocalProcurementBackgroundImportTest` 19 passed/173 assertions; JavaScript import 26 passed, termasuk resume, cancel lintas kind, pergantian modal dan respons stale. Suite MySQL berjalan serial.
- Diinspeksi: index/status/cancel controller, policy, admission slot predicate, job/status lifecycle, kedua modal dan panduan import.
- Dieksekusi: 47 tes terjemahan/copy ledger passed (180.171 assertions); PHP lint, scoped Pint, sintaks JavaScript, build Vite, Blade view cache dan `git diff --check` lulus. Reconcile invoice read-only menghasilkan 0 issues. Dua warning deprecation metadata PHPUnit existing tetap terlihat.
- Belum diverifikasi: browser nyata dan deployment produksi. Regresi JavaScript memakai DOM harness; bukan bukti visual browser.

## Deployment

Upload controller, kedua view modal, translation EN/ID, dan hasil build lengkap beserta manifest. Build produksi telah dibuat lokal. Preview lama akan tersedia di daftar sesuai ownership/status/expiry setelah perubahan dideploy; tidak membutuhkan migrasi atau pembersihan SQL. Gunakan tombol batal untuk membebaskan slot preview yang tidak diperlukan.
