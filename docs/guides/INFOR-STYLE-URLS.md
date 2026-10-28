# URL halaman gaya Infor

Fitur ini mengatur bentuk URL portal melalui tiga flag independen. Seluruhnya default `false`. Tidak ada migrasi atau package tambahan. Prefix, Hashids, dan UUID halaman adalah identitas URL; akses tetap diperiksa middleware, role, scope supplier, dan policy tujuan.

## Bentuk URL

| Kegunaan | Flag off | Semua flag on |
|---|---|---|
| Dashboard Purchasing | `/purchasing/dashboard` | `/v2/ADASI_PRD/purchasing/dashboard` |
| Detail PO | `/purchasing/purchase-orders/<hash pendek>` | `/v2/ADASI_PRD/purchasing/purchase-orders/<hash panjang>` |
| Menu daftar PO | `/purchasing/purchase-orders` | `/v2/ADASI_PRD/7c05c87f-d8ae-4f7d-8003-06b36d2ae324` → 302 ke daftar PO |
| Bookmark lama | URL tanpa prefix/hash pendek | 301 untuk prefix, kemudian 301 untuk hash bila diperlukan |

Mode C1 memakai UUID sebagai tautan menu. Sesudah 302, address bar menampilkan route halaman sebenarnya. Filter, pagination, server tabs, dan URL detail tetap memakai route bernama.

## Konfigurasi

Konfigurasi berada di `config/portal_urls.php`:

```dotenv
PORTAL_LONG_HASHIDS_ENABLED=false
PORTAL_LONG_HASHIDS_LENGTH=24
PORTAL_PATH_PREFIX_ENABLED=false
PORTAL_PATH_PREFIX=v2/ADASI_PRD
PORTAL_PAGE_REGISTRY_ENABLED=false
```

- Hashids panjang: panjang minimum 16–40 karakter, default 24. Koneksi `main` mempertahankan `HASHIDS_LENGTH` existing (default minimum 8); koneksi `long` memakai salt dan alfabet yang sama. Hashids melakukan padding minimum; panjang bukan pemotongan ID.
- Prefix: 1–4 segmen, total maksimum 64 karakter, dengan pola `^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+){0,3}$`. Tidak boleh ada slash awal/akhir, spasi, titik, atau percent encoding. Segmen pertama tidak boleh berbenturan dengan route publik/auth/protokol yang dipertahankan atau direktori di `public/`; bentrokan dibandingkan tanpa membedakan kapital. Konfigurasi tidak valid melempar `RuntimeException` saat boot ketika flag prefix aktif.
- Gunakan segmen khusus seperti `v2/ADASI_PRD` atau `v2/ADASI_TST`. Nama operasional seperti `purchasing` diperbolehkan, tetapi path yang juga cocok dengan route aktif akan mengikuti route aktif sebelum fallback kompatibilitas.
- Sebelum mengganti prefix yang pernah dipublikasikan, tambahkan nilainya ke `prefix.legacy_values` di `config/portal_urls.php`. Nilai default daftar ini adalah `['v2/ADASI_PRD']`; prefix terkonfigurasi saat ini juga dikenali untuk rollback.
- Prefix berasal dari server, termasuk `window.AdasiPortal.pathPrefix`. Jangan merangkai prefix literal di JavaScript.

Tetap di root: `/`, `/login` GET/POST, `/two-factor-challenge` GET/POST, `/logout`, `/confirm-password` GET/POST, `/password`, `/oauth/*` termasuk `/oauth/token`, `/sso/callback`, `/verify-receipt/*`, `/locale/*`, `/up`, dan `/broadcasting/auth`. Halaman serta aksi profile terautentikasi mengikuti prefix, begitu pula `/forgot-password`, `/supplier/register` dan `/supplier/registration/*` (GET lama dialihkan 301; POST lama tidak dialihkan, jadi form yang terbuka saat deploy perlu dimuat ulang). Nama route tidak berubah.

## Aktivasi dan cache

Uji setiap langkah di staging dengan prefix staging sebelum mengaktifkannya pada produksi. Sesuaikan aturan path proxy/WAF dan pemantauan bila ada.

1. Deploy kode dengan semua flag `false`.
2. Aktifkan `PORTAL_LONG_HASHIDS_ENABLED=true`; periksa detail record, notifikasi lama, dan filter tersimpan.
3. Aktifkan `PORTAL_PATH_PREFIX_ENABLED=true`; periksa seluruh role, SSO/MFA, forced password change, ekspor, server tabs, registrasi, dan QR receipt yang sudah tercetak.
4. Aktifkan `PORTAL_PAGE_REGISTRY_ENABLED=true`; periksa tautan sidebar dan Quick Access.

Setelah setiap perubahan flag/prefix:

```powershell
php artisan config:cache
php artisan route:cache
php artisan route:list --no-ansi
```

Prefix dan URI alias registry ditentukan saat route didaftarkan. Mengubah `.env` saja tidak memperbarui route cache. Cache config wajib dibangun lebih dahulu. Restart worker sesuai proses deployment existing agar konfigurasi URL pada job berikutnya ikut diperbarui. Tidak perlu memasang ulang client SSO karena callback tetap di root.

