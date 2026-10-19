# Login OAuth + PKCE dalam aplikasi

Portal dapat menjalankan authorization server Passport dan client portal di aplikasi,
origin, database, dan sesi Laravel yang sama. `/login` memulai transaksi, form lama
ditampilkan di `/oauth/authorize`, lalu `/sso/callback` memverifikasi hasil dan kembali
ke halaman tujuan. Ini OAuth 2.0 Authorization Code + PKCE S256; scope `openid` adalah
scope aplikasi dan **tidak** menambahkan OpenID Connect, ID Token, discovery atau federasi.

## Konfigurasi

```dotenv
SSO_LOGIN_ENABLED=false
SSO_CLIENT_ID=
SSO_STATE_PREFIX=ADASI
```

`config/sso.php` menetapkan TTL transaksi 600 detik, maksimum lima transaksi pending,
dan scope `openid`. Prefix hanya huruf/angka/underscore/tanda minus, maksimal 32 karakter.
State/verifier dibuat dengan `random_bytes`, disimpan di sesi server dan tidak dicatat
ke audit. Halaman tujuan disimpan per transaksi dan disaring dengan helper intended
existing serta filter khusus SSO yang mencegah tujuan berulang ke endpoint autentikasi.

Flag default nonaktif. Nama `login` dan `login.store`, validasi kredensial, CAPTCHA,
rate limiter, 2FA, known devices, pembatasan sesi, pergantian password wajib, RBAC,
supplier scope, dan proteksi sesi tetap dipakai. Tidak ada guard API baru.

## Persiapan deployment

