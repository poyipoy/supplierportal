# Rencana: Account Settings UX — Redesign Customization + Navigasi Akun Bersama

**Tanggal:** 2026-10-08 · **Status:** rencana, belum ada kode diubah · **Sumber:** wawancara pemilik produk + inspeksi kode.
Tidak ada tes, build, atau aplikasi yang dijalankan saat menyusun ini. **[Verified]** = dibaca langsung dari kode; **[Inferred]** = disimpulkan; **[Belum diverifikasi]** = harus dicek saat implementasi.

Setelah rencana disetujui, salin ke `docs/plans/IMPLEMENTATION-PLAN-ACCOUNT-SETTINGS-UX-20261008.md` (pilihan deliverable pemilik produk; AGENTS.md melarang dokumen baru di root).

## Context

Halaman `/profile/customization` adalah satu form panjang (317 baris Blade, enam section ber-bingkai) dengan Save/Reset hanya di paling bawah, empat grup radio Appearance yang ditumpuk, paragraf contoh Regional per opsi, editor dashboard dengan checkbox "Hide" yang logikanya terbalik, dan batas Quick Access yang baru ketahuan setelah server menolak. Empat halaman akun (Profile, Security, Notifications, Customization) hanya terjangkau lewat dropdown navbar. Pemilik produk ingin tampilan lebih segar, bersih, modern, bergaya ERP, dengan **fokus pada UX**, dan memilih keempat pain point sebagai prioritas.

## Keputusan dari wawancara

| ID | Keputusan |
|---|---|
| D1 | Cakupan: Customization penuh **+** sub-navigasi bersama di keempat halaman akun. Isi Profile/Security/Notifications tidak didesain ulang. |
| D2 | Satu halaman, satu form, **nav section sticky di kiri** dengan penanda posisi. Mobile: tab horizontal (`x-ui.tabs`). |
| D3 | **Preview langsung + Save eksplisit + penanda dirty**; sticky bar: "N perubahan belum disimpan", Discard, Save. Backend PATCH tidak berubah. |
| D4 | Reset **per section**; semantiknya **mengisi kontrol dengan default sebagai perubahan tertunda** (bisa di-Discard). Bahasa tidak ikut direset. |
| D5 | Appearance: segmented ringkas + deretan swatch accent + strip preview. |
| D6 | Dashboard: daftar ringkas, switch **Tampil**, drag handle, tombol naik/turun berupa ikon, gembok untuk panel wajib. |
| D7 | Regional: hapus paragraf contoh per opsi → **satu baris contoh live**. |
| D8 | Quick Access: penghitung "N dari 6", opsi lain nonaktif di batas. |
| D9 | Keempat pain point dipilih → fase diurutkan menurut ketergantungan/risiko. |

## Temuan kode yang membentuk rencana

