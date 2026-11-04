# DESIGN.md: ADASI Portal Supplier

> Kontrak desain visual dan interaksi untuk seluruh UI portal (Core Impor dan Core Local Supplier & Payment).
> Disusun dari kode pada working tree **2 November 2026**: `resources/css/app.css`, `tailwind.config.js`, `resources/views/components/ui/`, `app/View/Components/Ui/Icon.php`, `app/Support/StatusHelper.php`, `resources/js/chart-theme.js`, `config/user_preferences.php`, [AGENTS.md](AGENTS.md), dan [REDESIGN-PHASE2-GLOBAL-CONTRACT.md](ADASI-UI-REDESIGN-PHASE2-MISSIONS/ADASI-UI-REDESIGN-PHASE2-MISSIONS/REDESIGN-PHASE2-GLOBAL-CONTRACT.md).

## 0. Kedudukan dokumen ini

- Ini peta desain, **bukan** sumber nilai token. Nilai final ada di `resources/css/app.css`. Bila file ini dan CSS berbeda, CSS yang menang. Perbaiki file ini, jangan menyalin angka lama ke kode.
- Urutan prioritas: aturan platform → instruksi pengguna → [AGENTS.md](AGENTS.md) → file ini → Global Contract Phase 2 (untuk pekerjaan mission Phase 2) → preferensi estetika. Aturan bisnis, otorisasi, kontrak data, dan selector JS selalu di atas pertimbangan visual.
- Ringkasan per direktori: [resources/views/components/ui/CLAUDE.md](resources/views/components/ui/CLAUDE.md). Skill `.claude/skills/design-expert` memakai aturan yang sama.

---

## 1. Arah desain

**Target:** enterprise ERP yang tajam, padat, dan akrab bagi operator ERP. Platform pengadaan dan kolaborasi supplier yang matang dengan bahasa visual ADASI yang disengaja.

Prinsip:

1. **Operasional dulu.** Setiap layar menjawab "apa yang perlu ditangani sekarang". Antrean kerja di atas, ringkasan di bawah.
2. **Hierarki lewat tipografi**, bukan dekorasi. Border dulu, baru bayangan. Radius kecil.
3. **Warna punya makna.** Biru ADASI hanya untuk aksi utama, navigasi aktif, seleksi, dan fokus. Warna semantik hanya untuk state nyata.
4. **Padat tapi terbaca.** Desktop-first, tetapi tablet dan mobile tetap bisa dipakai penuh. Pengguna mengatur kepadatan sendiri (§3.5).
5. **Perilaku dibekukan, komposisi tidak.** Redesain mempertahankan route, data, alur kerja, dan selector JS. Tata letak visual boleh dibangun ulang.

Referensi kualitas (bukan untuk disalin): SAP Fiori (kejelasan), Odoo (keakraban operasional), GitHub (utilitas yang menahan diri), Linear (presisi).

---

## 2. Arsitektur styling

| Lapisan | Peran | Aturan |
|---|---|---|
| CSS custom properties di `resources/css/app.css` | Sumber kebenaran token (`--md-*` gaya Material 3, `--ui-*` alias komponen) | Token baru ditambahkan di sini, beserta nilai dark-nya |
| `tailwind.config.js` | Memetakan token ke utility (`tw:bg-primary`, `tw:text-on-surface`, `tw:rounded-ui-sm`, …) | Tailwind 4, dimuat via `@config` |
| Tailwind utilities | Layout dan styling komponen | **Wajib prefix `tw:`** di awal seluruh variant (`tw:lg:hover:bg-primary`). Preflight tidak diimpor, jadi class tanpa prefix tidak berefek |
| Bootstrap 5 (CDN) | Compatibility layer: grid lama, modal, dropdown, offcanvas, DataTables | Jangan dihapus. Variabel `--bs-*` diadaptasi ke token di `:root` |
| CSS aplikasi (`.ui-toolbar`, `.ui-action-bar`, `.sidebar`, `.table`, …) | Fondasi pola yang dipakai lintas halaman | Tetap CSS biasa; pakai token, bukan nilai mentah |

Aturan keras:

- **Tidak ada hex mentah** di Blade, JS, atau CSS komponen baru. Pakai token. Seed `#1F5FA6` (biru) dan `#C0392B` (merah) hanya hidup di blok primitive `--md-ref-*`.
- Class Tailwind harus ditulis **literal lengkap** agar terbaca scanner. Sumber scan: `resources/views`, `resources/js`, `app/` (HTML DataTables dari PHP), `public/assets/js`. Utility yang benar-benar dinamis memakai `@source inline()`. Jangan memindai compiled views atau laporan historis.
- Palet legacy `emerald`/`amber`/`rose`/`slate`/`teal` di `tailwind.config.js` hanya dipertahankan untuk layar lama. Pekerjaan baru memakai token semantik (`success`, `warning`, `error`, …).
- Varian `hover` dideklarasikan ulang sebagai `&:hover` (perilaku v3 dipertahankan).

---

## 3. Design tokens

### 3.1 Warna: peran semantik (light)

| Peran | Token | Nilai light | Pemakaian |
|---|---|---|---|
| Primary | `--md-primary` / `tw:bg-primary` | `#1F5FA6` | Aksi utama, nav aktif, fokus, seleksi |
| Primary container | `--md-primary-container` | `#EBF3FC` | Latar state info/terpilih yang lembut |
| Secondary | `--md-secondary` | `#475569` | Aksi sekunder, teks pendukung |
| Error | `--md-error` | `#B12B21` | Gagal, tolak, destruktif |
| Success | `--md-success` | `#198754` | Selesai, OK, disetujui |
| Warning | `--md-warning` | `#8A5708` | Butuh perhatian, mendekati batas |
| Info | `--md-info` | = primary | Informasi netral bertanda |

Setiap peran punya pasangan `on-*`, `*-container`, `on-*-container`, dan varian `*-rgb` untuk alpha. Di Tailwind: `tw:bg-success-container tw:text-success-container-foreground`.

### 3.2 Surface dan teks

| Token | Light | Peran |
|---|---|---|
| `--ui-workspace-bg` | `#E9EEF4` | Latar area kerja (nada terendah) |
| `--md-background` | `#F8FAFC` | Latar body / `--bs-body-bg` |
| `--md-surface` / `--ui-card-bg` | `#FFFFFF` | Card, tabel, form |
| `--md-surface-container-low` | `#F8FAFC` | Header tabel, baris hover, kotak spesifikasi |
| `--md-surface-container` | `#F1F5F9` | Toolbar, header section, header data-table |
| `--md-surface-container-high(est)` | `#E2E8F0` | Pressed state, dialog di dark |
| `--md-outline` | `#CBD5E1` | Border container |
| `--md-outline-strong` | `#94A3B8` | Border kontrol input |
| `--md-outline-variant` | `#E2E8F0` | Divider |
| `--md-on-surface` | `#0F172A` | Teks utama |
| `--md-on-surface-variant` | `#566579` | Teks sekunder / meta |

### 3.3 Dark mode

Diaktifkan lewat `:root[data-theme="dark"]` (preferensi user `light | dark | system`). Aturan:

- **Elevasi bersifat tonal, bukan bayangan:** `--ui-workspace-bg` < card (`--md-surface` / `--ui-card-bg`) < `--ui-menu-bg` (dropdown, popover) < `--ui-dialog-bg` (modal). `.card`, `.modal-content`, `.dropdown-menu`, `.popover` Bootstrap dipetakan ke tangga ini hanya di blok dark.
- Utilitas Bootstrap yang hanya-terang (`.bg-light`, `.bg-white`, `.table-light`, `.btn-outline-*`, badge mentah, `.text-dark`) punya adapter dark di blok yang sama. Jangan menulis `#fff`/`#000` baru.
- Kontras teks dark diukur terhadap `--md-surface` dan `--ui-dialog-bg`, bukan hanya `--md-background`.
- Seri chart dark memakai `--md-chart-primary` / `--md-chart-warning` (≥ 3:1 terhadap surface, WCAG 1.4.11).
- Penjaga: tes "dark overlays form a tonal elevation ladder…" di `tests/js/preferences.test.mjs`.

### 3.4 Accent preset