1. Backup database dan konfigurasi. Uji pada staging hasil restore sebelum produksi.
2. Deploy kode dan jalankan `composer install --no-dev --prefer-dist --optimize-autoloader`.
   `laravel/passport` dipin **13.10.0** di Composer; jangan menjalankan `composer update`
   pada server. Referensi: [dokumentasi Passport Laravel 12](https://laravel.com/docs/12.x/passport).
3. Periksa ledger dengan `php artisan migrate:status`. Tinjau seluruh migrasi pending,
   lalu jalankan migrasi deployment sesuai prosedur operator. Lima migrasi Passport
   hanya membuat `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`,
   `oauth_clients`, dan `oauth_device_codes` pada database portal. Tidak ada database baru.
   **Keberadaan file migrasi bukan bukti sudah diterapkan.**
4. Tetapkan `APP_URL` ke origin HTTPS portal yang kanonik. URI callback yang terdaftar
   harus identik dengan `APP_URL` + `/sso/callback`, termasuk scheme, host, port, dan path.
   Konfigurasikan trusted proxies dan cookie secure sesuai deployment existing.
5. Siapkan kunci sebelum mengaktifkan fitur. Pastikan cache mendukung atomic locks
   (database/file/Redis), session driver server-side, dan semua node memakai key serta
   session/cache bersama yang sesuai. Route authorize/callback memakai session blocking.

### Kunci penandatanganan

Untuk instalasi pertama dengan berkas lokal:

```powershell
php artisan passport:keys
```

Perintah default menghasilkan RSA 4096-bit pada `storage/oauth-private.key` dan
`storage/oauth-public.key`; jangan memakai `--force` pada kunci aktif tanpa rencana rotasi.
Pada Linux produksi atur private key mode **600** dan hak baca hanya untuk proses aplikasi;
public key cukup read-only sesuai kebutuhan. Pada Windows gunakan ACL yang setara.
`/storage/*.key` sudah diabaikan Git. Jangan commit, menyalin ke laporan, atau mencetak kunci.

Alternatif produksi: simpan PEM pada secret manager/environment sebagai
`PASSPORT_PRIVATE_KEY` dan `PASSPORT_PUBLIC_KEY`. Konfigurasi bawaan package sudah
memuat kedua env ini; tidak perlu publish config Passport. Escape `\n` didukung.
Kunci harus stabil antar request/node dan cocok sebagai pasangan. APP_KEY existing
juga dipakai Passport untuk mengenkripsi authorization code; jangan mengubahnya.

### Client portal

```powershell
php artisan sso:install-client
```

Perintah membuat public client authorization-code + refresh-token tanpa client secret,
atau memakai ulang client yang identik. Salin output `SSO_CLIENT_ID=...` ke environment.
Perintah tidak mengedit `.env`. Bila ID sudah dikonfigurasi, client tersebut harus aktif,
public, first-party, memiliki redirect persis dan grant yang sesuai. Konfigurasi yang
berbeda/duplikat diblokir; perintah tidak memperbaiki atau mencabut client lain diam-diam.
Lock cache melindungi instalasi bersamaan. Pembuatan client adalah mutasi database
deployment yang disengaja, bukan langkah verifikasi read-only.

Aktifkan `SSO_LOGIN_ENABLED=true`, lalu perbarui cache konfigurasi sesuai prosedur
deployment (`php artisan config:cache`). Buka `/login`: browser mengikuti redirect ke
authorization URL, form tetap POST ke `/login`, dan setelah password/MFA callback
mengembalikan user ke deep link atau dashboard. Sesi existing tanpa bukti SSO menjalankan
transaksi baru langsung, tanpa melewati form kredensial lagi. Request JSON/polling pada
sesi tersebut mendapat 401 sampai navigasi dokumen menyelesaikan transaksi.

## Token, audit dan endpoint

- Hanya client yang ID-nya sama dengan `SSO_CLIENT_ID` melewati consent. Client lain
  mendapatkan consent eksplisit. Ini belum merupakan kontrak integrasi aplikasi lain.
- `/oauth/authorize` memvalidasi client, redirect persis, response `code`, dan S256 sebelum
  form tampil. Request tidak valid mendapat HTML 400 tanpa `Location`.
- Callback menukar code **in-process** melalui League AuthorizationServer, tanpa HTTP ke
  portal sendiri. JWT RS256 diverifikasi (signature/expiry/sub/aud) dan dicocokkan dengan
  token tersimpan/user sesi. Issuance, pemeriksaan dan revocation berada dalam satu
  transaksi database portal. Access/refresh token langsung dicabut; sesi Laravel adalah
  kredensial operasional. Gagal exchange/validasi/revocation tidak menghasilkan proof SSO.
- Route standar `POST /oauth/token` berada di luar web group, tanpa sesi/CSRF dan dengan
  throttle. Respons protokol standar adalah JSON OAuth; callback portal tidak meneruskan
  token atau verifier ke browser/JSON aplikasi. Jangan mencatat request/response endpoint ini.
- Event audit: `sso_authorization_completed`, `sso_state_rejected`, `sso_subject_mismatch`,
  `sso_invalid_authorization_request`. Metadata hanya client ID dan reason code tetap.
  Jangan log code, state, verifier, access/refresh token, JWT, request URL penuh, atau
  exception OAuth mentah. Halaman OAuth/callback memakai no-store dan no-referrer.
- Konfigurasikan access log Apache/Nginx/reverse proxy/observability agar membuang query
  string untuk `/oauth/*` dan `/sso/*` serta menyensor body token endpoint. Header browser
  tidak menyensor log server. Periksa juga request collector/APM sebelum rollout.

## Scheduler dan rollback

`passport:purge` dijadwalkan 02:40 pada `app.business_timezone`, `withoutOverlapping`,
hanya ketika fitur aktif. Pertahankan cron `schedule:run` existing. Default Passport
membersihkan token/code revoked dan token/code yang kedaluwarsa lebih dari 168 jam.

Rollback perilaku login:

```dotenv
SSO_LOGIN_ENABLED=false
```

```powershell
php artisan config:clear
```

Login kembali ke alur lama. Tabel OAuth dan kunci dapat tetap tersedia; tidak perlu
rollback/drop tabel untuk rollback perilaku. Endpoint OAuth/callback berhenti melayani
flow ketika flag nonaktif. Jangan rollback migrasi pada data aktif tanpa analisis terpisah.

## Troubleshooting dan verifikasi

- Error 400 authorize: periksa client aktif, redirect persis, response `code`, scope dan
  challenge S256. `prompt` belum didukung oleh flow ini dan ditolak.
- Error 503 entry: client ID/prefix konfigurasi belum lengkap. Periksa config cache.
- Restart setelah callback: transaksi habis 600 detik, state/code dipakai ulang, verifier
  tidak cocok, session/cookie hilang, key tidak cocok atau token tidak dapat dicabut.
  Periksa audit reason code tanpa menyalin parameter rahasia.
- Remember-cookie user dengan MFA menginvalidasi sesi lama sebelum challenge; transaksi
  lama hilang dan OAuth dimulai ulang dengan aman setelah MFA. Ini perilaku yang disengaja.
- Password sementara tetap diarahkan ke Security. Sesudah diganti, navigasi portal
  berikutnya menyelesaikan transaksi baru dengan halaman tujuan awal.
- Pada banyak tab, lima transaksi terbaru dapat diselesaikan terpisah; tab lebih lama
  perlu restart. Session blocking mencegah dua callback bersamaan memakan state yang sama.

Tes berjalan serial pada MySQL `adasi_portal_test` setelah safety guard. Suite SSO membuat
RSA sementara di memory, tanpa bergantung pada key developer. Jalankan Auth, store/flow,
isolation/hashid/registrasi, terjemahan, business time, asset, lint/Pint, build dan full suite
sesuai objective. Browser nyata, multi-tab/back, Apache dan `artisan serve`, HTTPS/cookie,
cache locks lintas node, staging dan deployment produksi harus dicatat sebagai bukti terpisah.
