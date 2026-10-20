# Logout lintas tab

Portal tetap mengakhiri sesi secara sinkron melalui `POST /logout` dengan CSRF,
lalu mengarahkan ke halaman login. Tidak ada halaman perantara atau instruksi
untuk menunggu sebelum menutup tab. Ini bukan Single Logout antar aplikasi.

## Konfigurasi

| Environment | Key `portal_session` | Default | Perilaku |
|---|---|---|---|
| `PORTAL_CROSS_TAB_LOGOUT_ENABLED` | `cross_tab_logout` | `true` | Pesan logout lintas tab dan redirect pada polling 401 |
| `PORTAL_STORAGE_CLEANUP_ENABLED` | `scoped_storage_cleanup` | `true` | Hapus key lokal yang diizinkan saat logout/login |
| `PORTAL_LOGOUT_ALL_DEVICES_ENABLED` | `logout_all_devices` | `false` | Tampilkan dan proses checkbox keluar dari semua perangkat |

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
Notifikasi dan chat memakai handler respons bersama: **hanya HTTP 401** yang
mengirim `adasi:session-ended`. 403, 419, 5xx dan kegagalan jaringan mempertahankan
perilaku lama. Polling tetap 30 detik dan dilewati saat tab tersembunyi; deteksi
revokasi perangkat lain karena itu terjadi pada polling aktif berikutnya.
Jika kedua transport browser diblokir, polling menjadi fallback.

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
