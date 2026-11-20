# Audit Desain Panel Filter & Rencana Implementasi Standardisasi — ADASI Supplier Portal

*Disusun: 20 November 2026 | Repositori: `poyipoy/supplierportal` (Laravel 12, Blade, Tailwind CSS 4 `tw:`, Bootstrap 5.3 compat, Alpine.js 3.4, DataTables, `AdasiServerTabs`) | Tingkat Kepercayaan Audit Codebase: Tinggi (`[Verified]` langsung dari source tree) | Sumber Riset Eksternal: 10 referensi resmi & standar industri*

---

## 1. Ringkasan Eksekutif

Audit menyeluruh terhadap seluruh menu lintas **7 role** (`Purchasing`, `Supplier Import`, `Local Supplier`, `Finance`, `General Affairs`, `Accounting`, `QC`, `Admin`) beserta modul bersama (`Payment Vouchers`, `Supplier Registrations`, `Exports`, `Notifications`) di `resources/views/partials/sidebar.blade.php` dan **76 berkas Blade kandidat** menunjukkan bahwa **panel filter saat ini terpecah menjadi 7 pola desain dan implementasi berbeda** `[Verified]`.

Walaupun `DESIGN.md` (§6.2 & §11) sudah menetapkan kontrak bahwa *grammar filter harus konsisten di semua role* dengan pola *toolbar (filter frekuensi tinggi terlihat, filter sekunder di balik More filters) → tabel*, implementasi aktual di lapangan mengalami *drift* signifikan:

- **Kontainer & permukaan berbeda-beda:** sebagian memakai `<x-ui.toolbar>`, sebagian membungkus form di dalam `<x-ui.card>`, sebagian menyisipkan form langsung di `<x-slot:filters>` milik `<x-ui.data-table>`, dan `payment-vouchers/index.blade.php` menulis `<form>` 12-kolom mandiri tanpa komponen toolbar.
- **Duplikasi CSS & JS lokal:** empat halaman DataTables (`purchasing/pr`, `purchasing/po`, `purchasing/shipments`, `supplier/shipments`) menyalin blok `<style>` `.{module}-filter-reset--active` dan logika jQuery `#filterChips` secara manual.
- **Ketidakkonsistenan kontrol & label:** pencampuran tinggi input (`form-control-sm` vs `min-h-[var(--ui-control-height-md)]`), ukuran font (`tw:text-ui-xs` vs `tw:text-ui-sm`), strategi label (`visually-hidden`, label terlihat, hingga `tw:uppercase`), serta tombol Reset yang berubah-ubah posisi, varian (`ghost` vs `outline` vs `icon-button`), dan kondisi tampilnya.
- **State filter tersembunyi kurang konsisten:** hanya `local-invoices/filters.blade.php` yang menampilkan *badge count* jumlah filter aktif pada tombol *Advanced/More Filters*; halaman collapse lain (`admin/users`, `admin/auth-audit-logs`, `admin/material-hs-code`, `supplier/price-history/historical`) tidak memberi indikator angka saat panel ditutup.

**Keputusan Desain:** Alih-alih membuat desain ulang generik yang memutus kontrak `DESIGN.md`, rencana ini menyatukan seluruh panel filter ke dalam **Unified ADASI Operational Filter System** berbasis satu komponen kanonik `<x-ui.filter-bar>` + `<x-ui.filter-chips>` dan helper JS ringan `resources/js/filter-bar.js`, dengan **2 tier standar**, **1 varian embedded**, serta **3 pengecualian domain yang secara eksplisit diizinkan** (Analytical Query Bar, Async Export Parameter Form, dan Minimal/Zero-Filter Views).

---

## 2. Metodologi Audit & Sintesis Panduan Web Modern

Riset eksternal dilakukan terhadap panduan sistem desain enterprise modern yang paling relevan dengan karakteristik ADASI Supplier Portal (B2B ERP / Infor LN UI / operasional padat data):

