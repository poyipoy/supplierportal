# Hashid Audit Findings (Pra-Perubahan)

Tanggal audit: 14 Agustus 2026  
Ruang lingkup: source aktif aplikasi; `vendor`, compiled view, dan `routes/web.php.backup` tidak diperiksa sebagai konsumen aktif.

## Tujuan dan batasan

Audit ini memetakan semua pembentuk dan penerima URL untuk model `PurchaseOrder`, `Quotation`, `PurchaseRequisition`, `MaterialClaim`, `QcInspection`, `Conversation`, dan `User`. ID pada database, payload bisnis, hidden form bisnis, serta metadata notifikasi tetap integer. Hashid tidak menggantikan policy, role middleware, atau pemeriksaan kepemilikan supplier.

Pengecualian yang disengaja:

- `verification.verify` tetap memakai User ID integer karena URL ditandatangani Laravel.
- `Attachment`, `Period`, `Notification`, `Announcement`, `ExchangeRate`, `PoDocument`, `PrItem`, dan `MaterialMaster` tetap memakai route key integer.
- Tidak ada migration, backfill, atau perubahan skema dalam misi ini.

## Route matrix aktif

| Model | Parameter implicit | Parameter scalar/manual | Keluarga route aktif | Perlindungan |
|---|---|---|---|---|
| PurchaseRequisition | `{requisition}`, `{purchaseRequisition}` | `{id}`, `{pr_id}` | admin/purchasing requisition, supplier quotation create/store/import, comparison, export | auth + role; visibility supplier pada alur supplier |
| Quotation | `{quotation}` | `{id}`, `{quotation_id}` | purchasing/supplier quotation, PO create, export | auth + role; ownership supplier |
| PurchaseOrder | `{purchaseOrder}` | `{id}`, `{po_id}` | purchasing/supplier PO, QC create/store, conversation, export, shared PDF | auth + role; ownership supplier pada alur supplier |
| MaterialClaim | `{claim}` | `{id}`, `{inspection_id}` | purchasing/supplier claim | auth + role; ownership supplier |
| QcInspection | - | `{id}`, `{inspection_id}` | QC show/attachment, claim create, shared PDF | auth + role |
| Conversation | - | `{id}` | purchasing/supplier show dan shared drawer/message endpoints | auth + membership check |
| User | `{user}` | `{supplier_id}` | admin users dan conversation start | auth + role; signed exception untuk verification |

Middleware `DecodeHashids` berjalan setelah `SubstituteBindings`: parameter yang sudah menjadi model dilewati, sedangkan parameter scalar/manual perlu didecode oleh middleware.

## Temuan pembentuk URL

| File | Baris pra-perubahan | Kode saat ini | Model | Kategori | Rencana |
|---|---:|---|---|---|---|
| `resources/views/purchasing/quotations/show.blade.php` | 7, 17 | route menerima `purchaseRequisition->id` | PurchaseRequisition | Blade/action | kirim objek model |
| `resources/views/purchasing/pr/show.blade.php` | 178 | route detail quotation menerima `quotation->id` | Quotation | Blade/path | kirim objek model |
| `resources/views/purchasing/claims/show.blade.php` | 151 | route PDF menerima `inspection_id` | QcInspection | Blade/PDF | kirim relasi inspection |
| `resources/views/supplier/quotations/show.blade.php` | 179, 183 | route PR dan token drawer memakai ID integer | PurchaseRequisition, Conversation | Blade/chat | kirim model/route key |
| `resources/views/purchasing/pr/show.blade.php` | 182, 249 | query `pr_id` dan start conversation memakai ID integer | PurchaseRequisition, User | query/action | kirim model |
| `resources/views/purchasing/quotations/show.blade.php` | 235, 344 | start conversation dan PO link memakai ID integer | PurchaseRequisition, User, PurchaseOrder | action/path | kirim model |
| `resources/views/purchasing/quotations/index.blade.php` | 256 | helper navigation menerima `quotation->id` | Quotation | list/action | kirim model |
| `app/Http/Controllers/Purchasing/MaterialClaimController.php` | 61, 95, 187 | action dan redirect memakai raw ID | QcInspection, MaterialClaim | AJAX/redirect | kirim model |
| `app/Http/Controllers/Purchasing/PurchaseOrderController.php` | 321 | redirect show memakai `po->id` | PurchaseOrder | redirect | kirim model |
| `app/Http/Controllers/Purchasing/QuotationListController.php` | 242 | redirect show memakai `quotation->id` | Quotation | redirect | kirim model |
| `app/Http/Controllers/Supplier/SupplierPriceHistoryController.php` | 308 | detail quotation memakai `quotation_id` | Quotation | AJAX/history | gunakan relasi eager-loaded |
| `app/Support/ConversationPresenter.php` | 65, 120, 265, 278, 290 | context/action URL memakai foreign/raw ID | PurchaseRequisition, PurchaseOrder, Quotation | presenter | kirim relasi/model |
| `app/Http/Controllers/Purchasing/PriceComparisonController.php` | 59, 70, 311, 333, 362, 368 | option/query dan row SQL memakai raw ID | PurchaseRequisition, Quotation | query/AJAX | resolve hash; cache model per request untuk URL row |
| `routes/web.php` | 173-175 | closure meneruskan decoded `pr_id` integer ke query URL | PurchaseRequisition | redirect | resolve model lalu canonicalize ke hash |
| `app/Http/Controllers/Purchasing/PdfController.php` | 18-19, 45-46, 63-84 | decoder lokal menerima integer dan hash | PurchaseOrder, QcInspection | decoder/PDF | hapus decoder; terima integer hasil middleware |
| `app/Http/Controllers/ConversationMessageController.php` | format payload | `conversation.id` memakai integer | Conversation | JSON/chat | keluarkan route key |
| `app/Http/Controllers/Purchasing/ConversationController.php` | response start | `conversation_id` memakai integer | Conversation | JSON/chat | keluarkan route key |
| `resources/views/partials/chat-drawer.blade.php` | state drawer | token URL dikonversi dengan `Number()` | Conversation | JavaScript/chat | simpan hash sebagai string |

