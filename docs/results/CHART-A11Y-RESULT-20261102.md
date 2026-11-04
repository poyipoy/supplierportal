# Hasil perbaikan aksesibilitas chart (2 November 2026)

Ditulis untuk engineer, QA, dan reviewer perubahan. Baseline: [CHART-A11Y-BASELINE-20261102.md](../audits/CHART-A11Y-BASELINE-20261102.md). Tugas per chart: [CHART-A11Y-TASKS-20261102.md](../plans/CHART-A11Y-TASKS-20261102.md). Kontrak desain: [DESIGN.md](../../DESIGN.md) §7.

Pengukuran sesudah perbaikan memakai protokol yang sama dengan baseline (harness loopback GET-only, Chrome DevTools MCP, build Vite baru), tanpa screenshot.

## Status kegagalan baseline

| ID | Perbaikan | Bukti sesudah | Status |
|---|---|---|---|
| X-KBD | Plugin global `adasiA11y`: canvas `tabindex="0"`, ←/→/↑/↓, Home/End menelusuri titik (melewati titik kosong), tooltip ikut aktif, Esc menutup. | Semua halaman: readout terbaca (contoh QC "Jun 2026: Material OK 0; Material NG 0"; inter-supplier "SKD11: … Rp 59.625 · Terendah …"); tooltip `opacity 1` saat fokus keyboard; Esc mengosongkan. | Tertutup (sighted keyboard); screen reader: lihat "Belum diverifikasi" |
| X-CUES | Petunjuk keyboard terlihat di `figcaption`, ditautkan `aria-describedby`. | Ada di setiap chart, termasuk template fetch historis. | Tertutup |
| X-TEXT | Tick, legend, tooltip 12 px. | `ticks.font.size` dan `legend.labels.font.size` = 12 di semua halaman yang diukur. | Tertutup |
| X-THEME | Warna dataset dibaca saat render (`AdasiChart.color`), cache dibersihkan saat tema/accent berubah. | QC, ganti ke dark tanpa reload: NG `#ffb4ab` **9,75:1** (sebelumnya 2,55:1), OK 5,45:1, ring titik = surface dark. | Tertutup |
| X-MOTION | `beforeUpdate` mematikan animasi bila `prefers-reduced-motion`, termasuk durasi per chart. | Dengan media query aktif: `chart.options.animation = false`, `Chart.defaults.animation = false`. | Tertutup |
| X-PAL | Token `--md-chart-series-1..6` light/dark, tidak ikut accent; status OK/NG terpisah. | Validator: light ΔE CVD terburuk 10,2 (protan), dark 8,4; semua ≥ 3:1 (light 3,26–6,47; dark 4,20–5,39). Tes `chart-theme-a11y`. | Tertutup |
| X-FORCED | Mode `forced-colors`: warna sistem untuk seri/teks/grid, hatch untuk bar. | Disimulasikan (override `matchMedia`): seri = warna sistem, dash/bentuk titik tetap. | Sebagian: Windows Contrast Theme asli belum diuji |
| PUR-RACE | Script chart Purchasing menunggu `DOMContentLoaded`. | Callback tooltip ada, warna token, skala ADASI. | Tertutup |
| PUR-PR | Tabel bulan → jumlah, ringkasan otomatis. | Feature test: tiap baris tabel = data chart. | Tertutup |
| PUR-PO | Donut → bar horizontal terurut satu warna + label nilai + tabel status/jumlah/porsi. | `Perlu Klaim 2 (67%)` dan `Terlambat 1 (33%)` terpisah (feature test). | Tertutup |
| QC-RATIO | Donut → angka tingkat lolos + bar proporsi + daftar OK/NG berikon. | "75%", "3 of 4 inspections passed QC." | Tertutup |
| QC-TREND | Bulan kontinu zona bisnis (bulan kosong = 0), NG garis putus + segitiga, tabel, empty state. | Feature test: inspeksi 31 Agt 18:00 UTC tercatat September; 6 bulan berturut. | Tertutup |
| FIN-FC | Warna token, tooltip `AdasiChart`, status gagal muat, readout Rupiah. Instance Chart dipindah dari state Alpine (lihat temuan). | Toggle mingguan/bulanan memperbarui chart dan ringkasan, tanpa error. | Tertutup |
| SUP-HIST | Label terjemahan, rata-rata/terendah/tertinggi dibedakan dash + bentuk titik, angka regional. | "Harga terendah 3,8 USD/Kg" dengan segitiga dan dash. | Tertutup |
| CMP-INTER | Palet baru, supplier ke-7+ di-hatch, readout harga + "Terendah" juga di mode %. | Tes builder 7 supplier. | Tertutup |
| CMP-HIST | String tooltip diterjemahkan; cabang dual-axis mati dihapus. | Tooltip "Rata-rata: Rp 67.358"; satu sumbu Y; guard statis. | Tertutup |
| CMP-PR-* | Palet bersama, bentuk titik + dash per supplier, readout per tanggal. | Tiga mode terbaca keyboard; tes builder. | Tertutup |

