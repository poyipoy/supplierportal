# Riset URL login internal tanpa penyedia identitas eksternal

Tanggal: 19 Oktober 2026. Status: riset dan usulan, belum implementasi autentikasi.

## Kesimpulan

**Bisa.** URL login dapat memuat identitas transaksi internal, masa berlaku, dan tanda tangan tanpa Google, Infor, atau layanan SSO eksternal. Laravel yang sudah terpasang menyediakan primitive signed URL. Manfaat tambahan muncul dari pemeriksaan transaksi di server, bukan banyaknya karakter URL.

Untuk portal ini, manfaat yang paling masuk akal adalah mengikat konteks tujuan dan tahap MFA pada satu percobaan login, menolak alur lama yang sudah selesai, serta menghubungkan kejadian audit. Sebagian proteksi yang mungkin diklaim sebagai manfaat baru ternyata sudah tersedia. Belum ditemukan kebutuhan terverifikasi yang mengharuskan perubahan login; tidak tepat menyatakan login sekarang tidak aman hanya karena URL-nya pendek.

## Pertanyaan dan metode

Riset menjawab lima pertanyaan:

1. Apa yang dapat dijamin signed URL bawaan Laravel?
2. Apa manfaat tambahan ID alur lokal dibanding session dan CSRF yang sudah ada?
3. Apa risiko membawa informasi autentikasi di URL?
4. Apakah PKCE/OIDC atau server identitas sendiri diperlukan?
5. Apa perubahan terkecil yang masuk akal untuk proyek ini?

Metode: penelusuran web terarah, pembacaan sumber primer Laravel/OWASP/IETF/W3C, inspeksi source aplikasi dan framework terpasang, serta eksperimen CLI terisolasi. Search-first digunakan untuk mencari primitive yang sudah tersedia sebelum mengusulkan custom code. Satu peneliti tambahan memeriksa batas klaim secara independen. Firecrawl/Exa tidak tersedia pada tool sesi ini; kanal riset memakai web search/open. Tidak dilakukan pencarian registry paket karena kebutuhan inti sudah tercakup oleh Laravel terpasang, dan tidak ada usulan instalasi paket.

Key query: `Laravel 12 signed URLs`, `OWASP login CSRF pre-session`, `OWASP session ID URL`, `RFC 9700 PKCE state`, dan penelusuran lanjutan pada dokumentasi autentikasi/referrer. Standar lama dipakai ketika masih menjadi spesifikasi yang relevan; kebaruan tanggal artikel tidak menggantikan kontrak resmi.

## Fakta implementasi saat diperiksa

Framework terpasang melaporkan Laravel **12.69.3** melalui `Application::VERSION`.

| Komponen | Temuan inspeksi |
|---|---|
| `routes/web.php` | GET/POST `/login` memakai middleware guest dan no-store. Tidak ada alur OIDC lokal pada route autentikasi yang diperiksa. |
| `resources/views/auth/login.blade.php` | Form POST berisi `@csrf`. Middleware web bawaan framework menyertakan `ValidateCsrfToken`; bootstrap tidak mengecualikan login. |
| `LoginRequest::authenticate()` | Memeriksa email/password, akun aktif dan status ACTIVE; memakai limiter yang sudah ada. |
| `AuthenticatedSessionController::store()` | Memulai MFA bila aktif; jika tidak, memanggil `CompleteLoginService`, lalu memilih intended URL lewat pemeriksaan yang sudah ada. |
| `CompleteLoginService::complete()` | Membuat autentikasi session, meregenerasi ID session, menyimpan versi/waktu autentikasi, dan menjalankan kontrol perangkat/session. |
| `TwoFactorService` | Satu objek pending pada key `auth.two_factor_pending`, berisi user, remember, intended dan started_at; kode TOTP/recovery diperiksa dengan row lock. |
| `EnsurePendingTwoFactorChallenge` dan config | Pending MFA dibatasi 600 detik; ini bukan manfaat baru yang harus ditambahkan lewat URL. |
| `TwoFactorService::safeIntendedUrl()` | Memeriksa asal URL dan menolak kategori tujuan tertentu. Belum diaudit menyeluruh terhadap seluruh edge case URL. |
| `AuthAuditLogger` | Audit sudah ada. Metadata memakai allowlist yang saat ini tidak memuat `flow_id`; menambahkan korelasi memerlukan perubahan eksplisit. |
| `AddSecurityHeaders` | HTML menggunakan `strict-origin-when-cross-origin`, frame protection dan CSP; cache dynamic dicegah kecuali respons public. |
| `config/session.php` | Konfigurasi produksi menetapkan session encryption, Secure, HttpOnly dan SameSite Lax. Konfigurasi file bukan bukti deployment aktif. |

