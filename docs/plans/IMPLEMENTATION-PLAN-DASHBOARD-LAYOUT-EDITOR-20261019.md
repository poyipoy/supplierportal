# Implementation Plan — Dashboard Layout Editor (Edit di Dashboard + Wireframe di Customization)

> Tanggal: 19 Oktober 2026 · Status: **disetujui untuk dikerjakan, belum diimplementasikan**
>
> Sebelum mulai, agent pelaksana wajib membaca `AGENTS.md` (beserta framework kognitif yang dirujuk di sana) dan mencocokkan rencana ini dengan kode terbaru. **Kode, migrasi, dan tes menang** bila ada perbedaan; laporkan perbedaannya.

## 1. Latar Belakang

Bagian **Dashboard Layout** di `profile/customization` saat ini hanya berupa daftar teks. Setiap baris berisi toggle "Show panel" dan tombol ↑/↓; widget wajib bertanda "Always shown". User tidak bisa membayangkan dampaknya ke dashboard karena label seperti "Charts and exchange-rate benchmark" tidak terhubung secara visual dengan card yang sebenarnya. Akibatnya fitur ini terasa tidak berdampak.

Tujuan perubahan ini adalah membuat kustomisasi terasa langsung dan visual:

- **A — Edit langsung di dashboard:** user menyusun dashboard asli (dengan data asli) dalam mode edit.
- **B — Wireframe mini di Customization:** daftar teks diganti miniatur grid dashboard yang proporsional dan bisa di-drag.

### Keputusan produk (sudah final)

| # | Keputusan |
|---|---|
| 1 | Kombinasi **A + B** |
| 2 | Lebar card memakai **lebar pintar otomatis** (lihat §3). User **tidak** mengatur ukuran; tidak ada preferensi `size` baru |
| 3 | Penyimpanan **eksplisit lewat Save Changes**, tanpa autosave |
| 4 | Tiap user **hanya bisa mengatur dashboard audience miliknya sendiri**. Admin yang membuka dashboard Finance/GA, atau Finance yang membuka Accounting, tidak mendapat kontrol edit |

## 2. Kondisi Kode Saat Ini (hasil inspeksi)

- **Registry widget:** `config/dashboard_widgets.php`, berisi `audiences[audience] = {route, roles, context, widgets[key] = {slot, label, required}}`. Audience yang ada: `admin`, `purchasing`, `finance`, `accounting`, `qc`, `ga`, `supplier.import`, `supplier.local`.
- **Service:** `app/Services/Dashboard/DashboardWidgetService.php`
  - `audienceFor(User, ?routeName)`: tanpa route, menentukan audience "milik" user (role, atau `supplier.<context>`).
  - `layoutFor(User, savedLayouts, ?audience)`: mengembalikan baris `{key, slot, label, required, visible}` sesuai urutan tersimpan.
  - `mergeLayout()`: **hanya menulis audience milik user** (`audienceFor($user)`), jadi keputusan #4 sudah ditegakkan di sisi simpan.
  - `normalizeLayout()` / `trustedKeys()`: memfilter key asing dan melarang menyembunyikan widget `required`.
- **Penyimpanan:** `user_preferences.dashboard_preferences` (JSON) = `{ "<audience>": { "order": [...], "hidden": [...] } }`. Skema tidak berubah.
- **Render dashboard:** `resources/views/components/ui/dashboard-layout.blade.php` (`<x-ui.dashboard-layout audience="...">`)
  - Grid `tw-grid-cols-1 lg:tw-grid-cols-12`.
  - Setiap widget berasal dari named slot. Lebar diambil dari class slot, contohnya `<x-slot:recent_requisitions class="lg:tw-col-span-5">`; tanpa class, default `lg:tw-col-span-12`.
  - Ada link "Customize" (`data-dashboard-customize-link`) ke `profile.customization#dashboard-layout-title` yang **selalu** dirender, termasuk di dashboard yang bukan milik user.
  - Widget hidden tidak dirender.