Preferensi `accent`: `brand` (ADASI Blue, default), `slate`, `indigo`, `teal`, `violet` (`config/user_preferences.php`). Preset **hanya** mengganti keluarga `--md-primary*`, `--ui-primary-text`, focus, dan link. **Token status dan seri chart tetap di palet brand.** Preset baru wajib punya pasangan light + dark dan adapter `.btn-primary` / `.btn-outline-primary`.

### 3.5 Density

Preferensi `density`: `spacious | comfortable (default) | balanced | compact | dense`, diterapkan sebagai `:root[data-density]` di dalam `@media screen` (print tidak terpengaruh). Komponen membaca token `--ui-density-*` dengan fallback:

| Tier | `--ui-density-row-min` | Cell block | Page inline |
|---|---|---|---|
| spacious | 2.75rem | .75rem | 1.75rem |
| comfortable | 2.5rem | .625rem | 1.5rem |
| balanced | 2.25rem | .5rem | 1.25rem |
| compact | 2rem | .375rem | 1rem |
| dense | 1.75rem | .25rem | .75rem |

Token lain: `page-block`, `stack-sm|md|lg`, `card-padding`, `metric-padding`, `panel-*`, `toolbar-*`, `form-gap`, `list-block`, `sidebar-*`, `modal-padding`. Komponen baru yang punya padding/gap berulang memakai `var(--ui-density-…, <default comfortable>)`, bukan angka tetap. Penjaga: `tests/js/density-contract.test.mjs`.

### 3.6 Tipografi

- **Font:** Inter (`@fontsource/inter` versi eksak via CDN dengan SRI) untuk layout app, auth, dan dokumen publik. Layout `guest` memakai Figtree dengan pola yang sama.
- **Skala** (`tw:text-ui-*`): `xs .75rem` · `sm .875rem` · `base 1rem` · `lg 1.125rem` · `xl 1.25rem` · `2xl 1.5rem`. Line-height `tight 1.25` (lg ke atas), `normal 1.5`.
- **Grammar hierarki:**
  - Judul halaman (`x-ui.page-header`): `lg` → `xl` di breakpoint shell, bold, tracking-tight.
  - Judul section dan form-section: `xs`/`sm`, bold, **UPPERCASE**, tracking-wider.
  - Header tabel: `xs`, bold, UPPERCASE, letter-spacing .04em, nowrap.
  - Body tabel: `sm`. Deskripsi dan meta: `xs`/`sm` dengan `on-surface-variant`.
  - Eyebrow: `xs` bold uppercase berwarna primary (opsional).

### 3.7 Spacing, shape, elevation

- **Spacing** (`tw:p-ui-*`): `1 .25rem` · `2 .5rem` · `3 .75rem` · `4 1rem` · `5 1.25rem` · `6 1.5rem` · `8 2rem`. Skala Tailwind standar juga dipakai di komponen.
- **Radius** (`tw:rounded-ui-*`): `xs 2px` · `sm 4px` (kontrol, `--ui-radius-control`) · `md 6px` (surface, `--ui-radius-surface`) · `lg 8px` (maksimum untuk container) · `full` hanya untuk avatar, switch, dot.
- **Elevation:** `--ui-shadow-1` (halus) dan `--ui-shadow-2` (floating layer). Card dan tabel statis memakai `shadow-none` dengan border `outline`. Bayangan hanya untuk lapisan yang benar-benar mengambang: dropdown, dialog, drawer, toast, kalender.

### 3.8 State, motion, layering

- **State layer:** hover .05 · focus .08 · pressed .08 · dragged .12 · disabled .4. Komponen memakai `hover:bg-surface-container` / `brightness-95` dan `disabled:opacity-50`.
- **Focus:** `.ui-focus-ring:focus-visible` = cincin ganda (offset 2px warna surface + 2px `--md-primary`). Jangan menghapus outline tanpa pengganti yang terlihat.
- **Motion:** `fast 150ms` · `standard 200ms` · `slow 300ms`; easing `standard cubic-bezier(.2,0,0,1)`. `.ui-motion` hanya mentransisikan warna, border, bayangan, opacity, dan transform. Motion sidebar baru aktif setelah `data-sidebar-motion-ready`. Hormati `prefers-reduced-motion`. View transition navigasi berdurasi 0s, kecuali region toast. **Dilarang:** spring, bounce, confetti.
- **z-index** (`tw:z-*`): dropdown 1000 · sticky 1020 · drawer 1040 · modal 1060 · calendar 1070 · toast 1080. Jangan menulis z-index angka baru di luar skala ini.

