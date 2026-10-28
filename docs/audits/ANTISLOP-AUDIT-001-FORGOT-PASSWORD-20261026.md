# Audit 001: Forgot Password (Password Assistance)

Tanggal: 2026-10-26. Mode antislop: AFTER (pilihan sesi).
Cakupan: [forgot-password.blade.php](../../resources/views/auth/forgot-password.blade.php), string di `lang/en/auth.php`, dan screenshot desktop 1920 px.
Metode: inspeksi screenshot dan source. Halaman belum dijalankan di browser, jadi mobile, kontras terukur, dan perilaku tombol salin ditandai "belum diverifikasi".

Prioritas mengikuti tier aturan: Hard Gate = HIGH, Purpose-Gate = MEDIUM, Quality Locks = LOW. Temuan UX di luar 38 aturan dipetakan ke prinsip C-1..C-5.

## Penilaian singkat

Fondasinya benar: struktur semantik (h1, h2 per section, `aria-labelledby`), `role="status"` untuk umpan balik, focus ring, token desain, dan jalur utama yang jelas (tombol biru "Open Email App"). Masalahnya bukan gaya visual, melainkan **beban informasi**: satu keputusan sederhana ("kirim email ke support") dipecah menjadi lima blok dengan tiga tombol salin, sehingga kartu memanjang melewati layar dan bagian terpenting kedua (template) jatuh di bawah lipatan.

## Temuan

| # | Prioritas | Aturan | Temuan | Perbaikan yang diusulkan |
|---|---|---|---|---|
| 1 | HIGH | R-26, C-4 | Umpan balik salin ada di `<p data-copy-status>` di dasar kartu (baris 77), jauh dari tombol yang diklik. Di layar 1080 px elemen itu di bawah lipatan, jadi klik "Copy Email Address" tampak tidak berbuat apa-apa. Belum diverifikasi apakah label tombol berubah. | Ubah label tombol sesaat menjadi "Tersalin" (sambil tetap mengisi `role=status`), atau taruh status tepat di bawah tombol. |
| 2 | MEDIUM | C-3, R-31 | Konten berulang dan terlalu banyak langkah: CTA email, lalu alamat yang sama sebagai teks, subjek yang sama sebagai teks, dan template yang sama sebagai blok. Tiga tombol salin menyalin isi yang sudah dibawa tombol utama. | Bungkus "detail manual" dalam satu `<details>` (tertutup) dengan satu tombol "Salin semua" (subjek + isi), atau pertahankan hanya email + template. |
| 3 | MEDIUM | R-04 | Setiap judul section memakai ikon dekoratif (send, at-sign, whatsapp, text-cursor-input, file-text). Ikon "text-cursor-input" untuk Subjek tidak relevan, dan lima ikon sebaris menciptakan ritme seragam tanpa menambah makna. | Hapus ikon dari judul kecuali WhatsApp (merek dikenali). Biarkan label teks bekerja. |
| 4 | MEDIUM | Hierarki | Judul h2 (`text-ui-sm` semibold) berukuran sama dengan isi (`text-ui-sm`). Pembeda hanya bobot, jadi label dan nilai sulit dipindai. | Jadikan label kecil dan redup (`text-ui-xs`, `on-surface-variant`), nilai yang tegas. Atau tampilkan pasangan label-nilai sebagai satu baris dengan aksi di kanan. |
| 5 | MEDIUM | R-03 | Tombol salin `size="sm"` berukuran sekitar 30 px. Memenuhi 24 px WCAG 2.2, tetapi di bawah 44 px yang diminta R-03 untuk mobile. Belum diverifikasi di viewport sempit. | Naikkan ke tinggi minimum 44 px di bawah breakpoint kecil, atau jadikan baris salin berukuran penuh. |
| 6 | MEDIUM | C-3 | Halaman tidak menjawab "apa yang terjadi berikutnya": tidak ada perkiraan waktu respons atau jam layanan. Pengguna yang terkunci tidak tahu kapan mengharapkan balasan. | Tambah satu kalimat nyata (mis. "Biasanya dibalas pada jam kerja"). Isi hanya jika SLA memang ada (R-38). |
| 7 | LOW | R-06 | Pembatas "OR COPY THE DETAILS MANUALLY" memakai uppercase dengan tracking lebar dan ukuran `text-ui-xs`. Ini pola tell di R-06 dan teksnya panjang untuk dibaca dalam huruf kapital. | Pakai sentence case tanpa tracking, atau hapus pembatas jika detail manual dilipat (temuan 2). |
| 8 | LOW | Konsistensi | Email dukungan ditampilkan sebagai teks biasa, sedangkan WhatsApp berupa tautan biru bergaris bawah. Dua kanal setara diperlakukan berbeda. Nomor WhatsApp tampil format lokal tanpa petunjuk bahwa tautan membuka aplikasi eksternal. | Samakan perlakuan kedua kanal sebagai baris yang sama. Tambah penanda tautan eksternal untuk WhatsApp. |
| 9 | LOW | Responsif | Email memakai `break-all` (baris 40) yang memotong alamat di tengah kata pada lebar sempit. | Ganti dengan `overflow-wrap: anywhere` agar alamat hanya pecah bila terpaksa. |
| 10 | LOW | R-16, R-31 | Tagline panel kiri "Growing together with trusted partners." generik dan tidak berkaitan dengan tugas halaman. Ini bagian layout bersama [layouts/auth.blade.php](../../resources/views/layouts/auth.blade.php), bukan halaman ini. | Putuskan terpisah. Jangan ubah di tugas ini tanpa persetujuan karena memengaruhi semua halaman auth. |
| 11 | LOW | Catatan operasional | Kontak dukungan adalah alamat email pribadi seseorang. Kalau orang itu berhenti atau cuti, kanal tunggal ini mati. Bukan temuan desain, tetapi risiko layanan. | Pertimbangkan alias fungsional (mis. support@). Perubahan ada di konfigurasi, bukan di view. |

## Belum diverifikasi

- Perilaku mobile (overflow, tinggi tombol, posisi kartu).
- Rasio kontras terukur untuk teks abu-abu dan pembatas `text-ui-xs`.
- Apakah tombol salin mengubah label atau hanya mengisi `data-copy-status`.
- Mode gelap.
- Navigasi keyboard dan urutan fokus (struktur source terlihat benar).

## Rekomendasi urutan kerja

1 lalu 2 (menyelesaikan masalah utama sekaligus memperpendek kartu), kemudian 3, 4, 7 sebagai satu paket gaya. Temuan 5 dan 9 kecil dan aman dikerjakan bersama. Temuan 10 dan 11 perlu keputusan terpisah.

Catatan: tiap perbaikan di view harus menambah atau mengubah key di `lang/en` dan `lang/id` (TranslationParityTest), dan `PasswordAssistanceTest` perlu dijalankan.