- **Slot dengan lebar non-penuh saat ini:**
  - `resources/views/purchasing/dashboard.blade.php`: `recent_requisitions` = 5, `arrivals` = 7
  - `resources/views/admin/dashboard.blade.php`: `summary` = 12, `rates` = 8, `shortcuts` = 4, `notifications` = 12
  - `resources/views/supplier/dashboard.blade.php`: `metrics` = 12, `quotations` = 12, `orders` = 8, `updates` = 4
  - Dashboard lain (finance, accounting, qc, ga, local-supplier) semuanya penuh.
- **Halaman Customization:** `resources/views/profile/customization.blade.php` (section `#dashboard-layout`)
  - `<ol data-dashboard-controls>` berisi `<li data-dashboard-choice data-widget-key data-widget-label draggable="true">`.
  - Input `dashboard[order][]` dan `dashboard[hidden][]`; tombol `data-dashboard-move="up|down"`; `data-dashboard-handle`, `data-dashboard-status`, `data-dashboard-section`; reset lewat `resetSection('dashboard')`.
- **JS yang ada:**
  - `resources/js/preferences.js`: `bindDashboardReorder()` dan `bindDashboardDragAndDrop()`. Drag-drop HTML5 hanya memakai sumbu Y (asumsi daftar vertikal).
  - `resources/js/customization-form.js`: Alpine `customizationForm` untuk dirty tracking. Membaca `[data-dashboard-order-input]`, memakai `sameOrder()`/`sameSet()`.
  - `resources/js/unsaved-changes.js`: pola peringatan saat meninggalkan halaman.
- **Simpan sekarang:** `PATCH profile.customization.update` → `UserPreferenceController::update()` dengan `UpdateUserPreferenceRequest`. Request ini **mewajibkan** `theme`, `density`, `sidebar_state`, `page_size`, dst., sehingga tidak bisa dipakai untuk menyimpan layout saja. Rules dashboard ada di `UpdateUserPreferenceRequest::rules()` (`dashboard.order.*` ∈ keys, `dashboard.hidden.*` ∈ optional keys, `distinct`, `max`).
- **Pola simpan parsial yang bisa ditiru:** `UserPreferenceService::saveLocale()`, yaitu transaksi + `lockForUpdate` pada user, isi default bila row belum ada, `revision + 1`, invalidasi request cache, `NotificationPreferenceService::forget()`.
- **Route:** `routes/web.php` sekitar baris 371–373 (`profile.customization`, `.update`, `.reset`).
- **Tes terkait:** `tests/Feature/UserDashboardUiTest.php`, `tests/Feature/UserRegionalPreferencesTest.php`, `tests/js/dashboard-customization.test.mjs`, `tests/js/dashboard-drag-drop.test.mjs`, `tests/js/preferences.test.mjs`.

## 3. Desain Lebar: "Smart Span"

Tujuannya tidak pernah ada celah kosong setelah card diurutkan ulang atau disembunyikan, tanpa menambah preferensi baru.

1. Tambahkan `span` (lebar natural, default `12`) ke setiap widget di `config/dashboard_widgets.php`. Nilainya mengikuti class slot saat ini (§2): purchasing `recent_requisitions` 5 / `arrivals` 7; admin `rates` 8 / `shortcuts` 4; supplier.import `orders` 8 / `updates` 4; selebihnya 12.
2. Tambahkan juga `kind` untuk ikon/skeleton wireframe: `metrics | chart | table | list | summary`. Pilih per widget sesuai isinya.
3. **Algoritma** (harus identik di PHP dan JS):
   - Iterasi **hanya widget visible** sesuai urutan.
   - Widget dengan span 12 menempati satu baris penuh dan menutup baris sebelumnya.
   - Widget parsial ditambahkan ke baris berjalan selama total ≤ 12. Bila tidak muat, baris berjalan ditutup dan baris baru dimulai.
   - **Saat baris ditutup dan totalnya < 12, widget terakhir di baris itu dilebarkan** sampai total = 12.
   - Contoh: `[5]` → `[12]`; `[5,7]` → `[5,7]`; `[4,5]` → `[4,8]`; `[8,4]` → `[8,4]`; `[7, 12, 5]` → `[12, 12, 12]`; `[8 hidden, 4]` → `[12]`.
