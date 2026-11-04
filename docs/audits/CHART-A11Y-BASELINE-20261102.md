# Audit baseline aksesibilitas chart (2 November 2026)

Ditulis untuk engineer dan QA yang mengerjakan atau memeriksa perbaikan chart. Kontrak tugas: [CHART-A11Y-TASKS-20261102.md](../plans/CHART-A11Y-TASKS-20261102.md). Metode: Chartability (POUR + CAF, 14 heuristik kritis; hasil tak meyakinkan dihitung gagal), ditambah pengukuran pada halaman yang dirender.

## Cara mengukur

- Working tree `master` 2 November 2026 (termasuk WIP Gate 2 perbandingan harga yang belum di-commit), build Vite yang ada, DB dev.
- Halaman dibuka lewat harness loopback GET-only pra-autentikasi (`php -S`, di luar repo, tanpa tulis DB) dan Chrome DevTools MCP. Role: QC, Purchasing, Finance, Supplier (import).
- Diukur: atribut canvas dan accessibility tree, konfigurasi Chart.js yang benar-benar aktif (`Chart.getChart`), warna piksel hasil render (`getImageData`, dicampur dengan surface) dan rasio kontras WCAG, perilaku ganti tema tanpa reload, reflow 320 px, Lighthouse accessibility (axe-core).
- Palet token diuji dengan validator OKLab (`validate_palette.js`, skill dataviz): pita lightness, chroma, ΔE CVD bertetangga, ΔE penglihatan normal, kontras.
- Tidak dijalankan: screen reader sungguhan (NVDA tidak terpasang di mesin audit), Windows Contrast Theme, screenshot/visual diff (dilarang kontrak Phase 2).

Data dev kecil (maks. 3 supplier per PR, 2 item per PR, inspeksi QC 2 bulan, 3 status PO aktif), jadi kasus ekstrem tidak terwakili.

## Temuan lintas chart

| ID | Heuristik Chartability (kritis?) | Bukti | Status |
|---|---|---|---|
| X-KBD | Interaction modality only has one input type (kritis) | Semua 11 canvas `tabIndex = -1`; tooltip hanya hover/pointer. | Gagal, terverifikasi |
| X-CUES | No interaction cues or instructions (kritis) | Tidak ada petunjuk cara membaca/berinteraksi di chart mana pun. | Gagal, terverifikasi |
| X-TEXT | Small text size (kritis) | Font tick sumbu 11 px di semua chart yang memakai `AdasiChart.getScales()` dan di konfigurasi inline; legend dashboard 11 px. | Gagal, terverifikasi |
| X-THEME | User style change not respected (kritis) | QC: setelah tema diganti ke dark tanpa reload, seri NG tetap `#b12b21` = **2,55:1** terhadap surface dark `#171f2c` (gagal WCAG 1.4.11); ring titik dan border donut tetap `#fff`. `refreshChartTheme()` hanya mewarnai ulang skala/legend/tooltip. | Gagal, terverifikasi |
| X-MOTION | User style change not respected (kritis) | `Chart.defaults.animation` = 1000 ms; chart memakai 250–400 ms; tidak ada pemeriksaan `prefers-reduced-motion` (kecuali historical PR yang `animation:false`). | Gagal, terverifikasi |
| X-PAL | Low contrast / color-only (kritis) | Validator: slot 5 (`--md-info` = `var(--md-primary)`) **identik** dengan slot 1 (ΔE 0) dan ikut berubah oleh accent; `#475569` di bawah chroma floor; di dark tiga seri di luar pita lightness. Pasangan OK/NG light: ΔE deutan 6,6 (butuh encoding sekunder). | Gagal, terverifikasi |
| X-FORCED | User style change not respected (kritis) | Isi canvas tidak ikut Windows Contrast Theme; tidak ada penanganan `forced-colors`. | Gagal, inferensi (perlu uji manual) |
| X-GRAD | Low contrast | Hipotesis "fill gradien memudar di dasar batang" **terbantah**: `createBarGradient(context)` menerima scriptable context (bukan canvas), jadi selalu mengembalikan warna solid; sampel piksel batang Finance 6,47:1 dari atas ke bawah. `createAreaGradient` juga selalu `transparent`. | Lulus (tidak ada perbaikan a11y) |
| X-REFLOW | Reflow | Dashboard QC pada 320 px: tidak ada overflow horizontal. | Lulus |
| X-AXE | Otomatis | Lighthouse a11y: perbandingan historis 100, dashboard Purchasing 96 (dua kegagalan bukan chart: kontras teks kartu kurs, nama tombol bahasa). Skor 100 di halaman yang gagal di atas menunjukkan axe tidak menilai chart. | Catatan |

