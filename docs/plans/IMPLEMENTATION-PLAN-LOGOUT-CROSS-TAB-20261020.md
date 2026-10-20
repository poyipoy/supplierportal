# Implementation Plan — Logout Lintas Tab, Pembersihan Storage, dan Opsi "Keluar dari Semua Perangkat"

> Tanggal: 20 Oktober 2026 · Status: **disetujui, belum diimplementasikan**
>
> Agent pelaksana wajib membaca `AGENTS.md` lebih dulu dan mencocokkan rencana ini dengan kode terbaru di `master`. Bila ada perbedaan, **kode, migrasi, dan tes yang menang**; laporkan perbedaannya.
>
> Riset pendukung: [`reports/Logout Infor dan single logout.md`](../../reports/Logout%20Infor%20dan%20single%20logout.md) dan catatan di `research_notes/Logout Infor dan single logout/` (terutama `adasi_logout_options.md`).

## 1. Context

Logout Infor berantai ke banyak aplikasi lewat browser, sehingga user diminta tidak menutup tab sampai selesai. Portal ADASI hanya satu aplikasi dengan satu sesi: `POST /logout` sudah selesai seketika dan token OAuth sudah dicabut saat callback SSO. **Pesan "jangan tutup tab" tidak diperlukan dan tidak boleh ditambahkan.**

Yang benar-benar kurang (hasil inspeksi `master`, HEAD 1c4ab2c):
1. **Tab lain di browser yang sama tidak ikut logout.** Polling badge (`resources/views/layouts/app.blade.php` ≈L415-509, interval 30 detik) menelan respons non-OK (`r.ok ? r.json() : null`), sehingga tab lain baru sadar saat diklik, dan form-nya gagal dengan halaman 419.
2. **Data localStorage yang tidak terikat user** tetap ada setelah logout dan terlihat oleh user lain di komputer yang sama:
   - `adasi:pending-export-jobs:v1` (`public/assets/js/async-export.js` ≈L12, L236, L252);
   - `quotation_draft_<prId>` (`resources/views/supplier/quotations/create.blade.php` ≈L2794-2821).
3. **Tidak ada opsi "keluar dari semua perangkat"** pada saat logout, padahal `SessionRevocationService` sudah ada.

Di luar cakupan (diputuskan dari riset): `Clear-Site-Data` (menghapus cookie perangkat dikenal), single logout antar aplikasi, halaman interstisial "Signing out…" (splash `AUTH_SPLASH_ENABLED` yang sudah ada cukup; hanya didokumentasikan), serta perubahan alur `POST /logout`.

### Keputusan produk yang sudah diambil (default, dapat diubah pemilik produk)
- **Draft penawaran supplier tidak dihapus saat logout.** Kuncinya diberi ID user (`quotation_draft_<userId>_<prId>`) sehingga tidak bocor antar akun. Kunci lama tanpa ID user dibersihkan, sehingga draft yang sedang dikerjakan saat deploy hilang satu kali; ini dicatat di panduan.
- Antrean ekspor lokal (`adasi:pending-export-jobs:v1`) dihapus saat logout dan saat halaman login tampil.
- "Keluar dari semua perangkat" tidak meminta password, karena aksi ini hanya mengurangi akses. Defaultnya **mati** lewat flag.

## 2. Flag

File baru `config/portal_session.php`:

| Key | Env | Default | Fungsi |
|---|---|---|---|
| `cross_tab_logout` | `PORTAL_CROSS_TAB_LOGOUT_ENABLED` | `true` | Sinkron logout antar tab + redirect saat poll menerima 401 |
| `scoped_storage_cleanup` | `PORTAL_STORAGE_CLEANUP_ENABLED` | `true` | Pembersihan key localStorage tidak ber-scope dan key draft per user |
| `logout_all_devices` | `PORTAL_LOGOUT_ALL_DEVICES_ENABLED` | `false` | Checkbox "keluar dari semua perangkat" |

Tambahkan placeholder ke `.env.example`. Perubahan JS hanya aktif bila flag on; nilai flag disuntikkan dari server ke `window.AdasiSession` (`{crossTab, cleanup, userId}`); kosong bila guest atau flag off.

## 3. Phase A — Logout lintas tab

**Modul baru** `resources/js/session-sync.js` (di-import dari `resources/js/app.js`; ikuti pola modul lain yang punya file tes `node --test`). Logika murni dipisah dari DOM agar bisa dites.

- Kanal: `BroadcastChannel('adasi-session')`, fallback event `storage` pada key `adasi:session-event` (nilai berisi tipe dan timestamp, dihapus segera setelah ditulis). Semua akses storage dibungkus try/catch (mode privat, storage diblokir).
- Pesan: `{type: 'signed-out'}`. Pesan tidak membawa data sensitif.
- **Pengirim:**
  1. Saat form logout (`form[action$="logout"]`, selektor mengikuti atribut `data-logout-form` yang ditambahkan ke form navbar) di-submit.
  2. Saat halaman guest `/login` tampil (`window.AdasiSession` kosong dan elemen `data-auth-login-page`). Halaman ini hanya bisa tampil bila sesi browser tidak terautentikasi (middleware `guest`), sehingga semua tab lain di browser yang sama memang sudah tidak punya sesi. Ini juga menangani logout karena sesi habis atau dicabut dari perangkat lain.
