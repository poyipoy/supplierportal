# Logout lintas tab

Portal tetap mengakhiri sesi secara sinkron melalui `POST /logout` dengan CSRF,
lalu mengarahkan ke halaman login. Tidak ada halaman perantara atau instruksi
untuk menunggu sebelum menutup tab. Ini bukan Single Logout antar aplikasi.

## Konfigurasi

| Environment | Key `portal_session` | Default | Perilaku |
|---|---|---|---|
| `PORTAL_CROSS_TAB_LOGOUT_ENABLED` | `cross_tab_logout` | `true` | Pesan logout lintas tab dan dialog sambungkan-kembali saat sesi berakhir (401/419) |
| `PORTAL_STORAGE_CLEANUP_ENABLED` | `scoped_storage_cleanup` | `true` | Hapus key lokal yang diizinkan saat logout/login |
| `PORTAL_LOGOUT_ALL_DEVICES_ENABLED` | `logout_all_devices` | `false` | Tampilkan dan proses checkbox keluar dari semua perangkat |
| `AUTH_IDLE_TIMEOUT` | `auth_security.session.idle_timeout_minutes` | `SESSION_LIFETIME` (60 di `.env.example`) | Batas diam (menit) aktivitas nyata sebelum sesi berakhir, di-clamp `<= min(SESSION_LIFETIME, AUTH_ABSOLUTE_TIMEOUT)` |
| `AUTH_ABSOLUTE_TIMEOUT` | `auth_security.session.absolute_timeout_minutes` | `480` | Batas durasi maksimum sesi (menit) meski pengguna aktif terus-menerus |
| `AUTH_SESSION_HEARTBEAT_SECONDS` | `auth_security.session.heartbeat_interval_seconds` | `120` | Jeda minimal throttled heartbeat (`POST session.heartbeat`) saat pengguna aktif |

Setelah mengubah environment pada deployment yang memakai cache konfigurasi,
bangun ulang `php artisan config:cache`. Tidak ada perubahan route atau migrasi.
Bundle JS baru harus ikut deployment (`npm.cmd run build` pada mesin build).
Jangan memasukkan `.env` atau kredensial ke Git.

## Cara kerja

Shell menerima `window.AdasiSession` berisi flag dan ID user dari server. Guest
dan shell dengan kedua flag browser mati menerima `null`. Halaman login guest
membawa flag melalui atribut `data-auth-login-page`; halaman publik lain tidak
mengirim pesan. URL login selalu berasal dari `route('login')`, sehingga prefix
portal dan hashid panjang tidak mengubah tujuannya.

Submit form navbar dengan `data-logout-form` mengirim `{type: 'signed-out'}`
melalui `BroadcastChannel('adasi-session')`. Jika API tidak tersedia atau gagal,
pengirim menulis key `adasi:session-event` berisi tipe dan timestamp, lalu segera
menghapusnya. Penerima juga mendengarkan event storage ketika BroadcastChannel
tersedia, agar dapat menerima fallback dari tab lain. Semua akses kedua API
ditangani dengan `try/catch`.

Penerima memutus Echo bila tersedia dan menavigasi ke URL login satu kali.
Halaman login guest mengirim pesan lagi setelah sesi browser berakhir.
Pesan `signed-out` berarti logout yang disengaja, jadi penerima tetap langsung
menavigasi. Pengecualiannya: pesan itu diabaikan selama dialog sesi berakhir
(bagian berikut) sedang terbuka atau sudah ditolak user.
Notifikasi dan chat memakai handler respons bersama: **hanya HTTP 401** dari polling
yang dianggap sesi berakhir. 403, 419, 5xx dan kegagalan jaringan pada polling
mempertahankan perilaku lama. Polling tetap 30 detik dan dilewati saat tab
tersembunyi; deteksi revokasi perangkat lain karena itu terjadi pada polling aktif
berikutnya. Jika kedua transport browser diblokir, polling menjadi fallback.

## Sesi berakhir: modal ERP & pelacakan aktivitas nyata

Sesi yang habis atau dicabut tidak lagi dibiarkan diam-diam hidup selamanya oleh polling badge dan tidak lagi menghancurkan form kotor secara tiba-tiba.

### Pemisahan aktivitas nyata vs passive polling

- `App\Support\Auth\SessionLifecycle` dan `App\Http\Middleware\StartPortalSession` (dibind pada `Illuminate\Session\Middleware\StartSession` di `AuthSecurityServiceProvider::register()`) memastikan route polling latar (`notifications.unread-count`, `conversations.unread-count`, `conversations.messages.latest`, `exports.status`, status impor/dokumen PO lokal, serta `session.status?passive=1`) **tidak** memperbarui `auth_last_activity_at`, **tidak** menulis ulang `sessions.last_activity`, dan **tidak** menyegarkan masa berlaku cookie sesi.
- Aktivitas nyata pengguna (klik, ketik, ubah field, submit di top shell maupun di dalam iframe Workspace Shell) disinkronkan lintas tab dan mengirim throttled `POST session/heartbeat` (`session.heartbeat`, middleware `auth`, `no-store`, `throttle:30,1`, CSRF) untuk memperpanjang `auth_last_activity_at` tanpa pernah melampaui `AUTH_ABSOLUTE_TIMEOUT`.
- `resources/js/session-sync.js` menjadwalkan timer proaktif berdasarkan sisa waktu dari server (`window.AdasiSession.timing`) dan segera memverifikasi ke `GET session/status?passive=1` saat deadline tercapai atau tab kembali aktif (`visibilitychange`, `focus`, `pageshow`, `online`).

