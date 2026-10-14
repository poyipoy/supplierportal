# Akun Finance, GA, dan Supplier Local — 12 Oktober 2026

File import: [USER-ACCOUNTS-FINANCE-GA-LOCAL-20261012.sql](USER-ACCOUNTS-FINANCE-GA-LOCAL-20261012.sql).

Versi revisi ini khusus **MariaDB hosting**, mengikuti dump `adaw2196_supplierportal (3).sql` dengan server **11.4.13-MariaDB-cll-lve**. Gunakan file SQL revisi yang ditautkan di atas, bukan salinan lama.

File ini menambahkan tepat lima akun baru. Akun existing dipertahankan. SQL belum diimport pada database lokal maupun hosting.

## Kredensial

| Nama | Email login | Password | Role / scope |
|---|---|---|---|
| Finance 01 | finance01@example.com | password | finance |
| GA 01 | ga01@example.com | password | ga |
| Supplier Local 01 | supplier.local01@example.com | password | supplier / local |
| Supplier Local 02 | supplier.local02@example.com | password | supplier / local |
| Supplier Local 03 | supplier.local03@example.com | password | supplier / local |

Semua akun menggunakan `account_status=ACTIVE`, `is_active=1`, dan `must_change_password=0` sesuai pilihan pengguna. Password disimpan sebagai hash **bcrypt cost 12**, bukan teks plaintext di database. Tidak ada kewajiban mengganti password saat login pertama. Konfigurasi autentikasi, Turnstile, dan kebijakan keamanan hosting tetap berlaku.

Alamat `example.com` merupakan identitas login contoh; tidak disiapkan sebagai inbox untuk menerima email. Email login bersifat tetap setelah akun dibuat.

## Profil Supplier

| Akun | Perusahaan | Kategori | PKP | Payment term |
|---|---|---|---|---|
| Supplier Local 01 | PT Supplier Local 01 | Barang | Tidak | 30 hari |
| Supplier Local 02 | PT Supplier Local 02 | Barang | Tidak | 30 hari |
| Supplier Local 03 | PT Supplier Local 03 | Barang | Tidak | 30 hari |

PIC memakai nama dan email akun yang bersangkutan. Alamat, telepon, NIB, NPWP, fingerprint legalitas, kuesioner, dan dokumen tetap kosong/default. Supplier hanya memperoleh scope `local`. Profil dan scope menggunakan **ID dari tabel users**, tanpa ID hardcoded.

File tidak membuat rekening, dokumen, attempt registrasi, notifikasi, employee, PO/GR, invoice, GA claim, atau pembayaran. Rekening dan transaksi perlu disiapkan melalui workflow masing-masing apabila akan menguji DRP.

## Persyaratan database

- Pilih database **ADASI Portal Supplier** milik hosting di phpMyAdmin. File tidak berisi `USE` atau membuat database.
- Struktur harus mengikuti migrasi repository, khususnya `users.must_change_password` dari migrasi `2026_10_12_000002_add_financial_review_and_credential_controls`.
- `users`, `suppliers`, dan `supplier_scopes` harus memakai **InnoDB**. Email user harus memiliki unique index satu kolom.
- Role enum harus mendukung `finance`, `ga`, `supplier`; account status mendukung `ACTIVE`; scope mendukung `local`.
- Hosting menggunakan bcrypt untuk password sebagaimana konfigurasi proyek yang diperiksa. **File revisi memakai `BEGIN NOT ATOMIC` khusus MariaDB; jangan jalankan pada MySQL lokal.** Struktur kolom/index diperiksa langsung dari dump hosting; eksekusi pada MariaDB hosting belum diuji.

Pemeriksaan awal melalui tab **SQL** phpMyAdmin, tanpa mengubah data:

```sql
SELECT DATABASE() AS selected_database;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'suppliers', 'supplier_scopes');

SHOW COLUMNS FROM users LIKE 'must_change_password';

SHOW CREATE TABLE users;
SHOW CREATE TABLE suppliers;
SHOW CREATE TABLE supplier_scopes;

SELECT name, email, role
FROM users
WHERE email IN (
    'finance01@example.com', 'ga01@example.com',
    'supplier.local01@example.com', 'supplier.local02@example.com',
    'supplier.local03@example.com'
);
```

Sebelum import pertama, query email di atas harus menghasilkan **nol baris**. Bila kolom/migrasi yang diperlukan belum ada, selesaikan pembaruan schema hosting terlebih dahulu; file ini tidak menjalankan migrasi.

## Import tanpa terminal

1. Buka **cPanel → phpMyAdmin**, kemudian pilih database ADASI yang benar.
2. Jalankan pemeriksaan awal di atas dan siapkan backup database melalui hosting.
3. Download ulang file SQL revisi yang ditautkan di awal dokumen. Buka tab **Import**, pilih file tersebut, gunakan format SQL, lalu jalankan import. Jangan memakai salinan lama dari Downloads.
4. Import **seluruh file sekaligus**, dengan partial import/interruption dinonaktifkan jika tersedia. Semua statement harus berjalan pada satu koneksi; jangan memisahkan bagian file menjadi beberapa request.
5. Periksa result set terakhir. Hasil sukses harus `CREATED` dengan `users_created=5`, `supplier_profiles_created=3`, dan `local_scopes_created=3`.
6. Jalankan query pemeriksaan hasil berikut, kemudian coba login masing-masing akun menggunakan password `password`.

File menggunakan satu blok server MariaDB `BEGIN NOT ATOMIC` dengan delimiter `$`. Semua INSERT dan penghitungan hasil berada di dalam blok yang sama sehingga query client tidak dapat menyela `ROW_COUNT()`. Handler otomatis menjalankan ROLLBACK dan menampilkan error jika ada exception atau warning. COMMIT hanya dilakukan setelah tepat 5 user, 3 profil, dan 3 scope terbentuk. Tidak membuat procedure atau object database tambahan.