4. PHP: `DashboardWidgetService::layoutFor()` menambahkan `span` dan `effective_span` ke setiap baris. Baris hidden boleh tetap mendapat `effective_span = span` untuk keperluan wireframe.
5. JS: modul murni baru `resources/js/dashboard-layout-core.js` mengekspor `computeEffectiveSpans(items)`, dengan `items = [{key, span, visible}]` dan hasil berupa map key → span.
6. Hapus atribut `class="lg:tw-col-span-N"` dari `<x-slot:...>` di tiga view dashboard di atas. Class lain pada slot (bila ada) tetap dipertahankan.
7. Map span → class ditulis **literal** agar terbaca scanner Tailwind: `3,4,5,6,7,8,9,12` → `lg:tw-col-span-N`, di blade component dan di `dashboard-layout-core.js`. Periksa `content` di `tailwind.config.js`; bila salah satu file tidak tercakup, tambahkan class ke `safelist`.

## 4. Bagian A — Mode Edit di Dashboard

### 4.1 Komponen `resources/views/components/ui/dashboard-layout.blade.php`

- Hitung `$ownsAudience = $audience === DashboardWidgetService::audienceFor($user)`. Tambahkan helper `ownsAudience(User, string): bool` di service agar bisa dites.
- Link `data-dashboard-customize-link` diganti tombol **"Customize dashboard" / "Atur dashboard"**. Atribut `data-dashboard-customize-link` tetap dipertahankan. Tombol **hanya dirender bila `$ownsAudience`**.
- Root grid mendapat `data-dashboard-editor` beserta payload JSON (`data-dashboard-config` atau `<script type="application/json">`) berisi:
  - `saveUrl` (route baru §5);
  - `defaultOrder` (`array_column(layoutFor($user, []), 'key')`);
  - daftar widget `{key, label, span, required, visible, kind}`;
  - copy terjemahan yang dibutuhkan editor.
- Setiap widget visible dibungkus elemen dengan `data-dashboard-widget="{key}"`, `data-span`, `data-required`, dan class `effective_span` dari server.
- **Widget hidden tidak dirender isinya.** Bila `$ownsAudience`, render placeholder ringan yang tersembunyi di luar mode edit: `data-dashboard-widget="{key}" data-dashboard-placeholder hidden`, berisi label + skeleton sesuai `kind`. Dengan begitu data/chart widget hidden tetap tidak dikirim.
- Sediakan elemen kosong untuk strip editor per widget dan sticky action bar (lihat 4.2), yang dirender hanya bila `$ownsAudience`.

### 4.2 JS baru `resources/js/dashboard-editor.js` (import dari `resources/js/app.js`)

- **Masuk mode edit:** klik tombol, atau URL berisi `?customize=1` (deep link dari Customization; hapus param dari URL lewat `history.replaceState`).
- **Saat mode edit:**
  - Ambil **snapshot** urutan dan hidden awal.
  - Tampilkan placeholder widget hidden dengan gaya redup dan label "Tersembunyi".
  - Pasang strip editor di atas setiap card: drag handle, label, toggle "Tampilkan" (atau ikon gembok + "Selalu tampil" bila `required`), serta tombol ↑/↓ dengan `aria-label`.
  - Beri atribut `inert` pada isi widget agar link/tombol di dalamnya tidak bisa diklik.
  - Tampilkan **sticky action bar**: "N perubahan belum disimpan" · **Reset ke default** · **Batal** · **Simpan perubahan**.
- **Interaksi:**
  - Drag-and-drop HTML5 di grid. Generalisasikan logika `bindDashboardDragAndDrop()` dari `preferences.js` ke helper bersama: posisi drop ditentukan dari **sumbu X dan Y**, karena card bisa berdampingan (sebelah kiri/atas = before, kanan/bawah = after).
  - ↑/↓ memindahkan satu posisi dan tetap tersedia untuk keyboard dan layar sentuh, karena HTML5 DnD tidak jalan di touch.
  - Setelah setiap perubahan: hitung ulang `computeEffectiveSpans()`, perbarui class span, lalu umumkan posisi lewat `aria-live` dengan key yang sudah ada `js.dashboard.position`.
  - Toggle hide pada widget visible mengubahnya menjadi placeholder redup; isi asli disembunyikan, bukan dihapus, agar Batal bisa memulihkan. Toggle show pada placeholder memberi label "Tampil setelah disimpan".