- **Penerima** (halaman terautentikasi): saat menerima `signed-out`, panggil `Echo.disconnect()` bila `window.Echo` ada, lalu `window.location.assign(loginUrl)`. `loginUrl` disuntikkan dari server (`route('login')`), tidak dibangun di JS. Guard satu kali (`ended` flag) agar tidak loop.
- **Poll 401:** pada `updateBadges()` (notifikasi dan chat), bila `response.status === 401`, kirim event DOM `adasi:session-ended`. `session-sync.js` mendengarkannya dan melakukan langkah penerima yang sama. Status lain tetap diabaikan seperti sekarang (jangan redirect pada 403, 419, 5xx, atau error jaringan).
- Route polling sudah mengembalikan JSON 401 untuk request `expectsJson()` (lihat `bootstrap/app.php`, pengecualian `AuthenticationException`). **Verifikasi** ini terhadap kode `master`, termasuk perilaku `EnsureSsoSession` untuk request JSON. Jangan mengubah semantik 401 di server kecuali verifikasi menunjukkan polling tidak mendapat 401.
- Tidak ada route baru, dan tidak ada perubahan pada `POST /logout`.

## 4. Phase B — Pembersihan storage

- `window.AdasiSession.cleanup` true → di `session-sync.js`, fungsi `clearUserScopedStorage()` menghapus:
  - `adasi:pending-export-jobs:v1` (konstanta diekspor dari modul yang sama atau dibaca dari satu tempat; hindari menduplikasi string. `async-export.js` adalah file `public/assets/js` biasa, jadi cukup dokumentasikan key di kedua tempat dan uji dengan tes yang membaca keduanya);
  - key lama berpola `^quotation_draft_\d+$` (tanpa user id).
- Dipanggil saat submit form logout dan saat halaman `/login` tampil, sehingga logout lewat tab lain, sesi habis, atau sesi dicabut ikut membersihkan.
- **Draft penawaran:** ubah `draftKey` di `resources/views/supplier/quotations/create.blade.php` menjadi `quotation_draft_<userId>_<prId>`, dengan `userId` dari server (`auth()->id()`, integer, di-escape lewat `@json`). Tidak menyentuh isi draft.
- **Chat draft** di sessionStorage sudah ber-key user id dan hilang saat tab ditutup, sehingga tidak diubah. Draft registrasi supplier adalah halaman publik pra-login dan **tidak boleh** dibersihkan.
- Preferensi sidebar per akun tidak diubah.

## 5. Phase C — "Keluar dari semua perangkat" (opsional, flag default off)

- `AuthenticatedSessionController::destroy`: bila `config('portal_session.logout_all_devices')` dan `$request->boolean('logout_all')`, panggil `SessionRevocationService::revokeOtherSessions` **sebelum** logout lokal (service menaikkan `auth_session_version` dan memutar remember token; baca implementasinya untuk urutan yang benar), lalu lanjutkan logout normal.
- Audit: gunakan `AuthSecurityEvent` yang sudah ada untuk pencabutan sesi (ikuti event pada alur `logout-other-devices`); tambahkan metadata `source: logout_checkbox` bila katalog audit mengizinkan key tersebut (cek allow-list metadata di `AuthAuditLogger`), tanpa menambah nama event baru kecuali perlu.
- UI: checkbox di form logout navbar (`resources/views/partials/navbar.blade.php` ≈L162-167), hanya dirender bila flag on. Teks lewat `__('navigation.logout_all_devices')` di `lang/en/navigation.php` dan `lang/id/navigation.php` (key berpasangan, placeholder identik). Istilah mengikuti kebijakan UI Language di `AGENTS.md`.
- Tidak ada perubahan pada `POST /logout` selain parameter opsional itu. `logout` tetap di root dan tetap dikecualikan dari `EnsureSsoSession` serta `EnforcePasswordChange` seperti sekarang.

## 6. Invarian yang tidak boleh berubah

- `POST /logout` + CSRF tetap satu-satunya cara logout. Tidak ada route GET logout.
- Cookie `adasi_known_device` **tidak** dihapus saat logout (`KnownDeviceSecurityTest` bergantung padanya).
- Perilaku `terminateSession` (sesi dicabut/habis) tidak berubah.
- Respons `no-store, private` dan CSP tidak berubah. Tidak ada `Clear-Site-Data`.
- Path prefix portal dan hashid panjang tetap bekerja: URL login disuntikkan dari server, tidak ditulis di JS.
- Draft registrasi supplier publik dan sessionStorage chat tidak disentuh.
- Teks UI baru ada di `lang/en` dan `lang/id`; teks JS lewat `window.AdasiI18n` (`lang/*/js.php`, `JsTranslations`).
- Tanpa migrasi database dan tanpa package baru.