| Sumber Referensi | Prinsip Kunci yang Diadopsi | Implikasi Langsung untuk ADASI Portal |
|---|---|---|
| [IBM Carbon Design System — Filtering](https://www.carbondesignsystem.com/building-blocks/core/patterns/filtering) | Membedakan **Batch Updates** (tombol *Apply* untuk multi-filter / query berat) vs **Instant Updates** (filter tunggal atau live client/AJAX cepat); panel tertutup wajib menampilkan indikator jumlah filter aktif + opsi *clear all* tanpa membuka ulang panel. | Mode A (Server `GET` / `AdasiServerTabs`) tetap memakai tombol **Apply**; Mode B (DataTables) memakai debounced/instant update; tombol *More Filters* wajib menampilkan *badge count*. |
| [PatternFly 6.7 — Filters Design Guidelines](https://www.patternfly.org/patterns/filters/design-guidelines) | **Filter Group** horizontal untuk sedikit atribut; **Filter Labels (Chips)** di bawah toolbar menampilkan kondisi aktif (relasi `AND` antar kategori, `OR` dalam satu kategori) dengan tombol `×` per chip + aksi `Clear filters`; gunakan *typeahead/searchable select* jika opsi >10 item; di mobile, filter dilipat ke ikon/tombol filter. | Standarkan **Active Filter Chips Strip** seragam di bawah baris toolbar utama; rekomendasikan `<x-ui.searchable-select>` untuk dropdown Supplier/Material yang panjang (>10 opsi). |
| [SAP Fiori Design Guidelines — Filter Bar](https://www.sap.com/design-system/fiori-design-web/v1-148/ui-elements/filter-bar) | Filter Bar ditempatkan di area header daftar, menampilkan *Basic filters* yang paling krusial dan menyembunyikan sisanya di balik *Adapt/Advanced*; menyediakan nilai default bermakna untuk dataset besar. | Selaras 100% dengan `DESIGN.md` §1 & §6.2: maksimal **3–4 kontrol utama** di baris pertama, sisanya di panel collapsible *More Filters*. |
| [Infor Design System — List and Details](https://design.infor.com/patterns/page-layouts/list-and-details/) & [Infor Documentation — Grid Filtering](https://docs.infor.com/csdis/2025.x/en-us/useradminlib_distpod_cloud/pod_managers_adminguide/itm1609282605919.html) | Integrasi toolbar datar dengan kepadatan tinggi, batas hairline tegas, serta konsistensi kontrol *Clear Filter* di atas data grid tanpa memboroskan ruang vertikal. | Gunakan permukaan `--md-surface-container`, border `--md-outline`, radius `tw:rounded-ui-md`, dan patuhi 5 tier `--ui-density-*` agar selaras dengan `WorkspaceShell` ala Infor LN. |
| [Nielsen Norman Group — User Intent Affects Filter Design](https://www.nngroup.com/articles/applying-filters/) & [Helpful Filter Categories and Values](https://www.nngroup.com/articles/filter-categories-values/) | Kategori filter harus mencerminkan model mental operator (status kerja, supplier, periode, rentang tanggal), bukan struktur mentah kolom database; hindari *multi-select* native browser. | Urutkan kontrol konsisten di setiap menu: **Search (kiri) → Primary Status/Context → Secondary Entity/Date → More Filters → Actions (kanan)**. |
| [Pencil & Paper — Enterprise Filtering UX](https://www.pencilandpaper.io/articles/ux-pattern-analysis-enterprise-filtering), [UXPin — Filter UI & UX](https://www.uxpin.com/studio/blog/filter-ui-and-ux/), [Eleken — Filter UX for SaaS](https://www.eleken.co/blog-posts/filter-ux-and-ui-for-saas) | Terapkan **Progressive Disclosure** (baris utama bersih + tray lanjutan), cegah *layout shift* saat filter diterapkan, gunakan label aksi jelas (*Apply* / *Reset*, bukan label ambigu), dan sediakan pemulihan *empty state* dengan tombol reset cepat. | Tray lanjutan memakai transisi terukur (`--ui-motion-*`), tombol Reset tampil konsisten saat ada filter aktif, dan *empty state* tabel mengarahkan user untuk mereset filter. |
| [W3C WCAG 2.2](https://www.w3.org/TR/WCAG22/) & [Compound Design System — Chip Accessibility](https://compound.thephoenixgroup.com/latest/components/components/chips/accessibility-NJBNqvu5) | Semua kontrol dapat dioperasikan keyboard (`Tab`, `Enter`, `Esc`), *focus ring* jelas (`2.4.7`, `2.4.11`), target sentuh memadai (`2.5.8`), daftar chip memakai `role="region"` / `aria-live="polite"`, dan tombol hapus per chip memiliki `aria-label="Remove [filter]"`. | Wajib memakai `.ui-focus-ring`, token kontras `--md-on-surface-variant` (≥4.5:1), border `--ui-input-border` (≥3:1), serta `aria-expanded` + `aria-controls` pada tombol *More Filters*. |

---

## 3. Hasil Audit Codebase: 7 Pola Terfragmentasi & Akar Masalah

### 3.1 Taksonomi Pola Filter Saat Ini (`[Verified]`)

| ID Pola | Deskripsi Arsitektur Saat Ini | Contoh File di Codebase | Kekuatan | Masalah / Drift terhadap `DESIGN.md` |
|---|---|---|---|---|
| **Pola A** | `<x-ui.toolbar>` + Bootstrap `collapse` (`#...MoreFilters`) di bawahnya | `local-invoices/filters.blade.php`, `admin/users/index.blade.php`, `admin/auth-audit-logs/index.blade.php`, `admin/material-hs-code/index.blade.php` | Paling dekat dengan visi `DESIGN.md` §6.2; mendukung *progressive disclosure*. | Markup panel collapse tidak seragam (`local-invoices` punya header ikon + footer toggle chips + tombol Close/Reset/Apply ganda; `admin/*` hanya kotak `tw:p-4` polos tanpa radius/badge count). |
| **Pola B** | `<x-ui.toolbar :sticky="true">` + inline `<style>` `.{mod}-filter-reset--active` + jQuery `#filterChips` | `purchasing/pr/index.blade.php`, `purchasing/po/index.blade.php`, `purchasing/shipments/index.blade.php`, `supplier/shipments/index.blade.php` | Punya *active filter chips* dan umpan balik reset visual untuk DataTables. | CSS & JS chip/reset diduplikasi di 4 file Blade; tombol Search menyatu di `input-group` Bootstrap; chip menempel di dalam slot `filters` sehingga sering membungkus canggung di layar sedang. |
| **Pola C** | `<x-ui.toolbar>` datar 1 lapis (semua kontrol dijejalkan sejajar tanpa collapse) | `finance/local-procurement/index.blade.php`, `finance/drp/paid.blade.php`, `purchasing/drp/paid.blade.php`, `purchasing/quotations/index.blade.php`, `purchasing/outstanding-materials/index.blade.php`, `supplier/outstanding-materials/index.blade.php`, `local-supplier/purchase-orders/index.blade.php`, `admin/maintenance/index.blade.php`, `admin/announcements/index.blade.php`, `admin/exchange-rates/_content.blade.php`, `purchasing/reference-prices/_content.blade.php`, `qc/inspections/index.blade.php` | Ringkas untuk ≤3 filter; memakai komponen toolbar resmi. | Beberapa halaman menaruh `<form>` di luar `<x-ui.toolbar>`, lainnya di dalam `<x-slot:filters>`; ukuran font (`tw:text-ui-xs` vs `sm`), lebar minimum (`tw:sm:w-36`, `min-width: 150px` inline style), dan posisi Reset berbeda-beda. |
| **Pola D** | Standalone `<form>` 12-kolom dengan kelas custom (`tw:rounded-ui-md tw:border tw:bg-surface-container tw:p-4`) | `payment-vouchers/index.blade.php` | Tipografi label rapi, ikon search di dalam input, dukungan `x-ui.date-range-picker`. | Tidak memakai `<x-ui.toolbar>`; memakai tinggi `var(--ui-control-height-md)` dan tombol `size="md"` padahal halaman lain memakai `sm`; 6 filter selalu terbuka penuh memakan ruang vertikal di atas tabel. |
| **Pola E** | `<x-ui.card>` membungkus `<form>` grid/flex Bootstrap atau Tailwind | `finance/overpayments/index.blade.php`, `finance/drp/supplier.blade.php`, `finance/drp/ga.blade.php`, `finance/ga-claims/index.blade.php`, `ga/employees/index.blade.php`, `supplier/price-history/historical.blade.php` | Jelas terpisah secara visual untuk formulir filter multi-kolom. | Melanggar prinsip `DESIGN.md` §5 (`card` hanya untuk pengelompokan bermakna, bukan toolbar daftar) dan §6.2; latar belakang putih `--md-surface` berbenturan visual dengan toolbar `--md-surface-container` di menu tetangga. |
| **Pola F** | `<x-slot:filters>` disisipkan langsung di dalam `<header>` komponen `<x-ui.data-table>` | `supplier/requests/index.blade.php`, `supplier/quotations/period.blade.php`, `purchasing/import-suppliers/_queue_content.blade.php`, `purchasing/supplier-audits/_queue_content.blade.php`, `supplier-registrations/_attempts_content.blade.php`, `admin/exchange-rates/_annual_matrix.blade.php` | Hemat ruang vertikal ketika hanya ada 1–3 filter kecil di dalam tab/queue. | Menggunakan campuran grid Bootstrap `row g-2` (`supplier/requests`, `supplier/quotations/period`) vs flex Tailwind (`import-suppliers`, `supplier-audits`); tombol Reset memakai `<x-ui.icon-button variant="outline">` di Supplier tapi teks `ghost` di Purchasing. |
| **Pola G** | Tanpa filter bar eksternal (hanya DataTables built-in search atau daftar polos) | `purchasing/claims/index.blade.php`, `purchasing/periods/index.blade.php`, `supplier/po/index.blade.php`, `supplier/claims/index.blade.php`, `supplier/price-history/index.blade.php`, `finance/vendors/index.blade.php`, `purchasing/local-vendors/index.blade.php`, `ga/claims/index.blade.php`, `exports/index.blade.php`, `notifications/index.blade.php`, `conversations/index.blade.php` | Minimalis untuk daftar pendek atau ber-tab status (`x-ui.tab-list`). | Beberapa halaman operasional bervolume tinggi (mis. `supplier/po`, `purchasing/claims`, `finance/vendors`) hanya mengandalkan kotak cari bawaan DataTables tanpa filter status/periode yang konsisten. |

### 3.2 Akar Masalah Teknis & UX (Root Causes)

1. **Tidak ada komponen khusus `<x-ui.filter-bar>`:** `<x-ui.toolbar>` saat ini hanya memiliki 3 slot generik (`search`, `filters`, `actions`) berpanjang 27 baris (`resources/views/components/ui/toolbar.blade.php`), tanpa dukungan bawaan untuk *More Filters* toggle, *badge count*, *collapsible tray*, *active filter chips*, maupun *form wrapper*. Akibatnya setiap developer menulis variasi lokal sendiri `[Verified]`.
2. **Kontras tinggi kontrol tidak seragam:** `local-invoices/filters.blade.php` memakai `form-select-sm` di baris atas tapi `tw:min-h-[var(--ui-control-height-md)]` di panel collapse; `payment-vouchers/index.blade.php` memakai `md` di semua kontrol; `purchasing/*` memakai `form-control-sm` / `form-select-sm` (`2.125rem`).
3. **Label & aksesibilitas tidak konsisten:** Sebagian memakai `<label class="visually-hidden">` (`local-invoices`, `finance/local-procurement`, `finance/drp/paid`), sebagian memakai label terlihat di atas input (`admin/auth-audit-logs`, `payment-vouchers`, `supplier/requests`), sebagian lagi hanya `aria-label` pada `<select>` (`purchasing/po`, `purchasing/shipments`).
4. **Duplikasi tombol Reset & inkonsistensi status aktif:** Di `local-invoices/filters.blade.php`, tombol Reset muncul **dua kali** (di toolbar utama L92 dan di footer panel collapse L231). Di `purchasing/po` dan `purchasing/pr`, Reset selalu tampil namun berubah warna merah via CSS lokal `.po-filter-reset--active`. Di `supplier/requests`, Reset berupa `icon-button` tanpa teks.
5. **Pencampuran grid Bootstrap vs Tailwind `tw:`:** `supplier/requests` dan `supplier/quotations/period` masih memakai `row g-2` + `col-md-4 col-lg-3`; `purchasing/quotations` dan `purchasing/outstanding-materials` memakai `d-flex flex-wrap gap-2`; sementara halaman baru memakai `tw:grid` / `tw:flex`.
6. **Ketidakkonsistenan interaksi submit:** `local-supplier/purchase-orders/index.blade.php` dan `supplier/goods-locations/index.blade.php` memakai *auto-submit* pada perubahan dropdown, sedangkan halaman `GET` lain menunggu klik tombol *Apply/Filter* tanpa isyarat visual yang membedakannya.

---

## 4. Inventaris Lengkap Menu Lintas Role & Klasifikasi Target

Seluruh menu dari `resources/views/partials/sidebar.blade.php` dipetakan ke target pola desain berikut:

- **Tier 1 (`Standard Compact`):** `<x-ui.filter-bar>` satu baris (Search + 1–3 filter utama + Actions/Reset + Active Chips opsional).
- **Tier 2 (`Standard Progressive`):** `<x-ui.filter-bar>` dengan baris utama (Search + 1–2 filter utama + tombol **More Filters** ber-badge) + panel collapse terstandar + **Active Filter Chips Strip**.
- **Embedded (`Embedded Queue`):** `<x-ui.filter-bar variant="embedded">` di dalam header `<x-ui.data-table>` untuk sub-tab antrean ringan (1–2 filter).
- **Exception (`Pengecualian Resmi`):** Dipertahankan terpisah secara sengaja karena kebutuhan domain analitik/ekspor berbeda.

### 4.1 Modul Shared & Lintas Role

| Menu / Layar | File Blade (`resources/views/...`) | Pola Saat Ini | Mekanisme | Target Klasifikasi | Catatan Migrasi |
|---|---|---|---|---|---|
| Local Invoices Filter (dipakai 5 menu: Finance Invoices, Finance Master Invoices, Accounting Invoices, Accounting Reports, Local Supplier Invoices) | `local-invoices/filters.blade.php` | Pola A | Server `GET` & `AdasiServerTabs` | **Tier 2 (`Standard Progressive`)** | Prioritas tertinggi; jadikan referensi utama migrasi ke `<x-ui.filter-bar>` + hapus duplikasi tombol Reset ganda, tambah Active Filter Chips. |
| Payment Vouchers (Finance & Purchasing) | `payment-vouchers/index.blade.php` | Pola D | Server `GET` | **Tier 2 (`Standard Progressive`)** | Pindahkan `Search` + `Status` + `Department` ke baris utama; `Payment Method`, `Payee Type`, `Date Range` ke panel *More Filters* + Active Chips. |
| Supplier Registrations (Admin, Finance, Purchasing) | `supplier-registrations/_attempts_content.blade.php` | Pola F | `AdasiServerTabs` | **Embedded (`variant="embedded"`)** | Pertahankan ringkas di atas/header tabel tab; seragamkan tinggi input `sm` dan tombol Reset. |
| Export History (Semua role terkait) | `exports/index.blade.php` | Pola G | Server list | **Exception (Minimal)** | Daftar riwayat ekspor personal pendek; tidak butuh filter panel berat. |
| Notifications | `notifications/index.blade.php` | Pola G | Server list | **Exception (Minimal)** | Cukup tab/aksi *mark as read*; bukan tabel analitik/operasional. |

### 4.2 Role: Purchasing

| Menu Sidebar | File Blade | Pola Saat Ini | Mekanisme | Target Klasifikasi | Catatan Migrasi |
|---|---|---|---|---|---|
| Outstanding Materials | `purchasing/outstanding-materials/index.blade.php` | Pola C | Server `GET` | **Tier 1 (`Standard Compact`)** | Ganti `d-flex` Bootstrap ke `<x-ui.filter-bar :sticky="true">`, pertahankan Search + 2 select + Reset. |
| Period Management | `purchasing/periods/index.blade.php` | Pola G | DataTables | **Exception (Minimal / DT)** | Daftar periode sedikit; DataTables search bawaan sudah memadai. |
| Requisition (PR) | `purchasing/pr/index.blade.php` | Pola B | DataTables | **Tier 1 (`Standard Compact`)** | Migrasi ke `<x-ui.filter-bar mode="live" :sticky="true">` + `<x-ui.filter-chips>` & `filter-bar.js`; hapus inline `<style>`. |
| Import Suppliers | `purchasing/import-suppliers/_queue_content.blade.php` | Pola F | `AdasiServerTabs` | **Embedded (`variant="embedded"`)** | Sudah sangat dekat dengan standar; ganti ke `<x-ui.filter-bar variant="embedded">`. |
| Quotation | `purchasing/quotations/index.blade.php` | Pola C | Server `GET` | **Tier 2 (`Standard Progressive`)** | 5 kontrol (Search, Period, Supplier, Status, Date Range) → pisahkan Search + Period + Status di baris utama, Supplier + Date Range di *More Filters*. |
| Price Comparison — Inter-Supplier | `purchasing/comparison/_inter_supplier_content.blade.php` | Pola C (Custom) | `AdasiServerTabs` | **Exception (Analytical Query Bar)** | Wajib pilih PR (typeahead) + Benchmark Month/Year header; bukan filter daftar biasa. Selaraskan token surface/height saja. |
| Price Comparison — Vs Best | `purchasing/comparison/_vs_best_content.blade.php` | Pola C | `AdasiServerTabs` | **Exception (Analytical Query Bar)** | Selaraskan token & kontrol ke `<x-ui.filter-bar>` ringan namun tetap pertahankan semantik *Compare*. |
| Price Comparison — Historical | `purchasing/comparison/_historical_content.blade.php` | Pola C (Custom) | `AdasiServerTabs` | **Exception (Analytical Query Bar)** | Memiliki dependent selector `Supplier → Material → Shape → Key Dimension → Aggregation (Monthly/Yearly)`. Pertahankan layout analitik khusus; seragamkan tinggi/border kontrol. |
| Purchase Order (PO) | `purchasing/po/index.blade.php` | Pola B | DataTables | **Tier 1 (`Standard Compact`)** | Migrasi ke `<x-ui.filter-bar mode="live" :sticky="true">` + `filter-bar.js`; pertahankan ID `#filter_po_number`, `#filter_status`, `#filter_supplier` (terikat `x-export.advanced-modal`). |
| Consolidate Awards (sub-halaman PO) | `purchasing/po/consolidate-awards.blade.php` | Pola C (Raw) | Server `GET` | **Tier 1 (`Standard Compact`)** | Bungkus `<form>` dengan `<x-ui.filter-bar>` standar. |
| Shipment & Logistics | `purchasing/shipments/index.blade.php` | Pola B | DataTables | **Tier 2 (`Standard Progressive`)** | Search + Status + Supplier di baris utama; Date Range di *More Filters* (atau tetap Tier 1 jika layar lebar); hapus inline `<style>` & gunakan `filter-bar.js`. |
| Local Vendors | `purchasing/local-vendors/index.blade.php` | Pola G | Server list | **Tier 1 (Opsional/Ringan)** | Saat ini tanpa filter bar; jika pencarian ditambah di masa depan, wajib memakai Tier 1. |
| Supplier Audit | `purchasing/supplier-audits/_queue_content.blade.php` | Pola F | `AdasiServerTabs` | **Embedded (`variant="embedded"`)** | Seragamkan ke `<x-ui.filter-bar variant="embedded">` dengan `x-ui.select`. |
| Local PO / GR | `finance/local-procurement/index.blade.php` (shared) | Pola C | Server `GET` | **Tier 2 (`Standard Progressive`)** | Lihat bagian Finance. |
| DRP Supplier & DRP GA (Purchasing) | `purchasing/drp/{supplier,ga}.blade.php` | Pola G | Server list | **Exception (Minimal)** | Tampilan read-only antrean DRP; tidak memerlukan filter bar berat. |
| DRP Paid (Purchasing) | `purchasing/drp/paid.blade.php` | Pola C | `AdasiServerTabs` | **Tier 2 (`Standard Progressive`)** | Samakan 100% dengan `finance/drp/paid.blade.php` (Search + Type di baris utama; Overpayment Status + Date Range di *More Filters*). |
| Materials & HS Code | `admin/material-hs-code/index.blade.php` (shared) | Pola A | DataTables | **Tier 2 (`Standard Progressive`)** | Lihat bagian Admin. |
| Reference Prices | `purchasing/reference-prices/_content.blade.php` | Pola C | `AdasiServerTabs` | **Tier 1 (`Standard Compact`)** | Setiap tab (Historical Benchmark & Legacy) memakai `<x-ui.filter-bar>` seragam. |
| Exchange Rates | `admin/exchange-rates/{_content,_annual_matrix}.blade.php` | Pola C / F | `AdasiServerTabs` | **Tier 1 / Embedded** | Lihat bagian Admin. |
| Chat / Conversations | `purchasing/conversations/index.blade.php` | Pola G | Server list | **Exception (Minimal)** | Daftar thread chat; bukan tabel filter multi-kriteria. |
| Material Claim | `purchasing/claims/index.blade.php` | Pola G (Tab-list + DT) | DataTables | **Exception (Tab-List + DT)** | Sudah menggunakan `<x-ui.tab-list>` sebagai filter status utama di atas `x-ui.data-table` (dijaga `ClaimAndInspectionListLayoutTest`). |
| Report | `purchasing/reports/index.blade.php` | Pola E (Cards) | Async Export `GET` | **Exception (Export Parameter Form)** | Bukan filter daftar tabel; halaman ini adalah 2 kartu generator Excel asinkron (`data-async-export`). Tetap `<x-ui.card>`. |

### 4.3 Role: Finance, General Affairs (GA), dan Accounting

| Role & Menu | File Blade | Pola Saat Ini | Mekanisme | Target Klasifikasi | Catatan Migrasi |
|---|---|---|---|---|---|
| Finance — Invoice Register & Master Invoices | `local-invoices/filters.blade.php` | Pola A | Server `GET` / `AdasiServerTabs` | **Tier 2 (`Standard Progressive`)** | Otomatis terstandarisasi lewat partial bersama. |
| Finance — GA Claims | `finance/ga-claims/index.blade.php` | Pola E (`x-ui.card`) | Server `GET` | **Tier 1 (`Standard Compact`)** | Keluarkan form dari `<x-ui.card>` ke `<x-ui.filter-bar>`. |
| Finance — DRP Supplier | `finance/drp/supplier.blade.php` | Pola E (`x-ui.card`) | Server `GET` | **Tier 2 (`Standard Progressive`)** | Pindahkan filter kandidat dari `<x-ui.card>` ke `<x-ui.filter-bar>` (Supplier + Search di baris utama; 2 Date Range di *More Filters*). |
| Finance — DRP GA | `finance/drp/ga.blade.php` | Pola E (`x-ui.card`) | Server `GET` | **Tier 1 (`Standard Compact`)** | Keluarkan dari `<x-ui.card>` ke `<x-ui.filter-bar>`. |
| Finance — DRP Paid | `finance/drp/paid.blade.php` | Pola C | `AdasiServerTabs` | **Tier 2 (`Standard Progressive`)** | Search + Type di baris utama; Overpayment Status + Date Range di *More Filters* + Active Chips. |
| Finance — Supplier Overpayment | `finance/overpayments/index.blade.php` | Pola E (`x-ui.card`) | Server `GET` | **Tier 2 (`Standard Progressive`)** | Keluarkan dari `<x-ui.card>` ke `<x-ui.filter-bar>` (Search + Status di baris utama; Supplier + Date Range di *More Filters*). |
| Finance — Master PO / GR | `finance/local-procurement/index.blade.php` | Pola C | Server `GET` | **Tier 2 (`Standard Progressive`)** | Search + Status di baris utama; Supplier + Date Range di *More Filters* + Active Chips. |
| Finance — Master Vendor | `finance/vendors/index.blade.php` | Pola G | Server list | **Tier 1 (Opsional/Ringan)** | Belum memiliki form filter; gunakan Tier 1 jika pencarian ditambahkan. |
| GA — GA Claims | `ga/claims/index.blade.php` | Pola G | Server list | **Exception (Minimal)** | Antrean klaim per user GA; belum memerlukan panel filter kompleks. |
| GA — Employee Master | `ga/employees/index.blade.php` | Pola E (`x-ui.card`) | Server `GET` | **Tier 1 (`Standard Compact`)** | Keluarkan dari `<x-ui.card>` ke `<x-ui.filter-bar>`. |
| Accounting — Invoice Register & Reports | `local-invoices/filters.blade.php` | Pola A | Server `GET` | **Tier 2 (`Standard Progressive`)** | Otomatis terstandarisasi lewat partial bersama. |

### 4.4 Role: Supplier (Import & Local) dan QC

| Role & Menu | File Blade | Pola Saat Ini | Mekanisme | Target Klasifikasi | Catatan Migrasi |
|---|---|---|---|---|---|
| Supplier Import — Outstanding Materials | `supplier/outstanding-materials/index.blade.php` | Pola C | Server `GET` | **Tier 1 (`Standard Compact`)** | Migrasi ke `<x-ui.filter-bar :sticky="true">`. |
| Supplier Import — Quotation Period | `supplier/quotations/index.blade.php` | Pola G | Server list | **Exception (Minimal)** | Daftar periode aktif pendek. |
| Supplier Import — Quotation Period Detail | `supplier/quotations/period.blade.php` | Pola F | DataTables | **Tier 1 (`Standard Compact`)** | Pindahkan dari `<x-slot:filters>` (`row g-2`) ke `<x-ui.filter-bar mode="live">` atau `variant="embedded"` seragam. |
| Supplier Import — Purchasing Requests | `supplier/requests/index.blade.php` | Pola F | Server `GET` | **Tier 1 (`Standard Compact`)** | Pindahkan dari `<x-slot:filters>` (`row g-2`) ke `<x-ui.filter-bar>` di atas tabel (hilangkan `icon-button` reset yang beda sendiri). |
| Supplier Import — Purchase Orders | `supplier/po/index.blade.php` | Pola G | DataTables | **Exception (Minimal / DT)** | DataTables built-in search; pertahankan kecuali ditambah filter status. |
| Supplier Import — Shipments | `supplier/shipments/index.blade.php` | Pola B | DataTables | **Tier 1 (`Standard Compact`)** | Migrasi ke `<x-ui.filter-bar mode="live" :sticky="true">` + `filter-bar.js`; hapus inline `<style>`. |
| Supplier Import — Goods Locations | `supplier/goods-locations/index.blade.php` | Pola F (Custom) | Server `GET` (auto-submit) | **Embedded (`variant="embedded"`)** | Seragamkan kontrol & tombol Reset ke `<x-ui.filter-bar variant="embedded">`. |
| Supplier Import — Material Claims & Price History List | `supplier/claims/index.blade.php`, `supplier/price-history/index.blade.php` | Pola G | DataTables | **Exception (Minimal / DT)** | DataTables standar. |
| Supplier Import — Price History (Historical Analytics) | `supplier/price-history/historical.blade.php` | Pola E + Collapse | Server `GET` | **Exception (Analytical Query Bar)** | Wajib pilih Material + Horizon + Currency + Aggregation + Dimensi teknis (mm). Pertahankan layout analitik khusus; seragamkan token kontrol, border, dan tombol collapse. |
| Supplier Import — Conversations & Announcements | `supplier/conversations/index.blade.php`, `supplier/announcements/index.blade.php` | Pola G | Server list | **Exception (Minimal)** | Bukan tabel operasional berfilter. |
| Local Supplier — Invoice Register | `local-invoices/filters.blade.php` | Pola A | Server `GET` | **Tier 2 (`Standard Progressive`)** | Otomatis terstandarisasi lewat partial bersama. |
| Local Supplier — Purchase Orders | `local-supplier/purchase-orders/index.blade.php` | Pola C (auto-submit) | Server `GET` | **Tier 1 (`Standard Compact`)** | Migrasi ke `<x-ui.filter-bar>` dengan pola submit konsisten (Apply + Reset) dan ganti 2 `date-picker` terpisah ke `x-ui.date-range-picker` compact. |
| Local Supplier — Supplier Audit | `local-supplier/supplier-audits/index.blade.php` | Pola G | Server list | **Exception (Minimal)** | Daftar audit supplier sedikit. |
| QC — QC Inspection | `qc/inspections/index.blade.php` | Pola C (dalam tab History) | DataTables + `x-ui.tab-list` | **Tier 1 (`Standard Compact`)** | Migrasi toolbar tab History ke `<x-ui.filter-bar mode="live">` (pertahankan `x-ui.tab-list` & penjaga `ClaimAndInspectionListLayoutTest`). |

### 4.5 Role: Admin

| Menu Sidebar | File Blade | Pola Saat Ini | Mekanisme | Target Klasifikasi | Catatan Migrasi |
|---|---|---|---|---|---|
| Users | `admin/users/index.blade.php` | Pola A | DataTables | **Tier 2 (`Standard Progressive`)** | Migrasi ke `<x-ui.filter-bar mode="live">` + badge count pada *More Filters* + Active Chips. |
| Auth Audit Logs | `admin/auth-audit-logs/index.blade.php` | Pola A | DataTables | **Tier 2 (`Standard Progressive`)** | Migrasi ke `<x-ui.filter-bar mode="live">` + badge count + Active Chips; pertahankan event `adasi:calendar-reset`. |
| Maintenance | `admin/maintenance/index.blade.php` | Pola C | Server `GET` | **Tier 1 (`Standard Compact`)** | Migrasi ke `<x-ui.filter-bar>` (Search + Type + Status + Module). |
| Announcements | `admin/announcements/index.blade.php` | Pola C | Client JS | **Tier 1 (`Standard Compact`)** | Migrasi ke `<x-ui.filter-bar mode="live">`. |
| Materials & HS Code | `admin/material-hs-code/index.blade.php` | Pola A (2 toolbar per tab) | DataTables | **Tier 2 (`Standard Progressive`)** | Migrasi kedua toolbar (Material Master & HS Code Rules) ke `<x-ui.filter-bar mode="live">` + badge count + Active Chips. |
| Exchange Rates | `admin/exchange-rates/_content.blade.php`, `_annual_matrix.blade.php` | Pola C / F | `AdasiServerTabs` | **Tier 1 / Embedded** | Tab utama memakai Tier 1; Annual Matrix memakai `variant="embedded"`. |

---

## 5. Spesifikasi Desain Target: Unified ADASI Operational Filter System

### 5.1 Anatomi Visual 3 Lapis (`<x-ui.filter-bar>`)

Semua halaman daftar operasional (Tier 1 & Tier 2) menggunakan satu struktur visual yang dapat diprediksi di seluruh portal, sepenuhnya mematuhi token `DESIGN.md` (`--md-*`, `--ui-*`, `--ui-density-*`, `tw:` prefix, tanpa hex mentah, tanpa shadow berlebihan):

1. **Lapis 1 — Primary Filter Row (Selalu Terlihat)**
   - **Permukaan:** `tw:bg-surface-container tw:border tw:border-outline tw:rounded-ui-md`, padding mengikuti `var(--ui-density-toolbar-block)` dan `var(--ui-density-toolbar-inline)`.
   - **Urutan kiri → kanan (Desktop):**
     1. **Search Input** (`tw:flex-1 tw:min-w-[220px] tw:max-w-sm`): input `type="search"` dengan ikon `search` di dalam sisi kiri (`ps-8`) dan tombol clear native/kecil. Tanpa tombol Search terpisah di dalam `input-group` (menghilangkan duplikasi tombol Search + Apply).
     2. **Primary Filters (maks. 2–3 kontrol):** filter frekuensi tertinggi (mis. `Status`, `Period`, `Type`). Di baris utama, gunakan **label aksesibel (`tw:sr-only`) + opsi default deskriptif** (*"Semua Status"*, *"Semua Periode"*) agar tinggi baris tetap 1-garis padat ala Infor LN / SAP Fiori.
     3. **More Filters Toggle (hanya Tier 2):** `<x-ui.button variant="outline" size="sm">` dengan ikon `sliders-horizontal`, teks `__('common.filters.more')`, dan **badge count** (`tw:bg-primary tw:text-primary-foreground tw:rounded-ui-full`) ketika `advancedCount > 0`. Memiliki `aria-expanded` dan `aria-controls`.
     4. **Actions Zone (kanan, `tw:ms-auto`):**
        - **Mode Manual (`GET` / `AdasiServerTabs`):** tombol `<x-ui.button type="submit" variant="primary" size="sm">` (*Apply / Terapkan*) + tombol **Reset** (`variant="ghost" size="sm"`, ikon `rotate-ccw`) yang tampil saat `hasActiveFilters` bernilai benar (atau dalam state subtle-disabled bila kosong pada mode live).
        - **Mode Live (DataTables / Client JS):** tidak perlu tombol *Apply* (filter langsung berjalan dengan debounce 300ms untuk search dan instan untuk select); cukup tombol **Reset** di kanan.
2. **Lapis 2 — Collapsible Advanced Tray (Hanya Tier 2)**
   - **Permukaan:** menyatu secara visual tepat di bawah Lapis 1 (`tw:border-t tw:border-outline-variant tw:bg-surface-container-low tw:rounded-b-ui-md tw:p-4`) sehingga terasa sebagai **satu kesatuan panel** (bukan dua kotak terpisah yang mengambang dengan margin terputus seperti di `admin/auth-audit-logs` saat ini).
   - **Grid responsif:** `tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:lg:grid-cols-3 tw:gap-3.5 tw:items-end`.
   - **Label wajib terlihat:** di dalam tray lanjutan, setiap field **wajib memiliki label terlihat** di atas kontrol (`tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant`, sentence case — **bukan** `tw:uppercase` kecuali heading section) karena field lanjutan (Date Range, Supplier, Refund Status, Overdue toggle) membutuhkan konteks eksplisit.
   - **Footer tray (opsional):** hanya digunakan bila ada *quick toggle chips* (seperti `Overdue` / `Include Paid` di `local-invoices/filters.blade.php`). **Dilarang menduplikasi tombol Reset** di dalam tray bila tombol Reset sudah ada di Lapis 1 & Lapis 3.
3. **Lapis 3 — Active Filter Chips Strip (`<x-ui.filter-chips>`)**
   - Tampil otomatis tepat di bawah panel (hanya ketika minimal 1 filter aktif).
   - Berisi label kecil `__('common.filters.active')` + daftar chip (`tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-primary-container tw:text-on-primary-container tw:px-2 tw:py-0.5 tw:text-ui-xs`) dengan format **`Kategori: Nilai`** + tombol `×` (`aria-label="Hapus filter [Kategori]"`) + tautan/tombol **`Clear all`** di ujung kanan.
   - Mengikuti [PatternFly 6.7](https://www.patternfly.org/patterns/filters/design-guidelines) & [IBM Carbon](https://www.carbondesignsystem.com/building-blocks/core/patterns/filtering): ketika panel *More Filters* ditutup, operator tetap melihat 100% kriteria yang sedang menyaring tabel dan dapat mencabut kriteria satu per satu.

### 5.2 Kontrak Komponen Blade & Helper JS

| Komponen / Aset | Lokasi File | Props / API Utama | Tujuan |
|---|---|---|---|
| `<x-ui.filter-bar>` | `resources/views/components/ui/filter-bar.blade.php` | `action` (nullable untuk mode live), `method="GET"`, `mode="submit\|live"`, `variant="default\|embedded"`, `sticky=false`, `serverTabs=false`, `id`, `ariaLabel`, `advancedId`, `advancedCount=0`, `advancedOpen=false`, `hasActiveFilters=false`, `resetUrl=null`, `resetId=null`, `submitLabel=null` + slots: `hidden`, `search`, `filters`, `advanced`, `advancedFooter`, `chips`, `actions` | Satu komponen kanonik untuk seluruh halaman daftar operasional; tetap backward-compatible dengan `<x-ui.toolbar>` yang tidak langsung dihapus. |
| `<x-ui.filter-chips>` | `resources/views/components/ui/filter-chips.blade.php` | `items` (array `['key', 'label', 'value', 'removeUrl']` untuk SSR), `clearUrl=null`, `id=null` (untuk mode live JS), `serverTabs=false` | Menampilkan chip filter aktif secara konsisten baik untuk halaman SSR (`GET` / `AdasiServerTabs`) maupun DataTables (live JS). |
| `filter-bar.js` | `resources/js/filter-bar.js` (lazy-loaded di `resources/js/app.js` via penanda `[data-adasi-filter-bar]`) | `AdasiFilterBar.bindLive(containerEl, { onFilterChange, onReset, fields })` | Menggantikan 4x duplikasi jQuery `#filterChips` & `.{mod}-filter-reset--active` di `purchasing/{pr,po,shipments}` dan `supplier/shipments`, menangani debounce 300ms, update badge count, render chip aksesibel (`aria-live="polite"`), dan sinkronisasi `adasi:calendar-reset`. |
| CSS Foundation | `resources/css/app.css` | `.ui-filter-bar`, `.ui-filter-bar--embedded`, `.ui-filter-bar--sticky`, `.ui-filter-bar__tray`, `.ui-filter-chips`, `.ui-filter-chip` | Memakai 100% token `--md-*`, `--ui-density-toolbar-*`, `--ui-control-height-sm`, dan adapter `:root[data-theme="dark"]` tanpa satu pun hex mentah. |

### 5.3 Standar Kontrol, Responsif, Dark Mode, & Aksesibilitas

- **Ukuran kontrol seragam:** Seluruh input teks, select, `date-range-picker` (`:compact="true"`), dan tombol di dalam `<x-ui.filter-bar>` memakai ukuran **`sm`** (`var(--ui-control-height-sm)` = `2.125rem` desktop, otomatis melebar ke `2.5rem` pada *coarse pointer* / perangkat sentuh per `DESIGN.md` §9).
- **Dropdown panjang (>10 opsi):** Untuk pilihan Supplier atau Material yang jumlahnya dapat melebihi 10–15 item, gunakan `<x-ui.searchable-select>` (sesuai rekomendasi [PatternFly 6.7](https://www.patternfly.org/patterns/filters/design-guidelines) dan [NN/G](https://www.nngroup.com/articles/filter-categories-values/)).
- **Responsif Mobile (`< 640px` / `< 993px`):**
  - Search input membentang `tw:w-full` di baris paling atas.
  - Kontrol filter utama membungkus rapi ke grid 1–2 kolom tanpa overflow horizontal.
  - Tombol *More Filters* tetap tampil jelas dengan badge count; tray lanjutan menjadi 1 kolom penuh; tombol *Apply* dan *Reset* sejajar penuh lebar di bawah kontrol.
- **Dark Mode & Accent Preset:**
  - Permukaan Lapis 1 memakai `--md-surface-container`, Lapis 2 memakai `--md-surface-container-low`, input memakai `--md-surface` dengan border `--ui-input-border` (`--md-outline-strong`, kontras ≥3:1).
  - Chip aktif memakai `--md-primary-container` / `--md-on-primary-container` sehingga otomatis mengikuti kelima *accent preset* (`brand`, `slate`, `indigo`, `teal`, `violet`).
- **WCAG 2.2 AA:**
  - Landmark `<form role="search" aria-label="...">` (atau `<div role="search">` pada mode live).
  - Setiap kontrol memiliki `<label for="...">` (terlihat di tray, `tw:sr-only` di baris utama).
  - Container chip memakai `aria-live="polite"` dan `aria-atomic="false"` agar screen reader mengumumkan perubahan filter tanpa mengganggu navigasi.
  - `prefers-reduced-motion: reduce` menonaktifkan transisi buka/tutup tray.

### 5.4 Kapan Panel Filter Boleh Berbeda? (Kebijakan Pengecualian Resmi)

Sebuah halaman **hanya boleh** menyimpang dari `<x-ui.filter-bar>` jika memenuhi salah satu dari 3 kriteria objektif berikut:

1. **Analytical Query Bar (Filter sebagai Parameter Kalkulasi Wajib):** Halaman yang tidak menampilkan daftar sebelum user memilih entitas analitik utama + parameter dimensi/agregasi (`purchasing/comparison/{inter-supplier,historical,vs-best}`, `supplier/price-history/historical`). Tetap wajib memakai token warna, radius, border, dan ukuran kontrol `sm` yang sama.
2. **Async Export Parameter Form:** Halaman yang aksinya men-generate berkas unduhan asinkron (`purchasing/reports/index.blade.php`), bukan menyaring tabel di layar. Tetap menggunakan `<x-ui.card>`.
3. **Minimal / Tab-Driven / Zero-Filter Lists:** Halaman dengan volume data sangat rendah atau sudah tersaring utuh oleh `<x-ui.tab-list>` / DataTables search (`purchasing/claims`, `purchasing/periods`, `exports/index`, `notifications/index`, `conversations/index`, `supplier/announcements`, `local-supplier/supplier-audits`).

---

## 6. Rencana Implementasi Bertahap (Phased Execution Plan)

Prinsip eksekusi (`@boost`): **perubahan minimal yang koheren per fase**, mempertahankan 100% route, parameter query string, otorisasi, selector JS/DataTables, dan `x-export.advanced-modal` filter selectors.

### Fase 0 — Fondasi Komponen, CSS, JS, & i18n
- **File baru:**
  - `resources/views/components/ui/filter-bar.blade.php`
  - `resources/views/components/ui/filter-chips.blade.php`
  - `resources/js/filter-bar.js`
  - `tests/Feature/FilterBarComponentTest.php`
  - `tests/js/filter-bar.test.mjs`
- **File dimodifikasi:**
  - `resources/css/app.css` (tambah kelas `.ui-filter-bar*`, `.ui-filter-chip*` berbasis `--md-*` & `--ui-density-toolbar-*`, termasuk blok `:root[data-theme="dark"]`).
  - `resources/js/app.js` (registrasi lazy-import `filter-bar.js` saat `[data-adasi-filter-bar]` ada di DOM; perbarui `tests/js/app-lazy-modules.test.mjs`).
  - `lang/en/common.php` & `lang/id/common.php` (tambah key pasangan `common.filters.more`, `common.filters.active`, `common.filters.clear_all`, `common.filters.remove_filter`, `common.filters.apply`, `common.filters.reset`).
- **Kriteria selesai Fase 0:** Semua unit/feature test komponen baru + `TranslationParityTest` + `density-contract.test.mjs` + `text-contrast.test.mjs` + `tailwind-build.test.mjs` lolos.

### Fase 1 — Shared Local Invoices & Seluruh Modul Finance / GA / Local Supplier
- **Dampak terbesar lebih dulu:**
  1. `resources/views/local-invoices/filters.blade.php` → langsung menyelaraskan **5 menu** sekaligus (`finance.invoices`, `finance.master-invoices`, `accounting.invoices`, `accounting.reports`, `local-supplier.invoices`), mempertahankan `data-server-tabs-form` & `data-server-tabs-reset`, menghilangkan tombol Reset ganda, dan menambah `<x-ui.filter-chips>` SSR.
  2. `resources/views/payment-vouchers/index.blade.php` → migrasi dari Pola D (form 12-kolom selalu terbuka) ke Tier 2 `<x-ui.filter-bar>`.
  3. `resources/views/finance/local-procurement/index.blade.php`, `resources/views/finance/drp/paid.blade.php`, `resources/views/purchasing/drp/paid.blade.php` → migrasi ke Tier 2 `<x-ui.filter-bar>` + Active Chips (tetap pertahankan `data-server-tabs-form` pada DRP Paid).
  4. `resources/views/finance/overpayments/index.blade.php`, `resources/views/finance/drp/supplier.blade.php`, `resources/views/finance/drp/ga.blade.php`, `resources/views/finance/ga-claims/index.blade.php`, `resources/views/ga/employees/index.blade.php` → keluarkan filter dari `<x-ui.card>` ke `<x-ui.filter-bar>` (Tier 1 / Tier 2).
  5. `resources/views/local-supplier/purchase-orders/index.blade.php` → migrasi ke Tier 1 `<x-ui.filter-bar>` dan rapikan input tanggal ke `x-ui.date-range-picker`.

### Fase 2 — Purchasing & Supplier Import (DataTables & Server Lists) + QC
- **Standarisasi mode `live` (menghapus duplikasi `<style>` & jQuery chips):**
  1. `resources/views/purchasing/pr/index.blade.php`
  2. `resources/views/purchasing/po/index.blade.php` (pertahankan exact ID `#filter_po_number`, `#filter_status`, `#filter_supplier` karena terikat `x-export.advanced-modal` dan MMT workspace)
  3. `resources/views/purchasing/shipments/index.blade.php` (pertahankan `adasi:calendar-reset` pada `#shipmentDateRange`)
  4. `resources/views/supplier/shipments/index.blade.php` (pertahankan `#supplierShipmentDateRange`)
  5. `resources/views/qc/inspections/index.blade.php` (pertahankan `<x-ui.tab-list>` & lulus `ClaimAndInspectionListLayoutTest`)
- **Standarisasi mode `submit` (Server `GET`):**
  6. `resources/views/purchasing/quotations/index.blade.php` (Tier 2)
  7. `resources/views/purchasing/outstanding-materials/index.blade.php` & `resources/views/supplier/outstanding-materials/index.blade.php` (Tier 1)
  8. `resources/views/purchasing/po/consolidate-awards.blade.php` (Tier 1)
  9. `resources/views/supplier/requests/index.blade.php` & `resources/views/supplier/quotations/period.blade.php` (pindah dari `<x-slot:filters>` Bootstrap `row g-2` ke `<x-ui.filter-bar>`).

### Fase 3 — Admin & Master Data
- **Migrasi Pola A & C ke `<x-ui.filter-bar>`:**
  1. `resources/views/admin/users/index.blade.php` (Tier 2 `mode="live"`, tambah badge count + active chips)
  2. `resources/views/admin/auth-audit-logs/index.blade.php` (Tier 2 `mode="live"`, tambah badge count + active chips, pertahankan `#auditDateRange` reset)
  3. `resources/views/admin/material-hs-code/index.blade.php` (Tier 2 `mode="live"` untuk kedua tab)
  4. `resources/views/admin/maintenance/index.blade.php` & `resources/views/admin/announcements/index.blade.php` (Tier 1)
  5. `resources/views/admin/exchange-rates/_content.blade.php` (Tier 1) & `_annual_matrix.blade.php` (`variant="embedded"`)
  6. `resources/views/purchasing/reference-prices/_content.blade.php` (Tier 1 di kedua tab).

### Fase 4 — Embedded Queues, Penyelarasan Token Pengecualian, & Dokumentasi
- **Embedded queues:** `purchasing/import-suppliers/_queue_content.blade.php`, `purchasing/supplier-audits/_queue_content.blade.php`, `supplier-registrations/_attempts_content.blade.php`, `supplier/goods-locations/index.blade.php` → migrasi ke `<x-ui.filter-bar variant="embedded">`.
- **Token polish pada Analytical Query Bars (tanpa mengubah layout analitik):** rapikan label uppercase yang menyimpang dan kelas padding/border di `supplier/price-history/historical.blade.php` dan `purchasing/comparison/_{inter_supplier,historical,vs_best}_content.blade.php`.
- **Dokumentasi & Governance:** Perbarui `DESIGN.md` (§5 tabel komponen `x-ui.*` & §6.2 Halaman Daftar) serta `resources/views/components/ui/CLAUDE.md` agar seluruh pekerjaan baru wajib memakai `<x-ui.filter-bar>`.

---

## 7. Risiko, Invarian Kritis, & Rencana Verifikasi

### 7.1 Invarian yang Wajib Dijaga (Zero Regression)
1. **DOM Selector & Export Binding:** Jangan mengubah `id` input yang direferensikan oleh `x-export.advanced-modal` (`filter-selectors`), DataTables AJAX payload (`#filter_po_number`, `#filter_status`, `#filter_supplier`, `#auditEmail`, `#materialStatusFilter`, dll.), atau `AdasiServerTabs` (`data-server-tabs-form`, `data-server-tabs-reset`, `data-server-tabs-content`).
2. **Event Kalender:** Reset filter wajib tetap men-dispatch `new CustomEvent('adasi:calendar-reset')` pada elemen `<x-ui.date-range-picker>` dan `<x-ui.date-picker>`.
3. **Kontrak `TranslationParityTest` & `DESIGN.md`:** Setiap string baru wajib hadir di `lang/en/` dan `lang/id/`; nol hex mentah; nol `bi bi-*`; nol `<input type="date">` native; semua utilitas Tailwind wajib berprefiks `tw:`.
4. **WorkspaceShell Embed:** Mode `:sticky="true"` pada `<x-ui.filter-bar>` harus tetap memperhitungkan `top: var(--topbar-height)` dan tidak menutupi fokus keyboard (WCAG 2.2 `2.4.11`).

### 7.2 Perintah Verifikasi Otomatis (Setiap Selesai 1 Fase)

```powershell
# 1. Build aset Vite & kompilasi cache Blade
npm.cmd run build
php artisan view:cache

# 2. JS contract tests (file eksplisit di Windows)
node --test tests/js/filter-bar.test.mjs tests/js/density-contract.test.mjs tests/js/text-contrast.test.mjs tests/js/tailwind-build.test.mjs tests/js/app-lazy-modules.test.mjs tests/js/server-tabs.test.mjs tests/js/calendar.test.mjs

# 3. Feature tests terkait UI, i18n, dan modul terdampak
php artisan test --filter="FilterBarComponentTest|RenderedComponentTest|TranslationParityTest|TranslationContentTest|ClaimAndInspectionListLayoutTest|ServerTabsListUrlTest|ShipmentUiAndExportTest|AdvancedExportUiTest|SupplierAuditServerTabsTest|SupplierRegistrationServerTabsTest|PriceComparisonServerTabsTest"

# 4. Formatting & diff hygiene
vendor/bin/pint --test app/ resources/
git diff --check
```

### 7.3 Checklist QA Manual Lintas Kondisi
- [ ] **Theme:** Light Mode & Dark Mode (`:root[data-theme="dark"]`) + verifikasi kontras chip aktif pada 5 *accent preset* (`brand`, `slate`, `indigo`, `teal`, `violet`) dan `data-shell="slate"`.
- [ ] **Density:** Uji ke-5 tier (`spacious`, `comfortable`, `balanced`, `compact`, `dense`) — pastikan tinggi kontrol tidak pecah dan gap toolbar menyesuaikan token `--ui-density-toolbar-*`.
- [ ] **Viewport:** Desktop (`1440px`), Tablet (`992px` / `768px`), Mobile (`375px`) — tidak ada overflow horizontal, tombol *More Filters* & *Reset* selalu terjangkau.
- [ ] **Interaksi & Keyboard:** Navigasi `Tab`/`Shift+Tab`, tekan `Enter` pada search, buka/tutup *More Filters* dengan `Enter`/`Space`, hapus chip satu per satu, dan klik *Reset* di dalam iframe `WorkspaceShell` tanpa reload penuh yang rusak.