## Temuan yang muncul saat implementasi

- **Forecast Finance tidak bisa ganti mode (sudah ada sebelumnya).** Instance Chart disimpan di state reaktif Alpine; Proxy Alpine membuat `update()` rekursi tanpa akhir ("Maximum call stack size exceeded"), dan chart rusak sesudahnya. Diperbaiki dengan menyimpan instance di closure.
- **Bug placeholder di ringkasan.** `:max` memakan awal `:max_label`; diganti penggantian satu jalan yang juga tidak memindai ulang teks bisnis (tes regresi dengan label berisi `:min`).

## Verifikasi yang dijalankan

| Pemeriksaan | Hasil |
|---|---|
| `node --test tests/js/*.test.mjs` | 611 lulus, 0 gagal. Dua asersi `dashboard-editor.test.mjs` disesuaikan dengan teks yang sengaja dipendekkan commit `62b5e1a5` (budget kamus 16 KiB) |
| `tests/js/chart-theme-a11y.test.mjs` (baru) | 11 lulus |
| PHPUnit terfilter (chart, terjemahan, budget kamus JS, aset CDN, dashboard regional/rendering, perbandingan harga, riwayat harga supplier, komponen, isolasi supplier) | 181 lulus |
| `tests/Feature/Procurement` | 258 lulus |
| `ChartAccessibilityTest` (baru) | 5 lulus |
| Pint pada file PHP baru/diubah | lulus |
| `npm.cmd run build`, `php artisan view:cache` | lulus |
| Lighthouse a11y dashboard Purchasing | 96 → **100** (locale en dan id). Ikut diperbaiki: teks "kurs diperbarui" memakai `--md-outline` (1,48:1) → `on-surface-variant` (5,94:1); nama aksesibel item sidebar, logo (id), dan tombol bahasa kini memuat teks yang terlihat (WCAG 2.5.3), dengan tes regresi di `RenderedComponentTest` |
| Sisa pola kontras | 53 elemen teks lain di view masih memakai `tw:text-outline` (≈1,48:1); belum diubah karena di luar halaman yang diaudit |

Semua run PHPUnit memakai `dbguard` (tanpa tabrakan sesi lain).

## Belum diverifikasi

- Screen reader sungguhan (NVDA + Firefox, JAWS + Chrome, VoiceOver): NVDA tidak terpasang di mesin ini. Jalankan tugas di dokumen tugas per kondisi.
- Windows Contrast Theme asli (forced colors hanya disimulasikan).
- Data produksi: data dev maksimal 3 supplier per PR; perilaku 7+ supplier hanya diuji tes builder.
- `UserFacingCopyInventoryTest` tidak dijalankan; ledger salinannya diketahui merah di baseline.
- Validasi pertanyaan per chart oleh pemilik proses.