## 7. Daftar file

| File | Perubahan |
|---|---|
| `config/portal_session.php`, `.env.example` | baru / placeholder flag |
| `resources/js/session-sync.js`, `resources/js/app.js` | baru / import |
| `resources/views/layouts/app.blade.php` | suntik `window.AdasiSession`, dispatch `adasi:session-ended` pada 401 poll |
| `resources/views/partials/navbar.blade.php` | `data-logout-form`, checkbox opsional |
| `resources/views/auth/login.blade.php` (atau `layouts/auth.blade.php`) | penanda `data-auth-login-page`, suntik flag |
| `resources/views/supplier/quotations/create.blade.php` | key draft per user |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | parameter `logout_all` opsional |
| `lang/{en,id}/navigation.php`, `lang/{en,id}/js.php` | key baru bila diperlukan |
| `tests/js/session-sync.test.mjs`, `tests/Feature/Auth/LogoutAllDevicesTest.php` | baru |
| `docs/guides/LOGOUT-CROSS-TAB.md`, `AGENTS.md` | dokumentasi |

## 8. Tes

**Baru:**
- `tests/js/session-sync.test.mjs` (pola `node --test` yang ada; DOM dan storage di-mock):
  - pesan `signed-out` memicu redirect satu kali;
  - fallback `storage` event bekerja saat `BroadcastChannel` tidak ada;
  - 401 pada poll memicu redirect, sedangkan 403, 419, 500, dan error jaringan **tidak**;
  - `Echo.disconnect()` dipanggil bila ada;
  - pembersihan hanya menghapus key yang dimaksud;
  - flag off → tidak melakukan apa pun;
  - storage yang melempar exception tidak membuat error.
- `tests/Feature/Auth/LogoutAllDevicesTest.php` (flag on):
  - checkbox terender hanya bila flag on;
  - `logout_all=1` menaikkan `auth_session_version` dan sesi perangkat lain tidak valid;
  - tanpa parameter, perilaku logout biasa;
  - flag off mengabaikan parameter;
  - audit tercatat;
  - logout tetap redirect ke login.
- Tes tampilan: login page memuat penanda `data-auth-login-page`; form logout memuat `data-logout-form`; key draft penawaran memuat user id.

**Harus tetap hijau:** `tests/Feature/Auth/*` (khususnya `AuthenticationTest`, `AuthSplashTest`, `KnownDeviceSecurityTest`, `LoginSecurityTest`, `TwoFactorAuthenticationTest`, `AuthAuditSecurityTest`, `SessionSecurityTest`), `UserAccountNavigationTest` (asserts markup form navbar), `PortalPathPrefixTest`, `PortalPathTest`, `SsoAuthorizationFlowTest`, `TranslationParityTest`, `UserFacingCopyInventoryTest`, `FrontendAssetLoadingTest`, dan `tests/js/*` yang ada. Terakhir, full suite serial.

## 9. Kriteria penerimaan

- [ ] Logout di satu tab membuat semua tab lain di browser yang sama pindah ke login dalam hitungan detik, tanpa menunggu klik atau poll 30 detik. Fallback `storage` event bekerja.
- [ ] Tab dengan sesi yang dicabut atau habis pindah ke login paling lambat pada poll berikutnya (401).
- [ ] 403, 419, 5xx, dan error jaringan pada poll **tidak** mengeluarkan user.
- [ ] Setelah logout, antrean ekspor lokal dan key draft lama tanpa user id sudah hilang. Draft penawaran milik user tetap ada untuk user yang sama dan tidak terbaca user lain.
- [ ] Flag `logout_all_devices` on: checkbox muncul dan sesi lain dicabut; flag off: tidak ada perubahan.
- [ ] `POST /logout` tidak berubah, known-device cookie tetap, tidak ada `Clear-Site-Data`.
- [ ] Tanpa migrasi dan tanpa package baru. Teks baru ada di en dan id.

## 10. Urutan dan commit

1. Phase A → `feat(auth): cross-tab logout and session-ended redirect on 401`
2. Phase B → `feat(auth): scope and clear client storage around logout`
3. Phase C → `feat(auth): optional sign-out-all-devices on logout`
4. Dokumentasi → `docs(auth): logout cross-tab guide and AGENTS.md update`

## 11. Belum terverifikasi (wajib dicek di source/browser)

- Apakah `SessionGuard::logout()` bawaan Laravel memutar remember token (memengaruhi perangkat lain).
- bfcache: tombol Back setelah logout (header `no-store` sudah ada, tetapi tidak diuji).
- Perilaku `BroadcastChannel` di mode privat dan `storage` event lintas jendela.
- Perilaku Pusher/Echo setelah sesi berakhir; `Echo.disconnect()` cukup atau tidak.
- Apakah request polling selalu menerima 401 JSON (bukan redirect 302 ke login) di semua kondisi, termasuk `EnsureSsoSession`.