- **[Verified]** Preview langsung theme/accent/density sudah ada dan sementara sampai Save (`resources/js/preferences.js:7-25`, `layouts/app.blade.php:187-190`); `restoreSaved()` mengembalikannya. Preview bekerja lewat `root.dataset.accent/density`, jadi strip preview tidak butuh JS tambahan.
- **[Verified]** Preseden sticky save bar dengan penghitung dirty + Discard + Save sudah ada di `profile/notifications.blade.php:388-415` (`x-ui.action-bar`, Alpine `discard()` baris 703). `x-ui.tabs`, `x-ui.switch`, `x-ui.action-bar`, `x-ui.status-chip`, `x-ui.alert`, `x-ui.dialog` tersedia; **segmented control dan swatch picker belum ada**.
- **Koreksi:** reset bahasa ke English itu disengaja (`lang/en/customization.php:60` "Language will return to English"; flash `'en'` di `UserPreferenceController.php:78` konsisten). Masalahnya desain, bukan bug pesan; D4 menyelesaikannya.
- **[Verified]** `UserPreferenceService::save` menggabungkan shortcut dari konteks supplier *lain* ke total sebelum cek batas 6 (baris 67-83). Penghitung klien harus menghitung `terlihat + otherContextCount`, kalau tidak, pilihan valid di klien bisa ditolak server.
- **[Verified]** `AdasiPreferences.displayTimestamp/displayNumber/displayDate` menutup nilai regional yang *tersimpan* dan beku saat load (`layouts/app.blade.php:64,178-191`) → tidak bisa memformat pilihan yang belum disimpan. Contoh live butuh formatter murni terpisah.
- **[Verified]** Markup yang dikunci `UserDashboardUiTest.php:17-76`: `name="accent"`, `value="slate" checked`, `name="dashboard[hidden][]" value="<key>" checked` (urutan atribut dicek persis; centang = disembunyikan), `name="dashboard[order][]"`, `data-dashboard-controls`, `draggable="true"`, `data-dashboard-handle`, `data-dashboard-move="up|down"`, `data-dashboard-status`, `role="status"`, `form="resetDashboardLayout"`, `name="scope" value="dashboard"`, teks "Accent Color", "Dashboard Layout", "Always shown", "Reset Layout to Default", dan tautan `route('profile.customization').'#dashboard-layout-title'`. JS memakai `[data-dashboard-choice]`, `data-widget-key`, `data-widget-label`, `[data-dashboard-section]`.
- **[Verified]** Profile punya skrip pengalih `#active-sessions` → Security (`profile/edit.blade.php:37-50`) yang harus dipertahankan. Keempat rute dapat diakses ketujuh role (`routes/web.php:346-356`).
- **[Inferred]** Guard unsaved-changes generik melacak form POST (`unsaved-changes.js:58-59`), termasuk form ini; Notifications punya `beforeunload` sendiri (`notifications.blade.php:502`) yang kemungkinan menggandakannya — tidak disentuh.

## Desain target

### Account shell
Komponen anonim baru `resources/views/components/account/shell.blade.php` (prop `active`, slot `sections`). Keempat halaman membungkus isinya; `x-ui.page-header` tetap di atas.
- Rail ≥ 993 px (`shell:` breakpoint, `tailwind.config.js:32`), sticky: grup "Account" (Profile `user-cog`, Security `shield-check`, Notifications `bell`, Customization `sliders-horizontal`); aktif = `aria-current="page"` + penanda batas kiri; di bawah Customization: anchor section dengan `aria-current="location"` (IntersectionObserver) dan penanda titik+ikon/teks untuk section berisi perubahan belum tersimpan atau galat.
- < 993 px: `x-ui.tabs` berisi tautan keempat halaman; pada Customization ditambah baris tab kecil kedua untuk lompat section.
- Eyebrow "Account" di keempat header dihapus (digantikan label grup rail).

### Anatomi Customization (empat section)
1. **Appearance & display** — Theme, Accent, Density, Sidebar default, Rows per page (dipindah dari "Data Display"), strip Preview.
2. **Language & region** — Language, Timezone, Date, Time, Number (grid 2×2), satu baris contoh live. Language diberi catatan "berlaku setelah disimpan".
3. **Dashboard layout** — heading mempertahankan `id="dashboard-layout-title"`.
4. **Shortcuts** — grid + penghitung.
Tiap section: heading, satu baris deskripsi, "Reset section" (ghost kecil) di header, kontrol label-kiri/kontrol-kanan di `shell:`, bertumpuk di layar kecil. Dipisah garis & tipografi, tanpa kartu bersarang.

