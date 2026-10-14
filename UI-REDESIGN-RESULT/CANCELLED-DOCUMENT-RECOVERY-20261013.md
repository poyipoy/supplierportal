# Pemulihan invoice, PO, dan GR yang dibatalkan di portal

## Perilaku

- Supplier aktif dengan scope lokal memilih **Ajukan ulang** pada invoice cancelled miliknya. Nomor invoice, nomor pengajuan, dan receipt tetap; pengiriman membuat revision baru dan mengulang penerimaan fisik/verifikasi. Histori pembatalan dan lampiran lama tetap tersimpan.
- Finance/Admin/Purchasing sesuai route memilih **Pulihkan** pada PO atau GR lama. PO menjadi OPEN tanpa memulihkan GR otomatis. GR menjadi AVAILABLE hanya ketika PO OPEN dan tidak terdapat owner, reservasi, atau konsumsi aktif.
- Alasan setelah trim wajib, maksimum 1.000 karakter, beserta konfirmasi bahwa dokumen bisnis masih berlaku. Pemulihan ini untuk kesalahan portal; validitas dokumen ERP dikonfirmasi pengguna, tanpa integrasi ERP baru.
- Nomor tetap unik. Pembuatan invoice baru dengan identitas cancelled mengarahkan pemilik ke pengajuan ulang. Impor PO/GR cancelled menghasilkan error nomor/baris dan memblokir seluruh file; status diperiksa lagi saat commit. Tidak ada pengaktifan otomatis dari impor.
- Status/alokasi, eligibility, PO, GR, batas nilai, pajak, dan dokumen diperiksa ulang di server. Voucher/pembayaran/alokasi aktif memblokir invoice. Verifikasi revisi lama tidak digunakan sebagai verifikasi revisi aktif.

## Keputusan implementasi

Memakai service, tabel revision/history, finance audit, formulir invoice, serta endpoint resubmit existing. Empat route POST pemulihan baru mengikuti middleware role/auth dan throttle 30/menit; policy dan service memeriksa ulang actor. Transaksi memakai urutan supplier/user, PO, lalu GR agar pemulihan dan impor tidak membuat alokasi ganda. Tidak ada package, migrasi, backfill, penghapusan histori, atau pelepasan unique constraint.

UI memakai label/error EN/ID, alasan dan checkbox native, modal Bootstrap dengan fokus awal, Tab/Shift+Tab berputar, Escape, serta fokus kembali ke trigger atau filter ketika tabel redraw. Named error bag PO/GR membuka kembali modal dengan target dari route server. Komponen upload diperbaiki agar `x-if` hanya dipakai pada template; dokumen retained tidak lagi menimbulkan exception Alpine.

## Bukti yang dieksekusi

- RED invoice: 7 gagal, 1 lulus; RED impor: 15 gagal, 2 lulus; RED PO/GR: 28 gagal karena route/method belum tersedia. RED JavaScript awal: 3 gagal, 1 lulus; tambahan boundary Tab gagal sebelum perbaikan.
- Pengujian pemulihan awal: 58 lulus/397 assertions; pengujian pemulihan dengan rendering EN/ID dan guard tambahan: 62 lulus/427 assertions. Concurrency memakai proses/connection terpisah di dalam satu suite serial pada `adasi_portal_test`.
- Regresi invoice, filename, supplier isolation, dan GR table: 121 lulus/759 assertions.
- Regresi background import, GR import, whole-GR settlement, Finance verification, dan concurrency existing: 91 lulus/665 assertions.
- Pemeriksaan akhir gabungan pemulihan, EN/ID/copy ledger, rendering komponen, dan error modal: **126 lulus / 183.291 assertions**. Error GR membuka kembali modal yang benar dengan target dari route server.
- JavaScript: **26 lulus**, mencakup pemulihan tanpa DataTables, URL asing ditolak, reason trim, siklus Tab/Shift+Tab, fokus setelah redraw, feedback filename, file-preserving submit, serta batas jenis/ukuran upload.
- Browser Chrome headless: **10 skenario lulus** pada view Laravel dan build asli dengan HTTP feed fixture. Modal EN/ID light/dark, 375px, reflow 400% (360x237), role/nama dialog, validasi native, keyboard dan focus return, kontras 5,68 (light) dan 6,64 (dark). Dua hidden input lampiran retained benar-benar terbentuk tanpa exception Alpine. Persistence/otorisasi dibuktikan terpisah melalui HTTP/backend tests. Hasil/screenshot: `storage/framework/testing/cancelled-recovery-ui/browser/`.
- Build 27 modul lulus. Blade cache dan formatter terarah lulus. Copy ledger diperbarui.