### 3.9 Dimensi shell

`--sidebar-width 256px` · `--sidebar-width-collapsed 64px` (icon rail) · `--sidebar-item-height 40px` · `--topbar-height 56px` · kontrol `sm 2.125rem` / `md 2.5rem` / `lg 2.875rem` · baris tabel compact `2.5rem`. Breakpoint `tw:shell:` = **993px** (selaras dengan batas Bootstrap `lg`, yang membuat sidebar menjadi drawer di bawahnya).

---

## 4. Ikonografi

- **Hanya** `<x-ui.icon name="…" size="xs|sm|md|lg">` (14/16/18/20px), Lucide via blade-lucide-icons, kelas `App\View\Components\Ui\Icon`.
- Nama `bi-*` lama dipetakan lewat `Icon::ALIASES`; nama yang tidak dikenal jatuh ke `circle-help`. **Jangan** menulis `bi bi-*` atau `<x-lucide-*>`; tambahkan alias (dan fixture) bila perlu.
- Markup yang dibangun di JS: render dengan `Icon::html(new ComponentAttributeBag([...]))` di `@php`, lalu kirim lewat `@json(...)`.
- Ikon bersifat fungsional, berukuran kecil, dan selaras dengan teks. Tanpa lingkaran ikon dekoratif, ikon raksasa, atau **emoji** (termasuk HTML entity) di Blade maupun JS.
- Kontrol icon-only wajib punya `aria-label` (`x-ui.icon-button` mewajibkan prop `label`).
- Output dikunci byte-per-byte oleh `tests/Feature/UiIconComponentTest.php`.

---

## 5. Komponen (`x-ui.*`)

Pakai ulang sebelum membuat markup baru. Mengubah props atau markup di sini mengubah setiap halaman yang memakainya.

| Komponen | Kontrak |
|---|---|
| `button` | `variant`: `primary` (satu per area aksi) · `secondary` · `outline` · `ghost` · `danger`. `size`: `sm` / `md`. `loading` = spinner + disabled. `href` merender `<a>`. Radius `ui-sm`, font semibold |
| `icon-button` | `icon` + `label` wajib. `variant` ghost (default) / outline / secondary / primary / danger (container merah, bukan fill penuh). `size` sm 32 / md 36 / lg 44px |
| `status-chip` | `tone`: `neutral · info · success · warning · error` (alias `primary→info`, `danger→error`). Setiap tone punya ikon default sebagai **isyarat non-warna**. Tone dan label diambil dari `StatusHelper` |
| `page-header` | `title`, `description`, `eyebrow`, `breadcrumbs`; slot `status`, `meta`, `actions`. Ringkas, tanpa hero |
| `toolbar` | Slot `search`, `filters`, `actions`; `sticky` menempel di bawah topbar |
| `data-table` | Shell tabel (bukan DataTables): header dengan title/toolbar/filters, state `loading` (skeleton), `error` (alert), `empty` (empty-state). `sticky` + `scrollLabel` untuk scroll terbatas dengan header lengket. Halaman DataTables wajib `@section('uses-datatables', true)` |
| `card` | `variant` default/flat/tonal/elevated, `padding` none/sm/md. Pakai hanya bila pengelompokan bermakna. `elevated` bukan untuk konten statis |
| `section` / `form-section` | Judul uppercase kecil + divider. `form-section` adalah blok bersection di dalam form |
| `action-bar` | Sticky bottom bar untuk form (slot `left` / `right`) |
| `metric-card` | Ringkasan angka berwarna tone. Jangan membangun dinding KPI di atas tabel operasional |
| `alert` | `tone` info/success/warning/error, border + container + ikon; `dismissible` |
| `dialog` / `drawer` | `name` wajib; `maxWidth` sm–2xl; drawer `side` right default. Fokus dikelola, harus muat di viewport |
| `tabs`, `breadcrumb`, `pagination`, `empty-state`, `skeleton`, `widget-skeleton` | Navigasi dan state standar |
| `input`, `textarea`, `select`, `multi-select`, `searchable-select`, `switch`, `field-error` | Kontrol form; border `--ui-input-border`, fokus `--ui-input-focus` |
| `date-picker` / `date-range-picker` | **Wajib** untuk semua tanggal. Dilarang menulis `<input type="date">` langsung di halaman. Di loop/modal, beri `id` bersuffix unik. `allowedDaysOfWeek` untuk jadwal fisik (Rabu), dengan backend tetap memvalidasi |
| `file-upload` | Menampilkan error dari event `adasi:form-errors`. Form dengan upload wajib `data-async-submit` |
| `toast` / `toast-container` | Feedback transient (AdasiToast), live region |
| `dashboard-layout`, `dashboard-editor-*` | Grid 12 kolom dengan smart span (lihat §6.6) |

