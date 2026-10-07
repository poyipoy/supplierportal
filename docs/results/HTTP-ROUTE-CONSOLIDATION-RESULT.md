# Hasil Konsolidasi Route HTTP

Tanggal: 6 Oktober 2026.

## Perubahan

Seluruh definisi route HTTP dari `auth.php`, `supplier-local.php`, `accounting.php`, `finance.php`, dan `ga.php` digabung langsung ke section domain di `routes/web.php`. Lima file tersebut dan backup tidak aktif `web.php.backup` dihapus sesuai keputusan pengguna.

Folder `routes` akhirnya berisi `web.php`, `console.php`, dan `channels.php`. Pemuatannya di `bootstrap/app.php`, scheduler, otorisasi channel, controller, policy, middleware, dan skema database tidak diubah. Import controller yang bentrok memakai alias; urutan registrasi dan batas group akses dipertahankan. Panduan routing aktif di `AGENTS.md` dan `CLAUDE.md` diperbarui; dokumen historis dipertahankan.

## Verifikasi Awal Konsolidasi (Sebelum Remediasi)

| Pemeriksaan | Hasil |
|---|---|
| Manifest sebelum/sesudah | Seluruh 353 route dan urutan registrasi identik: method, domain, URI, nama, action, middleware deklaratif/resolved/excluded, constraints, defaults, binding fields, trashed binding, dan fallback |
| Route cache | Pembuatan cache berhasil; seluruh 353 kontrak cached identik setelah normalisasi nama otomatis route tanpa nama dan pengurutan untuk perbandingan |
| Keadaan cache akhir | Dikembalikan ke keadaan awal: tidak ada route cache |
| Guard database test | 2 passed / 4 assertions; koneksi test adalah `adasi_portal_test` |
| Characterization test | 7 passed / 18 assertions pada routing lama, sesudah konsolidasi, saat cached, dan setelah pemulihan baseline |
| Targeted regression | 250 passed / 1.776 assertions: Auth, SupplierRegistration, isolation, hashid, GA, Finance DRP, unified payment |
| Full suite (`composer test`) | 1.495 passed, 4 failed / 180.386 assertions; 722,51 detik |
| Pembandingan failure dengan routing asli Git | Dua kelas terkait menghasilkan 7 passed, 4 failed / 38 assertions; empat test dan baris failure sama |
| Syntax, scoped Pint, diff whitespace | Lulus |
| Scheduler | Tiga tugas tetap terdaftar; scheduler tidak dieksekusi |
| Browser publik | `/login`, `/forgot-password`, `/supplier/register`: HTTP 200, tampilan terbaca, tidak ada console error/warning |
| Browser guest | Membuka dashboard Finance kembali ke halaman login |

Manifest menangkap route collection langsung, sehingga parity urutan tidak bergantung pada urutan tampilan `route:list`. Perbandingan failure menggunakan definisi route asli dari Git, dengan backup sementara dan pemulihan otomatis; `web.php` hasil refaktor dipulihkan byte-for-byte dan manifest final kembali identik dengan baseline.

Pemeriksaan independen dijalankan bersamaan ketika aman. Semua suite yang menggunakan database test dijalankan serial. Tidak ada commit, push, deployment, atau mutasi database operasional.

## Temuan Baseline di Luar Scope Refaktor (Historis)

| ID | Severity / prioritas | Bukti | Dampak dan status requirement | Remediasi |
|---|---|---|---|---|
| HTTP-BASE-01 | Medium / P2 | `LocalInvoiceViewTimezoneTest.php:64,128,224` tetap gagal pada routing asli: assertion mengharapkan `09:00 WIB` atau `14 Oct 2026 09:00 WIB` | Full suite belum hijau. Ini failure baseline, bukan regresi konsolidasi. View saat ini memakai `RegionalDisplayFormatter`; default timezone adalah `system`, yang mempertahankan timezone input, sedangkan test mengharapkan zona bisnis Jakarta | Selaraskan kontrak display `system` versus zona bisnis dan coverage terkait pada task terpisah; jangan mengubah aturan waktu sebagai bagian refaktor routing |
| HTTP-BASE-02 | Medium / P2 | `PresentationLayerTimezoneTest.php:39` tetap gagal pada routing asli karena `NewDeviceLoginNotification` tidak tersedia. Git commit `d94932f` menghapus class tersebut; referensi aktif yang ditemukan hanya pada test ini | Full suite belum hijau akibat test terhadap class yang telah dihapus; tidak ada perubahan class notifikasi dalam refaktor ini | Perbarui coverage terhadap jalur notifikasi perangkat yang masih aktif; jangan menghidupkan kembali class lama hanya untuk meluluskan test |

