# Cara menambah export baru

Advance Export menggunakan dispatcher, queue `exports`, file private, preset pribadi, dan SDK progres yang sudah ada. Acuan implementasi dan hasil gerbang berada di [rencana Advance Export](../plans/IMPLEMENTATION-PLAN-ADVANCE-EXPORT-20261007.md), terutama Bagian 11. Tidak ada dependency baru untuk integrasi ini.

## 1. Tetapkan kontrak dan audience

- Baca controller/index, query, model, migration, policy, mapper, heading dan semua caller export existing. Catat grain baris dan buat golden literal en/id sebelum refactor.
- Pertahankan constructor positional existing. Options diterapkan melalui `AcceptsExportOptions::applyOptions()`, sebelum query/count/collection pertama, bukan argumen constructor baru.
- Tetapkan export key per entrypoint, misalnya `finance.local-invoices` dan `accounting.local-invoices`. Audience berasal dari key yang ditentukan controller, tidak dari role/input browser. Admin tidak otomatis mendapat semua audience.
- Pertahankan ownership/policy dan middleware role/scope. `supplier_id` dokumen adalah `users.id`; supplier-facing memaksakan user auth. Public URL/filter supplier memakai hashid; integer di args job adalah data internal, bukan response HTTP.

## 2. Daftarkan definisi dan katalog

- Implementasikan `App\Exports\Advanced\ExportDefinition`, lalu daftarkan pada `ExportDefinitions`. Jangan menerima nama class dari request.
- Tier 1: definisikan key kolom stabil, heading translation, default/required, audience, tipe, width dan `with` berdasarkan callback yang benar-benar digunakan. Gunakan `UsesColumnCatalog` untuk penyaringan ulang keys, mapping, headings dan CSV settings.
- Katalog/callback hanya boleh hidup pada cache **static** definisi. Instance export/job hanya menyimpan scalar/array options, locale dan progress; jangan menyimpan definition, ExportColumn atau closure sebagai properti objek export.
- Tipe metadata tidak mengizinkan perubahan nilai default. Local Invoice mempertahankan string decimal/termin; tanggal QC tetap `d/m/Y H:i`. Jangan menghitung ulang snapshot harga/kurs.
- Timestamp bisnis memakai `BusinessTime`, beserta suffix zona pada heading. Kolom date murni tetap date. Nama file memakai instant `now()` dengan `// biz-time:ignore instant filename`.
- Semua teks dari data melewati `SpreadsheetCellSanitizer` pada XLSX dan CSV. Angka/persentase hasil perhitungan tetap numerik/format authoritative. Pengecualian keamanan QC/Price History default disetujui di Bagian 11; `preserveWhitespace: true` mempertahankan teks aman legacy, hanya memberi prefix apostrof pada teks formula. Jangan menyamakan placeholder internal `-` dengan input berbahaya.
- Tier 2 format-only: katalog kosong menandakan `supports_columns=false` dan `supports_presets=false`. `sanitizeKeys()` hanya menerima array kosong untuk definisi itu; validasi required/minimum kolom Tier 1 tidak dilonggarkan. Price History tetap `FromCollection` dan mengecualikan `cachedRows` lewat `__sleep()`.

## 3. Filter, controller dan UI

- Buat filter bersama berdasarkan filter bisnis existing; pakai kembali pada index/export/preset. Jangan menyimpan payload DataTables, ID supplier integer, atau options/audience di filter preset.
- Relative day range disimpan sebagai mode/days dalam preset, lalu disnapshot menjadi tanggal absolut saat dispatch. Relative month range belum didefinisikan; jangan mengarang semantiknya.
- Pertahankan batas tanggal legacy ketika tidak ada options. QC advanced/history menggunakan batas business-day yang dikonversi UTC. Local Invoice mempertahankan `InvoiceQuery` existing, termasuk `whereDate(submitted_at)`; `from/to` tidak diam-diam diubah menjadi WIB.
- Local Invoice: Finance selalu register; Accounting memvalidasi `report=register|payments`, menyimpannya dalam filter preset dan menerjemahkannya ke argumen payments server. Pemeriksaan aktor di `query()` tetap wajib. Pengurangan eager loads dibatasi builder export, tidak mengubah query default endpoint lain.
- Pertahankan GET legacy dan nama route; tambahkan POST advanced pada URI yang sama bila diperlukan. Gunakan `AdvancedExportRequest`, resolver dengan key server, kemudian dispatcher. Respons JSON 202 berisi hash job, status/cancel URLs; response HTML existing tetap kompatibel.
- Tier 1 memakai `x-export.advanced-modal`: kalender, subset/order, preset, format dan SDK async existing. Tier 2 menggunakan form `options[format]` tanpa kolom/preset dan meneruskan filter hasil tampilan. Gunakan CSRF, label, feedback teks/live region, busy state dan retry; jangan menambah monitor progres baru.
- Semua teks baru berpasangan pada lang en/id. Audit memakai workflow `tests/Support/user-facing-copy-audit.php` melalui `TranslationParityTest`; jangan menulis ulang snapshot audit historis sebagai efek samping.