Komponen kalender sengaja merender native `type="date"` sebagai dasar progressive enhancement; ini bukan pelanggaran.

---

## 6. Pola halaman

### 6.1 Shell

- Sidebar terang, lebar tetap 256px, bisa diciutkan menjadi icon rail 64px (`html[data-sidebar-collapsed]`). Item aktif ditandai halus (bar indikator + warna primary), tanpa blok warna besar.
- Topbar minimal setinggi 56px. Konten full-width adaptif dengan padding dari token density.
- Di bawah 993px, sidebar menjadi drawer.
- Setiap layout yang memuat `resources/js/app.js` wajib `@include('partials.loader-logo')` sebelum `@vite`.

### 6.2 Halaman daftar

- Page header ringkas → toolbar (filter frekuensi tinggi terlihat, filter sekunder di balik **More filters**) → tabel.
- **Tanpa dinding KPI** di atas tabel operasional.
- Aksi utama baris terlihat; aksi sekunder di overflow/kebab menu.
- Toolbar dan header tabel boleh sticky untuk daftar panjang.
- Volume tinggi memakai DataTables server-side; sisanya pagination server-rendered. Semua daftar wajib punya pencarian dan filter.

### 6.3 Form

- **Single-page bersection + sticky action bar.** Kelompokkan dengan tipografi, spasi, dan divider; jangan membungkus tiap grup field dalam card terpisah.
- Spinner pada tombol submit selama proses berjalan.
- Error validasi: ringkasan bertaut ke field + pesan inline. Berkas yang sudah dipilih tidak boleh hilang saat validasi gagal (pola `data-async-submit`, AGENTS.md "Berkas tidak boleh hilang").

### 6.4 Detail

Page header ringkas dengan status dan metadata utama → section datar dengan hierarki operasional jelas. Detail sekunder (riwayat aktivitas, respons supplier, audit ringan, konteks record terkait) boleh di drawer kanan. Workflow kompleks tetap halaman sendiri.

### 6.5 Tab, filter, dan paginasi tanpa reload

Memakai `AdasiServerTabs` (`data-server-tabs-container`, `-content`, `-form`, `data-server-tab`). Gaya tab aktif ditulis `tw:aria-[current=page]:…` karena JS hanya memindahkan `aria-current`. Pola state terpilih memakai **satu sumber** (varian `aria-*`), jangan mencampur class statis dengan `:class` Alpine.

### 6.6 Dashboard

- Prioritas: **antrean operasional** → ringkasan singkat → 2–3 chart bermakna → aktivitas terbaru bila berguna.
- Registry `config/dashboard_widgets.php` (`span`, `kind`). Smart span dihitung identik di `DashboardWidgetService::computeEffectiveSpans()` dan `resources/js/dashboard-layout-core.js` (fixture bersama).
- Jangan menulis `tw:lg:col-span-N` pada slot dashboard; peta class ditulis literal di komponen layout.
- Mode edit hanya untuk pemilik audience.

### 6.7 Feedback dan state

