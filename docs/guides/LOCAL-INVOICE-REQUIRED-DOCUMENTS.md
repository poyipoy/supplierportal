# Dokumen PO dan GR pada invoice supplier

## Aturan penggunaan

- Setiap submit dan pengiriman revisi wajib memiliki satu PDF PO dan satu sampai lima PDF GR, maksimal 5 MiB per berkas.
- Dokumen yang dipertahankan pada revisi ikut dihitung. PO baru menggantikan PO pada revisi berikutnya; berkas revisi lama tetap menjadi histori.
- Satu PDF boleh memuat beberapa nomor GR. Jumlah berkas tidak membatasi jumlah GR terpilih; Finance memeriksa kecocokan isinya.
- Invoice lama tidak diubah otomatis. Saat direvisi, lampiran PO/GR yang belum ada wajib dilengkapi.
- Lampiran berada pada invoice, bukan pengganti dokumen Master PO.

## Proteksi dan batasannya

Unggahan memakai profil `invoice` pada native file inspection: PDF-only, maksimal 5 MiB, pemeriksaan MIME/ekstensi/envelope PDF, checked private storage, SHA-256, dan linkage revisi. Akses download dan reuse tetap memakai policy serta `FileAccessGuard`.

`VALIDATED` berarti lolos inspeksi native tersebut. Status ini bukan hasil antivirus dan tidak menjamin bebas malware, tidak adanya JavaScript atau embedded content, maupun keabsahan isi bisnis. Hosting yang dinyatakan tidak menyediakan scanner otomatis tetap memakai proteksi existing ini; tidak ada pengiriman dokumen ke layanan pemindai eksternal, OCR, atau penulisan ulang PDF.

## Urutan deployment

1. Back up dan uji migrasi pada restored staging. Penambahan enum tetap merupakan DDL yang perlu diperiksa terhadap versi MySQL/MariaDB dan ukuran tabel produksi.
2. Terapkan migrasi `2026_10_15_000001_add_po_and_gr_to_local_invoice_documents.php` sebelum merilis kode yang menulis jenis `purchase_order` dan `goods_receipt`.
3. Deploy kode, Blade, terjemahan EN/ID, dan hasil build; perbarui cache sesuai prosedur deployment existing.
4. Uji satu submit dan revisi dengan akun supplier staging, lalu pastikan Finance dapat melihat dan mengunduh lampiran.

Pembuatan fitur tidak menjalankan migrasi pada database aplikasi. Pengujian otomatis menggunakan `adasi_portal_test` secara serial.

## Pemeriksaan kapasitas hosting

Dengan batas seluruh kategori existing ditambah PO/GR, satu pengiriman dapat membawa 18 file baru dan total 90 MiB. Berkas tersimpan yang dipertahankan tidak dikirim ulang sebagai isi upload.

- Periksa konfigurasi **SAPI web**, bukan hanya PHP CLI.
- `max_file_uploads` harus mengizinkan minimal 18 file; nilai 20 mencukupi batas aplikasi ini.
- `post_max_size` perlu menampung 90 MiB ditambah overhead multipart; 100M adalah titik awal konfigurasi untuk diuji, bukan bukti kapasitas hosting.
- `upload_max_filesize` harus menampung 5 MiB per file invoice. Pertahankan batas global yang dibutuhkan modul lain, misalnya upload Master PO/ZIP 50 MiB.
- Cocokkan batas body web server/ModSecurity, waktu proses, direktori sementara, disk/inode, dan kuota akun. Jangan menaikkan atau menonaktifkan batas inspeksi native hanya agar upload lolos.
- Verifikasi kasus maksimal pada staging dengan sumber daya setara produksi. HTTP 413 harus menjelaskan batas pengiriman, bukan menyatakan berkas wajib belum diisi.

## Rollback

Rollback aplikasi tidak boleh menghapus lampiran. `down()` migrasi menolak penyempitan enum jika sudah ada dokumen PO/GR. Pertahankan schema additive tersebut ketika rollback kode; jangan menghapus dokumen untuk memaksakan rollback enum.
