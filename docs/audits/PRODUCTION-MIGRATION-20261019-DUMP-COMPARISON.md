# SQL migrasi 19 Oktober 2026 — perbandingan dump produksi

Disusun20 Oktober2026 pada HEAD `1a830c0` (Merge feature/sso-oauth-login into master). Scope: **seluruh5 file** `database/migrations/2026_10_19_*.php`; tidak mencakup tanggal lain atau aktivasi SSO.

## Artifact

[database/2026_10_19_migration.sql](../../database/2026_10_19_migration.sql)

**Hanya dibuat sebagai file; belum diimpor atau dijalankan pada database apa pun.** Jangan menjalankan SQL ini sekaligus dengan5 PHP migrations yang sama.

## Dump yang diperiksa

- Input user: `adaw2196_supplierportal (4).sql`,69.907.185bytes.
- SHA256: `0cbefe1c00be6b7715e17b39e2fa288701af9960ee87788863bc6684ecd1a7d3`.
- Header dump: MariaDB **11.4.13-MariaDB-cll-lve**, dibuat19 Oktober2026 13:16, database `adaw2196_supplierportal`.
-90 CREATE TABLE; **tidak ada tabel oauth_***, termasuk pada ALTER TABLE.
- Ledger110rows, batch maksimum40, nama terakhir `2026_10_12_000003_guard_active_supplier_audit_uniqueness`. Tidak ada5 migration names tanggal19.
- Ledger `migrations.id` memiliki PRIMARY KEY dan AUTO_INCREMENT pada ALTER setelah definisi tabel. Membaca CREATE saja tidak cukup.
- Dump adalah snapshot historis, **bukan bukti live production sekarang**. Riwayat ledger yang disalin tidak dapat membuktikan physical schema; skrip memeriksa tabel target secara terpisah.

## Pemetaan lengkap

| Migration | Tabel | Indeks selain PK |
|---|---|---|
|2026_10_19_172826_create_oauth_auth_codes_table|oauth_auth_codes|user_id|
|2026_10_19_172827_create_oauth_access_tokens_table|oauth_access_tokens|user_id|
|2026_10_19_172828_create_oauth_refresh_tokens_table|oauth_refresh_tokens|access_token_id|
|2026_10_19_172829_create_oauth_clients_table|oauth_clients|owner_type+owner_id|
|2026_10_19_172830_create_oauth_device_codes_table|oauth_device_codes|user_id,client_id,unique user_code|

Total5 tabel,5PK,6 secondary non-unique indexes dan1unique index. Auth/access/refresh/device ID CHAR(80); client UUID CHAR(36); owner_id numeric unsigned nullable; boolean revoked NOT NULL tanpa default tambahan. Nullable scopes dan timestamps/datetime mengikuti masing-masing sumber; device scopes tetap NOT NULL.

`foreignId()` dan `foreignUuid()` pada sumber hanya mendefinisikan tipe: tidak ada `constrained()` atau deklarasi FK. Karena itu SQL **tidak menambahkan FK, cascade, seed/client/key/token atau business-data backfill**.

## Kompatibilitas dan guard

