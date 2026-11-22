# Implementation Plan (v2 — Deep Design Systems Spec): Standardisasi Button Aksi & Ghost Button Governance

*Diperbarui: 20 November 2026 | Repositori: `poyipoy/supplierportal` (Laravel 12, Blade `x-ui.button` / `x-ui.icon-button`, Tailwind CSS 4 `tw:`, Bootstrap 5 compat, 16 PHP DataTables controllers, 5 Accent Presets × Light/Dark) | Prinsip: Lebih dalam, bukan lebih panjang — kontrak token terukur WCAG 2.2 AA, bahan mentah 4 permukaan render, dan penegakan otomatis di CI.*

## 1. Bahan Mentah (Raw Source of Truth) yang Wajib Digunakan

Agen pelaksana **dilarang** merancang ulang dari asumsi atau hanya membaca Blade. Seluruh perubahan wajib bertumpu pada **4 permukaan render mentah** dan **kontrak matematis token** di repositori:

1. **Token mentah 10 matriks tema (`resources/css/app.css`):** nilai hex eksak di `:root`, `:root[data-theme="dark"]`, dan 5 blok `:root[data-accent="brand|slate|indigo|teal|violet"]` (L83–560) — khususnya fakta terverifikasi bahwa `--md-outline` (`#CBD5E1`) di atas `--ui-workspace-bg` (`#E9EEF4`) hanya mencapai kontras **~1,26:1** (gagal WCAG 2.2 SC 1.4.11 3:1), sedangkan `--md-outline-strong` (`#76859A` light / `#71819A` dark, dipakai `.ui-record-nav` L2310) telah terbukti **≥ 3,0:1** di `tests/js/text-contrast.test.mjs` L36–45.
2. **Komponen Blade & pelanggaran token mentah:** `resources/views/components/ui/button.blade.php` (L13–24), `resources/views/components/ui/icon-button.blade.php` (L16, L19–21 memuat `tw:bg-white`, `tw:border-slate-300`, `tw:bg-amber-50`, `tw:bg-emerald-50`, `tw:bg-red-50`), dan `resources/views/components/export/advanced-modal.blade.php` (L14 `Quick Export` memakai `ghost` tanpa ikon).
3. **Permukaan non-Blade yang merender tombol:**
   * **16 controller PHP DataTables** (`app/Http/Controllers/**`) yang menghasilkan HTML `.ui-data-action`, `.ui-data-action--primary` (22), `--danger` (5), `--success` (1), `--warning` (1) terhadap aturan CSS `resources/css/app.css` L669–676 & L2437–2480.
   * **JS DOM builders:** `resources/js/adasi-loader.js:155`, `resources/js/local-procurement-import.js:141,157`, `resources/js/server-tabs.js:801`.
4. **Inventaris mentah 186 `ghost` + 102 raw Bootstrap `.btn-*`:** hasil pemindaian statis langsung di `resources/views/` (69 `Back` di 64 file, 40 `Reset` di 34 file, 37 `Cancel/Close` di 30 file, 23 `Table Row View/Details` di 17 file, 5 `Export`, 12 lainnya) beserta penjaga kontrak eksisting `tests/Feature/RenderedComponentTest.php` (L50–83) dan `tests/js/text-contrast.test.mjs`.

---

## 2. Kontrak Desain Kanonik (Token, Kontras 10-Tema, & Semantik)

### 2.1 Spesifikasi Varian Resmi (`<x-ui.button>` & `.ui-data-action` Paritas 1:1)

Setiap varian wajib lulus **WCAG 2.2 AA** (teks **≥ 4,5:1**, border/non-teks **≥ 3,0:1**) pada **semua 10 kombinasi tema** (5 accent × Light/Dark) dan di atas 3 permukaan utama (`--md-surface`, `--ui-workspace-bg`, `--md-surface-container`):

| Varian | Kontrak Token Visual (Light + Dark) | Penempatan Wajib |
|---|---|---|
| **`primary`** | Fill `--md-primary`, teks `--md-on-primary`, border transparan | **Maks. 1 per zona aksi** (Page Header kanan, Form Action Bar, Modal Footer, Filter Apply). |
| **`secondary`** | Fill `--md-secondary-container`, teks `--md-on-secondary-container`, border `--md-outline-variant` | Aksi konstruktif pendamping `primary` (mis. `Consolidate Selected Items`, `Save as Draft`). |
| **`outline`** *(diperkuat)* | **Fill `--md-surface`**, teks `--md-on-surface`, **border `--ui-input-border` (`--md-outline-strong`)**, hover `--md-surface-container` | **Seluruh aksi utilitas & navigasi Page Header/Toolbar/Dropdown:** `Document Chain`, `Export Excel`, `Advanced Export`, `Quick Export` (+ ikon `download`), `← Back to ...`, `More Filters`, `More ▾`. Selaras penuh dengan `.ui-record-nav` (`< Previous \| 2/52 \| Next >`). |
| **`outline-primary`** *(baru — paritas `.ui-data-action--primary`)* | **Fill `--md-surface`**, teks & border **`--ui-primary-text`** (otomatis adaptif di Dark Mode untuk ke-5 accent preset!), hover `--md-primary-container` / `--md-on-primary-container` | **Standar tunggal aksi utama baris tabel** di SEMUA tabel Blade maupun PHP DataTables (`View`, `Details`, `Submit`, `Review`, `Verify`). |
| **`danger` / `danger-outline`** | Solid: `--md-error` / `--md-on-error` · Outline: fill `--md-surface`, border & teks `--md-error` (dark: token kontras ≥ 4,5:1), hover `--md-error-container` | `danger` untuk konfirmasi final dialog/modal; `danger-outline` (`.ui-data-action--danger`) untuk trigger destruktif di baris tabel/header. |
| **`ghost`** *(dibatasi ketat)* | Fill & border transparan, teks `--md-on-surface-variant`, hover `--md-surface-container` / `--md-on-surface` | **Hanya 4 konteks ter-whitelist di §2.2.** |

