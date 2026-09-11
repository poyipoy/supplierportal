# Local Supplier Invoice Portal — Audit Findings

Tanggal audit: 9 September 2026  
Ruang lingkup: perbandingan implementation plan, source aktif, route, policy, middleware, service, UI, test, dan verification report.  
Status keseluruhan: **PARTIAL — belum dapat dinyatakan fully production-ready**.

## 1. Ringkasan

Implementasi Local Supplier Invoice sudah mencakup bounded context, supplier scopes, invoice lifecycle, revision integrity, private documents, receipt, payment snapshot, Accounting/Finance workflow, dan regression suite Import.

Audit ulang menemukan dua gap substantif:

1. Notification summary, unread count, dan mark-all-read belum memisahkan data Local dan Import.
2. Jalur pemilihan supplier pada domain Import belum memastikan supplier memiliki scope `import`.

Selain itu terdapat gap UI admin dan gap bukti release. Tidak ditemukan bypass langsung yang memungkinkan Local-only supplier membuka route Import atau supplier lain membuka invoice Local milik supplier lain.

## 2. Temuan

### LSI-001 — Notification belum terisolasi berdasarkan domain

**Severity:** Medium  
**Kategori:** Security / data isolation  
**Status:** Open

#### Bukti

- [`NotificationSummaryService.php`](../../app/Services/NotificationSummaryService.php#L14-L31) mengambil seluruh `$user->notifications()` dan `$user->unreadNotifications()` tanpa filter domain atau portal context.
- [`NotificationController.php`](../../app/Http/Controllers/NotificationController.php#L72-L86) mengambil dan mengubah seluruh unread notification saat `mark-all-read`.
- [`NotificationUrlResolver.php`](../../app/Services/NotificationUrlResolver.php#L30-L37) mengamankan destination URL, tetapi tidak menyaring title, message, category, unread count, atau read state.
- Notification Import dari PO/quotation/claim tetap dapat dikirim ke supplier yang dipilih oleh workflow Import tanpa pemeriksaan scope Import.

#### Dampak

Local-only supplier dapat melihat metadata notification Import yang telah tersimpan atau yang masuk akibat pemilihan supplier Import yang tidak valid. Supplier tersebut juga dapat menandai notification Import sebagai read. Destination transaction tetap dilindungi, tetapi metadata notification sudah melanggar isolasi domain.

#### Rekomendasi

- Tambahkan klasifikasi domain yang eksplisit pada notification Local dan Import.
- Filter summary, unread count, mark-read, dan mark-all-read berdasarkan domain yang boleh dilihat pada context aktif.
- Pertahankan notification security/system yang memang bersifat global.
- Tambahkan negative-path test untuk Local-only, Import-only, dan BOTH supplier.

### LSI-002 — Eligibility supplier Import belum scope-aware

**Severity:** Medium  
**Kategori:** Authorization boundary / workflow integration  
**Status:** Open

#### Bukti

- [`PurchaseRequisitionController.php`](../../app/Http/Controllers/Purchasing/PurchaseRequisitionController.php#L178-L182) dan [`#L393-L397`](../../app/Http/Controllers/Purchasing/PurchaseRequisitionController.php#L393-L397) mengambil semua supplier aktif.
- [`SavePurchaseRequisitionRequest.php`](../../app/Http/Requests/SavePurchaseRequisitionRequest.php#L18-L28) hanya memvalidasi role `supplier` dan `is_active`, tanpa scope `import`.
- Pola supplier selector serupa masih terlihat pada award, quotation, PO, shipment, report, dan price comparison.

#### Dampak

Local-only supplier dapat diundang ke PR Import atau menjadi target filter Import. Route Import tetap menolak akses supplier tersebut, tetapi sistem dapat membuat invitation, transaction relation, dan notification Import yang tidak dapat diproses oleh supplier itu.

#### Rekomendasi

- Filter picker dan validasi supplier Import menggunakan `supplier_scopes.scope = import`.
- Audit seluruh selector Import, tetapi jangan mengubah historical transaction.
- Tambahkan test bahwa Local-only supplier ditolak pada invitation/selection Import.

### LSI-003 — Admin User Management belum lengkap untuk role Accounting/Finance

**Severity:** Low  
**Kategori:** UI / operability  
**Status:** Open

#### Bukti

- Filter role pada [`resources/views/admin/users/index.blade.php`](../../resources/views/admin/users/index.blade.php#L26-L27) hanya berisi Admin, Purchasing, Supplier, dan QC.
- [`resources/css/app.css`](../../resources/css/app.css#L816-L834) belum memiliki treatment khusus `role-badge-accounting` dan `role-badge-finance`.
- Form create/edit sudah mendukung Accounting dan Finance, sehingga gap ini berada pada listing/filter dan presentation.

#### Rekomendasi

Tambahkan opsi filter Accounting/Finance dan style role badge yang konsisten dengan design token yang sudah ada.

### LSI-004 — Label kategori notification Local masih memakai terminologi Import

**Severity:** Low  
**Kategori:** UI / domain language  
**Status:** Open

#### Bukti

- [`InvoiceNotificationService.php`](../../app/Services/LocalInvoice/InvoiceNotificationService.php#L24) mengirim kategori `document`.
- [`NotificationCategory.php`](../../app/Support/NotificationCategory.php#L37-L39) memberi kategori tersebut label **PO Documents** dan deskripsi **Import document status**.

#### Dampak

Notification Local Invoice tampil seolah-olah notification dokumen PO Import. Ini membingungkan pengguna dan mencampur vocabulary dua bounded context.

#### Rekomendasi

Gunakan kategori Local Invoice terpisah atau label netral yang tidak membawa istilah PO Import.

### LSI-005 — Release gate migration dan manual UI belum sepenuhnya terbukti

**Severity:** Verification gap  
**Kategori:** Release readiness  
**Status:** Partial

#### Bukti

Implementation plan mensyaratkan migration/backfill berhasil dan manual UI walkthrough selesai. Saat audit:

- Migration dan supplier backfill diuji pada database test terisolasi, bukan staging/production.
- Headless smoke sudah dilakukan, tetapi belum ada manual cross-browser walkthrough.
- Tidak ada screenshot yang dibuat sesuai instruksi kerja.

#### Dampak

Automated tests membuktikan perilaku backend dan render smoke tertentu, tetapi belum membuktikan hasil deployment migration atau visual behavior lintas browser.

#### Rekomendasi

Jalankan migration/backfill pada staging dengan schema verification, lalu lakukan walkthrough manual pada role Local-only, Import-only, BOTH, Accounting, dan Finance. Dokumentasikan hasil tanpa perlu menyertakan screenshot jika tidak diinginkan.

### LSI-006 — Negative-path coverage belum mencakup notification dan seluruh shared endpoint

**Severity:** Low / Verification gap  
**Kategori:** Test coverage  
**Status:** Partial

#### Bukti

- Test scope matrix sudah mencakup Import-only, Local-only, dan BOTH untuk dashboard/quotation.
- Test shared endpoint saat ini terutama menggunakan kondisi semua scope dihapus, bukan supplier yang masih memiliki scope `local` tetapi tidak memiliki `import`.
- Belum ada test untuk notification summary, unread count, dan mark-all-read yang membuktikan tidak ada kebocoran Import ke Local-only.

#### Rekomendasi

Tambahkan test retained-Local-only untuk `exports.*`, `attachments.*`, `conversations.*`, `shared.pdf.*`, serta notification read/list/count behavior.

## 3. Requirement Closure

| Area | Status | Catatan |
|---|---|---|
| Supplier scopes | PASS | Backfill Import dan matrix Import/Local/BOTH tersedia. |
| Route/policy context isolation | PASS | Direct Local/Import access ditolak server-side. |
| Notification isolation | PARTIAL | Summary/count/read paths belum domain-aware. |
| Import supplier eligibility | PARTIAL | Picker dan request validation belum scope-aware. |
| Local invoice submission | PASS | Ownership dan input authoritative. |
| Revision/resubmit integrity | PASS | Identity, history, old documents, dan re-verification dipertahankan. |
| Physical verification | PASS | Required document dan actor checks enforced. |
| Review/rejection/approval | PASS | Explicit actions, transition checks, dan row lock tersedia. |
| Due date/payment | PASS | Approval date + payment-term snapshot; lifecycle manual. |
| Private documents/receipt | PASS | Private disk, policy, no-store, MIME/size, compensation. |
| Accounting/Finance authorization | PASS | Read/write route separation sesuai role. |
| Admin role UI | PARTIAL | Form ada; filter dan badge Accounting/Finance belum lengkap. |
| UI release evidence | PARTIAL | Build/headless smoke ada; manual walkthrough belum. |
| Supplier isolation | PASS | Invoice, revision, document, receipt, dan resubmit cross-owner ditolak. |
| Import regression | PASS | Full existing suite lulus. |

## 4. Verification Evidence

Hasil verifikasi yang tersedia:

- `php artisan test tests/Feature/LocalInvoice --compact` — **37 passed, 295 assertions**.
- `composer run-script --timeout=0 test` — **595 passed, 5,419 assertions**.
- `php artisan view:cache` — passed.
- `npm.cmd run build` — passed.
- PHP syntax checks pada file changed/new — passed.
- Scoped Pint — passed.
- Local dan Accounting route listing — passed.
- `git diff --check` — passed.

Bukti tersebut cukup untuk menyatakan core workflow dan Import regression green. Bukti tersebut belum cukup untuk menyatakan notification domain isolation, staging/production migration, atau manual cross-browser UI walkthrough selesai.

## 5. Prioritas Tindak Lanjut

1. Tutup LSI-001: domain-aware notification filtering dan negative-path tests.
2. Tutup LSI-002: scope-aware Import supplier eligibility pada picker dan validation.
3. Tambahkan test LSI-006.
4. Rapikan LSI-003 dan LSI-004.
5. Setelah perubahan lulus, perbarui status pada [`LOCAL-SUPPLIER-INVOICE-IMPLEMENTATION.md`](../results/LOCAL-SUPPLIER-INVOICE-IMPLEMENTATION.md) dari `PASS` menjadi status aktual sampai release gate benar-benar terpenuhi.

## 6. Batasan Audit

Audit ini read-only. Tidak ada kode aplikasi, migration, database staging/production, commit, push, atau deployment yang diubah.
