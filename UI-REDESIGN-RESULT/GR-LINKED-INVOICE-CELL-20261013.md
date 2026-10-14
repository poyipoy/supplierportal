# Kolom invoice terkait pada detail PO

Tanggal: 13 Oktober 2026.

Tautan invoice pada tabel GR sekarang memakai ikon dokumen, nomor invoice dengan penekanan tipografi, serta aksi sekunder `View invoice` / `Lihat invoice`. Tidak ada underline permanen pada nomor; hover dan focus tetap terlihat. Nomor panjang membungkus di dalam kolom dan tidak diubah, dipotong, atau disimpan ulang. GR tanpa invoice tetap menampilkan label existing dalam warna netral.

Render awal Blade dan respons DataTables memakai partial yang sama. URL Finance, Purchasing, dan Supplier mempertahankan route serta hashid masing-masing; otorisasi, query, dan data invoice tidak berubah.

## Dieksekusi

- Database safety: 2 passed, 4 assertions.
- LocalProcurementInvoiceNavigationTest + LocalPoGrTableTest: 9 passed, 86 assertions, serial pada database tes.
- TranslationParityTest + TranslationContentTest + UserFacingCopyInventoryTest: 48 passed, 181386 assertions.
- Syntax PHP, scoped Pint, Blade cache, Vite build, dan scoped diff check lulus.
- Empat screenshot Chrome headless dari partial Blade asli dan CSS build: EN/ID, light/dark. Model contoh hanya dirender, tidak disimpan. Screenshot di `storage/framework/testing/gr-invoice-preview/`.

## Diinspeksi dan belum diverifikasi

Pemilihan route lintas role, relasi invoice, komponen ikon/focus, token CSS, dan escaping Blade diinspeksi. Produksi belum diubah atau diperiksa langsung. Screenshot adalah fixture lokal; interaksi browser terhadap endpoint produksi tidak diuji.

## File deployment, tanpa test

```text
app/Http/Controllers/LocalGoodsReceiptTableController.php
resources/views/finance/local-procurement/_gr_table.blade.php
resources/views/finance/local-procurement/_gr_invoice.blade.php   (baru)
lang/en/local_procurement.php
lang/id/local_procurement.php
public/build/                                                (seluruh folder)
```

`resources/css/app.css` juga berubah dan diperlukan jika membangun ulang aset di server. Upload file terjemahan bersama view agar label tidak tampil sebagai key mentah. Tidak ada route, package, atau migrasi baru.
