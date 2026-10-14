# Hasil perbaikan modal import PO/GR dan pagination GR

Tanggal: 12 Oktober 2026. Scope: implementasi rencana yang disetujui, tanpa commit/push atau migrasi database aplikasi.

## Perilaku yang diterapkan

| Sebelumnya | Sekarang |
| --- | --- |
| Status import berupa satu baris teks di body modal | Panel status pada header berisi berkas, tahap, mini progress bar, jumlah record, dan bantuan kontekstual |
| QUEUED hanya menunjukkan nol baris | Indikator tanpa persen dengan penjelasan menunggu worker |
| Penyimpanan tidak memiliki progres sebelum commit | Worker melaporkan batch melalui file cache; polling HTTP membaca progres di luar transaksi master |
| Progres penyimpanan dapat tertukar dengan baris sumber | Counter memakai record PO/GR hasil konsolidasi, termasuk existing yang diperiksa |
| Panduan format selalu memakan ruang besar | Panduan dapat dibuka, template tetap terlihat, ringkasan validasi lebih ringkas |
| Aksi validasi/konfirmasi terlihat sepanjang proses | Aksi mengikuti tahap; Tutup berbeda dengan Batalkan import |
| Detail PO memuat semua GR dan modal per GR | Feed DataTables server-side, maksimum 100 per respons; satu modal edit dan satu pembatalan untuk operator |
| Tidak ada nomor baris lintas halaman | Kolom No. mengikuti offset server; pencarian, status, dan ordering diproses di SQL |

Polling tampilan setiap 3 detik; saat tab tersembunyi dihentikan dan dilanjutkan ketika aktif. Reset/resume modal, handoff antar PO/GR, konfirmasi eksplisit, watcher penyimpanan setelah modal ditutup, dan perlindungan respons lama tetap berlaku.

WAITING_SAVE menandai antrean konfirmasi bila snapshot tersedia. PREPARING/SAVING/FINALIZING/RETRYING adalah fase advisory; database COMPLETED saja yang membuktikan sukses dan 100%. Snapshot terikat owner job dan execution, kedaluwarsa satu jam, serta dibersihkan setelah penyimpanan/callback kegagalan dan cleanup terminal. Snapshot hilang atau kedaluwarsa menggunakan indikator tak terukur; perubahan lama tidak dianggap kegagalan worker. Kegagalan cache maupun log tidak membatalkan transaksi.

Otorisasi/status/CSRF import dan GR tetap berlaku. Supplier hanya membaca PO sendiri, tidak mendapat edit metadata/aksi, dan tidak melihat GR cancelled. Finance, Purchasing, dan Supplier memakai handler feed yang sama dengan konteks route berbeda. Respons private/no-store dan parameter SQL memakai whitelist server. Initial Blade hanya memuat 25 GR sebagai fallback; total GR/ringkasan PO mencakup seluruh data. Nominal yang disentuh memakai Money exact dan formatter regional, termasuk nilai di atas batas presisi integer float.

Teks baru EN/ID berpasangan. Kamus import dimuat sekali pada halaman melalui `local-import-i18n`; kamus global tetap di bawah 16 KiB. Tidak ada package, konfigurasi Pusher, queue, cron, atau migrasi baru.

## Verifikasi yang dieksekusi

Semua proses pengujian MySQL dijalankan serial pada `adasi_portal_test`.

| Pemeriksaan | Hasil terakhir |
| --- | --- |
| TestingEnvironmentDatabaseSafetyTest | 2 passed, 4 assertions |
| LocalProcurementBackgroundImportTest | 33 passed, 376 assertions |
| LocalImportProgressTest | 7 passed; termasuk HTTP ketika transaksi worker masih terbuka, retry, rollback, fencing callback failed, serta kegagalan cache/log |
| LocalPoGrTableTest | 7 passed, 80 assertions; 125 GR, maksimum respons 100, numbering, filter/search/order, escaping, hak akses, ringkasan, dan save ulang quantity |
| LocalImportUiFixtureTest | 1 passed, 22 assertions; render dan feed EN/ID untuk tiga audience |
| LocalProcurementInvoiceNavigationTest + LocalGoodsReceiptInformationTest | 8 passed, 45 assertions |
| LocalInvoiceScopeIsolationTest + LocalGrImportTest | 45 passed, 181 assertions |
| LocalizationPerformanceTest + FrontendAssetLoadingTest | 9 passed, 1183 assertions |
| RegionalDisplayFormatterTest | 17 passed, 138 assertions |
| TranslationParityTest + TranslationContentTest + UserFacingCopyInventoryTest | 48 passed, 181381 assertions |
| JavaScript modal import | 68 passed |
| Chrome headless dengan Blade/aset build nyata dan feed terkendali | 22 skenario passed |
| PHP syntax | 15 file terkait passed; perubahan berikutnya juga dimuat oleh tes terkait |
| Scoped Pint --test | Passed; file PHP baru/terakhir diformat dengan scoped Pint |
| Blade view:cache | Passed |
| Vite build | Passed, 27 modules |
| route:list --path=goods-receipts | 9 route terdaftar, termasuk 3 feed baru |
| git diff --check | Passed; hanya peringatan normalisasi CRLF pada working tree |
| local-invoices:reconcile --json | issue_count 0 |