| Kebutuhan | Pola |
|---|---|
| Sukses/gagal transient | AdasiToast |
| Konfirmasi, prompt, keputusan blocking | AdasiAlert / SweetAlert2 |
| Pesan inline persisten | `x-ui.alert` |
| Memuat | `x-ui.skeleton`; DataTables busy state di tabel (bukan indikator bawaan) |
| Kosong | `x-ui.empty-state` (ikon kecil, judul, deskripsi, satu aksi opsional) |
| Sesi berakhir | Dialog "Sambungkan kembali / Nanti saja", bukan redirect paksa |

### 6.8 Status

- Label dan warna status **hanya** dari `App\Support\StatusHelper`; perluas array atau `match()` di sana.
- Core Lokal: tone design system untuk `x-ui.status-chip` (`localInvoiceTone()`, `localFinanceTone()`, `gaClaimTone()`, `supplierAuditTone()`, …) + label dari `lang/*/status.php`.
- Core Impor: class badge Bootstrap (`prBadge()`, `poBadge()`, …) dan tone untuk jalur yang sudah memakai chip (`shipmentTone()`, …). Pakai pasangan yang dipakai modul tersebut.
- Status tidak boleh hanya dibedakan oleh warna: chip membawa ikon dan teks. Tanpa perlakuan status pelangi.

---

## 7. Visualisasi data

Kontrak aksesibilitas chart: [CHART-A11Y-BASELINE-20261102.md](docs/audits/CHART-A11Y-BASELINE-20261102.md) (audit), [CHART-A11Y-TASKS-20261102.md](docs/plans/CHART-A11Y-TASKS-20261102.md) (tugas per chart), [CHART-A11Y-RESULT-20261102.md](docs/results/CHART-A11Y-RESULT-20261102.md) (hasil).

- Chart.js dimuat per halaman; tema dan lapisan aksesibilitas dari `resources/js/chart-theme.js` (`window.AdasiChart`). Script chart halaman dijalankan **setelah** modul app siap (`DOMContentLoaded` atau init Alpine); script inline yang langsung berjalan kehilangan token, tooltip, dan skala ADASI.
- **Bingkai wajib:** setiap canvas memakai `<x-ui.chart>`: `<figure>`, canvas `role="img"` + `aria-label` singkat + `tabindex="0"`, `figcaption` berisi ringkasan teks (dihitung dari data chart bila tidak ditulis server) dan petunjuk keyboard, live region `polite`, serta slot `table` (`<details>` "Tampilkan data dalam tabel"). Chart yang dirender lewat fetch meniru markup yang sama.
- **Warna seri:** token `--md-chart-series-1..6` (light dan dark sendiri, tervalidasi ≥ 3:1 terhadap surface dan terpisah untuk protan/deutan/tritan; `tests/js/chart-theme-a11y.test.mjs`). Urutan tetap biru → oranye → aqua → amber → magenta → violet, mengikuti entitas, bukan urutan ranking. Seri ke-7+ memakai hue yang sama dengan hatch (`AdasiChart.hatch`), tidak pernah hue baru. Seri chart tidak ikut accent preset.
- **Status bukan seri:** `--md-chart-ok` / `--md-chart-ng` hanya untuk lolos/gagal, selalu dengan label dan isyarat non-warna (ikon, dash, bentuk titik). Jangan memakai hijau/merah status sebagai warna seri biasa.
- **Tidak hanya warna:** seri garis dibedakan `pointStyle` + `borderDash` (`AdasiChart.getSeriesStyle(i)`); kategori satu seri memakai satu warna (status/nama ada di label). Satu sumbu Y per chart; tidak ada dual-axis.
- **Warna dibaca saat render:** pakai `AdasiChart.color('series-1'|'ok'|'ng'|'surface')` (scriptable) agar ganti tema/forced colors tanpa reload ikut mewarnai ulang dataset. Jangan menyimpan hex hasil `getColors()` ke dataset.
- **Teks:** tick, legend, dan tooltip 12 px dengan warna teks token; angka lewat `AdasiChart.formatNumber` / `AdasiPreferences.displayNumber`. String chart di `lang/*/charts.php`; teks JS chart dikirim sekali per halaman (`script[data-adasi-chart-i18n]`), di luar kamus global 16 KiB.
- **Interaksi:** keyboard (←/→/↑/↓, Home/End, Esc) menelusuri titik dengan tooltip yang sama seperti hover; nilai juga tersedia di ringkasan dan tabel, jadi tooltip tidak pernah satu-satunya jalan. Canvas tetap `role="img"` (bukan `application`) agar tombol panah screen reader tidak direbut.
- **Motion dan mode sistem:** `prefers-reduced-motion` mematikan animasi (termasuk durasi per chart); `forced-colors` menggambar dengan warna sistem dan hatch.
- Bar dan garis solid; fill gradien tidak dipakai (`createBarGradient`/`createAreaGradient` tetap ada untuk kompatibilitas, tetapi tidak efektif pada scriptable context). Jangan bawa gradien ke surface UI.
- Grid hairline solid `--md-chart-grid`. Simpan instance Chart di luar state reaktif Alpine (Proxy membuat `update()` rekursi tanpa akhir).
- Nilai tanpa kurs dikeluarkan dan dilaporkan, tidak digambar sebagai nol (lihat AGENTS.md §Konversi Kurs).

