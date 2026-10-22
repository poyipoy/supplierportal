# Cron produksi ADASI Portal Supplier — 12 Oktober 2026

> **Diperbarui 21 Oktober 2026 (Gate 2 Stage 1):** queue `default` ternyata membawa push notifikasi realtime (`BroadcastNotificationCreated`), bukan email. Topologi yang direkomendasikan kini **empat cron setiap menit**: scheduler, worker `exports`, worker `imports`, dan worker `default`, semuanya **tanpa `--stop-when-empty`**. Sumber kanonik: [CPANEL-PERFORMANCE-RUNTIME.md](CPANEL-PERFORMANCE-RUNTIME.md) §5. Kondisi produksi per screenshot cPanel 21 Oktober 2026: tiga entry (scheduler, `exports,default`, `imports`), semuanya **sudah tanpa** `--stop-when-empty`; database `MariaDB 11.4.13-cll-lve`. Perubahan yang masih disarankan hanya dua: ubah entry worker export menjadi `--queue=exports` (bagian B), lalu tambahkan worker `default` (bagian B2). PCNTL dan batas proses akun belum diverifikasi; jalankan pemeriksaan bagian 3 lebih dulu dan catat baris lama sebelum mengubahnya.

Rekomendasi untuk cPanel pada screenshot: **empat cron setiap menit**. Scheduler menjalankan jadwal aplikasi; worker pertama khusus `exports`; worker kedua khusus `imports`; worker ketiga khusus `default`. Worker `po-documents` bersifat kondisional karena fitur async tersebut masih OFF di working tree.

Path berikut diambil dari screenshot, bukan pemeriksaan SSH produksi:

```text
APP=/home/adaw2196/supplierportal
PHP=/usr/local/bin/ea-php83
FLOCK=/usr/bin/flock
```