Untuk pengembangan tanpa cache setelah mengganti flag:

```powershell
php artisan route:clear
php artisan config:clear
```

## Transisi dan rollback

`HashidCodec` selalu menerima hash dari `main` dan `long`, apa pun flag aktifnya, dengan validasi tepat satu integer positif dan round-trip. GET/HEAD dengan format nonkanonik mendapat 301 ke format aktif, termasuk implicit binding. POST/PUT/PATCH/DELETE menerima format hash nonkanonik tanpa redirect. Parameter integer mentah tetap 404.

Fallback path hanya GET/HEAD: URL lama yang cocok dengan route aktif mendapat 301 dengan query asli dipertahankan. Path tidak dikenal tetap 404. Fallback tidak menjalankan aksi atau otorisasi tujuan; permintaan berikutnya ke route tujuan menjalankan seluruh middleware/policy. Metode mutasi ke path lama tidak di-redirect dan dapat mendapat 404/405; muat ulang form setelah perubahan prefix.

Rollback: kembalikan flag yang diperlukan ke `false`, pertahankan daftar prefix terdahulu, lalu bangun kembali kedua cache di atas. Hash panjang tetap diterima dan GET/HEAD diarahkan ke hash pendek. URL dengan prefix yang dikenal diarahkan ke path aktif tanpa prefix.

Mematikan flag registry menghentikan pembentukan tautan UUID baru di menu; route `portal.page` tetap menerima UUID yang sudah dipublikasikan, dengan auth dan 302 ke route tujuan seperti semula. Prefix historis tetap dinormalisasi oleh fallback. Setelah URL dipublikasikan, pertahankan juga `HASHIDS_LENGTH` dan `PORTAL_LONG_HASHIDS_LENGTH`; mengubah panjang koneksi membuat format sebelumnya pada koneksi tersebut tidak terbaca.

**Jangan mengganti `HASHIDS_SALT` atau alfabet setelah URL dipublikasikan.** Memperpanjang hash tidak memerlukan salt baru. Salt produksi di bawah 32 karakter hanya menghasilkan warning; salt kosong/default/tes tetap ditolak oleh guard produksi existing. Jangan mengatasi warning dengan rotasi salt mendadak.

Notifikasi tersimpan dinormalisasi saat dibaca tanpa memperbarui data DB. Resolver tetap memeriksa domain, role, ownership, dan policy yang sudah berlaku.

## Menambah halaman ke registry

1. Pastikan destination adalah route GET yang sudah bernama dan tidak mempunyai parameter wajib.
2. Buat UUID v4 satu kali, misalnya `[guid]::NewGuid().ToString()` pada PowerShell.
3. Tambahkan literal `'uuid' => 'nama.route'` ke `config/page_registry.php`. Pertahankan UUID tersebut bila destination route diganti nama; jangan membuat UUID saat runtime atau mengubah UUID existing.
4. Gunakan `PageRegistry::url('nama.route')` untuk menu. Pemanggil yang sebelumnya memakai URL Purchasing tersimpan boleh memberikan fallback sebagai argumen kedua agar perilaku flag-off tetap sama.
5. Jalankan `PageRegistryTest`, build bila Blade berubah, dan bangun ulang cache route/config.

Jangan memasukkan `pageUuid` ke `DecodeHashids::HASHED_PARAM_KEYS`. UUID halaman bukan ID record. Controller registry hanya melakukan 302; jangan mengubahnya menjadi dispatch internal yang dapat melewati middleware tujuan.

## Troubleshooting 301/404

| Gejala | Pemeriksaan |
|---|---|
| Path baru masih 404 setelah aktivasi | Pastikan config cache dibangun sebelum route cache, pada direktori/release yang benar. Periksa `route:list` dan konfigurasi proxy/base path. |
| URL lama dengan prefix custom 404 setelah rollback | Tambahkan prefix historis tepat ke `prefix.legacy_values`, lalu bangun ulang cache. |
| Hash lama dan baru sama-sama 404 | Pastikan salt/alfabet existing tidak berubah dan record masih ada/visible. Jangan mengizinkan integer mentah untuk mengatasi 404. |
| Dua hop 301 untuk bookmark lama | Normal: satu hop untuk prefix dan satu untuk hash. Simpan ulang bookmark kanonik bila diinginkan. |
| POST ke halaman lama gagal | Path mutasi tidak di-redirect. Muat ulang halaman agar form memakai named route terbaru. |
| UUID menghasilkan 302 lalu 403/404 | Periksa role, scope, dan policy route tujuan. UUID tidak memberikan hak akses. |
| URL UUID tidak tetap di address bar | Ini perilaku C1. Route halaman sebenarnya tampil setelah 302. |

Laporan verifikasi lokal dan keterbatasan browser/staging/produksi berada di [hasil implementasi](../results/INFOR-STYLE-URLS-RESULT-20261020.md).