---

## 8. Konten, bahasa, dan format

- Setiap teks UI adalah key `__('domain.key')` yang ada di **`lang/en` dan `lang/id`**. Teks JS memakai `window.AdasiI18n.t()`. Locale adalah preferensi per user.
- Kebijakan istilah per core ("UI Language Policy" di AGENTS.md): Impor English-first, Lokal Indonesian-first. Istilah kanonik (`DRP`, `NSFP`, `PO`, `Supplier`, `Vendor Master`, …) tetap. Jangan menulis `Pemasok` atau `Rekanan`.
- Data bisnis dan input user (nama supplier, material, nomor dokumen, catatan) dan nilai mesin (`STATUS_*`) tidak diterjemahkan.
- Angka dan tanggal tampilan lewat `$regionalFormatter` (`RegionalDisplayFormatter`) atau `@bizdt` / `BusinessTime`, selalu dengan label zona waktu untuk timestamp. Jangan `number_format()` atau `->format()` langsung.
- Microcopy profesional, ringkas, tanpa hero/marketing copy di halaman internal.
- Pengecualian dokumen tetap: PDF PO Impor mengikuti formulir PNR261178 (English, format angka/tanggal tetap), terlepas dari locale user.

---

## 9. Aksesibilitas (WCAG 2.2 AA)

- Fokus keyboard selalu terlihat (§3.8). Urutan fokus logis, termasuk di dialog dan drawer (focus trap dan kembali ke pemicu).
- Kontras: teks ≥ 4.5:1, komponen non-teks dan seri chart ≥ 3:1, diukur pada light **dan** dark surface yang relevan.
- Isyarat non-warna untuk status, error, dan seleksi.
- `aria-label` untuk kontrol icon-only. Toast di live region. Region scroll tabel sticky punya nama aksesibel.
- Heading berurutan; header tabel terbaca.
- Target sentuh wajar (kontrol md 40px, slider density 44px).
- Kepadatan tidak boleh mengorbankan aksesibilitas.

---

## 10. Responsif

Desktop adalah lingkungan utama; tablet dan mobile wajib bisa dipakai penuh.

- Sidebar menjadi drawer; tabel menggulir horizontal dengan sengaja (`ui-data-table__scroll`).
- Aksi kritis tidak boleh tersembunyi. Area sticky tidak menutupi konten. Dialog dan drawer muat di viewport.
- Form tetap bisa diselesaikan; filter tetap terjangkau.
- Jangan sekadar mengecilkan layout desktop.

---

## 11. Do / Don't (anti-AI-slop, kriteria penerimaan)

**Jangan menambah:** gradient dekoratif, glassmorphism, backdrop blur dekoratif, glow/neon, card beradius besar (16–24px), bayangan tebal berlapis, card bersarang, dinding KPI di atas tabel, pill/badge tanpa makna semantik, lingkaran ikon dekoratif, ikon raksasa, palet pastel atau pelangi, ilustrasi dekoratif, blob abstrak, emoji, confetti, animasi spring/bounce, whitespace berlebihan, hero atau pola landing page, komposisi dashboard SaaS generik.

