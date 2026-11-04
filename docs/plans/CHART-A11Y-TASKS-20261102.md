# Kontrak tugas chart — aksesibilitas (2 November 2026)

Setiap chart portal punya satu **pertanyaan utama** yang harus bisa dijawab dengan benar oleh pengguna mouse, keyboard-only, screen reader, buta warna, zoom 400%, dan Windows Contrast Theme. Tugas di bawah dipakai untuk audit Chartability (baseline dan sesudah) dan untuk uji manual dengan pengguna nyata. Jawaban harus bisa diperoleh dari chart **atau** dari alternatif teksnya (ringkasan/tabel), tanpa tooltip mouse.

Diturunkan dari kode (controller + view) dan istilah domain di AGENTS.md. Validasi bisnis oleh pemilik proses masih terbuka (lihat akhir dokumen). Audit: [CHART-A11Y-BASELINE-20261102.md](../audits/CHART-A11Y-BASELINE-20261102.md).

| ID | Chart (lokasi) | Pertanyaan utama | Tugas uji (jawaban pasti) |
|---|---|---|---|
| FIN-FC | Proyeksi Ready to Pay, `finance/dashboard.blade.php` | Kapan kebutuhan kas tertinggi dan berapa totalnya? | 1. Periode mana dengan nominal invoice tertinggi, berapa nominalnya? 2. Berapa akumulasi pada periode terakhir? 3. Berapa invoice pada periode X? |
| PUR-PR | Tren PR 6 bulan, `purchasing/dashboard.blade.php` | Apakah volume PR naik atau turun? | 1. Bulan mana PR terbanyak? 2. Berapa PR bulan berjalan? |
| PUR-PO | Distribusi status PO, `purchasing/dashboard.blade.php` | Status mana yang paling banyak menahan PO? | 1. Status dengan PO terbanyak dan jumlahnya. 2. Berapa PO Perlu Klaim, dan berapa yang Terlambat (dua status berbeda)? |
| QC-RATIO | Rasio OK/NG, `qc/dashboard.blade.php` | Berapa persen material lolos QC? | 1. Persentase OK. 2. Jumlah NG. |
| QC-TREND | Tren OK/NG 6 bulan, `qc/dashboard.blade.php` | Apakah NG naik di bulan terakhir? | 1. NG bulan terakhir dibanding bulan sebelumnya (naik/turun/sama). 2. Bulan tanpa inspeksi terbaca sebagai 0, bukan hilang. |
| SUP-HIST | Riwayat harga supplier, `supplier/price-history/historical.blade.php` | Bagaimana harga saya bergerak antar periode? | 1. Harga rata-rata periode terakhir. 2. Pada tampilan tahunan: harga terendah dan tertinggi tahun X, dibedakan tanpa warna. |
| CMP-INTER | Antar supplier, `purchasing/comparison/_scripts.blade.php` | Supplier mana termurah untuk material X? | 1. Supplier termurah material X dan harganya. 2. Supplier yang tidak menawar material X. |
| CMP-HIST | Historis material (bulanan/tahunan), `_scripts.blade.php` | Bagaimana harga rata-rata tertimbang bergerak? | 1. Rata-rata periode terakhir. 2. Tertinggi dan terendah periode X. |
| CMP-PR-CP | Historical PR: saat ini vs sebelumnya, `_historical_pr_scripts.blade.php` | Supplier mana yang naik harga dibanding penawaran sebelumnya? | 1. Harga saat ini dan sebelumnya supplier X. 2. Nilai mana yang Indikatif. |
| CMP-PR-TREND | Historical PR: tren penawaran | Bagaimana penawaran tiap supplier bergerak per tanggal? | 1. Penawaran terakhir supplier X dan tanggalnya, dibedakan dari supplier lain tanpa warna. |
| CMP-PR-PO | Historical PR: riwayat PO | Berapa harga beli rata-rata tertimbang historis? | 1. Nilai periode terakhir. |

## Kondisi uji

1. Mouse, light dan dark, termasuk ganti tema tanpa reload.
2. Keyboard saja (Tab, panah, Home/End, Esc, Enter/Space untuk tombol tabel).
3. Screen reader: NVDA + Firefox dan JAWS + Chrome di Windows; VoiceOver + Safari bila tersedia.
4. Simulasi/peserta buta warna (protan, deutan, tritan).
5. Zoom 200% dan 400% (viewport 320 px).
6. Windows Contrast Theme (forced colors).
7. `prefers-reduced-motion: reduce`.

Metrik per tugas × kondisi: benar/salah, waktu, dan catatan hambatan. Baseline pembanding: pengguna mouse dengan penglihatan normal.

## Belum divalidasi

- Pertanyaan utama di atas disusun dari kode, bukan dari wawancara pengguna Finance/Purchasing/QC/Supplier. Pemilik proses perlu mengonfirmasi atau mengganti pertanyaannya sebelum uji pengguna.
- Uji screen reader dan forced colors belum dijalankan oleh manusia; protokol ini disiapkan untuk itu.
