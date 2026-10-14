# Local PO: satu referensi PR — hasil implementasi

Tanggal: 14 Oktober 2026. Rancangan ini menggantikan rancangan awal banyak PR pada satu PO.

## Perilaku akhir

- Satu PO mempunyai maksimal satu PR opsional; nomor PR yang sama boleh dipakai beberapa PO.
- Berkas PO menambahkan Q berjudul `PR`, tanpa menggeser kolom A–P. Berkas XLSX/CSV/XLS lama tetap diterima. Template memformat Q sebagai teks; nol yang sudah dihilangkan Excel sebelum upload tidak dapat direkonstruksi.
- Nomor PR di-trim, maksimal 100 karakter, dibandingkan tanpa membedakan kapital, dan ditampilkan dengan bentuk asal. Formula, karakter kontrol dan daftar nomor dalam satu sel ditolak. Nomor `0` sah dan tetap terlihat.
- Baris PO berulang harus konsisten supplier/tanggal/total. Kosong dapat digabung dengan satu PR terisi; dua PR berbeda memblokir file, termasuk lintas batch. Total tidak dijumlah ulang.
- Impor ulang dapat mengisi PR kosong pada PO existing yang header-nya identik. PR terisi tidak diganti/dihapus; cancelled tetap ditolak. File yang hanya mengisi PR dapat dikonfirmasi; seluruh pasangan identik tetap tanpa perubahan.
- Preview menampilkan `FILL_PR` dengan label terjemahan dan `po_pr_filled`. Hasil commit menggunakan `filledPoPr` aktual; jumlah dilewati tidak memasukkan PO yang baru diisi PR.
- Master PO Finance/Purchasing, daftar/detail supplier, serta pilihan PO invoice baru/revisi menampilkan PR. Pencarian menerima PO/PR tanpa melonggarkan ownership, status atau syarat GR existing. PR lengkap tersedia di bawah pilihan PO; teks panjang membungkus pada layar kecil.

PR tidak mengubah eligibility invoice, GR, pajak, batas nilai PO, pembayaran atau snapshot keuangan. Tidak ada Master PR, relasi ke core pengadaan impor, edit/koreksi PR manual, backfill atau package baru.

## Penyimpanan dan keamanan

Migrasi `database/migrations/2026_10_14_000001_add_pr_number_to_local_purchase_orders.php` menambahkan nullable `pr_number` varchar(100) dengan indeks non-unique. Rollback menolak penghapusan jika terdapat referensi terisi.

Penulisan metadata dan audit `po_pr_filled` berada di transaksi seluruh file. PO dikunci mengikuti urutan existing, lalu header/status/PR diperiksa ulang. Pengisian batch menggunakan CASE dengan parameter binding dan kondisi PR kosong; kolom finansial tidak masuk statement update. Duplikasi delivery tidak menambah audit atau mengubah PR existing. Model juga menolak penggantian/penghapusan PR terisi pada save biasa.

Tidak ada endpoint baru. Payload pencarian PO menambahkan `pr_number`; summary import menambahkan `po_pr_filled`/`filledPoPr`, dengan default nol untuk preview legacy. Preview lama tanpa field PR tetap diterima.

## Dieksekusi

- Safety guard database: 2 lulus, 4 assertions, sebelum feature tests.
- RED normalisasi: 10 gagal karena helper belum ada; RED pipeline: 6 gagal karena PR belum tersimpan/konflik belum dijaga/PR-only belum actionable.
- Verifikasi akhir PR: **49 lulus, 260 assertions** melalui unit normalisasi, feature HTTP/pipeline, dan concurrency MySQL dua subprocess dengan aktor berbeda. Test suite tetap satu proses serial pada `adasi_portal_test`.
- Regresi gabungan: 166 lulus; satu kegagalan ledger copy diperbaiki dengan sinkronisasi ledger canonical. Suite copy sesudahnya: 28 lulus, 87.282 assertions. Cakupan gabungan meliputi background PO/GR, revalidation, cancelled imports, telemetry, informasi preview, supplier isolation, dan EN/ID parity/content.
- JavaScript modal: **80 lulus**. `--test-isolation=none` digunakan setelah runner subprocess diblokir EPERM; perilaku tes tetap dijalankan.
- Browser: **56 skenario lulus** melalui `tests/Support/local-po-pr-browser-check.mjs`: EN/ID × light/dark × 375/1440, daftar/detail operator/supplier, invoice create/revision, serta PR-only import. Keyboard memilih PR, dropdown menutup, dialog mempunyai nama/role, dan fokus tetap di modal. Grid invoice diperbaiki setelah QA menemukan overflow nomor PR panjang. Screenshot mobile ID dark diperiksa secara visual.
- Browser menggunakan Blade nyata yang dirender dari fixture tes, aset build, dan respons status terkontrol. Ini bukti rendering/interaksi, bukan deployment atau impor end-to-end pada database aplikasi. Aset CDN resmi diverifikasi SRI sebelum dipakai.
- Pint scoped lulus; PHP syntax dan JavaScript parse lulus; Vite build lulus (27 modules); Blade cache lulus; diff check scoped lulus; reconcile read-only: **0 issues**.
- Inspeksi schema aplikasi read-only: `adasi_portal` belum memiliki kolom PR dan ledger migrasinya belum tercatat. Tidak ada migrasi aplikasi dijalankan.

