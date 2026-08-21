# Notification Audit Report — Mission 2

Tanggal audit dan implementasi: 3 Agustus 2026

## Ruang Lingkup

Audit mencakup database notification Laravel, broadcast Reverb, antrean database, resolver URL, endpoint read/unread, navbar real-time, recipient procurement, batas transaksi, halaman tujuan Admin, dan isolasi data Supplier. Tidak ada migration, backfill, penghapusan notification, maupun retry job dalam Mission 2.

## Snapshot Sebelum Perbaikan

- Database `adasi_portal` memiliki 350 notification; 223 belum dibaca.
- Terdapat 1 job broadcast tertunda sejak 30 Juli 2026 dan 5 failed job akibat koneksi Reverb ke `127.0.0.1:8080` ditolak.
- 129 notification memakai URL lama `/purchasing/requirements/{id}`.
- Seluruh 14 notification Admin mengarah ke route Purchasing.
- 332 notification tidak memiliki entity ID dan 200 tidak memiliki kategori eksplisit.
- Snapshot hanya dibaca. Notification dan job lama tidak diubah, dihapus, atau di-retry.

## Temuan, Penyebab, dan Perbaikan

| Temuan | Cara reproduksi / dampak | Penyebab utama | Perbaikan |
|---|---|---|---|
| Notification dapat membuat transaksi yang sudah commit tampak gagal | Matikan Reverb lalu submit PR, quotation, atau PO dengan queue sinkron | Pengiriman berada di dalam blok error transaksi, dan beberapa pengiriman dilakukan sebelum commit | Semua pengiriman masuk melalui `NotificationService`, ditunda sampai commit jika masih berada dalam transaksi, dan error delivery diisolasi dari response bisnis |
| Notification ganda saat retry | Kirim kejadian yang sama lebih dari sekali | Tidak ada kunci idempotensi | Payload baru memiliki `event`/`event_key`; UUIDv5 deterministik dibuat per recipient dan event key |
| Akun nonaktif masih dapat menjadi recipient | Nonaktifkan user yang masih cocok dengan role recipient | Query recipient hanya memeriksa role | Query role memakai `is_active = true`, dan service kembali memfilter recipient aktif |
| Link Admin menuju area Purchasing | Klik notification PR sebagai Admin | URL dibentuk memakai route Purchasing | Ditambah `admin.requisitions.show` read-only dan seluruh PR submitted baru mengarah ke route tersebut |
| URL lama/rusak/berbahaya tidak ditangani konsisten | Klik URL host lama, `requirements`, entity terhapus, atau skema berbahaya | Resolver berbasis prefix dan fallback parsial | `NotificationUrlResolver` menormalisasi absolute URL ke root-relative, mencocokkan nama route/role, mengenali route lama, menolak skema berbahaya, memvalidasi entity serta ownership Supplier, lalu fallback ke dashboard |
| Kategori invalid dapat menandai semua notification | POST `category=invalid` ke mark-all | Nilai invalid diubah diam-diam menjadi `all` | Nilai invalid sekarang menghasilkan 422; hanya parameter kosong berarti `all` |
| Badge kategori berbeda dari badge utama | Unread lebih lama dari 30 item | Kategori dihitung hanya dari recent 30 | `NotificationSummaryService` menghitung seluruh unread untuk badge utama dan kategori, dengan recent 30 hanya untuk isi dropdown |
| Mark-all melakukan update per record | Mark-all pada kategori besar | Memanggil `markAsRead()` satu per satu | ID diklasifikasikan sekali lalu diperbarui dengan satu bulk update |
| UI real-time tidak memperbarui badge dan raw payload masuk ke DOM | Terima broadcast title/message berisi markup | Selector salah dan item dibuat dengan `innerHTML` | Selector memakai `.notif-badge`; item dibuat dengan DOM API/`textContent`; icon divalidasi; toast membuka endpoint mark-read |
| Konfigurasi Reverb tidak aman terhadap config cache | Jalankan `config:cache` | Blade memanggil `env()` langsung | Nilai public client berasal dari `config/reverb.php`; Echo hanya aktif jika broadcaster/config lengkap; polling 30 detik tetap menjadi fallback |
| Beberapa lifecycle tidak mengirim notification | Submit PR lewat update atau accept/reject dari daftar | Trigger hanya ada di jalur tertentu | Jalur update PR, accept/reject/revision quotation, PO/QC/claim/document/chat distandarkan melalui service |
| Update dokumen status sama mengirim ulang | Kirim status yang identik | Tidak ada pemeriksaan perubahan status | Notification dokumen hanya dikirim jika status benar-benar berubah |
| Latest Activities Admin memuat notification user lain | Buka dashboard Admin | Query global `DatabaseNotification` | Query sekarang memakai relasi notification Admin yang login |

