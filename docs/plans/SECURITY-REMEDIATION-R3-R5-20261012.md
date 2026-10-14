# Pelaksanaan remediasi R3–R5

Permintaan: lanjutkan rencana yang disetujui setelah R2. Rencana asal berada di `security-review/REMEDIATION-PLAN-20261010.md`; rincian di bawah mempertahankan keputusan percakapan yang memperjelas rencana tersebut.

## Batas pelaksanaan

- Pertahankan perubahan R1/R2 yang sudah ada. Tanpa commit, push, reset, clean, atau migrasi database aplikasi.
- Proses tes MySQL dijalankan serial pada `adasi_portal_test`; root mengoordinasikan seluruh tes.
- Amount menggunakan `Money`; teks antarmuka/validasi EN dan ID berpasangan.
- Verifikasi browser, staging, migrasi aplikasi, dan produksi dilaporkan terpisah.

## R3

- [x] Hashids hanya dari env; boot produksi menolak kosong/placeholder; salt tes eksplisit.
- [x] Migrasi default akun PENDING tanpa mengubah existing rows; seed akun aktif eksplisit.
- [x] Hapus controller/view tidak terpakai dan route pembayaran yang tidak memiliki action.
- [x] Cocokkan dokumentasi policy dan presisi quantity GR tiga desimal.
- [x] Ignore ZIP dan tambahkan CI guard arsip/data private (scaffold `.gitignore` diperbolehkan).

## R4

- [x] Revisi keputusan: seluruh tahap Finance menerima aktor yang sama; flag per kontrol menjaga kelengkapan aktor. Basic verification GA mengikuti maker-checker dengan override admin beralasan dan diaudit.
- [x] Revisi keputusan: Finance yang sama boleh menerima fisik, memverifikasi Section A/B dan Lock & Approve tanpa override. Penulis Section A/B tetap dicatat; historis tanpa aktor dicatat; baris baru tanpa aktor ditolak.
- [x] Finance yang sama boleh membuat/finalize batch serta membuat voucher dan melakukan settlement/koreksi di seluruh jalur tanpa override karena kesamaan aktor.
- [x] GA basic verifier berbeda dari submitter; revisi hanya submitter/admin melalui policy.
- [x] Freeze rekening employee ketika ada klaim aktif yang sudah diverifikasi; lock employee sebelum claim; semua perubahan rekening diaudit termasuk import.
- [x] Snapshot/hash bank saat Finance verification, diperiksa saat pembuatan/finalisasi DRP; klaim lama harus reverify tanpa DRP aktif.
- [x] Batas klaim GA/fee wajib eksplisit di produksi; fee GA nol; tidak ada perhitungan float baru.
- [x] Perubahan bank supplier menyebut dokumen deklarasi bank milik sendiri yang readable; approval diaudit.
- [x] Transfer hanya FINALIZED/PARTIALLY_PAID; group PAID dilewati; group sebagian diselesaikan ditolak seluruhnya. Pertahankan lima sheet, ID deterministik, Eff. Date kosong. Laporan DRP draft tetap tersedia.
- [x] Password confirmation pada admin create/edit/store/update; password sementara wajib diganti setelah MFA; sesi dicabut, audit/notifikasi tanpa password.

## R5

- [x] CDN versi eksak/SRI; Chart.js 4.5.1; CSP enforce dengan unsafe-inline dan unsafe-eval tetap selama Alpine belum dimigrasikan.
- [x] Limiter per user: upload 15/menit, dispatch export 10, PDF 60, pencarian/broadcast 120; kontrol existing yang lebih ketat dipertahankan; polling terpisah.
- [x] Pembaruan terisolasi Laravel 12.69.3, Flysystem 3.35.3, CommonMark 2.10.3; audit Composer/npm dan CI.
- [x] Import biasa: preflight 1000 baris data × 64 kolom dan read filter sebelum full load.
- [x] Office biasa 128 MiB; streaming PO/GR 512 MiB; kontrak 70.000 XLSX/CSV dan 65.535 XLS tetap.
- [x] CSV record 1 MiB/cell 64 KiB, baris kosong dihitung.
- [x] GET export hanya konfirmasi baca; POST dispatch, termasuk SDK async dan route detail.
- [x] Unique generated key audit aktif dengan guard MySQL >= 8.0.16 dan preflight duplikat.
- [x] Policy aktif dipanggil tanpa memperluas role; orphan inspection ERROR; exception superseded khusus.
- [x] Upload shipment ditolak saat verified/done/cancelled di dalam transaksi; attachment Message menurut membership, audit menurut policy, LPO/refund menurut scope lokal.
- [x] Return URL terdekode aman; kurs efektif dengan tie-break ID/cache tanggal bisnis, IDR satu tanpa mengubah snapshot lama.
- [x] Reviewer notes maksimum 1000; jawaban audit maksimum 150, field absen dipertahankan dan null eksplisit menghapus; eligibility vendor Purchasing mengikuti Finance.

## Status pelaksanaan

Semua butir kode di atas telah diimplementasikan. Checkbox menyatakan implementasi tersedia untuk review, bukan bukti migrasi aplikasi, deployment, atau QA manual sudah dilakukan. Hasil eksekusi dan batas verifikasi dicatat di [laporan hasil](../results/SECURITY-REMEDIATION-R3-R5-20261012.md).

- Guard database tes: 2 passed. Semua proses tes MySQL berjalan serial.
- Baseline penuh sebelum perubahan dihentikan setelah kegagalan terlihat. Baseline unit berikutnya menghasilkan 217 passed dan 1 failed; tidak diklaim sebagai baseline penuh yang hijau.
- Composer/vendor telah diperbarui, dengan perubahan lock terbatas pada Laravel 12.69.3, Flysystem 3.35.3, dan CommonMark 2.10.3.
- Perbaikan npm kompatibel diterapkan tanpa `--force`; tujuh paket rentan masih dilaporkan dan membutuhkan penilaian upgrade terpisah.
- Guard tracked artifacts gagal sesuai desain pada `.agents/skills/boost.zip`, `config (2).zip`, dan `config.zip`. Tidak ada pengecualian ZIP. Pembersihan arsip/riwayat oleh pemilik repositori masih diperlukan.
- Tiga migrasi baru hanya tersedia sebagai file; inspeksi read-only menunjukkan migrasi tersebut belum diterapkan pada database aplikasi.

Sumber rilis untuk pemeriksaan dependensi: [Laravel](https://github.com/laravel/framework/releases/tag/v12.69.3), [Flysystem](https://github.com/thephpleague/flysystem/blob/3.35.3/CHANGELOG.md), [CommonMark](https://github.com/thephpleague/commonmark/releases/tag/2.10.3).