Artefak lokal (ignored): `storage/framework/testing/po-pr-final-tests.log`, `po-pr-final-assets.log`, `po-pr-copy-check.log`, dan `po-pr-ui/browser/result.json` beserta screenshot. Fixture 12 halaman dibuat dengan `PO_PR_UI_FIXTURES=1` pada feature test PR.

## Diinspeksi

Alur reader → staging → konsolidasi → preview → confirm → locked commit; model/migrasi; pola audit; query supplier/operator; pilihan invoice create/revision termasuk PO CLOSED current; kompatibilitas CSV minimum kolom dan staging legacy; escaping Blade/textContent; teks EN/ID; serta lock/rollback/race melalui review independen.

Review menemukan header Q bertipe tanggal, baris PR-only pada reader legacy, dan tampilan nomor `0`; semuanya diperbaiki dan diberi regresi. Tidak ditemukan temuan high/critical pada scope PR.

## File runtime fitur

- `app/Support/LocalPoPrNumber.php` (baru), `app/Models/LocalPurchaseOrder.php`, migrasi PR (baru).
- `app/Services/LocalInvoice/LocalPoImportService.php`, `LocalProcurementImportService.php`, `LocalProcurementMasterService.php`, `LocalProcurementStreamingReader.php`, `LocalPoReferenceService.php`.
- `app/Imports/LocalPoImport.php`, `app/Exports/LocalPoImportTemplateExport.php`.
- `app/Http/Controllers/Finance/LocalProcurementController.php`, `LocalProcurementImportController.php`; `app/Http/Controllers/LocalSupplier/InvoiceController.php`, `PurchaseOrderController.php`.
- `resources/views/finance/local-procurement/index.blade.php`, `show.blade.php`, `_import_po_modal.blade.php`; `resources/views/local-supplier/purchase-orders/index.blade.php`, `show.blade.php`; `resources/views/local-invoices/form.blade.php`.
- `resources/js/local-procurement-import.js`; `lang/en/local_procurement.php`, `lang/id/local_procurement.php`, `lang/en/js.php`, `lang/id/js.php`; hasil `public/build/` beserta manifest.

Dokumentasi: `AGENTS.md`, `docs/guides/LOCAL-PROCUREMENT-IMPORTS.md`, laporan ini, serta ledger copy canonical. Tes tambahan berada pada `LocalPoPrNumbersTest`, `LocalPoPrReferenceTest`, `LocalPoPrReferenceConcurrencyTest`, worker/browser checker PR, dan tes JavaScript modal existing. Banyak file sudah dirty sebelum task; daftar di atas menyebut file yang disentuh fitur ini, bukan seluruh perubahan repository atau izin men-stage semua isinya.

## Belum diverifikasi / penerapan

- Migrasi pada DB aplikasi lokal, staging, atau produksi **belum dijalankan**. Setelah backup dan pemeriksaan ledger, terapkan migrasi yang tepat dengan instruksi tersendiri sebelum menggunakan kode baru. Koordinasikan drain/restart worker dan deploy PHP/views/EN-ID serta seluruh build bersama.
- Produksi/cPanel, batas resource host pada dataset maksimal, screen reader manual, serta upload/impor nyata pada DB aplikasi belum diuji.
- Tidak menjalankan full-project suite. Tidak melakukan commit, push, reset, clean, rollback, atau penghapusan data aplikasi. Perubahan unrelated dipertahankan.