### 2.2 Ghost Button Governance (Kebijakan Biner yang Dapat Diuji Mesin)

* **Allow-List (Hanya 4 konteks ini yang BOLEH memakai `variant="ghost"`):**
  1. `Cancel` / `Close` / `Dismiss` tepat di samping tombol `primary` pada Modal Footer, Drawer Footer, atau Form Action Bar ([IBM Carbon](https://www.carbondesignsystem.com/building-blocks/core/components/button/guidelines), [SAP Fiori](https://www.sap.com/design-system/fiori-design-web/v1-145/ui-elements/button)).
  2. `Reset` / `Clear Filters` di dalam Filter Toolbar atau Active Filter Chips strip (wajib berikon `rotate-ccw`).
  3. `<x-ui.icon-button variant="ghost">` berukuran kompak untuk kontrol utilitas mikro (`×`, kebab `⋮`, refresh, copy, toggle password) ([Atlassian](https://atlassian.design/components/button)).
  4. Tautan aksi mikro sekunder di dalam card/popover padat (`View all` di widget dashboard, `Clear` di popover).
* **Deny-List (DILARANG `ghost` — diuji otomatis oleh linter CI):**
  1. Segala tombol di dalam `<x-slot:actions>` milik `<x-ui.page-header>` (`Export Excel`, `Quick Export`, `← Back to ...`, `Document Chain`) → wajib `outline` / `secondary` / `primary`.
  2. Segala tombol aksi utama di dalam `<td>` kolom `Action` tabel (`Details`, `View`, `Open`) → wajib `outline-primary` / `.ui-data-action--primary`.
  3. Tombol aksi mandiri tanpa tetangga (mencegah *click uncertainty* per [Nielsen Norman Group](https://www.nngroup.com/articles/flat-design-best-practices/)).
* **Invarian Semantik & Target Sentuh:** Navigasi pakai `<a href>`, mutasi pakai `<button type="button|submit">`; seluruh tombol baris tabel & header pakai `size="sm"` (`--ui-control-height-sm: 2.125rem` desktop, `2.5rem` pada `@media (pointer: coarse)` sesuai WCAG 2.2 SC 2.5.8).

---

## 3. Metode, Alat, & Rencana Eksekusi Bertahap (4 Fase)

### Fase 0 — Fondasi Token, Komponen Inti, & Perbaikan Pelanggaran Dark Mode
* **File:** `resources/views/components/ui/button.blade.php`, `resources/views/components/ui/icon-button.blade.php`, `resources/css/app.css`, `resources/views/components/export/advanced-modal.blade.php`, `tests/Feature/RenderedComponentTest.php`.
* **Aksi:**
  1. Perbarui `outline` di `button.blade.php` ke `tw:bg-surface tw:border-outline-strong` (atau kelas CSS `.ui-button--outline` berbasis `--ui-input-border` & `--md-surface`), tambahkan `outline-primary` (berbasis `--ui-primary-text`) dan `danger-outline`.
  2. Hapus seluruh kelas palet legacy hardcode (`bg-white`, `slate-300`, `amber-50`, `emerald-50`, `red-50`) di `icon-button.blade.php` dan ganti ke token semantik `--md-*`.
  3. Samakan `.ui-data-action` di `app.css` agar memakai `background: var(--md-surface)` dan `border-color: var(--ui-input-border)` (100% identik secara piksel dengan `<x-ui.button variant="outline">` & `outline-primary`).
  4. Ubah `Quick Export` di `components/export/advanced-modal.blade.php:14` dari `ghost` polos menjadi `variant="outline"` + `<x-ui.icon name="download" size="sm" />`.
  5. Perbarui `tests/Feature/RenderedComponentTest.php` L50–83 agar mengunci kontrak baru (`bg-surface`, `outline-primary`, `danger-outline`, nol kelas legacy).

### Fase 1 — Penyatuan Aksi Baris Tabel (Blade + 16 PHP DataTables Controllers)
* **Alat:** Script audit statis untuk mendaftar seluruh 17 file Blade yang merender `ghost` di dalam `<td>` dan 16 controller DataTables di `app/Http/Controllers/`.
* **Aksi:** Migrasikan 23 tombol `ghost` baris tabel (`local-invoices/table.blade.php`, `payment-vouchers/index.blade.php`, `finance/local-procurement/_gr_actions.blade.php`, `finance/drp/_paid_table_content.blade.php`, `purchasing/drp/_paid_table_content.blade.php`, `supplier/requests/index.blade.php`, `purchasing/quotations/index.blade.php`, widget tabel dashboard) ke `<x-ui.button variant="outline-primary" size="sm">` agar seragam 100% dengan `.ui-data-action--primary` di DataTables.

### Fase 2 — Standardisasi Page Header (`← Back`, `Export Excel`, Utilitas)
* **Aksi:** Migrasikan 69 tombol `← Back to ...` (64 file) serta `Export Excel` / `Print` / `Download` di `<x-slot:actions>` milik `<x-ui.page-header>` dari `ghost` ke `outline`, dengan urutan baku: **Navigasi (`< Previous | N/M | Next >`, `← Back`) → Utilitas (`Document Chain`, `Export`) → 1 `primary` di paling kanan**.

### Fase 3 — Pembersihan Raw Bootstrap `.btn-*`, JS Builders, & Penegakan CI
* **Aksi:**
  1. Migrasikan tombol raw Bootstrap (`.btn-outline-primary`, `.btn-secondary`, `.btn-outline-secondary`, `.btn-danger`) yang tidak terikat plugin di Blade & 3 file JS (`adasi-loader.js`, `local-procurement-import.js`, `server-tabs.js`) agar selaras dengan token `.ui-data-action` / `<x-ui.button>`.
  2. Buat **static-analysis governance test** `tests/js/button-governance.test.mjs` dan perluas `tests/js/text-contrast.test.mjs` (matriks kontras 10 tema).
  3. Perbarui `DESIGN.md` (§5 & §11) dan `resources/views/components/ui/CLAUDE.md`.

---

## 4. Bukti Falsifikasi Eksternal (Objective Proof of Correctness)

Keberhasilan **tidak dibuktikan** oleh opini visual subjektif, melainkan wajib lolos 4 oracle objektif berikut:

1. **Mathematical Contrast Oracle (`tests/js/text-contrast.test.mjs` — diperluas):**
   Menghitung rasio luminansi WCAG 2.2 secara matematis langsung dari `resources/css/app.css` untuk **seluruh 10 kombinasi tema (5 accent × Light/Dark)** × **3 permukaan (`--md-surface`, `--ui-workspace-bg`, `--md-surface-container`)**: teks tombol `outline`, `outline-primary`, `danger-outline`, `ghost` **≥ 4,5:1**, dan border tombol `outline` / `outline-primary` / `.ui-data-action` **≥ 3,0:1**.
2. **Static-Analysis Governance Oracle (`tests/js/button-governance.test.mjs` — baru):**
   Memindai `resources/views/`, `app/Http/Controllers/`, dan `resources/js/` lalu **gagal otomatis** bila ditemukan:
   * `variant="ghost"` di dalam `<x-ui.page-header>` `<x-slot:actions>` atau di dalam `<td>` aksi tabel (`Details`/`View`/`Open`);
   * kelas palet legacy (`bg-white`, `slate-300`, `amber-50`, `emerald-50`, `red-50`) di `button.blade.php` atau `icon-button.blade.php`;
   * lebih dari satu `variant="primary"` di dalam satu `<x-slot:actions>` milik `<x-ui.page-header>`.
3. **Rendered Component & Regression Oracle (PHPUnit + Vite Build):**
   `RenderedComponentTest`, `UiIconComponentTest`, `AdvancedExportUiTest`, `ClaimAndInspectionListLayoutTest`, `TranslationParityTest`, `TestingEnvironmentDatabaseSafetyTest`, `density-contract.test.mjs`, `tailwind-build.test.mjs`, dan `preferences.test.mjs` seluruhnya lulus tanpa regresi.
4. **Computed-Style Visual Verification Oracle (4 Layar Referensi Screenshot):**
   Inspeksi *computed style* di DevTools pada 4 layar referensi (`purchasing/pr/show`, `purchasing/pr/index`, DataTables `ACTION` vs Blade `ACTIONS`) pada Light + Dark + Accent `teal`/`brand` + `compact`/`comfortable`: tinggi tombol sejajar piksel (`34px` desktop / `40px` touch), `border-color` & `background-color` antara `.ui-record-nav`, `Document Chain`, `Export Excel`, `Back to Requisitions`, `Quick Export`, dan `.ui-data-action--primary` vs `<x-ui.button variant="outline-primary">` identik 1:1.

```powershell
# Perintah Verifikasi Final Wajib
npm.cmd run build
php artisan view:cache
node --test tests/js/button-governance.test.mjs tests/js/text-contrast.test.mjs tests/js/density-contract.test.mjs tests/js/tailwind-build.test.mjs tests/js/preferences.test.mjs
php artisan test --filter="RenderedComponentTest|UiIconComponentTest|AdvancedExportUiTest|ClaimAndInspectionListLayoutTest|TranslationParityTest|TestingEnvironmentDatabaseSafetyTest"
vendor/bin/pint --test app/ resources/
git diff --check
```