## Temuan query identifier

| Area | Query key | Kondisi pra-perubahan | Rencana |
|---|---|---|---|
| Price comparison | `pr_id`, `supplier_id`, alias `supplier` | option dan filter memakai primary key | option memakai route key; controller resolve hash ke model |
| Daftar quotation purchasing | `supplier_id` | option/filter memakai primary key | hash pada URL, integer hanya untuk query internal |
| Daftar dan export PO | `supplier_id` | option/filter memakai primary key | hash pada URL, integer hanya untuk query/export internal |
| Report/export quotation | `supplier_id` | validasi `integer|exists` | resolve hash secara lokal sebelum export |
| Auth audit log | `user_id` | option/filter memakai primary key | hash pada URL, integer hanya untuk query internal |

Nilai kosong tetap berarti tanpa filter. Integer polos dan hash rusak harus menghasilkan 404. Query asli tidak dimutasi agar pagination, export URL, AJAX URL, dan `return_url` tetap membawa hash.

## Fallback dan kompatibilitas

- `HasHashids::resolveRouteBinding()` masih menerima integer polos.
- `DecodeHashids` masih melewati integer dan hash rusak, serta memiliki prefix PDF lama yang tidak cocok dengan route aktif.
- `PurchasingNavigation` menerima `return_url` same-host yang berisi identifier numerik.
- `NotificationUrlResolver` mengizinkan URL role-valid dikembalikan apa adanya, termasuk URL numerik lama.

Snapshot read-only database lokal sebelum perubahan:

- 365 notifikasi total.
- 316 menyimpan identifier numerik pada path URL.
- 130 sudah dicanonicalize oleh resolver lama.
- 186 masih berakhir pada URL numerik; 80 di antaranya tidak memiliki metadata fallback lengkap.
- Keluarga yang ditemukan meliputi conversation show, PO show, claim show/create, dan QC inspection create.
- Tidak ditemukan query identifier numerik pada URL notifikasi lokal.

Angka tersebut hanya snapshot lokal dan tidak mewakili produksi.

## Hard-stop gate

Pencarian ulang source aktif mencakup `Hashids::encode/decode`, pemanggilan `route()`, `PurchasingNavigation`, inline JavaScript, controller redirect, query identifier, notification/mail, dan test. Tidak ditemukan konsumen eksternal yang dapat dibuktikan dari repository. Bookmark/link numerik di luar repository dan keluarga URL lain di database produksi tetap menjadi risiko deployment dan harus diaudit secara read-only sebelum rilis.

Worktree sudah kotor sebelum misi ini, terutama pada area auth/security dan `routes/web.php`. Perubahan hashid harus dijaga per hunk; tidak ada commit otomatis.
