# Hasil implementasi dokumen PO dan GR invoice supplier

Tanggal: 15 Oktober 2026.

## Perubahan

- Submit dan revisi mewajibkan satu PDF PO serta satu sampai lima PDF GR, maksimal 5 MiB per file. Satu PDF dapat memuat beberapa GR; pemilihan whole-GR tetap mengikuti alur existing.
- Request dan service sama-sama memvalidasi dokumen. Revisi boleh mempertahankan lampiran invoice yang sama; PO baru menggantikan PO revisi berjalan, tanpa menghapus histori. Hitungan GR menggabungkan lampiran lama dan baru.
- Penyimpanan tetap privat dan terikat revisi, dengan profil inspeksi `invoice`, SHA-256 dan `FileAccessGuard`. Input bukan file dan ID lampiran yang dimanipulasi ditolak.
- Form, checklist wizard, detail reviewer, histori, petunjuk dan pesan EN/ID sudah mengenali PO/GR. File tetap terpilih setelah validasi 422.
- Kartu upload menggunakan batas lebar grid yang benar agar nama panjang tidak memperlebar konten mobile. Penanda wajib, penghitung file, live status dan fokus error tersedia.
- Migrasi additive menambahkan `purchase_order` dan `goods_receipt` pada enum dokumen. Rollback menolak bila tipe baru sudah dipakai.

## Dieksekusi

| Pemeriksaan | Hasil akhir |
|---|---|
| Baseline PDF invoice, cancelled recovery, akses dokumen | 69 passed, 471 assertions |
| Regresi PHP final, serial pada MySQL `adasi_portal_test` | 727 passed, 131.419 assertions; 394,18 detik |
| JavaScript upload, wizard dan async submit | 22 passed |
| PHP lint | 29 file valid |
| Pint pada file PHP task | Passed |
| Translation parity dan copy ledger | Passed dalam suite final; ledger canonical diperbarui |
| Blade cache | Berhasil |
| Vite build | Berhasil |
| `git diff --check` | Passed |
| `local-invoices:reconcile --json` pada koneksi aplikasi lokal | 0 issues |

Suite PHP mencakup submit/resubmit, whole-GR, cancelled recovery, nominal/pajak, isolasi supplier, inspeksi native, download, rollback, payment engine, rate limit, tampilan regional dan pemblokiran audit supplier. Fixture pengajuan existing diperbarui agar menyertakan dokumen wajib; assertion jumlah dokumen disesuaikan tanpa melonggarkan kewajiban pada kode aplikasi.

## Verifikasi browser

Browser memakai server QA localhost dan database `adasi_portal_test`, bukan database aplikasi. Server QA sudah dihentikan setelah pemeriksaan.

- Desktop English: kolom PO dan GR sejajar pada posisi vertikal yang sama.
- Mobile 390 px, Indonesian, light/dark: kedua kolom tersusun vertikal dan petunjuk membungkus sesuai lebar kartu.
- Nama PO panjang: lebar kartu 326,4 px dengan batas kanan 358,4 px pada viewport 390 px. Nama menggunakan elipsis; lebar teks yang tersedia 201 px dibanding konten 1.319 px.
- Memilih Invoice/PO/lima PDF GR mengaktifkan kelengkapan tahap dokumen.
- Respons validasi 422 untuk data invoice yang belum lengkap mempertahankan masing-masing 1/1/5 file tersebut.
- Enter pada tombol lanjut saat dokumen belum lengkap mempertahankan tahap pertama dan memindahkan fokus ke upload pertama yang belum lengkap.
- Tidak ada error JavaScript yang tertangkap pada skenario pilihan berkas yang diperiksa.

Keberhasilan penyimpanan, revisi, akses lintas role dan penolakan input yang tidak valid diverifikasi melalui tes HTTP/service. Pemeriksaan browser bukan bukti keberhasilan transaksi di produksi.

## Hanya diinspeksi dan batas verifikasi

- Rantai routes/request/service, kompensasi storage, policy, pemeriksaan integritas, view revisi dan migrasi existing ditinjau sebelum perubahan.
- Inspeksi read-only koneksi lokal `adasi_portal` menemukan migrasi baru sudah tercatat pada batch 42. Kolom fisik juga memuat kedua enum baru. Task ini tidak mengeksekusi migrasi aplikasi; waktu dan asal penerapan ledger tersebut belum dikonfirmasi.
- Akun fixture QA sempat tidak ditemukan saat pemeriksaan mobile. Fixture dibuat ulang hanya pada database tes dan pemeriksaan browser diulang; penyebab hilangnya fixture tidak dibuktikan.
- Ketersediaan antivirus hosting dinyatakan tidak ada oleh pengguna. Inspeksi native bukan antivirus dan tidak menjamin bebas malware maupun tidak adanya seluruh konten aktif PDF.
- Kapasitas produksi, upload maksimum 18 file/90 MiB, batas SAPI web/ModSecurity, kuota dan waktu proses belum diuji di hosting produksi/staging.
- Tidak ada commit, push, reset atau clean. Perubahan lain dalam working tree dipertahankan; patch aturan upload pada `AGENTS.md` tidak mengganti perubahan aturan Finance yang sudah ada.

Panduan penerapan: [LOCAL-INVOICE-REQUIRED-DOCUMENTS.md](../docs/guides/LOCAL-INVOICE-REQUIRED-DOCUMENTS.md).