Jangan mengaktifkan continue-on-error. Jika phpMyAdmin menampilkan error parser terkait delimiter/blok, jangan menjalankan potongan INSERT atau COMMIT secara terpisah. Catat pesan error lengkap agar kompatibilitas client dapat diperiksa.

## Perilaku import

| Skenario | Hasil |
|---|---|
| Schema sesuai dan kelima email belum ada | Insert 5 user, 3 profil, 3 scope; commit hanya saat seluruh jumlah sesuai |
| Salah satu email sudah ada | Error `Account import aborted: one or more target emails already exist`; rollback seluruh batch, akun existing tidak diubah |
| Import ulang setelah sukses | Error bentrok email; tidak membuat duplikat atau mereset password |
| Pemeriksaan schema tidak sesuai | Error schema tidak kompatibel atau error tabel/kolom; handler rollback, tidak commit |
| Jumlah insert tidak tepat | Error jumlah insert tidak tepat; handler rollback seluruh batch |
| Error insert/FK/unique, termasuk email bentrok karena concurrency | Handler rollback otomatis dan menampilkan error; jangan melanjutkan manual |

Result set `CREATED` ditampilkan hanya setelah commit sukses.

### Jika phpMyAdmin menampilkan #2014 setelah import

Error `Commands out of sync` saat phpMyAdmin menjalankan `SET FOREIGN_KEY_CHECKS = ON` tidak membuktikan transaksi akun gagal. Versi awal blok mengeluarkan result set dari dalam blok; versi terbaru memindahkan SELECT hasil ke luar blok untuk menghindari hasil bersarang. Penyebab ini merupakan dugaan kompatibilitas client dan belum diuji pada hosting.

Buka ulang phpMyAdmin melalui cPanel untuk koneksi baru, pilih database aplikasi, lalu jalankan query pemeriksaan hasil di bawah sebelum mengimport ulang. Jika sudah ada lima akun beserta tiga profil dan scope lokal, import tidak perlu diulang. Jika seluruh email belum ada, download ulang SQL terbaru dan import seluruh file. Pertahankan foreign key checks aktif; jangan mencoba menonaktifkan constraint untuk mengatasi error koneksi ini. Jika hanya sebagian akun/profil/scope muncul, hentikan dan catat hasilnya; jangan menghapus atau mereset akun secara manual.

## Pemeriksaan hasil

```sql
SELECT
    u.id,
    u.name,
    u.email,
    u.role,
    u.account_status,
    u.is_active,
    u.must_change_password,
    s.company_name,
    s.vendor_category,
    s.is_pkp,
    s.payment_term_days,
    (
        SELECT GROUP_CONCAT(sc.scope ORDER BY sc.scope SEPARATOR ',')
        FROM supplier_scopes AS sc
        WHERE sc.supplier_id = u.id
    ) AS supplier_scopes
FROM users AS u
LEFT JOIN suppliers AS s ON s.user_id = u.id
WHERE u.email IN (
    'finance01@example.com', 'ga01@example.com',
    'supplier.local01@example.com', 'supplier.local02@example.com',
    'supplier.local03@example.com'
)
ORDER BY FIELD(
    u.email,
    'finance01@example.com', 'ga01@example.com',
    'supplier.local01@example.com', 'supplier.local02@example.com',
    'supplier.local03@example.com'
);
```

Hasil yang diharapkan: tepat **5 baris**, semua ACTIVE/aktif/tanpa kewajiban ganti password. Finance dan GA tidak memiliki profil atau scope Supplier. Ketiga Supplier memiliki perusahaan sesuai tabel, non-PKP, term 30 hari, dan scope tepat `local`.

## Temuan dump hosting dan revisi

- Dump hosting memiliki **19 user, 10 profil Supplier, 11 scope**, dan tidak memuat satu pun dari kelima email target. Dump tersebut menunjukkan batch akun baru belum tersimpan, bukan bukti sebagian dari lima akun berhasil masuk.
- Kolom `must_change_password`, profil Supplier, scope `local`, enum role/status, InnoDB, auto-increment, unique email, dan FK yang dibutuhkan tersedia.
- SQL lama menggunakan `ROW_COUNT()` di antara statement client terpisah. Query tambahan client dapat mengubah penghitung dan menyebabkan rollback. Ini **dugaan penyebab**, bukan temuan dari log error import; dump tidak memuat riwayat eksekusi SQL.
- SQL revisi memindahkan keseluruhan operasi ke satu blok MariaDB dengan handler rollback. Tidak bergantung pada penghitungan antar-query phpMyAdmin.

## Verifikasi yang dilakukan

- Schema/kolom/index/FK diperiksa dari dump hosting secara read-only; dump tidak diimport atau diubah.
- Hash bcrypt cost 12 menerima `password` dan menolak password pembanding.
- Cakupan tetap 5 user, 3 profil dan 3 scope lokal, tanpa overwrite atau hardcoded ID.
- Alur happy path, email bentrok/import ulang, kegagalan INSERT, warning, dan count mismatch ditinjau secara statis.
- Validasi PREPARE pada MySQL lokal di versi awal **tidak memverifikasi blok MariaDB versi revisi**.
- Tidak tersedia server MariaDB lokal untuk uji eksekusi. Import SQL nyata dan login hosting **belum diuji**; tidak ada INSERT atau mutasi database selama revisi.

Referensi MariaDB: [compound statements di luar stored programs](https://mariadb.com/docs/server/reference/sql-statements/programmatic-compound-statements/using-compound-statements-outside-of-stored-programs) dan [perilaku ROW_COUNT](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/information-functions/row_count).