`/usr/local/bin/ea-php83` adalah symlink PHP CLI cPanel untuk PHP 8.3. Pastikan file tersebut tersedia pada akun hosting; PHP web dan CLI harus diperiksa terpisah. [cPanel About PHP](https://docs.cpanel.net/ea4/php/about-php/), [cPanel cron command examples](https://support.cpanel.net/hc/en-us/articles/360053606793-How-to-create-or-edit-cron-jobs-in-the-cPanel-user-interface).

## 1. Command yang dipasang di cPanel

Untuk masing-masing entry, isi **Minute, Hour, Day, Month, Weekday dengan `*`**. Kolom Command hanya berisi command di bawah, tanpa lima bintang. Edit dua entry existing dan tambahkan worker imports; satu fungsi cukup memiliki satu entry.

### A. Scheduler — wajib

```sh
cd /home/adaw2196/supplierportal && /usr/local/bin/ea-php83 artisan schedule:run >> /home/adaw2196/supplierportal/storage/logs/scheduler.log 2>&1
```

Laravel mengevaluasi tugas aplikasi setiap menit melalui satu scheduler. Jangan menambahkan cron terpisah untuk setiap command pada tabel bagian 2. [Laravel Scheduler](https://laravel.com/docs/12.x/scheduling#running-the-scheduler).

Repo sudah memakai `withoutOverlapping()` untuk setiap tugas. Lock aplikasi memerlukan cache yang tersedia dan persisten. Rekomendasi tidak menambah lock global scheduler: scheduler yang lama dapat membuat invocation berikutnya terlewat, padahal ada tugas lain yang jatuh tempo. Ini merupakan pertimbangan berdasarkan bentuk jadwal repo.

Saat setup, simpan output scheduler ke log agar kegagalan terlihat. Setelah operasi stabil, pemilik dapat memilih output rutin `/dev/null` dengan monitoring error lain yang sudah terbukti. Rotasi log tetap diperlukan jika output dipertahankan.

### B. Worker export — wajib

```sh
cd /home/adaw2196/supplierportal && /usr/bin/flock -n /home/adaw2196/supplierportal/storage/framework/async-export-worker.lock /usr/local/bin/ea-php83 artisan queue:work database --queue=exports --sleep=1 --max-time=50 --tries=3 --timeout=600 >> /home/adaw2196/supplierportal/storage/logs/queue-worker.log 2>&1
```

Pertahankan nama lock existing `async-export-worker.lock` ketika mengedit command. Jika worker lama masih berjalan, lock yang sama mencegah worker cron baru berjalan bersamaan. `flock -n` melewati invocation jika lock sedang dipakai; lock dilepas ketika proses pemegangnya selesai. Filesystem lock harus diuji pada hosting, terutama jika storage memakai NFS/CIFS. [util-linux flock manual](https://manpages.debian.org/bookworm/util-linux/flock.1.en.html).

`exports` memuat job export aplikasi (timeout 600 detik). Jangan menambahkan `--stop-when-empty`: flag itu membuat worker keluar ketika antrean kosong sehingga setiap job baru menunggu cron menit berikutnya (0–60 detik "Queued"). Tanpa flag itu, worker tetap polling hingga `--max-time=50`.

### B2. Worker default (push notifikasi realtime) — wajib

```sh
cd /home/adaw2196/supplierportal && /usr/bin/flock -n /home/adaw2196/supplierportal/storage/framework/default-worker.lock /usr/local/bin/ea-php83 artisan queue:work database --queue=default --sleep=1 --max-time=50 --tries=3 --timeout=60 >> /home/adaw2196/supplierportal/storage/logs/default-worker.log 2>&1
```

Setiap `SystemNotification` mengantrikan `BroadcastNotificationCreated` ke `default`. Jika `default` dilayani worker yang sama dengan `exports`, push realtime menunggu export yang sedang berjalan (hingga 600 detik). Jika batas proses akun tidak mengizinkan worker ketiga, gunakan fallback dua worker pada [CPANEL-PERFORMANCE-RUNTIME.md](CPANEL-PERFORMANCE-RUNTIME.md) §5.2 (`--queue=default,exports`).

### C. Worker import PO/GR — wajib untuk background import

```sh
cd /home/adaw2196/supplierportal && /usr/bin/flock -n /home/adaw2196/supplierportal/storage/framework/import-worker.lock /usr/local/bin/ea-php83 artisan queue:work database --queue=imports --sleep=1 --max-time=50 --tries=3 --timeout=300 --memory=384 >> /home/adaw2196/supplierportal/storage/logs/import-worker.log 2>&1
```

Queue `imports` ditetapkan di `config/local_procurement_imports.php`, dengan timeout job 300 detik. Blueprint existing ada di [LOCAL-PROCUREMENT-IMPORTS.md](LOCAL-PROCUREMENT-IMPORTS.md). Memisahkan worker mencegah export panjang menahan import di belakangnya. Lock dan log imports harus berbeda dari exports.

`--memory=384` adalah ambang memori worker yang diperiksa di antara job; bukan pengganti `memory_limit` PHP atau batas RAM akun hosting. Verifikasi memori CLI dan anggaran dua worker secara bersamaan. Jangan menetapkan memori unlimited untuk mengatasi kegagalan import/export.

### D. Worker PO documents — kondisional

`config/po_documents.php` saat ini memakai `async_enabled=false`, `host_verified=false`, dan anggaran runtime/timeout/lease belum diisi. Karena itu worker queue `po-documents` belum masuk daftar cron aktif. Scheduler `po-documents:reconcile` tetap terdaftar dan menangani recovery/retention.

Setelah kapasitas host dan semua anggarannya disetujui, worker tersendiri menggunakan pola:

```sh
cd /home/adaw2196/supplierportal && /usr/bin/flock -n /home/adaw2196/supplierportal/storage/framework/po-documents-worker.lock /usr/local/bin/ea-php83 artisan queue:work database --queue=po-documents --sleep=1 --max-time=50 --tries=RETRY_YANG_DISETUJUI --timeout=TIMEOUT_YANG_DISETUJUI >> /home/adaw2196/supplierportal/storage/logs/po-documents-worker.log 2>&1
```

Template D mengandung placeholder dan **belum siap dipasang**. Timeout job dari config juga harus selaras; lihat [NATIVE-FILE-SECURITY.md](NATIVE-FILE-SECURITY.md).

## 2. Tugas yang sudah ditangani scheduler

Sumber aktif: [routes/console.php](../../routes/console.php). Jam harian mengikuti `APP_BUSINESS_TIMEZONE`, default Asia/Jakarta. Tabel ini menggunakan WIB.

| Command aplikasi | Jadwal | Tujuan |
|---|---|---|
| `model:prune --model="App\Models\AuthAuditLog"` | 02:10 WIB | Menghapus audit auth melewati retensi; default model 180 hari |
| `exports:cleanup` | 02:20 WIB | Menghapus hasil export dan record yang sudah expired sesuai pemeriksaan path |
| `imports:cleanup` | 02:30 WIB | Membersihkan staging import yang selesai/melewati retensi dan menangani antrean import yang perlu recovery |
| `local-invoices:send-delivery-reminders` | 08:00 WIB | Pengingat jadwal penyerahan dokumen fisik invoice |
| `supplier-audits:notify-invoice-blocked` | 00:10 WIB | Notifikasi supplier ketika audit terlambat memblokir pengajuan invoice baru |
| `po-documents:reconcile` | Setiap menit | Recovery percobaan upload dokumen PO yang tertinggal dan cleanup staging |

`config/app.php` mempertahankan timezone penyimpanan UTC. Output `schedule:list` lokal menampilkan konversi UTC, misalnya 19:10 UTC setara 02:10 WIB hari berikutnya. Jangan mengubah timezone penyimpanan untuk mencocokkan label jam di cPanel.

## 3. Pemeriksaan sebelum memasang cron

Jalankan dari cPanel Terminal/SSH sebagai user akun hosting. Semua command pada bagian ini bersifat pemeriksaan.

```sh
cd /home/adaw2196/supplierportal
/usr/local/bin/ea-php83 -v
/usr/local/bin/ea-php83 --ini
/usr/local/bin/ea-php83 -r 'echo "SAPI=",PHP_SAPI,PHP_EOL,"memory_limit=",ini_get("memory_limit"),PHP_EOL; foreach (["pcntl","pdo_mysql","bcmath","mbstring","fileinfo","zip","xmlreader","dom"] as $ext) { echo $ext,": ",extension_loaded($ext) ? "OK" : "MISSING",PHP_EOL; } foreach (["pcntl_alarm","pcntl_signal","pcntl_async_signals"] as $fn) { echo $fn,": ",function_exists($fn) ? "OK" : "UNAVAILABLE",PHP_EOL; }'
/usr/bin/flock --version
test -w storage/framework && test -w storage/logs && echo "Storage writable"
/usr/local/bin/ea-php83 artisan about --only=environment
/usr/local/bin/ea-php83 artisan schedule:list
/usr/local/bin/ea-php83 artisan migrate:status
/usr/local/bin/ea-php83 artisan queue:failed
```

Hasil SAPI harus `cli`; PCNTL dan fungsi signal/alarm harus tersedia untuk deadline worker. Instalasi ekstensi PCNTL merupakan tanggung jawab lingkungan PHP. [PHP PCNTL installation](https://www.php.net/manual/en/pcntl.installation.php).

Environment produksi yang perlu dicocokkan, tanpa menampilkan seluruh `.env` atau credential:

```dotenv
APP_ENV=production
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=660
CACHE_STORE=database
APP_BUSINESS_TIMEZONE=Asia/Jakarta
APP_DEBUG=false
```

`CACHE_STORE=database` adalah pilihan yang cocok untuk lock/restart pada deployment database repo ini; cache persisten alternatif dapat digunakan jika tersedia dan diverifikasi. Pastikan tabel cache/jobs/failed_jobs serta migrasi aplikasi telah diterapkan lewat prosedur deployment yang disetujui. Import/exports memerlukan database queue pada koneksi database yang sama dengan state aplikasinya; pertahankan `after_commit=false` pada config koneksi queue.

## 4. Timeout, resource dan benchmark

Relasi saat ini: export job 600 detik, import job 300 detik, database `retry_after=660`. Timeout harus lebih pendek dari retry_after untuk mencegah job aktif diambil kembali. Timeout yang ditetapkan pada class job mengalahkan `--timeout` CLI; PCNTL diperlukan. [Laravel job timeout](https://laravel.com/docs/12.x/queues#timeout).

Source vendor terpasang (`Illuminate\Queue\Worker`) memeriksa `--max-time` dan ambang memori di antara job. Jadi `--max-time=50` bisa menghasilkan proses yang hidup lebih dari 50 detik ketika job aktif masih berjalan. Karena itu lock worker harus tetap terpegang selama job berjalan. Jangan memakai `--stop-when-empty` (lihat bagian B).

Benchmark lokal existing: CSV 50.000 baris/100 chunks, 655,26 detik, peak sekitar 296 MiB, driver sync. Ini bukan timing per-job database worker dan bukan bukti host produksi cukup. Khususnya, chain queued dapat membagi pekerjaan menjadi beberapa job. Karena itu hasil tersebut merupakan risiko performa yang perlu diuji pada staging, bukan alasan langsung menaikkan timeout cron. Source pengukuran: `storage/logs/advance-export-performance.json` dan [hasil remediasi](../results/SECURITY-REMEDIATION-R3-R5-20261012.md).

Mengganti hanya `--timeout=900` tidak mengubah class export yang tetap 600. Perubahan timeout memerlukan penilaian class job, database retry_after, lease aplikasi, anggaran host dan retry safety bersama. Konfirmasi dengan provider apakah wall-clock proses, CPU/RAM akun, filesystem lock dan jumlah proses memungkinkan worker ini berjalan.

## 5. Command setelah deployment dan monitoring

Berikut command sekali jalan, **bukan cron per menit**. Jalankan sesuai prosedur deployment setelah environment/release siap:

```sh
cd /home/adaw2196/supplierportal
/usr/local/bin/ea-php83 artisan config:cache
/usr/local/bin/ea-php83 artisan route:cache
/usr/local/bin/ea-php83 artisan view:cache
/usr/local/bin/ea-php83 artisan icons:cache
/usr/local/bin/ea-php83 artisan queue:restart
```

Urutan lengkap, termasuk `optimize:clear` di awal, upload `public/build` dari build yang sama, prosedur rilis Gate 2, dan rollback, ada di [CPANEL-PERFORMANCE-RUNTIME.md](CPANEL-PERFORMANCE-RUNTIME.md) §3–§3.3. `view:cache` wajib pada setiap rilis yang mengubah komponen Blade; rollback ke rilis sebelum `<x-ui.icon>` menjadi kelas komponen wajib mengompilasi ulang view.

`queue:restart` menyimpan sinyal cache agar worker keluar setelah job aktif selesai. Cron berikutnya atau process manager menjalankan worker baru. [Laravel queue deployment](https://laravel.com/docs/12.x/queues#queue-workers-and-deployment).

Pemeriksaan operasional:

```sh
cd /home/adaw2196/supplierportal
/usr/local/bin/ea-php83 artisan queue:failed
/usr/local/bin/ea-php83 artisan schedule:list
/usr/local/bin/ea-php83 artisan local-invoices:reconcile --json
tail -n 100 storage/logs/scheduler.log
tail -n 100 storage/logs/queue-worker.log
tail -n 100 storage/logs/import-worker.log
ps -u adaw2196 -o pid,etime,args | grep '[a]rtisan queue:work'
```

Ukuran log dan oldest queue age perlu dipantau. File stdout cron tidak otomatis mengikuti rotasi channel Laravel `daily`; gunakan rotasi/retensi yang didukung provider. `queue:retry <UUID>` hanya setelah akar kegagalan diperiksa. `schedule:clear-cache` merupakan pemulihan lock macet setelah memastikan task tidak aktif. Command repair, import employee, cleanup legacy GA dengan `--execute`, migrate, queue:clear dan db:wipe bukan daftar cron operasional ini.

Saat mengedit entry, pastikan tidak ada worker lain untuk queue yang sama di cron kedua/Supervisor tanpa desain concurrency. File lock yang terlepas otomatis tidak perlu dihapus setelah setiap run. Menghapus nama file lock ketika proses masih memegangnya dapat membuka jalur lock baru bagi proses kedua.

## 6. Alternatif jika memakai VPS

Pada VPS dengan akses administrator dan daemon yang didukung, pilihan yang direkomendasikan adalah scheduler cron ditambah worker kontinu di bawah Supervisor atau systemd. Worker kontinu menghindari jeda menunggu cron menit berikutnya. cPanel shared hosting mengikuti kemampuan provider, sehingga tiga cron di atas lebih cocok untuk screenshot ini.

Contoh konfigurasi Supervisor untuk **satu** worker export/default; tambah program imports terpisah dengan timeout 300 dan memory 384. Ini alternatif worker cron B/C, bukan tambahan untuk menjalankan queue yang sama dua kali.

```ini
[program:adasi-exports]
directory=/home/adaw2196/supplierportal
command=/usr/local/bin/ea-php83 artisan queue:work database --queue=exports --sleep=1 --tries=3 --timeout=600 --max-time=3600
user=adaw2196
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/home/adaw2196/supplierportal/storage/logs/exports-supervisor.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=660
```

Nilai stopwaitsecs harus memberi waktu cukup untuk job terpanjang sebelum process manager memaksa proses berhenti. [Supervisor configuration](https://supervisord.org/configuration.html#program-x-section-settings).

## 7. Bukti dan batas riset

- **Dieksekusi lokal:** `php artisan schedule:list --no-ansi` menunjukkan enam jadwal; `php artisan help queue:work --no-ansi` mengonfirmasi opsi CLI.
- **Diinspeksi:** routes scheduler, queue config, export/import/PO-document jobs, cleanup/reminder commands, vendor Worker, panduan deployment existing, benchmark lokal dan screenshot cPanel. Dokumen lama menyebut email keamanan di queue default; kode sekarang tidak menunjukkan custom queued-mail jobs tersebut. Default tetap dipertahankan sebagai queue umum/kompatibilitas.
- **Riset web:** dokumentasi Laravel 12, cPanel, PHP, manual util-linux, serta Supervisor. Firecrawl/Exa tidak tersedia; alat web bawaan dipakai. Pertanyaan riset: scheduler apa yang aktif, queue apa yang perlu worker, bagaimana menghindari overlap/timeout, dan command operasional apa yang dibutuhkan.
- **Belum diverifikasi:** binary/path/PCNTL produksi, cron aktual berhasil berjalan, provider process limits, konfigurasi efektif cached di host, queue depth/age, log rotation, end-to-end worker export/import dan anggaran dataset besar. Cron produksi tidak dipasang atau diubah dalam riset ini. Tidak dijalankan scheduler/worker/cleanup pada data aplikasi lokal atau produksi.