### Perilaku
- **Dirty & sticky bar** (`x-ui.action-bar`, `role="status" aria-live="polite"`): hitungan = jumlah *pengaturan* berbeda dari **baseline tersimpan dari server** (bukan DOM awal — setelah galat validasi form dirender dengan `old()`). Save nonaktif hanya saat tidak ada perubahan dan tidak ada galat; setelah submit: spinner + kunci tombol.
- **Discard:** kembalikan kontrol ke baseline, panggil `AdasiPreferences.restoreSaved()`, kembalikan urutan DOM dashboard, perbarui hitungan. Tanpa reload (menghindari guard unsaved).
- **Reset section:** isi kontrol dengan default (dari server, tak di-hardcode di JS), picu preview, naikkan hitungan, umumkan lewat `aria-live`; tanpa dialog (bisa di-Discard). Section Language & region hanya mereset format regional. "Reset all" (opsional, K4) melakukan hal sama kecuali bahasa.
- **Preview strip:** tombol utama/outline, dua chip status, tiga baris tabel contoh (teks netral dari lang).
- **Contoh live Regional:** modul murni dari sampel tetap dari server (1 instant ISO UTC + 1 angka) + empat pilihan saat ini; untuk `system` tampilkan "Mengikuti tampilan yang ada". Dijaga tes paritas dengan formatter layout.
- **Dashboard editor:** per baris: handle drag, nama, lalu (opsional) switch "Tampil" + dua tombol ikon naik/turun ber-`aria-label`; panel wajib: gembok + "Always shown". Tombol naik/turun selalu ada (alternatif non-gestur). Checkbox `dashboard[hidden][]` dipertahankan sebagai **sumber kebenaran** (disembunyikan secara visual), switch disinkronkan kebalikannya oleh JS; tanpa JS, checkbox "Hide panel" asli tampil.
- **Quick Access:** `N dari 6` (`aria-live`) = terpilih terlihat + `otherContextCount`; di batas, checkbox belum dicentang `disabled` dengan penjelasan lewat `aria-describedby`. Server tetap otoritatif.
- **Galat:** ringkasan galat dipertahankan + tautan ke section bermasalah + penanda di rail; fokus ke ringkasan saat render dengan galat.

### Wireframe

Desktop (≥ 993 px):
```
+-------------------------------------------------------------------------------+
| Customization                                                    [Reset all]  |
| Personalize how the portal looks and behaves.                                 |
+-----------------+-------------------------------------------------------------+
| ACCOUNT         | Appearance & display                      [Reset section]   |
|   Profile       | ----------------------------------------------------------- |
|   Security      | Theme            ( Light | System | Dark )                  |
|   Notifications | Accent           (*) ( ) ( ) ( ) ( )   ADASI Blue           |
| | Customization | Density          ( Comfortable | Compact )                  |
|     Appearance .| Sidebar default  ( Expanded | Collapsed )                   |
|     Language    | Rows per page    [ 25  v ]                                  |
|     Dashboard   | Preview  +---------------------------------------------+     |
|     Shortcuts   |          | [Primary] [Outline]  (Approved) (Pending)   |     |
|                 |          | Sample row A        Supplier A     1,250.00 |     |
|                 |          +---------------------------------------------+     |
|                 | Language & region                         [Reset section]   |
|                 | Language         ( English | Bahasa Indonesia )             |
|                 |                  Applies after you save.                    |
|                 | Timezone [ System v ]        Date format   [ System v ]     |
|                 | Time     [ 24-hour v ]       Number format [ Intl v ]       |
|                 | Sample   08 Oct 2026 14:30 WIB  .  1,234,567.89             |
|                 | Dashboard layout                          [Reset section]   |
|                 | :: Summary                  [lock] Always shown             |
|                 | :: Notifications  [ON] Tampil              [^] [v]          |
|                 | Shortcuts                     3 of 6   [Reset section]      |
|                 | [x] Users   [x] Rates   [x] Reports   [ ] Audit   [ ] ...  |
+-----------------+-------------------------------------------------------------+
| (*) 3 unsaved changes                              [ Discard ]  [ Save changes ] |
+-------------------------------------------------------------------------------+
```
Tablet (~768–992): layout mobile, kontrol dua kolom (`md:`), sticky bar tetap. Mobile:
```
+----------------------------------+
| Customization      [Reset all]   |
| [Profile][Security][Notif..][Cus |  <- tab halaman (geser)
| [Appearance][Language][Dash..]   |  <- tab lompat section (geser)
| Appearance & display   [Reset]   |
| Theme  ( Light | System | Dark ) |
| Accent (*) ( ) ( ) ( ) ( )       |
+----------------------------------+
| (*) 3 unsaved   [Discard] [Save] |
+----------------------------------+
```