## Area yang Sudah Benar Sebelum Perbaikan

- Database notification sudah menjadi channel pertama sebelum broadcast.
- `markRead`, `markAllRead`, dan unread count sudah berawal dari relasi user yang login.
- Queue memakai koneksi database dan broadcast merupakan enhancement asynchronous pada runtime normal.
- Route procurement utama sudah dipisahkan berdasarkan role; scope Supplier di controller bisnis tetap dipertahankan.

## Interface Setelah Perbaikan

- Tidak ada perubahan schema atau migration Mission 2.
- Route baru: `admin.requisitions.show` untuk detail PR read-only.
- Payload baru: `event`, `event_key`, `category`, `url`, dan entity ID relevan.
- `notifications.unread-count` tetap mengembalikan `count` dan menambah `category_counts`.
- `notifications.mark-all-read` mengembalikan 422 untuk kategori invalid.
- `SystemNotification` tetap memakai urutan channel `database`, lalu `broadcast`, tanpa implementasi `ShouldBroadcast` yang redundan.

## Verifikasi Otomatis

- Baseline sebelum Mission 2: 41 test lulus, 145 assertion.
- Targeted notification tests: 16 test lulus, 98 assertion setelah perbaikan test nondeterministik pada urutan timestamp yang sama.
- Test mencakup user scope, mark one/all, kategori valid/invalid, akun nonaktif, empat role, host/port lama, URL berbahaya, legacy `requirements`, entity terhapus, ownership Supplier, Admin read-only, recipient aktif, idempotensi, rollback, broadcast antre, broadcaster gagal, submit PR create/update, review quotation, PO/arrival/document, QC, dan claim lifecycle.
- Full suite: 57 test lulus, 243 assertion.
- Empat route notification terverifikasi memakai middleware `auth` dan `role:admin,purchasing,supplier,qc`; route detail PR Admin memakai `auth` dan `role:admin`.
- `php artisan config:cache`, `php artisan view:cache`, `npm.cmd run build`, targeted Pint test, dan `git diff --check` lulus.

## Skenario Manual

Delapan skenario yang perlu dijalankan pada `adasi_portal_test` dengan browser dan proses Reverb nyata:

1. Reverb aktif: notification baru tampil real-time pada tab All dan kategori terkait.
2. Reverb mati: transaksi tetap sukses dan badge terbarui melalui polling.
3. Akses dari dua host/port berbeda: link selalu mengikuti origin aktif.
4. Mark-all per kategori: kategori lain tetap unread.
5. Entity telah dihapus: klik berakhir di dashboard role tanpa 403/500.
6. Supplier mencoba link entity Supplier lain: diarahkan ke dashboard Supplier.
7. Admin membuka PR submitted: halaman read-only tanpa aksi Purchasing.
8. Toast real-time: klik melakukan mark-read lalu redirect melalui resolver backend.

Status browser/Reverb manual pada sesi implementasi ini: belum diverifikasi karena tidak ada browser yang tersedia pada backend QA dan proses Reverb nyata tidak dijalankan.

## Operasional Job Existing

Setelah Reverb dipastikan sehat, lakukan review terpisah sebelum mutasi queue:

1. Periksa `php artisan queue:failed` dan payload metadata tanpa menyalin data sensitif ke log/tiket.
2. Pastikan host, port, scheme, app key, dan origin Reverb sesuai konfigurasi deployment.
3. Jalankan worker pada queue yang benar dan verifikasi satu broadcast baru.
4. Setelah persetujuan operasional, retry failed job secara selektif dengan ID; jangan memakai retry-all tanpa review.
5. Audit job tertunda 30 Juli 2026 sebelum menjalankan worker agar notification lama tidak menimbulkan kebingungan.

Mission 2 tidak melakukan langkah retry/delete tersebut.
