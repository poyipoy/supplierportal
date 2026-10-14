# PR searchable pada PO manual — 14 Oktober 2026

## Hasil

Field PR tersedia setelah nomor PO pada create dan edit PO yang PR-nya kosong. Pengguna dapat memilih nomor existing lintas supplier atau mengetik nomor baru; teks yang diketik langsung menjadi nilai form tanpa harus memilih saran. PR tetap opsional dan maksimal satu per PO. PR terisi ditampilkan readonly; request penggantian atau penghapusan ditolak backend. Field yang tidak dikirim mempertahankan nilai existing.

Mode `allow-custom` dan `suggestions-url` ditambahkan secara opt-in pada `x-ui.searchable-select`; default dropdown existing tidak berubah. Komponen editable memakai input native bernama `pr_number`, combobox/listbox/active-descendant, status live, keyboard Arrow Up/Down/Enter/Escape/Tab, output `x-text`, pembungkusan saran panjang, dan token tema existing. Enter tanpa pilihan mempertahankan teks manual. Kegagalan pencarian tidak menonaktifkan input.

Pencarian minimum dua karakter dengan debounce 300 ms dan maksimal 20 hasil. Request yang diganti, dibatalkan, ditutup, atau komponennya dihancurkan tidak boleh memperbarui hasil. Fokus ulang pada combobox yang masih terbuka mempertahankan hasil dan posisi keyboard.

## Backend dan integritas

- GET `finance.local-procurement.pr-suggestions` dan `purchasing.local-procurement.pr-suggestions`, throttle 120/menit, auth/role existing dan `LocalPurchaseOrderPolicy::manage` untuk Finance/Admin/Purchasing aktif.
- Respons private/no-store hanya `{data:[{pr_number:...}]}`; tidak ada identitas supplier, ID PO, nominal atau metadata lainnya. Nomor dideduplikasi tanpa membedakan kapital menggunakan grouping database, lintas supplier dan status. Tidak ada endpoint saran supplier.
- Request create/update memvalidasi panjang/tipe serta normalisasi `LocalPoPrNumber` existing. Formula, daftar beberapa PR, dan karakter kontrol ditolak. Nol di depan dan nomor `0` dipertahankan.
- Update mengambil lock PO, menolak penggantian/penghapusan berdasarkan nilai terbaru, dan memakai `fillPurchaseOrderPr` untuk PR kosong. Audit `po_pr_filled` dan `po_updated` berada di transaksi yang sama. Pemeriksaan supplier, status cancelled, dependency, nilai PO dan financial ceiling existing tetap berlaku.
- Tidak ada package atau migrasi baru. Kolom PR existing digunakan. Pengisian input atau pencarian tidak menciptakan master baru sampai PO disimpan.

## Dieksekusi

- RED awal: 5 kasus gagal sebelum implementasi (PR hilang saat create/update, validasi/route/form belum tersedia).
- Regresi awal serial: **63 PHP lulus, 39.289 assertions**, termasuk manual PR, dua subprocess MySQL concurrency, existing PR import, RenderedComponent dan TranslationParity.
- Verifikasi PHP akhir: **43 lulus, 97.911 assertions**, meliputi manual create/edit/search/readonly/old input, concurrency, kompatibilitas komponen, parity dan content EN/ID.
- Copy ledger canonical diselaraskan; **28 tes copy lulus, 87.310 assertions**. Tidak ada teks baru yang hanya tersedia dalam satu locale.
- JavaScript akhir: **89 lulus** (9 editable-combobox + 80 regresi modal impor). Termasuk debounce, respons lama, close/select, offline, batas hasil, keyboard, destroy, fokus ulang dan Arrow Up awal.
- Browser final: **8 skenario lulus**, EN/ID × light/dark × 375/1440. Menguji pilihan existing melalui keyboard, FormData, nomor baru, offline, PR panjang, combobox bernama PR, tanpa overflow, dan readonly pada edit. Screenshot ID dark mobile diperiksa visual.
- QA browser menemukan hasil saran terhapus pada fokus ulang. Ditambahkan regresi RED yang membuktikan kehilangan opsi, lalu diperbaiki dan diverifikasi ulang. Fixture awal memakai URL absolut yang tidak cocok dengan server fixture; view kini menggunakan URL route relatif dan fixture dirender ulang.
- Browser menggunakan Blade yang dirender Laravel dari database tes, aset build, dan respons pencarian terkontrol. Persistensi/otorisasi diuji terpisah melalui HTTP/MySQL. Ini bukan klaim pengujian produksi atau browser end-to-end terhadap DB aplikasi.
- Pint scoped, PHP syntax, parse JavaScript, build Vite (28 modules), Blade cache, route list dan diff check scoped lulus. Reconcile read-only menghasilkan **0 issues**.
- Schema lokal diperiksa read-only: `adasi_portal` sudah memiliki `pr_number`. Tidak ada migrasi aplikasi dijalankan dalam task ini.

