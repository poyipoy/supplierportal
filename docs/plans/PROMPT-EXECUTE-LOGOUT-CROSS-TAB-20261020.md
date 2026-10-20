# Prompt Eksekusi (One-Shot) — Logout Lintas Tab dan Pembersihan Storage

> Salin seluruh blok di bawah ini ke Claude Code di proyek lokal. Prompt ini ditulis lengkap agar agent menyelesaikan pekerjaan dari awal sampai akhir tanpa berhenti untuk bertanya.

---

```text
Kamu mengimplementasikan perbaikan logout di ADASI Portal Supplier: logout lintas tab, redirect otomatis saat polling menerima 401, pembersihan localStorage yang tidak terikat user, dan checkbox opsional "keluar dari semua perangkat". Kerjakan SAMPAI SELESAI dalam satu sesi. Rencana sudah disetujui, jadi jangan berhenti untuk minta persetujuan. Berhenti dan tanya HANYA bila terjadi salah satu kondisi di bagian "STOP CONDITIONS". PENTING: jangan menambahkan pesan "jangan tutup tab" atau halaman interstisial; portal ini satu aplikasi dan logout-nya sudah selesai seketika.

## 0. Wajib dibaca dulu (berurutan)
1. AGENTS.md (seluruhnya) beserta file yang dirujuknya untuk inisialisasi konteks.
2. docs/plans/IMPLEMENTATION-PLAN-LOGOUT-CROSS-TAB-20261020.md. Ini SPESIFIKASI UTAMA. Ikuti flag (§2), Phase A/B/C (§3–§5), invarian (§6), tes (§8), kriteria penerimaan (§9), urutan commit (§10), dan daftar yang belum terverifikasi (§11).
3. reports/Logout Infor dan single logout.md dan research_notes/Logout Infor dan single logout/adasi_logout_options.md. Keduanya konteks, bukan spesifikasi.
4. Kode yang disentuh beserta tesnya:
   - routes/web.php (route logout, logout-other-devices, profile sessions), bootstrap/app.php (penanganan AuthenticationException/JSON 401 dan jalur polling)
   - app/Http/Controllers/Auth/AuthenticatedSessionController.php
   - app/Services/Auth/{SessionRevocationService,SessionInventoryService}.php, app/Listeners/LogAuthenticationEvent.php, app/Services/Auth/AuthAuditLogger.php
   - app/Http/Middleware/{EnforceAuthSessionSecurity,EnsureSsoSession,EnforcePasswordChange,AddSecurityHeaders,ShareAuthSplashState}.php
   - resources/views/layouts/app.blade.php (updateBadges ≈L415-509, inisialisasi Echo ≈L788-1140), resources/views/layouts/auth.blade.php, resources/views/auth/login.blade.php, resources/views/partials/navbar.blade.php
   - public/assets/js/async-export.js (key adasi:pending-export-jobs:v1)
   - resources/views/supplier/quotations/create.blade.php (draftKey)
   - resources/js/app.js, resources/js/unsaved-changes.js (contoh pola modul), tests/js/ (pola tes)
   - lang/{en,id}/navigation.php, lang/{en,id}/js.php, app/Support/JsTranslations.php
   - tests/Feature/Auth/*, tests/Feature/UserAccountNavigationTest.php
Nomor baris di rencana adalah perkiraan dari master 1c4ab2c. Cocokkan dengan kode terbaru. Bila berbeda, kode/migrasi/tes yang menang; sesuaikan dan catat di laporan akhir.

## 1. Persiapan
- `git status`. Pertahankan perubahan user yang sudah ada; jangan reset/clean/stash milik user.
- `git checkout master && git pull`, lalu buat branch `feature/logout-cross-tab`.
- Pastikan database tes aman: `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest`.
- Rekam baseline sebelum mengubah apa pun:
  `php artisan test tests/Feature/Auth --compact`
  `php artisan test --filter=UserAccountNavigationTest`
  `node --test tests/js/preferences.test.mjs`
  `node --test tests/js/unsaved-changes-confirmed-leave.test.mjs`

## 2. Verifikasi dulu (jangan mengandalkan ingatan)
- Baca vendor/laravel/framework: SessionGuard::logout() (apakah memutar remember token; apa efeknya ke perangkat lain), dan Authenticate/handler AuthenticationException untuk request JSON vs HTML.
- Baca kode portal: pastikan route polling (notifications.unread-count, conversations.unread-count) membalas JSON 401 saat sesi tidak ada, termasuk saat EnsureSsoSession aktif. Bila polling ternyata menerima redirect 302 ke login (bukan 401), catat dan sesuaikan JS agar menganggap response.redirected ke URL login sebagai sesi berakhir; jangan ubah semantik server kecuali tidak ada cara lain.
- Cari cara file JS di resources/js dimuat (Vite) dan cara tes `node --test` mengimpor modul tanpa DOM; ikuti pola tersebut.
Catat temuan yang berbeda dari asumsi rencana.

## 3. Implementasi (urutan: Phase A → B → C → dokumentasi; satu commit per phase, pesan commit sesuai §10 rencana)
Ikuti §3–§5 rencana secara persis. Aturan keras:
- config/portal_session.php dengan tiga flag (cross_tab_logout true, scoped_storage_cleanup true, logout_all_devices false) dan placeholder di .env.example. Nilai disuntikkan dari server ke window.AdasiSession ({crossTab, cleanup, userId}); kosong untuk guest atau saat flag off.
- Logika murni (penentuan redirect, filter key yang dibersihkan, penanganan status HTTP) dipisah dari DOM agar bisa dites dengan node --test.
- Semua akses BroadcastChannel/localStorage dibungkus try/catch. Fallback event `storage` bila BroadcastChannel tidak ada. Pesan tidak membawa data sensitif.
- URL login untuk redirect disuntikkan dari server (route('login')); JS tidak boleh membangun path sendiri (fitur prefix path dan hashid panjang sudah ada).
- Redirect pada poll HANYA untuk 401 (atau redirect ke login bila itu hasil verifikasi §2). 403, 419, 5xx, dan error jaringan tetap diabaikan seperti sekarang. Guard satu kali agar tidak loop.
- Halaman guest /login boleh memicu pesan `signed-out` karena halaman itu hanya tampil bila sesi browser tidak terautentikasi.
- Pembersihan storage hanya menghapus: adasi:pending-export-jobs:v1 dan key lama berpola ^quotation_draft_\d+$. Draft penawaran baru memakai quotation_draft_<userId>_<prId>. JANGAN menyentuh draft registrasi supplier, sessionStorage chat, preferensi sidebar, atau cookie.
- POST /logout + CSRF tetap satu-satunya cara logout. Tidak ada route GET logout. Cookie adasi_known_device tidak dihapus. Tidak ada Clear-Site-Data. Header no-store/CSP tidak berubah.
- Checkbox logout_all hanya dirender dan hanya diproses bila flag on. Urutan: revokeOtherSessions (baca implementasinya) lalu logout lokal. Audit memakai mekanisme yang sudah ada.
- Teks UI baru berpasangan di lang/en dan lang/id (navigation.php untuk Blade, js.php untuk JS via AdasiI18n). Jangan menulis teks langsung di Blade/JS. Ikuti kebijakan istilah UI Language di AGENTS.md.
- Tidak ada migrasi, tidak ada package baru, dan tidak ada refactor di luar daftar file rencana.
- JANGAN menjalankan migrate/migrate:fresh/rollback/db:wipe terhadap database aplikasi.

## 4. Tes yang wajib dibuat (lihat §8 rencana)
- tests/js/session-sync.test.mjs: pesan signed-out → redirect satu kali; fallback storage event; 401 → redirect; 403/419/500/error jaringan → tidak redirect; Echo.disconnect dipanggil bila ada; pembersihan hanya key yang dimaksud; flag off → tidak berbuat apa pun; storage yang melempar exception tidak menimbulkan error.
- tests/Feature/Auth/LogoutAllDevicesTest.php: checkbox hanya saat flag on; logout_all=1 menaikkan auth_session_version dan sesi perangkat lain tidak valid; tanpa parameter = logout biasa; flag off mengabaikan parameter; audit tercatat; tetap redirect ke login.
- Tes tampilan: halaman login memuat data-auth-login-page; form logout memuat data-logout-form; key draft penawaran memuat user id.
Tes lama tidak boleh diubah kecuali benar-benar terdampak markup form navbar; jelaskan setiap perubahan tes lama di laporan.

## 5. Verifikasi (jalankan semuanya, perbaiki sampai hijau)
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test --filter=LogoutAllDevicesTest
php artisan test tests/Feature/Auth --compact
php artisan test --filter=UserAccountNavigationTest
php artisan test --filter=PortalPathPrefixTest
php artisan test --filter=SsoAuthorizationFlowTest
php artisan test tests/Unit/TranslationParityTest.php
php artisan test --filter=UserFacingCopyInventoryTest
php artisan test --filter=FrontendAssetLoadingTest
node --test tests/js/session-sync.test.mjs
node --test tests/js/preferences.test.mjs
node --test tests/js/unsaved-changes-confirmed-leave.test.mjs
php -l <setiap file PHP yang diubah/dibuat>
php vendor/bin/pint --test <setiap file PHP yang diubah/dibuat>
php artisan view:cache
npm.cmd run build
git diff --check
Setelah semua hijau, jalankan `composer test` sekali secara serial (jangan paralel) dan bandingkan dengan baseline. Kegagalan hanya boleh disebut pre-existing bila juga gagal di baseline atau di checkout bersih master.

Simulasi manual di browser (Laragon), bila tersedia:
1. Login di dua tab. Logout di tab 1: tab 2 harus pindah ke login dalam hitungan detik.
2. Login di dua tab, lalu cabut sesi dari perangkat/browser lain (logout-other-devices): tab itu pindah ke login paling lambat pada poll berikutnya (30 detik).
3. Buat antrean ekspor async lalu logout: key adasi:pending-export-jobs:v1 hilang. Cek draft penawaran: key baru memuat user id dan tidak terbaca user lain.
4. Nyalakan PORTAL_LOGOUT_ALL_DEVICES_ENABLED: checkbox muncul, dan sesi di browser lain mati setelah logout. Kembalikan flag ke default.
Bila browser tidak tersedia, tandai sebagai belum terverifikasi.

## 6. Dokumentasi
- docs/guides/LOGOUT-CROSS-TAB.md: tujuan, flag, cara kerja (BroadcastChannel + fallback + 401 poll), data yang dibersihkan, catatan bahwa draft penawaran yang sedang dikerjakan saat deploy hilang satu kali, rollback (matikan flag), dan batasan (tidak ada SLO antar aplikasi, tidak ada Clear-Site-Data).
- AGENTS.md: tambahkan ringkasan singkat di bagian Auth dan Konfigurasi (flag, perilaku lintas tab, key storage yang dibersihkan) dan tes baru di tabel coverage. Tulis satu kali, tanpa duplikasi, dan jangan mengubah CLAUDE.md.
- Ubah status di docs/plans/IMPLEMENTATION-PLAN-LOGOUT-CROSS-TAB-20261020.md menjadi "diimplementasikan".
- Tulis laporan hasil ke docs/results/LOGOUT-CROSS-TAB-RESULT-<tanggal>.md.

## 7. Commit
Commit per phase di branch feature/logout-cross-tab (lihat §10 rencana). JANGAN push dan JANGAN membuat PR; user akan me-review dulu.

## STOP CONDITIONS (hanya ini yang boleh membuatmu berhenti dan bertanya)
- Database tes tidak aman atau tidak tersedia.
- Polling tidak mungkin mendeteksi sesi berakhir tanpa mengubah semantik autentikasi server secara luas.
- Pembersihan storage atau sinkron lintas tab ternyata mengharuskan perubahan pada alur POST /logout, cookie known-device, atau header keamanan.
- Perubahan menuntut modifikasi logika bisnis domain (Impor, Local Supplier, payment).
Untuk hambatan lain, pilih opsi yang paling aman dan paling kecil, lalu catat di laporan.

## 8. Laporan akhir (format wajib)
1. Ringkasan: yang dibangun, cara kerja, dan cara mengaktifkan/mematikan tiap flag.
2. Temuan verifikasi §2 dan perbedaan dari rencana.
3. Daftar file dibuat/diubah, dikelompokkan per phase.
4. Hasil verifikasi, dipisah menjadi: diverifikasi lewat eksekusi (dengan output ringkas), diinspeksi, inferensi, dan belum diverifikasi (browser lintas tab, mode privat, bfcache, Pusher setelah sesi berakhir, staging, produksi).
5. Perbandingan dengan baseline tes.
6. Keputusan yang diambil sendiri beserta alasannya, termasuk setiap tes lama yang diubah.
7. Risiko tersisa dan langkah deploy untuk user.
```
