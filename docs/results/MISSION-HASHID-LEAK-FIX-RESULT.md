# Hasil Penutupan Kebocoran Hashid Supplier Portal

Tanggal implementasi: 14 Agustus 2026  
Status: selesai di worktree, belum di-stage dan belum di-commit.

## Ringkasan hasil

URL untuk `PurchaseOrder`, `Quotation`, `PurchaseRequisition`, `MaterialClaim`, `QcInspection`, `Conversation`, dan `User` sekarang dibentuk dengan model/route key hash. Integer polos dan hash rusak ditolak pada implicit binding, route scalar/manual, PDF, serta query identifier yang dicakup.

Route name, URI, business flow, policy, role middleware, supplier ownership, payload bisnis, dan skema database tidak diubah. Tidak ada migration, backfill, seeder, atau mutasi database operasional yang dijalankan.

## Perubahan per subsistem

### Pembentuk URL

- Memperbaiki tiga leak Blade utama pada detail quotation Purchasing, detail PR, dan detail claim.
- Memperbaiki link/action tambahan pada list/detail Purchasing, supplier quotation, supplier price history, comparison, controller redirect, route closure, `ConversationPresenter`, dan `PurchasingNavigation`.
- Row SQL pada price comparison sekarang me-resolve model dengan cache per request sebelum membuat URL.
- Tidak ada encode manual baru di Blade/controller.

### Query URL

- `pr_id`, `supplier_id`, alias `supplier`, dan `user_id` memakai hash pada URL.
- Controller terkait me-resolve hash secara lokal lalu memakai primary key integer hanya untuk query/export internal.
- Nilai kosong tetap berarti tanpa filter; integer atau hash rusak menghasilkan 404.
- Pagination, AJAX, export URL, dan `return_url` mempertahankan hash asli karena query bag global tidak dimutasi.
- `PurchasingNavigation` menolak cached/session return URL dengan path atau query identifier numerik yang dicakup.

### Chat dan PDF

- Payload drawer `conversation.id` dan response `conversation_id` sekarang berupa hash string.
- JavaScript drawer tidak lagi mengubah token conversation menjadi `Number()`.
- Decoder lokal `PdfController::resolveId()` dan import `Hashids` dihapus; PDF hanya menerima ID hasil decode middleware.

### Kompatibilitas notifikasi lama

- `NotificationUrlResolver` boleh membaca ID numerik dari URL/metadata lama hanya untuk menemukan record.
- Resolver menjalankan pemeriksaan role/ownership yang sudah ada lalu selalu menghasilkan URL hash baru.
- Conversation show, PO show, claim show/create, QC inspection create, serta keluarga route hashed aktif lain yang terpetakan dicanonicalize tanpa backfill.
- Query dan fragment non-ID dipertahankan. Record hilang, target lintas supplier, atau nilai tidak valid diarahkan ke dashboard role.
- URL numerik tidak lagi dikembalikan sebagai hasil resolver.

### Penutupan fallback

- `HasHashids::resolveRouteBinding()` hanya menerima canonical hash tunggal.
- `DecodeHashids` menolak integer, hash rusak, compound hash, dan hash non-canonical dengan 404.
- Parameter yang sudah menjadi model akibat implicit binding tetap dilewati.
- Route model plain tetap dikecualikan dan `verification.verify` mendapat pengecualian exact untuk signed Laravel URL.
- Pemakaian `Hashids::encode/decode` di source aplikasi kini hanya tersisa pada trait dan middleware.

## Status tiga batch

| Batch | Isi | Status |
|---|---|---|
| 1 | Tiga leak Blade terkonfirmasi | Selesai |
| 2 | Leak tambahan, query URL, chat, navigation, dan notifikasi lama | Selesai |
| 3 | Trait/middleware strict dan penghapusan decoder PDF | Selesai |

Batch merupakan pemisahan logis di worktree. Tidak ada commit karena commit tidak diotorisasi, dan worktree sudah memiliki perubahan auth/security lain sebelum misi ini.

## Test dan command aktual

| Pemeriksaan | Hasil |
|---|---|
| `php artisan test tests/Feature/HashidUrlSecurityTest.php tests/Feature/NotificationUrlResolverTest.php --stop-on-failure` | Lulus: 10 test, 148 assertion |
| Supplier isolation, notification delivery/controller, export security, quotation availability, dan PO reference/remark | Lulus setelah fixture URL lama diperbarui ke model/hash |
| `php artisan route:list --except-vendor` | Lulus; 162 route aktif terdaftar |
| `php artisan view:cache` | Lulus |
| `git diff --check` | Lulus; hanya warning normalisasi CRLF pada file worktree lama |
| `php artisan test` | Lulus: 176 test, 1.612 assertion, 42,64 detik |
| `vendor\bin\pint --test tests\Feature\HashidUrlSecurityTest.php` | Lulus |
| Audit resolver lokal read-only | 365 notifikasi diperiksa; 316 URL tersimpan numerik, 0 hasil resolver numerik, 1 fallback dashboard |

Percobaan Pint multi-file juga mendeteksi style debt pada beberapa file legacy yang disentuh. Tidak dilakukan reformat massal agar tidak memperbesar diff atau membawa perubahan unrelated; syntax check, focused tests, full suite, Blade cache, dan diff check tetap lulus.

## Bukti acceptance

- Ketujuh model diuji melalui implicit binding dan route scalar/manual: hash valid mencapai action, integer dan hash rusak menghasilkan 404.
- POST valid dinilai berdasarkan response bisnis normal; start chat mengembalikan JSON dan redirect claim/quotation/PO memakai URL hash.
- PDF menerima hash dan menolak integer/hash rusak.
- Query `pr_id`, `supplier_id`, alias `supplier`, dan `user_id` diuji untuk hash valid, integer, dan hash rusak.
- Regression test mencakup link Blade utama, comparison, supplier history, PDF, controller redirect, chat drawer, notifikasi numerik lama, dan supplier isolation.
- Signed email verification dan route Period plain lulus.
- Full suite menggunakan konfigurasi database test repository (`adasi_portal_test` melalui konfigurasi PHPUnit).

## Open Questions

- Bookmark, email lama, atau integrasi eksternal dengan URL numerik yang tidak terlihat dari repository belum dapat diinventarisasi dari source lokal.
- Database produksi dapat memiliki keluarga URL notifikasi lain di luar snapshot lokal; audit read-only wajib dilakukan sebelum deployment.
- Browser QA belum dijalankan, sehingga interaksi UI nyata, navigation history, drawer chat, download PDF, dan DataTables belum dinyatakan terverifikasi melalui browser.
- Production/deployment QA belum dijalankan dan tidak dinyatakan berhasil.