Tes yang diinspeksi mencakup intended URL, expired MFA, penolakan replay TOTP dan keamanan session. Suite aplikasi tidak dijalankan pada riset ini. Keberadaan tes bukan bukti semua skenario tersebut lulus saat ini.

**Inferensi terbatas:** satu key pending MFA dan satu intended URL per session belum merupakan pemodelan eksplisit banyak transaksi login. Dua tab dapat saling memengaruhi konteks jika memulai alur berbeda. Ini kandidat pengujian UX, bukan bug atau eksploitasi yang telah direproduksi. Tab dalam profil browser yang sama biasanya berbagi cookie autentikasi; ID flow tidak otomatis menyediakan akun terpisah per tab.

## Signed URL: manfaat dan batas yang dibuktikan

Laravel menyatakan signature membantu memeriksa URL yang “has not been modified since it was created”. `temporarySignedRoute()` juga memeriksa kedaluwarsa. Ini berguna untuk konteks yang memang perlu dilindungi dari perubahan. [Dokumentasi Laravel 12](https://laravel.com/docs/12.x/urls#signed-urls).

Source terpasang `UrlGenerator::hasValidSignature()` hanya menggabungkan pemeriksaan signature dan expiry. Tidak ada konsumsi token, pengecekan cookie atau pembatasan aktor dalam helper tersebut. [Implementasi upstream Laravel](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Routing/UrlGenerator.php#L425).

Eksperimen CLI memuat `vendor/autoload.php`, membuat route sementara dalam memori, dan memakai key uji sekali pakai. Tidak memuat bootstrap aplikasi atau `.env`, tidak mengakses database, dan tidak menyimpan route aplikasi.

| Percobaan pada helper | Hasil aktual |
|---|---|
| URL asli, pemeriksaan pertama | Valid |
| URL sama, pemeriksaan kedua | Tetap valid |
| URL sama dengan cookie berbeda | Tetap valid |
| Parameter flow diubah setelah signing | Tidak valid |
| Signed URL yang sudah lewat expiry | Tidak valid |

Jadi: **expired bukan one-use; signed bukan browser-bound; signed bukan terenkripsi.** Middleware atau state aplikasi tambahan dapat memberi sifat tersebut, tetapi bukan jaminan helper sendiri. Eksperimen ini tidak menguji endpoint login melalui HTTP/browser.

## Desain yang dapat memberi manfaat tambahan

Bagian ini adalah usulan engineering berdasarkan kebutuhan portal, bukan kewajiban standar OIDC.

Entry publik tetap mudah dibagikan:

```text
https://supplier.example/login
```

Server dapat menerbitkan identitas flow, lalu mengarahkan ke bentuk berikut. Nilai dalam kurung adalah placeholder:

```text
https://supplier.example/login?flow=<id-acak>&expires=<unix-time>&signature=<hmac>
```

Detail tahap, user setelah password valid, deadline dan tujuan internal disimpan di server. Flow ID harus berbeda dari session ID, CSRF token, kode OTP, maupun token login. Mengetahui URL saja tidak boleh cukup untuk login, mengakses metadata akun, atau menyelesaikan MFA.

| Mekanisme yang benar-benar diterapkan | Manfaat yang mungkin didapat | Batas manfaat |
|---|---|---|
| ID acak memilih record konteks server | Memisahkan intended destination dan tahap per percobaan | Tidak otomatis memisahkan cookie/akun antartab |
| Record terkait pre-auth session | URL yang disalin ke browser lain tidak dapat melanjutkan flow asal | Bukan pertahanan terhadap cookie yang ikut dicuri |
| Deadline diperiksa server | Form lama dapat ditolak dengan pesan mulai ulang yang jelas | Pending MFA sekarang sudah punya deadline |
| Tahap diperiksa pada setiap POST | Form password/MFA lama tidak dapat mengoperasikan flow yang sudah maju atau selesai | Tidak menggantikan pemeriksaan kredensial atau permission |
| Penyelesaian dikonsumsi secara atomik | Mencegah dua request menyelesaikan transisi yang sama | `session()->pull()` saja tidak boleh diasumsikan aman terhadap request bersamaan |
| ID korelasi audit yang aman | Memudahkan penelusuran password → MFA → hasil | Audit sudah ada; manfaat baru adalah korelasinya |
| Tujuan dipetakan dari ID ke route lokal server | Mengurangi input redirect yang dipercaya dari URL | Tujuan tetap harus diotorisasi pada request berikutnya |

Pemetaan ID ke tujuan di server sesuai pola yang direkomendasikan OWASP untuk mengurangi risiko redirect tidak tervalidasi. Signature atas tujuan yang tidak aman tetap tidak membuat tujuan itu aman. [OWASP redirect guidance](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html#preventing-unvalidated-redirects-and-forwards).

Signature bersifat **opsional** bila seluruh konteks sudah berada pada record server yang ditemukan lewat ID acak dan diperiksa terhadap session/deadline/status. Jangan menambah HMAC, state, nonce dan token yang saling menduplikasi hanya untuk memperpanjang URL. Jika signature dipakai, parameter keamanan tidak boleh dikecualikan dari validasi dan form POST harus tetap membawa serta memvalidasi konteks yang sama.

Penyimpanan flow wajib berbatas jumlah dan umur. Retry password salah tetap memakai limiter existing, bukan membuat flow baru untuk memperoleh jatah percobaan baru. GET pembukaan halaman tidak mengonsumsi penyelesaian. Refresh/back, tab lama, session expiry dan kegagalan parsial harus punya perilaku yang diputuskan sebelum implementasi. Lifetime yang diusulkan harus diselaraskan dengan MFA 600 detik; angka itu bukan standar universal.

## Yang tidak diberikan oleh URL panjang

OWASP mendukung login pre-session dengan CSRF form token, serta pembaruan session setelah autentikasi. Ia mengutamakan proteksi framework yang sudah ada. Portal sudah memiliki primitive tersebut; menambah parameter URL tidak boleh dipromosikan sebagai pertama kalinya login mendapat proteksi CSRF. [OWASP CSRF guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html#possible-csrf-vulnerabilities-in-login-forms).

Flow publik tidak membuktikan pemakainya manusia: bot dapat membuka entry dan meminta flow sendiri. Desain ini tidak dengan sendirinya mencegah phishing, password bocor, credential stuffing, XSS atau pencurian session. Konsumsi flow melindungi transisi itu, bukan seluruh session yang sudah terautentikasi.

PKCE mempunyai pekerjaan spesifik: mengaitkan authorization code dengan verifier yang dibuktikan saat token exchange. Menambahkan `code_challenge` pada form password biasa tidak menghasilkan mekanisme tersebut. [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636.html#section-1.1). `state`/nonce dan PKCE pada OAuth juga dibahas dalam konteks pengikatan transaksi dan user agent, bukan dekorasi URL. [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html#section-2.1).

Server OAuth sendiri mungkin dibangun tanpa penyedia hosted eksternal. Namun Laravel merekomendasikan layanan autentikasi bawaannya untuk monolith browser, dan Passport ketika fitur OAuth2 benar-benar dibutuhkan. OAuth2 authorization server juga tidak otomatis merupakan penyedia identitas OIDC lengkap. Riset ini tidak merekomendasikan Passport atau server SSO baru untuk memenuhi kebutuhan tampilan URL. [Laravel authentication](https://laravel.com/docs/12.x/authentication#summary-and-choosing-your-stack), [Laravel Passport](https://laravel.com/docs/12.x/passport#introduction).

## Informasi yang harus tetap keluar dari URL

Session tetap ditransport lewat cookie. OWASP menjelaskan bahwa session ID pada URL dapat bocor melalui history, bookmark, log dan Referer. Jangan masukkan password, OTP, recovery code atau bearer token ke query. [OWASP session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#used-vs-accepted-session-id-exchange-mechanisms).

CSRF token tetap di field POST/header. Kutipan OWASP: “A CSRF token must not be leaked in the server logs or in the URL.” [OWASP token transmission](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html#transmitting-csrf-tokens-in-synchronized-patterns).

Jika flow masuk URL, perlakukan sebagai metadata privat yang berpotensi terekam: jangan log URL mentah untuk analitik, gunakan korelasi aman, dan audit akses record konteks. `Cache-Control: no-store` tidak sama dengan penghapusan URL dari history atau log. Kebijakan referrer proyek mengurangi detail lintas origin, tetapi masih dapat mengirim URL penuh untuk request same-origin. `no-referrer` pada halaman flow dapat dipertimbangkan saat implementasi; itu juga tidak menghapus history/access log. [W3C Referrer Policy](https://www.w3.org/TR/referrer-policy/#referrer-policy-strict-origin-when-cross-origin).

## Rekomendasi keputusan

1. **Layak secara teknis tanpa penyedia identitas eksternal atau paket baru.** Pilih transaksi login internal jika dibutuhkan korelasi, pembatasan tahap, atau penanganan form/tab lama yang lebih jelas.
2. **Utamakan record konteks server dan pengikatan session.** URL `?flow=<id>` bisa cukup; variant signed/expiring dapat dipakai ketika ada parameter yang benar-benar perlu ditandatangani.
3. **Ukur manfaat terhadap baseline.** Reproduksi masalah konteks/multi-tab sebelum menyatakan ada defect. CSRF, session rotation, replay TOTP, audit dan timeout MFA bukan fitur baru dari usulan ini.
4. **Pertahankan kontrak domain.** Password lokal, MFA, akun ACTIVE, approval supplier, role/scope, revocation, intended URL guards dan `CompleteLoginService` tetap menjadi bagian alur. Flow tidak memberi hak akses.
5. **Belum ada implementasi.** Scope riset tidak memerlukan migrasi, perubahan konfigurasi, deployment, instalasi dependency atau perubahan database.

Jika dilanjutkan, acceptance penting: tampering/expiry, URL disalin tanpa cookie asal, ulang penyelesaian, request bersamaan, password salah dan rate limit lintas flow, refresh/back, dua tab, OTP/recovery replay, session regeneration, tujuan eksternal, akun dinonaktifkan di tengah alur, dan kebocoran metadata. Itu daftar verifikasi rencana, bukan hasil tes yang sudah lulus.

## Status bukti

- **Dieksekusi:** eksperimen helper URL di atas, pembacaan framework version, inspeksi Git awal dan sumber.
- **Diinspeksi:** route/controller/service/middleware/config serta tes relevan; sumber primer web.
- **Inferensi/usulan:** manfaat multi-flow, korelasi audit, serta desain lifecycle tambahan.
- **Belum diverifikasi:** bug multi-tab aktual, concurrency login, browser, runtime HTTP aplikasi, konfigurasi produksi dan efektivitas kontrol pada deployment.
- **Perubahan:** hanya dokumen riset ini. Tidak menjalankan feature suite, build, atau mutasi database karena tidak ada perubahan runtime.