Browser mencakup modal GR pada 375/768/1440 px untuk EN/ID dan light/dark; modal PO EN/ID light/dark; reflow setara viewport 400%; reduced motion; label/value pada accessibility tree; fokus tetap dalam modal; Escape; kontras teks minimal 4.5:1; halaman kedua tabel bernomor 26; edit/batal GR dan pemulihan fokus; Supplier tanpa aksi operator. Screenshot akhir berada di `storage/framework/testing/import-ui/browser/`, bersama `result.json`. Aset core DataTables untuk fixture diambil dari CDN resmi dan diverifikasi terhadap SHA-384 yang sama dengan markup aplikasi.

Kegagalan sementara yang diperbaiki selama verifikasi: fixture timer lama 5 detik, assertion separator angka, checker yang belum menunggu draw/transisi Bootstrap, kamus global melewati 16 KiB, overflow baris recovery pada tablet, kontras ikon tutup dark mode, quantity edit berisi padding empat desimal, serta label fase utama yang sempat tidak tampil setelah pengurangan pengumuman berulang. Assertion browser/JavaScript kini memeriksa teks fase utama secara eksplisit. Hasil lulus di atas merupakan eksekusi setelah perbaikan terkait.

## Yang hanya diinspeksi

Routes/middleware/policy, schema staging/index GR, transaksi dan batching import, callback worker/cleanup, format Infor dan mixed UOM, integrasi kalender/DataTables, serta konfigurasi file cache dan deployment existing. Desain mengikuti token portal dan referensi resmi [Carbon progress bar](https://carbondesignsystem.com/components/progress-bar/usage/), [W3C ARIA25](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA25), [W3C modal dialog](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/), [Laravel cache](https://laravel.com/docs/12.x/cache), dan [DataTables server-side](https://datatables.net/manual/server-side).

## Yang belum diverifikasi

- Deployment, izin cache web/CLI pada hosting cPanel nyata, dan graceful restart worker produksi belum dijalankan.
- Browser menggunakan fixture server dengan respons terkendali, bukan sesi produksi. Transaksi/HTTP/otorisasi diverifikasi terpisah melalui tes MySQL dan subprocess worker nyata.
- Penggunaan NVDA/VoiceOver dengan keluaran suara oleh manusia belum dilakukan; verifikasi aksesibilitas browser mencakup accessibility tree, keyboard, kontras, dan reduced motion.
- Benchmark ulang file 70.000 baris pada hosting produksi tidak dijalankan. Batas transaksi/batch/file existing tidak dinaikkan.
- Full suite seluruh portal tidak dijalankan untuk revisi ini; pemeriksaan dipilih sesuai domain yang berubah.

## File deployment, tanpa test dan laporan

```text
app/Jobs/ProcessLocalProcurementImport.php
app/Console/Commands/CleanupLocalProcurementImports.php
app/Services/LocalInvoice/LocalProcurementImportService.php
app/Services/LocalInvoice/LocalProcurementImportProgress.php
app/Services/RegionalDisplayFormatter.php
app/Support/JsTranslations.php
app/Http/Controllers/Finance/LocalProcurementImportController.php
app/Http/Controllers/Finance/LocalProcurementController.php
app/Http/Controllers/LocalSupplier/PurchaseOrderController.php
app/Http/Controllers/LocalGoodsReceiptTableController.php
routes/web.php
lang/en/js.php
lang/id/js.php
lang/en/local_procurement.php
lang/id/local_procurement.php
resources/css/app.css
resources/js/app.js
resources/js/local-procurement-import.js
resources/js/local-po-gr-table.js
resources/views/finance/local-procurement/_import_po_modal.blade.php
resources/views/finance/local-procurement/_import_gr_modal.blade.php
resources/views/finance/local-procurement/show.blade.php
resources/views/finance/local-procurement/_gr_table.blade.php
resources/views/finance/local-procurement/_gr_status.blade.php
resources/views/finance/local-procurement/_gr_actions.blade.php
resources/views/finance/local-procurement/_gr_modals.blade.php
resources/views/local-supplier/purchase-orders/show.blade.php
public/build/ (seluruh folder, termasuk manifest.json)
```

Upload source server/views/translations yang berubah dan seluruh folder build bersama. Source JS/CSS diperlukan untuk rebuild, sedangkan runtime browser memakai hasil build. Pertahankan revisi sebelumnya yang masih diperlukan; daftar ini hanya perubahan revisi modal/progres/pagination ini. Panduan penerapan dan perintah fixture ada di [LOCAL-PROCUREMENT-IMPORTS](../docs/guides/LOCAL-PROCUREMENT-IMPORTS.md). Drain pekerjaan aktif dan graceful restart worker melalui mekanisme operasional existing; tidak perlu migrasi baru.