- DDL dikompilasi dari Laravel framework terpasang **v12.69.3**, Passport **v13.10.0**, dengan **MySqlGrammar**, InnoDB dan utf8mb4_unicode_ci (charset/collation sesuai dump).
- CHAR(36) merupakan keluaran driver Laravel mysql dan valid pada MariaDB. Driver Laravel **mariadb** memakai native UUID untuk server≥10.7; native UUID tidak dipilih diam-diam pada artifact portable ini. Pemilihan tipe ini dinyatakan pada header SQL. [Dokumentasi UUID MariaDB](https://mariadb.com/docs/server/reference/data-types/string-data-types/uuid-data-type).
- Semua objek aplikasi/routine dan pemeriksaan diberi qualifier `adaw2196_supplierportal`; filter information_schema juga memakai nama schema eksplisit. Untuk rehearsal isolated staging, ganti seluruh kemunculan nama database itu dengan nama salinan yang benar.
- Memerlukan tabel ledger InnoDB existing; menolak duplicate selected migration names serta **tabel/view target yang sudah ada**. Tidak memakai CREATE TABLE IF NOT EXISTS yang dapat menyembunyikan perbedaan struktur.
- Entry ledger existing dipertahankan; nama yang hilang ditambahkan **sesudah** CREATE dan indeks migration tersebut berhasil. Batch dihitung dari live MAX(batch)+1, bukan hardcoded41. Jika ledger tepat seperti dump ini, hasil perhitungannya41.
- SQL session dibuat strict sebelum CREATE PROCEDURE, lalu mode sebelumnya dikembalikan setelah sukses. Global sql_mode tidak diubah.
- DELIMITER/helper procedure dipakai agar kegagalan preflight menghentikan DDL di dalam routine. Helper adalah satu-satunya objek yang di-DROP; tidak ada DROP TABLE/DELETE/TRUNCATE.
- DDL melakukan implicit commit; skrip **tidak atomik** dan tidak dapat di-rollback utuh dengan ROLLBACK. [Dokumentasi implicit commit MariaDB](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit).

## Pemeriksaan yang dilakukan

**VERIFIED-EXECUTION (offline/static, bukan SQL runtime):**

1. Menjalankan method up() kelima migration melalui `Connection::pretend()`, dengan PDO resolver yang selalu melempar error jika koneksi dicoba. Tidak boot environment aplikasi atau menghubungkan MySQL.
2. Mencocokkan **12 DDL statements** di file SQL dengan keluaran compiler Laravel setelah normalisasi whitespace. Definisi kolom, nullability, PK, dan nama/urutan indeks cocok.
3. Memeriksa5 conditional ledger inserts, urutan migration, serta tidak adanya destructive table DDL atau disabling FK checks.
4. Menghitung ulang hash dump dan hash sumber; tidak mengimpor data sensitif dump ke artifact.
5. `git diff --check` lulus. Source PHP/config/schema migrations tidak diubah; file untracked `.rnd` existing dipertahankan.

Hash SHA256 sumber:

- `2026_10_19_172826_create_oauth_auth_codes_table.php`: `41dff9b139101246364cc9313dd9fecf56fca8cbea5c503c63634cf5a6c2cefd`.
- `2026_10_19_172827_create_oauth_access_tokens_table.php`: `3bf6dc642eae0edd69facbff1f0cb4d00a9f13c697120b505a1b244bf84b7b13`.
- `2026_10_19_172828_create_oauth_refresh_tokens_table.php`: `23f602fe6a363df8665f479a106f684bd695fa5007ede6521ee77dc9af1d7d26`.
- `2026_10_19_172829_create_oauth_clients_table.php`: `2d7123ec306666257a861e9f05d256b01cf0075812569fbe05f647d24d38359e`.
- `2026_10_19_172830_create_oauth_device_codes_table.php`: `f58e7e0409628118d1808631d8270692e54fdb2f227020365bb30a18b0564336`.

**NOT EXECUTED / UNVERIFIED:** parsing/eksekusi full procedure pada MariaDB11.4; import phpMyAdmin; privileges hosting; live schema/ledger; lock/durasi DDL; staging/application SSO smoke test. Offline compiler membuktikan generated DDL, bukan bahwa seluruh script sudah dijalankan atau production-ready.

## Pelaksanaan operator nanti

1. Backup schema+data terbaru dan rehearsal pada restored staging; jangan mengganti data produksi dengan dump lokal.
2. Verifikasi server/database aktual, privileges CREATE/ALTER TABLE, routine CREATE/EXECUTE/DROP dan SELECT/INSERT ledger. Hentikan write/OAuth issuance/workers dan concurrent migration/import.
3. Import seluruh file melalui client yang memahami DELIMITER dan berhenti pada error. Tidak menggunakan opsi force/ignore errors.
4. Jika error, inspeksi SHOW CREATE TABLE dan ledger; sebagian DDL dapat sudah committed. Jangan mengulang seluruh file, menghapus ledger, atau men-drop OAuth data agar guard lolos. Siapkan narrower roll-forward setelah rekonsiliasi.
5. Setelah sukses, periksa kelima SHOW CREATE TABLE/indeks/ledger pada bagian postcheck SQL, lalu migrate:status di deployment yang sesuai. SQL ini alternatif migration tanggal19 saja; tanggal14–15 memiliki artifact terpisah.
6. Pembuatan key/client dan aktivasi SSO merupakan tahap deployment terpisah sesuai [panduan SSO existing](../guides/SSO-OAUTH-LOGIN.md); tidak dijalankan oleh SQL ini. Rollback perilaku SSO memakai feature flag sesuai panduan dan tidak mengharuskan drop tabel berisi token.

Tidak ada deployment, commit, staging Git, schema/data mutation atau live production connection pada task pembuatan artifact ini.

## Perbaikan setelah laporan error1109

User melaporkan `Unknown table 'migrations' in information_schema` pada SELECT ledger postcheck. **Terbukti dari error:** statement itu dijalankan dengan context information_schema dan nama migrations tidak qualified. **Belum terbukti:** apakah context berubah lewat pemilihan schema di phpMyAdmin atau query dijalankan terpisah; apakah CREATE/ALTER dan ledger sebelumnya sudah selesai.

Artifact diperbaiki dengan qualifier database eksplisit untuk tabel, ledger, routine, SHOW CREATE dan filter metadata. Perubahan ini tidak mengubah definisi kolom/indeks. Script pemeriksaan terpisah [2026_10_19_verify_only.sql](../../database/2026_10_19_verify_only.sql) hanya SELECT/SHOW, dapat dijalankan tanpa memilih database produksi sebagai default.

**Jangan reimport migration.sql untuk mengatasi error pemeriksaan ini. Jalankan verify_only.sql dahulu.** Jika5 tabel/5 nama ledger/indeks dan kolom sesuai, masalahnya hanya postcheck dan DDL tidak perlu diulang. Jika ada yang kurang, kirim hasil/error pemeriksaan untuk rekonsiliasi spesifik; jangan drop tabel atau hapus ledger. Generator tidak menjalankan repair atau menghubungi produksi.