Empat failure di atas direproduksi pada verifikasi awal, bukan diasumsikan dari catatan baseline lama. Full suite pada tahap tersebut tidak dilaporkan lulus.

## Batas Verifikasi

Browser smoke test menggunakan guest pada aplikasi lokal. Login/logout, role/scope, dan akses dokumen diuji lewat automated feature tests, tetapi perjalanan browser terautentikasi lintas role belum dijalankan. Staging, produksi, dan PDF/print manual tidak diverifikasi. Build frontend tidak dijalankan karena tidak ada perubahan asset atau Blade.

Konsolidasi route memenuhi kontrak yang disepakati. Bukti ini tidak menyatakan aplikasi production-ready. Status empat failure setelah perbaikan test dicatat di bawah; hasil baseline di atas tetap dipertahankan.

## Remediasi Test Baseline — 6 Oktober 2026

Perbaikan dibatasi pada dua file test dan laporan ini. Tidak ada perubahan runtime, default preferensi, timezone aplikasi/database, route, atau skema.

- `LocalInvoiceViewTimezoneTest`: tiga halaman kini diuji dengan data provider `system` dan `Asia/Jakarta`. Fixture preferensi dibuat untuk aktor yang membuka halaman sebelum request pertama; locale tetap English dan pola tanggal/jam default dipertahankan.
- Assertion memeriksa node tanggal/jam invoice, timestamp timeline PO, dan tanggal penerimaan kasir secara spesifik. Instant `2026-10-14 02:00:00 UTC` ditampilkan `02:00` tanpa WIB pada `system`, atau `09:00 WIB` pada Jakarta. Raw timestamp database dan tanggal kalender juga diperiksa tetap sama setelah rendering.
- `PresentationLayerTimezoneTest`: import dan test `NewDeviceLoginNotification::toMail()` yang obsolete dihapus. Test export, PDF, dan audit yang masih berlaku dipertahankan. Coverage jalur notifikasi aktif tetap menggunakan `KnownDeviceSecurityTest`, termasuk in-app notification, audit, dan tidak adanya email keamanan.

| Pemeriksaan remediasi | Hasil |
|---|---|
| Guard database test | 2 passed / 4 assertions |
| Dua kelas timezone, formatter, known-device security | 41 passed / 265 assertions |
| Setelah scoped formatting: timezone, regional Purchasing/Local Invoice, route contract | 37 passed / 836 assertions |
| Syntax, scoped Pint, diff whitespace | Lulus |
| Full suite (`composer test`) | 1.501 passed / 180.420 assertions, tanpa failure; 765,61 detik |
| Manifest route sesudah remediasi | Seluruh 353 kontrak route dan urutan registrasi kembali identik dengan baseline awal |

HTTP-BASE-01 dan HTTP-BASE-02 berstatus **CLOSED** untuk remediasi test yang disepakati: seluruh skenario timezone lulus, test email obsolete sudah dihapus, coverage in-app notification/audit tetap lulus, dan full suite tidak memiliki failure. Tiga test timezone kini memiliki enam skenario, sementara satu test email obsolete dihapus; jumlah total test naik dari 1.499 menjadi 1.501.

Browser, staging, dan produksi tidak diuji ulang karena remediasi hanya mengubah tests. Tidak ada perubahan runtime atau pengaktifan kembali email keamanan. Passing automated tests tidak dinyatakan sebagai bukti production-ready.