**Utamakan:** hierarki tipografi, spasi ringkas, surface netral, border halus, densitas seimbang, ikon Lucide fungsional, warna semantik yang menahan diri, hierarki aksi eksplisit, section operasional datar, card hanya bila pengelompokan bermakna.

> Bila sebuah perlakuan visual tidak memperbaiki pemahaman, hierarki, state, atau kemampuan bertindak, hapus.

Konsistensi lintas modul: radius, card, grammar page header, grammar filter, hierarki tombol, gaya ikon, sistem spasi, dan perlakuan status-chip **sama di semua role**. Yang boleh berbeda hanya prioritas informasi, konten domain, kolom workflow, dashboard per role, dan state semantik.

---

## 12. Kompatibilitas dan aset frontend

- Pertahankan atribut `data-bs-*`, dropdown, modal, offcanvas, DataTables, serta ID, class, dan relasi DOM yang dipakai jQuery/AJAX. Bila markup terikat JS, bangun ulang komposisi visual di sekitarnya.
- Bootstrap, jQuery, SweetAlert2, dan Chart.js lewat CDN versi eksak + SRI. Vite membundel `resources/css/app.css` dan `resources/js/app.js`.
- Modul JS per halaman di-`import()` lazy berdasarkan penanda DOM; komponen Alpine halaman didaftarkan di `pageComponents` (`tests/js/app-lazy-modules.test.mjs`).
- Rilis yang mengubah komponen Blade wajib `view:cache`, termasuk saat rollback.

---

## 13. Penjaga otomatis dan verifikasi

| Aspek | Penjaga |
|---|---|
| Ikon | `tests/Feature/UiIconComponentTest.php` |
| Komponen | `RenderedComponentTest`, `CalendarComponentTest`, `tests/js/calendar.test.mjs` |
| Aset / loader | `FrontendAssetLoadingTest`, `tests/js/adasi-loader.test.mjs`, `tests/js/tailwind-build.test.mjs` |
| Dark mode, accent, preferensi | `tests/js/preferences.test.mjs`, `tests/js/customization-form.test.mjs` |
| Density | `tests/js/density-contract.test.mjs` |
| Dashboard | `DashboardSmartSpanTest`, `tests/js/dashboard-{layout-core,editor,wireframe}.test.mjs` |
| Server tabs | `tests/js/server-tabs.test.mjs`, `ServerTabsResponseTest` |
| Terjemahan | `TranslationParityTest`, `TranslationContentTest` |

Setelah perubahan UI:

```powershell
npm.cmd run build
php artisan view:cache
node --test tests/js/<file>.test.mjs   # file eksplisit; glob direktori gagal di Windows
php artisan test --filter=<TestTerkait>
git diff --check
```

Hasil visual tetap memerlukan QA manual di light/dark, beberapa density, dan lebar desktop/tablet/mobile; tes otomatis tidak membuktikannya.

---

## 14. Drift yang diketahui

Dicatat agar tidak disalin ke pekerjaan baru; perbaikan dilakukan pada task terpisah.

1. **Bahasa UI.** Global Contract Phase 2 §8 menulis "all visible UI copy remains professional English". Ini sudah digantikan oleh lokalisasi per user + UI Language Policy di AGENTS.md.
2. **Realtime.** Global Contract §7 menyebut Reverb. Provider aktif adalah Pusher (AGENTS.md); Reverb tidak dipakai.
3. **Role badge.** `.role-badge-purchasing` memakai warna success dan `.role-badge-qc` warna error. Ini warna semantik untuk identitas role, bukan state. Jangan ditiru untuk badge baru.
4. **Chart.** `createBarGradient`/`createAreaGradient` masih diekspor `chart-theme.js`, tetapi sejak audit 2 November 2026 chart memakai fill solid (helper itu selalu mengembalikan warna solid/transparan pada scriptable context). Tooltip "pill" gelap tetap perlakuan khusus chart. Keduanya bukan izin untuk gradien di surface UI.
5. **Palet legacy Tailwind** (`emerald`, `amber`, …) masih dipakai beberapa layar lama, termasuk adapter dark `tw:bg-emerald-600` / `tw:bg-amber-500`. Gunakan token semantik untuk pekerjaan baru.