## Temuan per chart

| ID | Bukti | Kegagalan |
|---|---|---|
| PUR-RACE | Dashboard Purchasing: script chart berjalan sebelum modul Vite yang mendefinisikan `window.AdasiChart`. `Chart.getChart(prChart).config.options.plugins.tooltip.callbacks.label` tidak ada, skala default Chart.js dipakai, donut memakai warna fallback hardcode `#1E8449`/`#C0392B`. | Tooltip tanpa label terlokalisasi dan format angka preferensi; warna di luar token, tidak ikut dark. |
| PUR-PR | Tidak ada tabel/teks setara; ringkasan hanya judul generik. | No table (kritis); No title, summary, or caption (kritis) |
| PUR-PO | Donut; `overdue` dan `claim_needed` sama-sama `colors.error`; `cancelled` dan status tak dikenal sama-sama secondary; tanpa tabel. | Content is only visual (kritis); No table (kritis); warna status ganda |
| QC-RATIO | Donut dua irisan; legend memakai `bg-success`/`bg-danger` Bootstrap, angka tidak diformat regional. Angka OK/NG ada sebagai teks. | Data density (donut untuk dua nilai); format angka |
| QC-TREND | OK hijau vs NG merah, garis sama, titik sama; tanpa tabel; tanpa empty state; bulan tanpa inspeksi dilompati; bulan dihitung dari `inspected_at->format('Y-m')` UTC, bukan zona bisnis (melanggar Business Time Invariant). | Color-only (kritis); No table (kritis); sumbu waktu tidak kontinu |
| FIN-FC | Canvas tanpa fallback text, tanpa `aria-describedby` ke tabel periode yang sudah ada; garis akumulasi teal hardcode `#0D9488` (3,74:1 light, tidak ikut token); tooltip diduplikasi inline. | No interaction cues; warna di luar token |
| SUP-HIST | Label seri `Lowest Price`/`Highest Price` English di UI Indonesia; min dan max memakai dash yang sama `[4,3]` dan hanya beda hijau/merah. Positif: `aria-describedby`, fallback `role="status"`, tabel rincian. | Color-only (kritis); Reading level/bahasa |
| CMP-INTER | Ringkasan termurah per material ada di `aria-label`; tabel matriks ada; slot 2 `#8a5708`, slot 3 abu-abu `#475569`. | X-KBD, X-PAL |
| CMP-HIST | Tooltip `' Average:'`, `' Highest:'`, `' Lowest:'` hardcode English. Cabang dual-axis "transaksi" di `historicalChartConfig()` **tidak terjangkau**: controller menormalisasi `period_view` ke `monthly`/`yearly` (`PriceComparisonController.php:384`). | Bahasa; kode mati dual-axis |
| CMP-PR-CP | Tanpa fallback text; marker hollow untuk Indikatif (isyarat non-warna) sudah ada. | No interaction cues |
| CMP-PR-TREND | Tiga supplier hanya dibedakan warna (pointStyle/dash sama); palet lokal 7 warna (`info` duplikat primary, `onSurfaceVariant` abu-abu). | Color-only (kritis) |
| CMP-PR-PO | Satu seri; tanpa fallback text. | X-KBD |

## Ringkasan baseline

Setiap chart gagal pada X-KBD, X-CUES, X-TEXT, dan X-MOTION; chart dengan warna seri juga gagal X-THEME dan X-FORCED (inferensi). Kegagalan kritis tambahan per chart ada di tabel di atas. Target sesudah perbaikan: nol kegagalan kritis yang bisa diverifikasi di mesin ini, dengan X-FORCED dan screen reader dinyatakan sebagai uji manual.

Hasil sesudah perbaikan: [CHART-A11Y-RESULT-20261102.md](../results/CHART-A11Y-RESULT-20261102.md).