- Pemeriksaan sintaks PHP/JS, Pint terarah, Blade cache, manifest/seluruh aset rujukan, serta scoped diff check lulus. Route list mengonfirmasi empat route POST dengan auth/role/throttle yang sesuai. Reconcile lokal read-only melaporkan **0 masalah**.
- Verifier menemukan lalu membuktikan perbaikan fokus Tab, exception Alpine retained files, message generation tanpa principal, modal error GR, dan konversi encoding UTF-8. Percobaan formatter yang ditolak sandbox diulang secara terarah dan lulus; warning penulisan cache PHPUnit dihilangkan dengan `--do-not-cache-result`. `Pint --dirty --test` juga menemukan masalah format pada file dirty di luar task; hanya file terkait diformat dan hasil terarah yang dinyatakan lulus.

## Yang hanya diinspeksi dan belum diverifikasi

Diinspeksi: schema/FK/unique constraints, scope/policies, route middleware, transaction/lock order, voucher/payment dependencies, private document linkage, kompensasi berkas, serta ketergantungan pembatalan PO terhadap GR.

Belum diverifikasi: deployment produksi, transaksi supplier nyata pada hosting, pengakuan validitas dokumen di ERP, screen reader manual/NVDA, full suite seluruh portal, dan angka coverage instrumentasi. Review akhir dilakukan langsung oleh agent utama; dua tugas agen terhenti karena batas penggunaan, lalu dilengkapi agent utama.

Tidak menjalankan commit, push, merge, reset, clean, atau migrasi database aplikasi. Tes hanya menggunakan database tes. Perubahan lain pada working tree dipertahankan.

## File runtime untuk penerapan

- Controllers: `app/Http/Controllers/Finance/{LocalProcurementController,FinanceInvoiceController}.php`, `app/Http/Controllers/Purchasing/PurchasingLocalVendorController.php`, `app/Http/Controllers/LocalSupplier/InvoiceController.php`, `app/Http/Controllers/LocalGoodsReceiptTableController.php`.
- Requests: `app/Http/Requests/LocalInvoice/{RestoreLocalProcurementRequest,StoreLocalInvoiceRequest,ResubmitLocalInvoiceRequest}.php`.
- Policies: `app/Policies/{LocalInvoicePolicy,LocalPurchaseOrderPolicy}.php`.
- Services: `app/Services/LocalInvoice/{InvoiceSubmissionService,LocalProcurementMasterService,LocalPoImportService,LocalGrImportService}.php`.
- `routes/web.php`; `lang/en/{local_invoice,local_procurement}.php`; `lang/id/{local_invoice,local_procurement}.php`.
- Views: `resources/views/local-invoices/{form,detail}.blade.php`, `resources/views/local-supplier/invoices/revision.blade.php`, `resources/views/components/ui/file-upload.blade.php`, `resources/views/finance/local-procurement/{show,_gr_actions,_gr_table,_restore_modal}.blade.php`.
- `resources/js/local-po-gr-table.js` bila server membangun aset; seluruh `public/build/` beserta manifest dan semua chunks yang dirujuk.

`AGENTS.md`, laporan ini, copy ledger, dan tests merupakan dokumentasi/verifikasi, bukan bagian yang perlu dipanggil aplikasi. Deploy PHP/views/translations/routes/build sebagai satu versi; refresh route/view cache melalui prosedur deployment existing. Tidak memerlukan migrasi baru.