### Perilaku modal `<x-ui.session-expired-modal>`

Saat sesi berakhir (idle timeout, absolute timeout, revokasi, deaktivasi, atau 401/419), top shell menampilkan `<x-ui.session-expired-modal>` (`role="alertdialog"`, `aria-modal="true"`, focus trap, token `--ui-dialog-bg` pada dark mode, dengan fallback ke `AdasiAlert`/`window.confirm` bila tidak terpasang):

- **Satu dialog sinkron lintas tab**: kegagalan paralel berbagi satu modal dan penemuan expiry disiarkan ke tab lain (`session-expired`).
- **Halaman bersih (hard-locked)**: tanpa tombol tutup `X`, tombol `Escape` dan klik backdrop diblokir.
- **Halaman atau iframe Workspace Shell dengan perubahan belum tersimpan**: modal menampilkan kotak peringatan + daftar judul tab dirty (`window.AdasiWorkspaceShell.getDirtyFrames()`) serta tombol **Tetap di Halaman (Salin Data)** yang menutup modal ke bilah pengingat atas permanen (`role="status"`) dengan tombol 1-klik **Sambungkan Kembali**.
- **Sambungkan Kembali (1-Klik Server-Side In-Place Reconnect, Tanpa Reload)** memanggil `POST session/reconnect` (`session.reconnect`, `SessionReconnectController` + `SessionReconnectService`, middleware `no-store`, `throttle:30,1`, dilindungi Same-Origin Custom-Header CSRF guard `X-Adasi-Reconnect: 1` + `X-Requested-With: XMLHttpRequest` + pemeriksaan `Sec-Fetch-Site`/`Origin`):
  - Server memverifikasi sesi aktif atau cookie `HttpOnly` terenkripsi `adasi_reconnect_grant` (`AES-256-CBC` + HMAC, terikat `uid`, `auth_session_version`, `session_id`, `device_hash`, dan `auth_absolute_started_at`).
  - Selama masih dalam jendela `AUTH_ABSOLUTE_TIMEOUT` (8 jam), akun aktif, `auth_session_version` cocok, dan sesi/perangkat belum di-revoke: server mengautentikasi ulang guard `web` di tempat (termasuk akun 2FA pada perangkat yang sama), merotasi session ID & CSRF token, mempertahankan `auth_absolute_started_at` asli, mereset `auth_last_activity_at`, mencatat audit `session_reconnected`, dan mengembalikan token CSRF baru beserta sisa waktu sesi.
  - Halaman **tidak dimuat ulang (`tanpa loading`)**, token CSRF baru disinkronkan ke top shell, seluruh iframe Workspace Shell, header auth Echo, dan token inline lama ditukar otomatis saat request same-origin. Polling lanjut, tab lain ikut pulih via broadcast `session-restored`, dan toast "Sesi tersambung kembali" tampil.
  - Jika sesi sudah tidak dapat di-reconnect (melewati 8 jam `AUTH_ABSOLUTE_TIMEOUT`, dicabut dari perangkat lain/admin, atau akun nonaktif): server mengembalikan `401 { reconnectable: false }` dan mengarahkan top window ke halaman login dengan `url.intended` terjaga.
- **Masuk Ulang (Tombol Sekunder)** mengarahkan top window ke `?reauth=1` sehingga server mengakhiri sesi penuh, mencabut grant reconnect, menyimpan `url.intended`, dan mengalihkan `302 /login`.
  - Jika server tidak terjangkau, `429`, atau `5xx`: pesan error tampil inline (`role="alert"`) di dalam modal agar operator dapat mencoba lagi.
- **Kebijakan ketat Remember Me (standar ERP / OWASP / NIST)**:
  - Saat `EnforceAuthSessionSecurity::terminateSession()` mengakhiri sesi akibat idle/absolute timeout, revokasi, atau deaktivasi, token per-perangkat di `auth_remember_tokens` untuk sesi tersebut ikut dicabut.
  - Passive poll, `session.status`, dan request JSON tidak pernah membangkitkan sesi secara diam-diam dari cookie Remember Me; jika recaller hadir saat sesi sudah habis, token recaller dicabut dan server mengembalikan `401 { authenticated: false, reason: 'idle_timeout' }`.
  - Kunjungan top-level HTML baru (non-JSON, non-passive, mis. membuka `/dashboard` di browser baru selama token belum dicabut) tetap mendukung Remember Me normal (termasuk challenge 2FA untuk akun MFA).