- **Reset ke default:** dilakukan di sisi klien dengan menerapkan `defaultOrder` dan menampilkan semua widget. Hasilnya tetap harus disimpan lewat Simpan.
- **Batal:** kembalikan DOM ke snapshot, lalu keluar dari mode edit.
- **Simpan:**
  - `fetch` dengan method `PATCH`, header `Accept: application/json` dan `X-CSRF-TOKEN`, body `{dashboard: {order, hidden}}`.
  - Sukses: `location.reload()` agar widget yang baru ditampilkan dirender dengan data dan chart asli. Pesan sukses ditampilkan lewat flash session → AdasiToast.
  - 422/error: AdasiToast error, tetap di mode edit. Tombol simpan memakai spinner/disabled selama request.
- **Guard perubahan:** pakai pola `unsaved-changes.js` agar muncul peringatan saat meninggalkan halaman dalam keadaan dirty.
- Pisahkan state/logic murni (snapshot, dirty count, payload, reorder) dari manipulasi DOM supaya bisa dites dengan `node --test`.

## 5. Endpoint Simpan Khusus Layout

- **Route** (grup auth yang sama dengan `profile.customization*`):
  ```php
  Route::patch('/profile/dashboard-layout', [UserPreferenceController::class, 'updateDashboardLayout'])
      ->name('profile.dashboard-layout.update');
  ```
  Route ini tidak memakai parameter hashed, jadi tidak perlu perubahan `DecodeHashids`.
- **FormRequest baru** `app/Http/Requests/UpdateDashboardLayoutRequest.php`:
  - Rules `dashboard` (`required|array:hidden,order`), `dashboard.order`, `dashboard.order.*`, `dashboard.hidden`, `dashboard.hidden.*` sama persis dengan `UpdateUserPreferenceRequest`.
  - Ekstrak rules ini ke satu helper kecil (mis. static method atau trait) yang dipakai kedua request, supaya tidak terduplikasi.
  - Keys dihitung dari `layoutFor($user, [])` milik audience user sendiri, sehingga key audience lain ditolak.
  - `authorize()`: user terautentikasi **dan** `audienceFor($user) !== null`.
- **Service:** `UserPreferenceService::saveDashboardLayout(User $user, array $dashboard): array`
  - Ikuti pola `saveLocale()`: transaksi (retry 3), `lockForUpdate` user, isi default bila row belum ada, `dashboard_preferences = mergeLayout(...)`, `revision + 1`, invalidasi request cache, `NotificationPreferenceService::forget()`.
  - **Tidak menyentuh** field lain (theme, locale, quick_access, regional, sidebar).
- **Controller:** `UserPreferenceController::updateDashboardLayout()`
  - Panggil service, lalu flash `success` = `__('customization.dashboard_saved')`.
  - Bila `$request->expectsJson()`, kembalikan `response()->json(['redirect' => url()->previous() ?: route(<dashboard route audience>)])`. Selain itu `redirect()->back()`.
  - 422 dari FormRequest otomatis berbentuk JSON.
- Tidak ada perubahan pada `profile.customization.update` maupun `.reset`.

## 6. Bagian B — Wireframe Mini di Customization

File: `resources/views/profile/customization.blade.php`, `resources/js/preferences.js`, `resources/js/customization-form.js`.

- Ganti `<ol>` vertikal dengan **grid mini 12 kolom** (`tw-grid tw-grid-cols-12`, juga di mobile agar proporsinya tetap terlihat).
- Setiap tile:
  - `col-span` = `effective_span`;
  - tinggi skeleton sesuai `kind`;
  - ikon kecil, label, toggle tampil atau gembok "Selalu tampil", tombol ↑/↓, drag handle;
  - tile hidden tampil redup/bergaris dan tetap ada di grid agar bisa ditampilkan kembali.