Artefak ignored: `storage/framework/testing/manual-pr-{red,green,regression,final-php,copy-test}.log`, `manual-pr-copy-summary.json`, dan `manual-pr-ui/browser/result.json` beserta screenshot. Fixture HTML dibuat melalui `MANUAL_PR_UI_FIXTURES=1` pada `ManualPoPrTest`.

## File fitur

Runtime:

- `app/Http/Requests/LocalInvoice/SaveLocalPurchaseOrderRequest.php`
- `app/Http/Controllers/Finance/LocalProcurementController.php`
- `app/Policies/LocalPurchaseOrderPolicy.php`
- `app/Services/LocalInvoice/LocalProcurementMasterService.php`
- `routes/web.php`
- `resources/views/finance/local-procurement/form.blade.php`
- `resources/views/components/ui/searchable-select.blade.php`
- `resources/views/components/ui/_searchable-free-input.blade.php` (baru)
- `resources/js/searchable-free-input.js` (baru), `resources/js/app.js`
- `lang/en/common.php`, `lang/id/common.php`, `lang/en/local_procurement.php`, `lang/id/local_procurement.php`
- `public/build/` dan manifest hasil build.

Tes: `ManualPoPrTest`, `ManualPoPrConcurrencyTest`, `manual-po-pr-worker.php`, `searchable-free-input.test.mjs`, dan `manual-po-pr-browser-check.mjs`. Dokumentasi: AGENTS.md, panduan impor existing, laporan ini dan ledger copy canonical. File yang sudah dirty sebelum task dipertahankan; daftar ini tidak mengotorisasi stage seluruh diff repository.

## Diinspeksi dan belum diverifikasi

Diinspeksi: route static sebelum model binding, policy, query shape/batas payload, normalisasi, locking, transaksi/audit, escaping, backward compatibility default dropdown, dan UI focus/status.

Belum diverifikasi: produksi/staging, screen reader manual, serta load host untuk volume Master PO produksi. Tidak menjalankan full-project suite. Tidak melakukan commit/push/reset/clean, migrasi database aplikasi atau restart worker. Deployment perlu PHP/views/terjemahan dan seluruh build bersama; kolom PR existing wajib tersedia pada target.

## Revisi tampilan: alignment dan gaya ERP ringkas

Label field manual menjadi `PR Number` pada EN/ID sesuai permintaan user; label PR pada daftar/detail lain tetap mengikuti kontrak existing. Label PO/PR menggunakan baris setinggi 24 px dan jarak input yang sama, termasuk readonly. Mode editable memakai ikon pencarian dan chevron dekoratif, dropdown berjarak 4 px dengan border/bayangan tipis, nomor monospace, highlight dengan border kiri dan check untuk nilai terpilih. Warna berasal dari token tema; transisi dinonaktifkan pada reduced motion. Tidak mengubah logika pencarian/penyimpanan.

Verifikasi revisi: build dan Blade cache lulus; 31 tes parity/copy lulus (126.119 assertions); 8 skenario browser EN/ID/light/dark/mobile/desktop lulus. Browser mengukur selisih posisi atas dan tinggi input PO/PR maksimal 1 px pada desktop, memeriksa label aksesibel PR Number dan highlight keyboard. Screenshot `en-light-1440-suggestions.png` diperiksa visual. Tidak ada migrasi, commit/push atau deployment produksi.