- **Perlindungan Workspace Shell**: iframe yang dialihkan 302 ke `[data-auth-login-page]` tidak memicu broadcast `signed-out` dan tidak langsung memanggil `breakoutToTop`; shell membuka `<x-ui.session-expired-modal>` sehingga tab lain dengan perubahan belum tersimpan tetap terlindungi.
- DataTables tidak menampilkan alert "Ajax error" sendiri untuk sesi berakhir; error tabel lain tetap memakai alert bawaan, dan halaman yang menetapkan `errMode` sendiri tidak diubah.
- Halaman guest tidak menampilkan dialog. Mengikuti flag `cross_tab_logout`: bila mati, tidak ada dialog dan tidak ada pengamat `fetch`/jQuery.

Batas verifikasi: perilaku ini diuji dengan mock (`tests/js/session-sync.test.mjs`),
belum di browser nyata dengan sesi yang benar-benar habis.

Saat navigasi akibat sesi berakhir, dialog native untuk perubahan belum
tersimpan tidak menghambat redirect. Handler halaman tetap berjalan agar draft
ber-scope yang memang disimpan halaman dapat bertahan. POST logout tidak
diganti dengan fetch atau link GET.

## Storage dan draft

Pembersihan hanya menghapus:

- `adasi:pending-export-jobs:v1`;
- key lama yang persis cocok dengan `^quotation_draft_\d+$`.

Pembersihan berjalan saat submit logout, penerimaan sesi berakhir, dan saat
login guest dirender. Draft registrasi, sessionStorage chat, preferensi sidebar,
cookie dan key lain dipertahankan. Tidak ada `Clear-Site-Data`.

Draft penawaran baru memakai `quotation_draft_<userId>_<prHash>` dan selalu
dibaca menggunakan identitas user aktif. Isi draft tidak diubah dan draft baru
bertahan saat logout. Scope key tetap berlaku bila flag cleanup dimatikan.

**Dampak deploy satu kali:** draft penawaran yang sedang dikerjakan dengan key
lama tidak dipulihkan oleh form baru; dari sisi pengguna draft lama hilang satu
kali. Source lama ternyata memakai hash PR, bukan angka seperti asumsi rencana.
Key hash lama tetap tersimpan tetapi menjadi orphan dan tidak dibaca. Key lama
numerik dihapus sesuai allowlist. Jangan memperluas pembersihan menjadi seluruh
prefix `quotation_draft_`, karena itu menghapus draft per user yang harus bertahan.

## Semua perangkat

Bila flag aktif, checkbox pada menu akun bersifat opsional dan tidak tercentang
secara default. Menu tetap terbuka ketika checkbox diklik. Backend memeriksa
flag dan `logout_all`, memanggil `SessionRevocationService::revokeOtherSessions`
sebelum logout lokal, lalu mengikuti redirect/splash existing. Tidak ada permintaan
password tambahan. Flag mati membuat parameter yang dikirim manual diabaikan.

Audit memakai event `other_device_logout`, metadata `guard=web` dan
`reason=logout_checkbox`, diikuti audit `logout`. Logger tidak mengizinkan key
`source`, sehingga dipakai `reason` existing. Cookie known-device dipertahankan.

Logout biasa juga memutar remember token Laravel bila token ada. Itu dapat
membatalkan pemulihan melalui recaller pada perangkat lain, tetapi tidak menaikkan
`auth_session_version` atau mencabut sesi aktif perangkat tersebut. Checkbox
menaikkan versi sehingga middleware menolak sesi perangkat lain berikutnya.

## Verifikasi dan rollback

Coverage: `tests/js/session-sync.test.mjs` dan
`tests/Feature/Auth/LogoutAllDevicesTest.php`, suite auth existing, navigation,
prefix, SSO, terjemahan dan asset loading. Pada Windows, jika worker Node
diblokir (`spawn EPERM`), gunakan
`node --experimental-test-isolation=none --test tests/js/session-sync.test.mjs`.
Jalankan file JS secara terpisah ketika isolation dimatikan.

Rollback perilaku browser: set kedua flag browser `false`, bangun ulang cache
konfigurasi. Matikan checkbox dengan `PORTAL_LOGOUT_ALL_DEVICES_ENABLED=false`.
Tidak ada data server yang perlu diubah atau migrasi yang perlu dibalik. Menonaktifkan
flag tidak mengembalikan draft numerik yang sudah dihapus atau mencabut revokasi
sesi yang sudah terjadi. Jika rollback source, ingat form lama tidak membaca key
draft baru yang sudah ber-scope user.

Uji browser masih diperlukan untuk dua tab, dua jendela/perangkat, mode privat,
Back/bfcache dan koneksi Pusher nyata. Dokumen hasil mencatat hasil eksekusi dan
batas verifikasi pada sesi implementasi; tes dengan mock tidak membuktikan
perilaku transport browser atau provider produksi.