- **Pertahankan kontrak yang ada:**
  - input `dashboard[order][]` (`data-dashboard-order-input`) dan `dashboard[hidden][]`;
  - atribut `data-dashboard-controls`, `data-dashboard-choice`, `data-widget-key`, `data-widget-label`, `data-dashboard-move`, `data-dashboard-handle`, `data-dashboard-status`, `data-dashboard-section`, `data-dashboard-visibility-toggle`, `data-dashboard-hide-fallback`;
  - dengan begitu Save Changes, dirty tracking `customizationForm`, `resetSection('dashboard')`, dan restore `old()` setelah 422 tetap bekerja.
- Setelah reorder/toggle, hitung ulang span tile memakai `dashboard-layout-core.js`. Tambahkan hook ini di jalur `bindDashboardReorder`/`bindDashboardDragAndDrop` dan di handler toggle/reset.
- Tambah tombol sekunder **"Edit langsung di dashboard"** yang mengarah ke `route(config('dashboard_widgets.audiences')[$dashboardAudience]['route'], ['customize' => 1])`. Tampilkan hanya bila `$dashboardAudience !== null`. Bila form Customization dirty, biarkan peringatan unsaved-changes yang sudah ada menangani navigasi.
- Perbarui teks bantuan `customization.dashboard_help` agar sesuai dengan UI baru.

## 7. Terjemahan

Tambahkan key berpasangan di `lang/en` **dan** `lang/id` (`customization.php` untuk Blade, `js.php` untuk teks JS via `AdasiI18n`), misalnya:

- `customization.dashboard_customize`, `customization.dashboard_edit_on_dashboard`, `customization.dashboard_saved`
- `js.dashboard.editor.save`, `.cancel`, `.reset`, `.unsaved` (dengan `:count`, pakai `choice` bila perlu), `.hidden`, `.shown_after_save`, `.always_shown`, `.show_panel`, `.move_up`, `.move_down`, `.drag`, `.save_failed`

Ikuti UI Language Policy di `AGENTS.md`: dashboard Impor (Purchasing/Admin/QC/Supplier Impor) English-first, Lokal Indonesian-first, istilah "Supplier" tetap. Placeholder harus identik di kedua locale (`TranslationParityTest`). Jangan menulis teks UI langsung di Blade/JS (`UserFacingCopyInventoryTest`).

## 8. Daftar File

| File | Perubahan |
|---|---|
| `config/dashboard_widgets.php` | + `span`, `kind` per widget |
| `app/Services/Dashboard/DashboardWidgetService.php` | + `span`/`effective_span` di `layoutFor()`, + `ownsAudience()`, + algoritma smart span |
| `app/Services/UserPreferenceService.php` | + `saveDashboardLayout()` |
| `app/Http/Requests/UpdateDashboardLayoutRequest.php` | baru |
| `app/Http/Requests/UpdateUserPreferenceRequest.php` | pakai helper rules dashboard bersama |
| `app/Http/Controllers/UserPreferenceController.php` | + `updateDashboardLayout()` |
| `routes/web.php` | + `profile.dashboard-layout.update` |
| `resources/views/components/ui/dashboard-layout.blade.php` | tombol edit (owner only), wrapper span, placeholder, payload editor |
| `resources/views/{purchasing,admin,supplier}/dashboard.blade.php` | hapus class col-span di slot |
| `resources/views/profile/customization.blade.php` | wireframe grid + tombol "Edit di dashboard" |
| `resources/js/dashboard-layout-core.js` | baru (smart span murni) |
| `resources/js/dashboard-editor.js` | baru (mode edit) |
| `resources/js/preferences.js` | DnD dua sumbu + hook hitung ulang span |
| `resources/js/customization-form.js` | hitung ulang span saat reset/discard |
| `resources/js/app.js` | import editor |
| `lang/{en,id}/customization.php`, `lang/{en,id}/js.php` | key baru |
| `tailwind.config.js` | safelist bila diperlukan |

Tidak ada migrasi, package baru, atau perubahan kontrak penyimpanan.

## 9. Urutan Pengerjaan

