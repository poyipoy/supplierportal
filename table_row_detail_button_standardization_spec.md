# Spesifikasi Arsitektur & Standardisasi Tombol Detail Baris Tabel (v2 — Deep Spec) — ADASI Supplier Portal

*Tanggal: 20 November 2026 | Cakupan: Ketat pada Row Detail / Row Action Button di Seluruh Tabel (Blade `<td>`, `<x-ui.mobile-card>`, & 16 PHP DataTables Controllers) | Mode: Evidence-First & Verification-Gated*

---

## 1. Tujuan & Batas Ruang Lingkup (Strict Scope Control)

Menjadikan seluruh tombol aksi tingkat baris tabel — khususnya navigasi **Detail** — identik secara visual, semantik, aksesibel, dan aman di seluruh 7 role, **tanpa** menyentuh tombol page header, filter bar, modal footer, atau toolbar non-tabel.

### Invarian Non-Negosiabel (Yang Harus Selalu Benar)
1. **Parity Blade ↔ PHP DataTables ↔ Mobile Card**: Ketiga permukaan rendering memancarkan kelas CSS yang sama (`.ui-data-action.ui-data-action--primary.ui-focus-ring`) dan mengikuti satu sumber kebenaran di `resources/css/app.css`.
2. **Tepat 1 Primary Action per Baris**: Melestarikan dan memperluas kontrak eksisting `tests/Feature/PrItemRemarkTest.php:140-141` (`substr_count($action, 'ui-data-action--primary') === 1`). Aksi sekunder masuk ke dropdown `.ui-data-action.dropdown-toggle` (`More` / `Lainnya`).
3. **Zero Eye-Icon & Zero Entity Duplication**: Tombol `Details` / `Detail` selalu teks murni tanpa `<x-ui.icon name="eye">` dan tanpa mengulang nama entitas (*Invoice Details*, *Batch Details*, *View Details*).
4. **WCAG 2.2 AA & 2.5.3 di 10 Kombinasi Tema**: Kontras teks ≥ 4,5:1 dan border ≥ 3,0:1 di atas *resting row* (`--md-surface`) maupun *hover row* (`--md-surface-container`) pada **5 aksen (`brand`, `slate`, `indigo`, `teal`, `violet`) × 2 mode (`light`, `dark`)**, serta setiap tombol `Details` wajib punya `aria-label` yang diawali label terlihat (`Details for :name` / `Detail untuk :name`) ([WCAG 2.2 SC 2.5.3](https://www.w3.org/WAI/WCAG22/Understanding/label-in-name.html)).
5. **XSS-Safe pada DataTables `rawColumns`**: Helper PHP wajib meng-escape seluruh atribut dan memblokir skema URL berbahaya (`javascript:`, `data:`) karena 16 controller melewati auto-escape Blade via `->rawColumns(['action'])`.

---

## 2. Bahan Mentah (Raw Source of Truth) yang Wajib Dibaca Sebelum Edit

Agen eksekutor **wajib** membaca bahan mentah berikut secara langsung — bukan mengandalkan asumsi:

| Bahan Mentah | Lokasi Persis | Fakta Teknis Kunci (`[Verified]`) |
|---|---|---|
| **Token Aksen, Tema & Cascade Layer** | `resources/css/app.css` L98, L197, L328, L420–560, L2437–2488, L4149, L5699 | `--ui-primary-text` sudah didefinisikan untuk 5 aksen × 2 mode (`#1F5FA6`/`#A8C9F0`, `#475569`/`#AEBBD0`, `#4338CA`/`#A5B4FC`, `#0F766E`/`#5EEAD4`, `#6D28D9`/`#C4B5FD`). `@import "tailwindcss/utilities.css"` ada di L4149 dengan **preflight OFF** — sehingga utility `tw:border` pada `<a>` tanpa `border-style: solid` eksplisit menghilangkan border. L5699 sudah menegakkan `min-height: 44px` pada coarse pointer untuk `:is(.ui-button, .ui-data-action)`. |
| **Dual Surface & Bug Aktual** | `resources/views/local-invoices/table.blade.php` L106–135 & L239–315 | L106 mencampur status chip + tombol detail dalam 1 `<td>`; L130 memiliki typo kelas `tw-shadow-xs` (tanpa `:`); L305 merender tombol `Detail` kedua di `<x-ui.mobile-card>` `<x-slot:actions>` dengan ikon `eye`. |
| **Kontrak DataTables Referensi** | `app/Http/Controllers/Purchasing/PurchaseRequisitionController.php` L126–154 | Sudah menerapkan 1 `.ui-data-action--primary` + dropdown `More` + `aria-label` kontekstual per `$pr->pr_number`, dirender lewat `->rawColumns(['status_badge', 'action'])`. |
| **Kontrak Kontras & Test Eksisting** | `tests/js/text-contrast.test.mjs` L1–80, `tests/Feature/PrItemRemarkTest.php` L140–141, `tests/Feature/RenderedComponentTest.php` | `text-contrast.test.mjs` memakai kalkulator luminansi WCAG 2.2 langsung dari token `app.css`; `PrItemRemarkTest` mengunci `.ui-data-action--primary` tepat 1 per baris. |
| **Kamus Terjemahan Mentah** | `lang/en/*.php` & `lang/id/*.php` | Header terpecah `Action` (35×) vs `Actions` (31×) / `Tindakan` vs `Aksi`; label terpecah 10+ variasi. Perubahan key wajib lulus `TranslationParityTest`. |

---

## 3. Desain Teknis Kanonis

### 3.1 CSS Tunggal — Akar Masalah Border & Row Hover Diselesaikan di Sumbernya

Perbarui blok `.ui-data-action` di `resources/css/app.css` (L2437–2488, **sebelum** `@import "tailwindcss/utilities.css"` di L4149, dan hapus override duplikat `:root[data-theme="dark"] .ui-data-action--primary` di L669–676 yang tidak lagi diperlukan bila langsung mengikat `--ui-primary-text`):

```css
.ui-data-action {
    align-items: center;
    background-color: var(--md-surface);
    border: 1px solid var(--md-outline-strong); /* >= 3:1 WCAG 1.4.11 pada light & dark */
    border-radius: var(--md-shape-sm);
    color: var(--md-on-surface);
    display: inline-flex;
    font-size: var(--ui-font-size-xs);
    font-weight: 600;
    gap: var(--ui-space-1);
    justify-content: center;
    line-height: 1.25;
    min-height: var(--ui-control-height-sm);
    padding: var(--ui-space-1) var(--ui-space-3);
    text-decoration: none;
    transition: background-color var(--ui-motion-fast) var(--ui-easing-standard),
                border-color var(--ui-motion-fast) var(--ui-easing-standard),
                color var(--ui-motion-fast) var(--ui-easing-standard);
    white-space: nowrap;
}

.ui-data-action:hover {
    background-color: var(--md-surface-container);
    color: var(--md-on-surface);
}

.ui-data-action--primary {
    background-color: var(--md-surface); /* tetap kontras tajam saat tr:hover aktif */
    border-color: var(--ui-primary-text); /* otomatis adaptif di 5 aksen x 2 mode */
    color: var(--ui-primary-text);
}

.ui-data-action--primary:hover {
    background-color: var(--md-primary-container);
    border-color: var(--ui-primary-text);
    color: var(--md-on-primary-container);
}

.ui-data-action--primary:active {
    background-color: var(--md-primary-container);
    filter: brightness(0.96);
}
```

**Alasan desain (`[Verified]`):**
- `border: 1px solid` eksplisit meniadakan ketergantungan pada Tailwind preflight (mengatasi border hilang pada tag `<a>`).
- `background-color: var(--md-surface)` menjaga kontras teks aksen tetap maksimal saat baris tabel sedang di-hover (`tr:hover`), bukan turun di atas latar abu-abu hover.
- Mengikat `border-color` & `color` ke `var(--ui-primary-text)` menyatukan light & dark mode pada 5 tema aksen tanpa duplikasi selektor.

### 3.2 Dua Adapter Tipis, Satu Kontrak HTML

1. **Blade Component** — `resources/views/components/ui/row-action.blade.php`
   - Props: `href = null`, `tone = 'primary'` (`primary|neutral|danger|warning|success`), `label = null` (default `__('common.actions.details')`), `context = null`, `icon = null` (default `null`; **dilarang** untuk `Details`), `type = 'button'`.
   - Otomatis menghasilkan `aria-label` bila `$context` diberikan dan `aria-label` belum di-pass manual: `__('common.actions.details_for', ['name' => $context])` jika memakai label default, atau `"$resolvedLabel — $context"` jika memakai kata kerja kustom — selalu diawali teks label terlihat (patuh [WCAG 2.2 SC 2.5.3](https://www.w3.org/WAI/WCAG22/Understanding/label-in-name.html)).
   - Meneruskan seluruh `$attributes` (`data-smart-panel-url`, `target`, `rel`, `class` seperti `tw:w-full tw:min-h-11` untuk mobile card) secara transparan agar kompatibel dengan `WorkspaceShell` dan `AdasiServerTabs`.
2. **PHP DataTables Helper** — `app/Support/Ui/RowAction.php`
   - `RowAction::detail(string $url, ?string $context = null, array $attributes = []): string`
   - `RowAction::link(string $url, string $label, string $tone = 'primary', ?string $context = null, array $attributes = []): string`
   - Validasi skema URL: hanya izinkan URL relatif (`/`) atau `http://` / `https://`; tolak skema berbahaya (`javascript:`, `data:`) dengan fallback `#`.
   - Escaping ketat `e($value)` untuk `href`, `aria-label`, nama/nilai atribut, dan isi teks.

### 3.3 Standar Microcopy, Header & Pengecualian Resmi

| Elemen | Standar Kanonis | Dasar Referensi | Pengecualian Diizinkan |
|---|---|---|---|
| **Label tombol detail** | `Details` (EN) / `Detail` (ID) | [Pencil & Paper](https://www.pencilandpaper.io/articles/ux-pattern-analysis-enterprise-data-tables) (tanpa duplikasi entitas) | Aksi workflow aktif: `Submit`, `Review`, `Edit`, `Start Inspection`, `Respond`. |
| **Ikon di tombol detail** | **Tidak ada** (teks murni) | [GitLab Pajamas](https://design.gitlab.com/components/button) & [NN/G](https://www.nngroup.com/articles/data-tables/) | Hanya tombol dokumen/media non-detail (`PDF` dengan `file-text`, `Print` dengan `printer`). |
| **Header kolom aksi** | `Action` (EN) / `Aksi` (ID) via `common.table.action` | [PatternFly Table](https://www.patternfly.org/components/table/design-guidelines) | Tidak ada pengecualian pada tabel data standar. |
| **Posisi & alignment** | Kolom paling kanan, `tw:text-right` (`th` & `td`) | [PatternFly](https://www.patternfly.org/components/table/design-guidelines) & [Pencil & Paper](https://www.pencilandpaper.io/articles/ux-pattern-analysis-enterprise-data-tables) | Pada `<x-ui.mobile-card>` (`< 768px`), pakai `<x-ui.row-action class="tw:w-full tw:min-h-11" />` (tetap tanpa ikon mata). |

---

## 4. Metode & Urutan Eksekusi Deterministik

1. **Tahap 1 — Fondasi CSS, Key Terjemahan & Adapter**:
   - Update `resources/css/app.css` (L669–676 & L2437–2488).
   - Tambah key terjemahan berpasangan di `lang/en/common.php` & `lang/id/common.php`: `actions.details`, `actions.details_for`, `table.action`.
   - Buat `resources/views/components/ui/row-action.blade.php` dan `app/Support/Ui/RowAction.php`.
2. **Tahap 2 — Migrasi Seluruh Surface Blade (Desktop `<td>` + Mobile Card)**:
   - Jalankan pemindaian statis untuk memastikan tidak ada satu pun file terlewat, lalu migrasikan seluruh 21+ view Blade (termasuk 3 lokasi di `local-invoices/table.blade.php` — pisahkan L106 dari kolom status chip dan bersihkan typo `tw-shadow-xs` — `payment-vouchers/index.blade.php` L223 & L296, `purchasing/quotations/index.blade.php` L152, `supplier-audits`, `finance/drp/*`, `purchasing/drp/*`, `finance/local-procurement/*`, `vendors`, `ga/*`, `qc/dashboard`, `shipments`, `requests`, `maintenance`).
   - Migrasikan 5 tombol legacy `.btn-outline-primary` pada tabel detail lampiran (`vendors/show`, `vendor-profile/show`, `po/show`) ke `<x-ui.row-action tone="neutral">` atau `.ui-data-action`.
   - Seragamkan seluruh `<th>` kolom aksi menjadi `{{ __('common.table.action') }}` rata kanan.
3. **Tahap 3 — Migrasi 16 Controller DataTables PHP**:
   - Ganti string HTML `<a class="ui-data-action ui-data-action--primary ...">` untuk tombol detail/view di seluruh controller DataTables menjadi `RowAction::detail($url, $identifier)` sambil mempertahankan `PurchasingNavigation::toRoute(...)` dan dropdown `More`.
4. **Tahap 4 — Pemasangan Guardrail & Eksekusi Oracle Verifikasi**:
   - Tambahkan kontrak matematis 10-tema di `tests/js/text-contrast.test.mjs`.
   - Tambahkan unit/feature test kontrak + XSS + static-audit guardrail di `tests/Feature/RenderedComponentTest.php` (atau test khusus `tests/Feature/Architecture/RowActionContractTest.php`).

---

## 5. Oracle Pembuktian Objektif (Verification Gate — Tanpa Penilaian Subjektif)

Implementasi dinyatakan **benar dan selesai** hanya jika **keempat oracle otomatis berikut lulus dengan exit code `0`**:

1. **Oracle Kontras Matematis 10-Tema (`node --test tests/js/text-contrast.test.mjs`)**:
   - Memverifikasi secara programatis bahwa untuk seluruh 5 aksen × 2 mode:
     - `contrast(--ui-primary-text, --md-surface) >= 4.5` (WCAG 1.4.3 teks & 1.4.11 border).
     - `contrast(--md-outline-strong, --md-surface) >= 3.0` dan `contrast(--md-outline-strong, --md-surface-container) >= 3.0`.
   - Memverifikasi `.ui-data-action` dideklarasikan sebelum `@import "tailwindcss/utilities.css"` dan mengandung `border: 1px solid`.
2. **Oracle Kontrak Komponen, Keamanan XSS & WCAG 2.5.3 (`php artisan test --filter="RenderedComponentTest|RowActionContractTest|PrItemRemarkTest|TranslationParityTest"`)**:
   - `<x-ui.row-action>` & `RowAction::detail()` menghasilkan kelas identik `.ui-data-action ui-data-action--primary ui-focus-ring` dan `0` tag `<svg>` secara default.
   - `aria-label` selalu diawali teks label terlihat (SC 2.5.3).
   - Payload XSS pada `$context` (`" onmouseover="alert(1)`, `<script>`) ter-escape penuh, dan `$url = 'javascript:alert(1)'` ditolak.
   - `PrItemRemarkTest` (tepat 1 `.ui-data-action--primary` per baris) & `TranslationParityTest` (paritas EN/ID) hijau 100%.
3. **Oracle Zero-Drift Static Audit (tertanam di `RowActionContractTest`)**:
   - `0` kemunculan `<x-ui.icon name="eye"` di dalam blok `<td>...</td>` atau `<x-slot:actions>` milik `<x-ui.mobile-card>` pada seluruh `resources/views/**/*.blade.php`.
   - `0` kemunculan typo `tw-shadow-xs` dan `0` kelas `.btn-outline-primary` di dalam `<td>` aksi tabel.
   - `0` pemanggilan `<x-ui.row-action>` tanpa atribut `:context` atau `aria-label`.
4. **Oracle Build & Keamanan Database (`npm run build` / `npm.cmd run build` + `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest`)**:
   - Bundle CSS produksi terkompilasi tanpa error, dan test berjalan strictly pada database `_test`.