## Rancangan teknis

| File | Perubahan | Fase |
|---|---|---|
| `resources/views/components/account/shell.blade.php` (baru) | rail + tab mobile | A |
| `resources/views/profile/{edit,security,notifications}.blade.php` | dibungkus shell; eyebrow dihapus | A |
| `resources/views/profile/customization.blade.php` | ditulis ulang; selector terkunci dipertahankan | B–D |
| `resources/js/customization-form.js` (baru) | baseline, diff, discard, defaults, penghitung, scroll-spy | B, D |
| `resources/js/regional-sample.js` (baru, murni) | contoh live | C |
| `resources/js/preferences.js`, `resources/js/app.js` | impor modul baru; ekspor lama tidak berubah (tes memuatnya) | B |
| `resources/css/app.css` | `.ui-segmented`, `.ui-swatch`, rail, baris dashboard (hanya token; swatch memakai `data-accent-swatch` di baris 580-584) | C, D |
| `app/Http/Controllers/UserPreferenceController.php` | `edit()` hanya menambah data view | B–D |
| `lang/{en,id}/customization.php`, navigasi akun, `js.php` | kunci baru berpasangan | A–D |

Data view tambahan (read-only, JSON ter-escape): `defaults` (config defaults + default dashboard `layoutFor($user, [])` + quick access kosong), `saved` (baseline), `otherContextCount` (dari `QuickAccessService::availableInOtherSupplierContext`, 0 untuk non-supplier), `regionalSample`. **Tidak berubah:** route, `UpdateUserPreferenceRequest`, `UserPreferenceService`, model, migrasi, `DashboardWidgetService`, `QuickAccessService`, endpoint `DELETE /profile/customization`.

Komponen/utilitas yang dipakai ulang: `x-ui.action-bar`, `x-ui.tabs`, `x-ui.switch` (+`.ui-switch`), `x-ui.status-chip`, `x-ui.alert`, `x-ui.icon`, `x-ui.button`; pola `.ui-preference-option:focus-within` (app.css:654); `AdasiPreferences.previewTheme/Accent/Density/restoreSaved`; `window.AdasiI18n`; `AdasiToast`.

CSS: `.ui-segmented` = `fieldset` dengan radio asli (fokusable, panah bawaan); `.ui-swatch` 24 px, terpilih = cincin + ikon centang + nama accent sebagai teks (bukan hanya warna); target sentuh ≥ 44 px di bawah `shell`; transisi 150 ms warna/opasitas saja, hormati `prefers-reduced-motion`; tanpa gradient/glass/bayangan dekoratif.

## Keamanan & integritas (backend-security-coder)

Tidak ada endpoint/mutasi baru; reset section murni sisi klien. Data milik `$request->user()` tanpa parameter ID (tak ada IDOR baru). `UpdateUserPreferenceRequest` tetap otoritatif; `disabled`/penghitung hanya UX. `supplier_context` hidden field dipertahankan. Data ke JS lewat JSON ter-escape, teks di-set via `textContent`. Save dikunci setelah submit pertama. `profile.security` tetap `no-store`. Konflik antar-tab tidak berubah dari sekarang.

## Fase (satu PR per fase)

| Fase | Isi | Pain | Risiko |
|---|---|---|---|
| **A. Account shell** | komponen, bungkus 4 halaman, tab mobile, hapus eyebrow, lang | #2 | rendah (view-only) |
| **B. Kerangka form** | 4 section, rail + scroll-spy, sticky bar, baseline dirty, Discard, ringkasan galat; kontrol masih radio/select | #1, sebagian #4 | sedang |
| **C. Kontrol** | segmented, swatch, strip preview, Rows per page → Appearance, grid regional, contoh live | #4 | sedang |
| **D. Dashboard/Quick Access/reset** | switch Tampil + ikon, gembok, penghitung + `otherContextCount`, Reset section/all klien, lepas form reset server dari halaman | #3 | sedang–tinggi (markup terkunci tes) |
| **E. Pengerasan** | audit a11y, QA manual, pembaruan dokumen | semua | rendah |