1. Config `span`/`kind` + smart span di service + unit test PHP.
2. `dashboard-layout-core.js` + `tests/js/dashboard-layout-core.test.mjs`, memakai kasus yang sama dengan PHP.
3. Komponen dashboard memakai `effective_span`, lalu hapus class col-span di slot. Pastikan tampilan default **identik** dengan sebelumnya.
4. Endpoint simpan layout (request, service, controller, route) + feature test.
5. Mode edit di dashboard (komponen + `dashboard-editor.js`) + test JS.
6. Wireframe Customization + DnD dua sumbu + penyesuaian test JS yang ada.
7. Terjemahan en/id, lalu jalankan verifikasi lengkap.

## 10. Kriteria Penerimaan

- [ ] Tampilan default setiap dashboard tidak berubah dibanding sebelum perubahan.
- [ ] Menyembunyikan atau memindahkan card tidak pernah menyisakan celah kosong di grid desktop.
- [ ] Hanya pemilik audience yang melihat tombol edit. Admin di `finance.dashboard`/`ga.dashboard`, dan Finance di `accounting.dashboard`, tidak melihatnya.
- [ ] Mode edit: drag-drop dan ↑/↓ berfungsi, widget wajib tidak bisa disembunyikan, Batal memulihkan state, Reset menerapkan default (tetap perlu Simpan), muncul peringatan saat meninggalkan halaman dalam keadaan dirty.
- [ ] Simpan hanya mengubah `dashboard_preferences` audience milik user; preferensi lain tidak berubah.
- [ ] Endpoint menolak (422): menyembunyikan widget required, key asing, key milik audience lain, dan payload tanpa `dashboard`.
- [ ] Isi widget hidden (data/chart) tidak dirender ke HTML; hanya placeholder untuk pemilik.
- [ ] Wireframe Customization menampilkan proporsi yang sama dengan dashboard dan tetap tersimpan lewat Save Changes.
- [ ] Semua teks baru ada di en dan id.

## 11. Verifikasi

```powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test --filter=UserDashboardUiTest
php artisan test --filter=UserRegionalPreferencesTest
php artisan test --filter=UserLocalePreferencesTest
php artisan test tests/Unit/TranslationParityTest.php
php artisan test --filter=TranslationContentTest
php artisan test --filter=UserFacingCopyInventoryTest
php artisan test --filter=FrontendAssetLoadingTest
php artisan test --filter=HashidUrlSecurityTest

node --test tests/js/dashboard-layout-core.test.mjs
node --test tests/js/dashboard-editor.test.mjs
node --test tests/js/dashboard-customization.test.mjs
node --test tests/js/dashboard-drag-drop.test.mjs
node --test tests/js/preferences.test.mjs

php -l <file php yang diubah>
php vendor/bin/pint --test <file php yang diubah>
php artisan view:cache
php artisan route:list --name=profile --no-ansi
npm.cmd run build
git diff --check
```

### Tes baru yang harus ditambahkan (`tests/Feature/UserDashboardUiTest.php` atau file baru)

- Pemilik melihat tombol edit di dashboard-nya; admin tidak melihatnya di `finance.dashboard` dan `ga.dashboard`; finance tidak melihatnya di `accounting.dashboard`.
- Widget hidden tidak merender isinya; placeholder hanya ada untuk pemilik.
- `PATCH profile.dashboard-layout.update`:
  - menyimpan order/hidden;
  - theme/locale/quick_access/regional tetap utuh;
  - respons JSON berisi `redirect` bila `Accept: application/json`, redirect biasa bila tidak;
  - 422 untuk required-hidden, key asing, key audience lain, dan payload kosong;
  - supplier tanpa konteks ditolak.
- Smart span PHP: `[5]`, `[5,7]`, `[4,5]`, `[8,4]`, partner hidden, urutan `[7,12,5]`.
- Customization: wireframe tetap memuat input/atribut kontrak lama dan tombol "Edit di dashboard" mengarah ke route dashboard dengan `customize=1`.

### Belum bisa diverifikasi otomatis (cek manual di browser)

- Drag lintas kolom di desktop (Chrome/Edge/Firefox).
- ↑/↓ di tablet/HP.
- Dark mode untuk strip editor, placeholder, dan action bar.
- Chart di widget yang baru ditampilkan setelah reload simpan.
- Akses cross-role: admin, purchasing, finance, supplier dengan dua scope.