## 4. Queue, limit dan file

- Daftarkan class dalam allowlist dispatcher. Untuk list Tier 1/2, daftarkan juga dalam `LIST_EXPORT_CLASSES`: batas **100.000 baris** berlaku sebelum dispatch dan di worker, termasuk request legacy; **5 job aktif/user** dihitung hanya dari list Tier 1/2 queued/processing.
- Lock user, pemeriksaan kapasitas dan pembuatan record/root queue berada dalam transaksi database. Handoff tetap database driver, connection yang sama dan `after_commit=false`. Sync dipakai untuk sebagian tests; jangan mengubahnya menjadi Redis/after-commit tanpa desain baru.
- Pertahankan lifecycle retry/cancel/duplicate-launch, locale, finalizer, progress chunk dan expiry. CSV harus memilih writer CSV eksplisit, delimiter koma, UTF-8 BOM, extension `.csv`, MIME `text/csv; charset=UTF-8` dan `nosniff`. Cleanup menggunakan path record, bukan asumsi extension.
- CSV advanced memakai timeout monitoring **660 detik tanpa kemajuan**: perubahan stage/status atau kenaikan processed_rows memperbarui clock; respons identik tidak. Flag/clock CSV bertahan saat navigasi. XLSX, detail dan DRP mempertahankan timeout absolut existing. Ini tidak mempercepat writer CSV atau mengubah timeout worker.
- Closure static harus dibuktikan tidak masuk serialisasi warmed export. Cache `FromCollection` tidak boleh ikut setiap job chunk. Jangan menghapus `collection()` yang masih dipakai tests/kontrak vendor.
- DRP/Transfer, template import, export detail dan PDF bukan bagian Advance Export; limit list maupun options baru tidak diterapkan pada jalur tersebut.

## 5. Gerbang verifikasi

1. Jalankan `TestingEnvironmentDatabaseSafetyTest` sebelum feature tests. Gunakan MySQL `adasi_portal_test` dan jalankan suite serial; concurrency memakai subprocess dengan koneksi test terpisah, bukan dua suite RefreshDatabase bersamaan.
2. Uji golden headings/map en/id, default dan subset/order/required, eager loads tanpa lazy loading, filter parity, scope/role/hashid, audience/kolom dipalsukan dan preset anti-IDOR.
3. Uji warmed serialize/unserialize serta payload `=`, `+`, `-`, `@` pada file XLSX dan CSV. Untuk CSV, uji kosong dan lebih dari satu chunk: BOM/heading sekali, urutan, quoting/Unicode dan Content-Type/filename.
4. Uji legacy/advanced berbagi limit, terminal job membebaskan slot, user lain terisolasi, dua koneksi berebut slot terakhir, dan pertumbuhan dataset ditolak worker.
5. Jalankan suite export lama dan seluruh test Advance Export, scoped Pint, syntax PHP/JS, TranslationParity/BusinessTime guard, regresi SDK, `npm.cmd run build`, `php artisan view:cache`, dan `git diff --check`.
6. Benchmark 50.000 baris harus mencatat format, kolom, chunk, waktu dan peak memory aktual. Benchmark CSV tiga kolom tidak membuktikan XLSX seluruh kolom atau performa Price History FromCollection. Hasil mesin lokal bukan SLA produksi.
7. Catat browser/keyboard/screen reader, Excel locale desktop, storage remote, database worker CSV dan staging/deployment sebagai belum diverifikasi sampai benar-benar dijalankan. Jangan menjalankan migrate pada DB aplikasi untuk test fitur ini.

Setiap fase memiliki branch/commit sendiri, hasil test aktual dan diff terhadap parent. Gerbang gagal harus diperbaiki dalam scope atau dilaporkan; jangan melanjutkan fase dengan asumsi test akan lulus.