## Verifikasi

Perlu diperbarui secara sadar (kopling visual): `UserDashboardUiTest`, `UserCustomizationTest`, `UserRegionalPreferencesTest`, `UserLocalePreferencesTest`, `NotificationPreferencesUiTest`, `tests/js/{preferences,dashboard-customization,dashboard-drag-drop}.test.mjs`, `UserFacingCopyInventoryTest` + `tests/Support/user-facing-copy-audit.php` bila ada teks tertulis langsung (isi sebagian besar belum dibaca penuh).
Tes baru: `AccountShellTest` (nav & `aria-current` di 4 halaman, ketujuh role, supplier tanpa konteks); `CustomizationViewContractTest` (payload, selector terkunci, `otherContextCount` supplier dua-scope, tanpa query preferensi tambahan); `customization-form.test.mjs`; `regional-sample.test.mjs` (**paritas** dengan formatter layout via pendekatan `vm` yang sudah dipakai).

```powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test --filter='UserCustomizationTest|UserDashboardUiTest|UserDashboardCustomizationTest|UserRegionalPreferencesTest|UserLocalePreferencesTest|NotificationPreferencesUiTest|AccountShellTest|CustomizationViewContractTest'
php artisan test tests/Unit/TranslationParityTest.php
node --test tests/js/preferences.test.mjs
node --test tests/js/customization-form.test.mjs
node --test tests/js/regional-sample.test.mjs
php artisan view:cache
npm.cmd run build
php vendor/bin/pint --test <file PHP yang berubah>
git diff --check
```
QA manual (`MANUAL_VISUAL_QA_REQUIRED`, tak bisa saya jalankan): 9 konteks role (admin, purchasing, finance, ga, qc, accounting, supplier import-saja, local-saja, dua-scope tanpa konteks); viewport 390/768/1280; light/dark/system × accent × density; en/id; keyboard-saja; pembaca layar untuk pengumuman reset & penghitung; zoom 200%; reduced-motion; alur ubah→Discard, ubah→pindah halaman, galat→perbaiki, bfcache setelah preview, reorder→Discard, batas 6 lintas konteks; kontras 4.5:1 teks bantu/penanda di kedua tema.

## Risiko & pertanyaan terbuka (default dipakai bila tak dijawab)

- **R1** dua baris tab bertumpuk di mobile bisa sesak → pertahankan; bila QA menunjukkan sesak, jadikan baris halaman satu select.
- **R2** formatter contoh live menduplikasi logika layout → satu modul murni + tes paritas (mengekspor helper dari layout ditolak: blast radius ke semua halaman).
- **R3** markup dashboard dikunci tes (urutan atribut) → checkbox sumber kebenaran dipertahankan; tes diperbarui hanya untuk kopling visual.
- **R4** token mana yang merespons `data-density` belum diverifikasi → cek di fase C.
- **R5** logika dirty Notifications berbeda → di luar cakupan; tindak lanjut.
- **R6** tanpa JS: Reset/Discard hilang (halaman sudah butuh JS untuk reorder; endpoint `DELETE` tetap).
- **R7** redirect setelah Save kembali ke atas halaman → ditunda.
- **K1** sub-alur 2FA ikut shell? → tidak. **K2** Rows per page ke Appearance? → ya. **K3** hapus eyebrow "Account"? → ya. **K4** "Reset all" di header? → ya (mudah dipotong). **K5** switch Tampil + checkbox tersembunyi, bukan mengubah kontrak server? → ya.

## Di luar cakupan
Redesain isi Profile/Security/Notifications; penyatuan logika dirty Notifications; deteksi konflik antar-tab; redirect ke section terakhir; perubahan backend/skema/route; prototipe HTML; migrasi/package baru.
