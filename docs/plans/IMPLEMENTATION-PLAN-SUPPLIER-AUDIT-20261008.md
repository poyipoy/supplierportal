# Rencana Implementasi: Supplier Audit (Purchasing ⇄ Supplier Local)

**Tanggal:** 2026-10-08 · **Status:** rencana, belum ada kode diubah · **Deliverable:** `docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-20261008.md`. File ini disalin ke sana setelah disetujui, karena AGENTS.md melarang dokumen baru di root.

Saat menyusun rencana ini tidak ada tes, build, migrasi, atau aplikasi yang dijalankan. Satu-satunya eksekusi adalah membaca file Excel terlampir lewat PhpSpreadsheet (read-only, skrip di direktori temp).

Label yang dipakai:
- **[Verified]** — dibaca langsung dari kode atau file.
- **[Inferred]** — disimpulkan dari fakta yang sudah diverifikasi.
- **Perlu verifikasi** — harus dicek saat implementasi.

---

## 1. Ringkasan

> **Revisi 2026-10-09 (2):** semua pertanyaan terbuka dijawab (§14). Ditambah 3 notifikasi dan command terjadwal (D15, §6.6, §10) serta export 4 sheet (D16, §3.1, §9.2).
>
> **Revisi 2026-10-09 (1):** alur tambahan dari user. Bila supplier melewati deadline dan belum submit form audit, menu dan aksi **Kirim Invoice baru** dinonaktifkan sampai form disubmit (D13). Purchasing bisa mengubah atau menghapus deadline (D14). Bagian yang berubah: §1, §2.15, §3.1, §3.3–3.4, §5.2, §6.3, §6.5, §7, §8.2, §8.4–8.5, §11, §12, §13, §14.

Purchasing menugaskan form **"FORM CHECKLIST AUDIT SUPPLIER & VENDOR — ISO 14001, ISO 9001 & AGC AFC"** ke supplier local. Supplier mengisi Ya/Tidak dan Score 1–5 untuk setiap kriteria di portal. Purchasing meng-export jawaban ke Excel, menilainya offline, lalu mengupload file hasil yang bisa diunduh supplier. Sistem **tidak** menghitung nilai.

| # | Keputusan final | Cara ditegakkan |
|---|---|---|
| D1 | Hanya supplier local (`role:supplier` + `supplier.scope:local`, prefix `local-supplier`) | Route di grup `routes/web.php:97`; policy juga memeriksa `hasSupplierScope('local')` |
| D2 | Akses lewat penugasan per supplier per periode; menu selalu tampil; empty state bila tidak ada penugasan aktif; riwayat tetap terlihat | Item sidebar statis; index supplier merender empty state dan tabel riwayat |
| D3 | Ya → Score 1–5 wajib; Tidak → Score null | FormRequest, normalisasi service, dan CHECK constraint DB. **Keputusan user (8 Okt):** saat simpan draft, Ya tanpa Score **boleh**; saat submit, Score **wajib** |
| D4 | Tidak ada upload bukti dari supplier | Form supplier tidak punya input file |
| D5 | Hasil penilaian hanya berupa file | Tidak ada kolom nilai, bobot, atau kategori |
| D6 | Point dan Temuan/Catatan diisi offline | Kolom G/H di export dibiarkan kosong |
| D7 | Satu audit aktif per supplier | `DB::transaction` dengan `lockForUpdate()` pada baris `users` supplier |
| D8 *(direvisi 9 Okt)* | Deadline opsional. Lewat deadline memberi label "Terlambat" **dan memblokir pengajuan invoice baru** (D13). Supplier tetap bisa submit audit. Tanpa auto-expire | `isLate()` / `SupplierAuditInvoiceGate` berbasis `BusinessTime`, dihitung saat request. Scheduler harian **hanya mengirim notifikasi** blok (D15), tidak mengubah state |
| D9 | Upload hasil langsung `RESULT_PUBLISHED`; bisa diganti dengan alasan; riwayat disimpan; supplier hanya melihat file terbaru | Attachment append-only dan policy "hanya file terbaru" |
| D10 | Template berversi di DB; jawaban menyimpan snapshot | Tabel template/section/criteria; snapshot dibuat saat penugasan |
| D11 | Purchasing bisa minta revisi (catatan wajib) dan membatalkan sebelum hasil terbit | `SupplierAuditReviewService` |
| D12 | Bisa menugaskan banyak supplier sekaligus | Satu record per supplier dalam satu aksi |
| D13 *(baru 9 Okt)* | Bila supplier melewati deadline dan belum submit (status `ASSIGNED`/`DRAFT`/`REVISION_REQUESTED`), menu dan aksi **Kirim Invoice baru** dinonaktifkan sampai form audit disubmit. **Keputusan user:** hanya invoice baru (`create`/`store`) yang diblokir; resubmit revisi dan pembatalan invoice yang sedang berjalan tetap boleh | Guard di `InvoiceSubmissionService::submit` (authoritative), redirect di `InvoiceController@create`, UI disabled di sidebar dan tombol (§6.5, §8.4) |
| D14 *(baru 9 Okt)* | **Keputusan user:** Purchasing bisa menetapkan, mengubah, atau menghapus deadline di halaman detail audit, dan opsional saat meminta revisi. Blok mengikuti deadline terbaru | `SupplierAuditReviewService::changeDeadline()` dengan history `deadline_changed` |

| D15 *(baru 9 Okt)* | Notifikasi tambahan ke supplier: audit **dibatalkan**, **deadline diubah**, dan **pengajuan invoice mulai diblokir**. Total 7 event | §10; event blok dikirim command terjadwal harian |
| D16 *(baru 9 Okt)* | Export Excel dibuat **4 sheet** seperti template (sheet 1: bagian 1–4, sheet 2: 5–8, sheet 3: 9–12, sheet 4: 13–16) | Kolom `sheet_number` di template section + snapshot di jawaban (§3.1, §9) |

**Keputusan user (8 Okt):** export hanya tersedia pada `SUBMITTED` dan `RESULT_PUBLISHED`, tidak pada `REVISION_REQUESTED`.

**Keputusan user (9 Okt) atas pertanyaan terbuka:** tabel koreksi §4 **disetujui** (termasuk semua baris ⚠); judul bagian 6 dan 10 **dibiarkan identik**; kode/judul template `ISO_14001_9001_AGC_AFC` v1 **disetujui**; notifikasi tambahan **perlu** (D15); export **4 sheet** (D16).

**Aturan batas hari (D8/D13):** supplier dianggap lewat deadline bila `due_date < BusinessTime::today()` (string `Y-m-d`). Hari deadline itu sendiri masih boleh, dan blok mulai pukul 00:00 zona bisnis (Asia/Jakarta) keesokan harinya. Tanpa `due_date`, supplier tidak pernah terblokir.

---

## 2. Temuan Investigasi

### 2.1 Routing dan middleware
- **[Verified]** Grup Local Supplier ada di `routes/web.php:97`: `Route::middleware(['auth', 'role:supplier', 'supplier.scope:local'])->prefix('local-supplier')->name('local-supplier.')`. Route POST di grup ini memakai `throttle:30,1` (L104, L107, L108).
- **[Verified]** Grup Purchasing ada di `routes/web.php:441`:
  - Middleware: `['auth', 'role:purchasing', 'purchasing.navigation']`, prefix `purchasing`.
  - Route vendor local di L512–517: `local-vendors.index/show` serta approve/reject change-request.
  - Export detail memakai GET: `export.requisitions.detail` (L506), `export.purchase-orders.detail` (L508), `export.quotations.detail` (L510).
- **[Verified]** `RoleMiddleware.php:17,23-32` menerima daftar role variadic dan memakai `in_array(..., true)` yang ketat.
- **[Verified]** `SupplierScopeMiddleware.php:10-16` menjalankan `abort_unless(hasSupplierScope($scope), 403)`. Supplier import-only mendapat 403 di grup local, sehingga D1 sudah dipenuhi middleware.
- **[Verified]** Alias `purchasing.navigation` (`bootstrap/app.php:71`) mengarah ke `RememberPurchasingListUrl` (`app/Http/Middleware/RememberPurchasingListUrl.php:19-32`):
  - Middleware ini menyimpan URL daftar ke session hanya bila nama route ada di `PurchasingNavigation::LIST_ROUTES` (`app/Support/PurchasingNavigation.php:12-30`).
  - Route baru **tidak** wajib didaftarkan agar bisa diakses.
  - Rencana: daftarkan `purchasing.supplier-audits.index` agar tautan "kembali" mempertahankan filter (lihat `fallbackListRoute()` L175–191).
- **[Verified]** `EnforceSupplierDomain` (`bootstrap/app.php:63`, berlaku global):
  - Tidak menjaga `local-supplier.*` maupun `purchasing.*`.
  - Menjaga `attachments.*`. Supplier tanpa scope import mendapat 403 (L32–34), local operator (finance/accounting) juga 403 (L25–27).
  - Pengecualian early-pass `attachments.show` hanya berlaku untuk `SupplierOverpaymentRefund` dan `LocalPurchaseOrder` (L15–23).

### 2.2 Sidebar dan navigasi
- **[Verified]** Sidebar di-hard-code di `resources/views/partials/sidebar.blade.php`:
  - Blok supplier local di L151–163 (`PortalContext::SCOPE_LOCAL`).
  - Blok purchasing mulai L222; section "Local Invoice" ada di L235–240.
- **[Verified]** Pola item:
  - Komponen `<x-ui.sidebar-item :href :icon :active :label>` (`components/ui/sidebar-item.blade.php:1,19`).
  - Item purchasing memakai `PurchasingNavigation::listUrl(...)` (L236).
  - Heading memakai `<div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: N;">`.
- **[Verified]** `<x-ui.icon>` (`components/ui/icon.blade.php:91-101`) membuang prefix `bi-`, memetakan alias, dan jatuh ke `circle-help` bila SVG tidak ada. `clipboard-check.svg` dan `list-checks.svg` **ada** di `vendor/technikermathe/blade-lucide-icons/resources/svg/`.
- **[Verified]** Label menu berasal dari `lang/{en,id}/navigation.php`. Tidak ada tes yang mengunci daftar item sidebar; `SidebarShellTest.php` hanya memeriksa string kasar.
- Quick Access (`config/quick_access.php`) opsional dan **di luar cakupan**.

### 2.3 Daftar supplier local untuk Purchasing
- **[Verified]** Daftar `/purchasing/local-vendors` (`Purchasing/PurchasingLocalVendorController.php:15-29`) memakai `User::where('role','supplier')->whereHas('supplierScopes', scope='local')`, **tanpa** filter `account_status`/`is_active`.
- **[Verified]** `User::scopeLocalEligible()` (`app/Models/User.php:199-205`) mensyaratkan `role=supplier`, `account_status=ACTIVE`, `is_active=true`, dan scope `local`. Scope ini sudah dipakai `LocalProcurementMasterService.php:35,120,158`.
- **Keputusan:** dropdown penugasan memakai `User::localEligible()->with('supplier')`, sesuai permintaan "hanya supplier aktif dengan scope local". Ini sengaja berbeda dari query daftar local-vendors.

### 2.4 Penyimpanan file hasil — keputusan: polymorphic `attachments`
- **[Verified]** Aturan AGENTS.md yang relevan:
  - "Impor, PDF Local PO, dan proof refund memakai `attachments` polymorphic".
  - "Akses polymorphic file lewat `AttachmentController`/`AttachmentPolicy`".
  - Dua penambahan Core 2 terakhir (Local PO PDF, bukti refund) memakai `attachments` ditambah pengecualian domain.
- **[Verified]** `Attachment` (`app/Models/Attachment.php:12-33`) memakai `HasHashids`. Tabelnya (`2025_05_07_000014_create_attachments_table.php:11-22`) tidak punya kolom versi, dan tidak ada migrasi dengan `is_current`/`superseded`. Pola "terbaru menang" di repo adalah baris terbaru per `id`, contohnya `SupplierRegistrationService.php:795-798`.
- **[Verified]** `AttachmentController::show` (L12–41) menjalankan `authorize('view')`, memakai disk `private`, header `no-store, private` + `nosniff`, dan disposition inline.
- **[Verified]** `AttachmentPolicy::view` (L24–88):
  - admin selalu boleh;
  - purchasing boleh semua tipe kecuali `SupplierOverpaymentRefund` (L30–32);
  - supplier diperiksa per tipe lewat `match` dengan `default => false` (L59–85);
  - finance hanya boleh refund/LPO.
- **[Verified]** Pola upload acuan: `SupplierOverpaymentService::settle` (`app/Services/Payment/SupplierOverpaymentService.php:22-96`):
  - cek ulang ukuran dan MIME nyata (L25–27);
  - path `attachments/Y/m/hashName` dengan `// biz-time:ignore storage path` (L33);
  - stream put ke disk `private` (L34–46);
  - transaksi dengan `lockForUpdate`, lalu `attachments()->create` (L49–91);
  - `catch` yang menghapus berkas (L92–95).
- **Keputusan:**
  - Hasil disimpan sebagai `Attachment` dengan `attachable = SupplierAudit`, append-only. Penggantian menambah baris baru, sehingga baris lama menjadi riwayat. Alasan penggantian dicatat di status history.
  - Tambahkan `SupplierAudit::class` ke daftar early-pass di `EnforceSupplierDomain.php:17-20`.
  - Tambahkan cabang supplier di `AttachmentPolicy`: pemilik, status `RESULT_PUBLISHED`, **dan** attachment harus file hasil terbaru.
  - Purchasing/admin sudah tercakup aturan blanket L27–32, jadi mereka bisa mengunduh seluruh riwayat. Finance/accounting tetap ditolak policy.
- **Tipe dan ukuran:** `pdf,xlsx,jpg,jpeg,png`, `max:10240` (10 MB), sama dengan batas umum dan bukti refund.
  - Service mengecek ulang ekstensi dan MIME nyata seperti `SupplierOverpaymentService.php:25`. MIME xlsx = `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
  - **Perlu verifikasi:** apakah `finfo` di server mendeteksi xlsx sebagai `application/zip`/`octet-stream`. Bila ya, izinkan kombinasi ekstensi `xlsx` + MIME zip.

### 2.5 Export Excel — keputusan: pipeline async yang ada
- **[Verified]** AGENTS.md: "Export operasional memakai `ExportDispatcher::dispatch()` … queue `exports`". Export detail satu dokumen juga sudah async:
  - contoh: `Purchasing/ExportController::purchaseOrderDetail` (L130–140) dengan `dispatchResponse` (L156–171, JSON 202 untuk downloader global);
  - `Excel::download` sinkron hanya dipakai untuk template import: `QuotationController.php:248`, `LocalProcurementController.php:127,132`, `PurchaseRequisitionController.php:191`.
- **[Verified]** Tidak ada tes yang melarang `Excel::download`. Namun konvensinya jelas, dan export audit adalah data operasional, bukan template. **Keputusan: tidak memakai direct download.**
- **[Verified]** Pola workbook buatan sendiri:
  - kontrak `App\Contracts\GeneratesWorkbook` (`generateWorkbook(string $path, string $disk)`);
  - `PaymentBatchDrpExport` (L18–80) menulis memakai `Xlsx` writer ke file temp lalu stream ke disk, dengan `authorizeActor()` di L152–159;
  - `PaymentBatchTransferSheetRenderer.php:97` membangun `new Spreadsheet` lengkap dengan `Fill`/`Border`;
  - `ProcessExportJob` (L88–101) meneruskan class `GeneratesWorkbook` ke `GenerateWorkbookJob`;
  - `ExportDispatcher::SUPPORTED_EXPORT_CLASSES` ada di L35–48, `LIST_EXPORT_CLASSES` di L50–54.
- **[Verified]** `SpreadsheetCellSanitizer::text()` wajib untuk setiap sel teks.

### 2.6 Notifikasi
- **[Verified]** `NotificationService::send($recipients, $event, $eventKey, $title, $message, $url, $icon, $data, $replace, $localizedReplace)` (`app/Services/NotificationService.php:21-32`):
  - ID adalah UUIDv5 dari `eventKey` (L70–73);
  - kategori diambil dari `data.category` (L75–78);
  - domain diambil dari `data.domain`, atau dari `NotificationDomain::resolveDomain` (L38);
  - di dalam transaksi, notifikasi baru dikirim `afterCommit` (L120–124).
- **[Verified] Batas domain** (`app/Support/NotificationDomain.php`):
  - `allowedDomainsForUser` (L84–121): purchasing = `[GLOBAL, IMPORT]`; supplier local-only = `[GLOBAL, LOCAL]`.
  - `isUserEligibleForDeliveryDomain` (L126–150) membuang penerima yang domainnya tidak cocok.
  - `resolveDomain` jatuh ke **IMPORT** bila tidak ada petunjuk (L66).
  - **Akibatnya:** event untuk supplier wajib `domain = LOCAL` eksplisit, dan event untuk purchasing wajib `domain = GLOBAL` eksplisit. Preseden: `supplier_registration.*` sampai ke purchasing lewat GLOBAL.
- **[Verified]** Filter kategori UI (`NotificationCategory::optionsForUser`, L72–101): supplier local-only hanya melihat `all`, `invoice`, dan `other`. Karena itu kedua sisi memakai `NotificationCategory::OTHER`.
- **[Verified]** Katalog preferensi:
  - `config/notification_preferences.php` berisi 40 entri. Contoh `local_invoice_revision_requested` (L261–271) dengan `roles`, `supplier_scopes`, `priority`.
  - Urutan grup ada di `config/notification_categories.php`.
  - `NotificationPreferenceService::keyFor` mencocokkan `source_event` (L43–57). Event yang tidak terdaftar tetap terkirim (fail-open), tetapi user tidak bisa mematikannya.
- **[Verified] Tes yang akan gagal bila katalog berubah:**
  - `NotificationCategoryOrderTest.php:75-79`: `assertCount(40)`, daftar key, dan urutan kategori literal (L45–73).
  - `UserNotificationPreferencesTest.php:25-50`: `assertCount(40)` dan `$expectedKeys`.
- **[Verified]** Preseden kirim ke semua purchasing: `ShipmentService.php:464-478` memakai `User::where('role','purchasing')->where('is_active', true)->get()`.
- **[Verified]** `NotificationUrlResolver` (L147–155, 207) memvalidasi prefix route per role (`purchasing.`, dan `local-supplier.` untuk scope local). Route supplier yang tidak dikenal diterima; otorisasi tetap di policy route.

### 2.7 Hashids
- **[Verified]** `HasHashids` (`app/Traits/HasHashids.php:16-60`):
  - `getRouteKey()` mengembalikan hash;
  - `resolveRouteBinding()` mengembalikan null (404) untuk digit mentah, decode gagal, atau hash non-kanonik;
  - menyediakan `$model->hash`.
- **[Verified]** `DecodeHashids::handle` (L99–128) melewati parameter yang sudah ter-bind ke model (L105–108). Parameter baru `{supplierAudit}` yang di-bind implisit ke model ber-`HasHashids` **tidak perlu** didaftarkan di `HASHED_PARAM_KEYS` (L29–61), karena daftar itu hanya untuk parameter skalar.
- **[Verified]** `HashidUrlSecurityTest.php` memakai daftar hard-code (model L138–156, route bernama L161–174, asersi L171–173) dan tidak mengiterasi semua route. Kasus `SupplierAudit` harus ditambahkan manual.
- Hanya `SupplierAudit` yang memakai `HasHashids`. Template, section, criteria, answer, dan history tidak muncul di URL.

### 2.8 Policy
- **[Verified]** `app/Policies/` berisi 12 file, termasuk `LocalProcurementImportPolicy.php` yang masih untracked. **Diskrepansi:** AGENTS.md menyebut 11.
- **[Verified]** Tidak ada `Gate::policy`/`$policies` di `AppServiceProvider` maupun `AuthSecurityServiceProvider`. Semua policy memakai auto-discovery `App\Policies\<Model>Policy`.
- **[Verified]** Contoh policy yang menghadap supplier:
  - `LocalInvoicePolicy::view` (L10–14) = `is_active && (operator || admin || purchasing || (hasSupplierScope('local') && (int) supplier_id === (int) user id))`.
  - `LocalPurchaseOrderPolicy` (L10–37).
- AGENTS: aksi Core 2 baru memakai method policy, bukan `abort_unless` inline.

### 2.9 Komponen UI
- **[Verified]** `resources/views/components/ui/` berisi 37 komponen. Yang relevan:
  - `date-picker`: props `name,id,label,value,min,max,helper,error,required…`; membaca `old()`/`$errors` sendiri.
  - `empty-state`: `icon,title,description,actionUrl,actionText`.
  - `status-chip`: `tone` neutral/info/success/warning/error.
  - `page-header`: slot `status`, `meta`, `actions`.
  - `data-table`: slot `filters`, `emptyState`, `pagination`.
  - `button`: `variant`, `loading`.
  - `file-upload`: `maxSizeMb`, `accept`.
  - Lainnya: `form-section`, `action-bar`, `dialog`, `textarea`, `multi-select`, `searchable-select`, `alert`.
- **[Verified]** **Tidak ada komponen stepper/wizard atau radio-group.** Wizard dibuat inline per view dengan Alpine. Acuannya `auth/supplier-register.blade.php`:
  - `x-data="supplierRegistrationWizard"` (L48–50);
  - setiap step memakai `x-show` + `data-wizard-step` (mulai L487);
  - stepper desktop L81–120, stepper mobile L300–332, bar bawah L1440–1500.
- **[Verified]** Pola Ya/Tidak terdekat adalah kuesioner registrasi (step 2, L785–833): `<fieldset>` berisi radio pill per pertanyaan dan label progres. Rules-nya di `app/Support/SupplierComplianceQuestionnaire.php`.
- **[Verified]** Pola draft vs submit:
  - `supplier/shipments/create.blade.php`: hidden `action` (L74), tombol `name="action" value="draft"` (L291), konfirmasi `AdasiAlert.confirm({... confirmTone: 'primary'})` (L547–557).
  - `supplier/quotations/create.blade.php` (L2678–2700).
  - API `AdasiAlert` di `public/assets/js/adasi-alert.js:169-188`.
  - `submit-guard.js` meneruskan nama/nilai tombol submitter.
- **[Verified]** `async-form-submit.js` (`[data-async-submit]`):
  - meneruskan `submitter.name/value` (L218);
  - pada respons 422 memicu `adasi:reveal-field`, yang dipakai wizard untuk pindah step;
  - controller membalas `{redirect}` saat `expectsJson()` (contoh `LocalSupplier/InvoiceController.php:129-140`).
- **[Verified]** Empty state di dalam tabel: `local-invoices/table.blade.php:245-254`.
- **[Verified]** Pola badge status lokal: `StatusHelper::registrationTone()` (L53) dan `registrationLabel()` (L74). Label berasal dari `lang/*/status.php` (`status.<domain>.<lowercase>`).
- **[Verified]** Pola halaman index local (`local-supplier/invoices/index.blade.php:1-36`): `@extends('layouts.app')`, `x-ui.page-header`, `x-ui.data-table` dengan slot pagination, tanpa DataTables.
- **[Verified]** `FrontendAssetLoadingTest.php:36-69` mengunci jumlah halaman DataTables di angka 15. Halaman baru memakai **pagination server-rendered** sehingga angka itu tidak berubah. Volume audit kecil, jadi pagination sudah sesuai AGENTS.
- **[Verified]** `SurfaceHierarchyTest.php:60-96` melarang `bg-white`/`tw-bg-white`/`#fff`/`tw-shadow-ui-1` di `views/purchasing`.

### 2.10 i18n
- **[Verified]** Ada 29 file berpasangan di `lang/en` dan `lang/id`. Domain baru tidak perlu didaftarkan di mana pun.
- **[Verified]** `JsTranslations` (`app/Support/JsTranslations.php:12,19`) hanya mengirim `js.*`, `datatables.*`, dan subtree `purchasing.js/supplier.js/shipments.js`. Teks untuk JS dikirim lewat `@js(__('...'))` di Blade.
- **[Verified]** Tes terjemahan:
  - `TranslationParityTest.php:10-62`: setiap key statis `__()` wajib ada di en dan id dengan placeholder yang sama.
  - `TranslationContentTest.php:13-50`: melarang HTML, mojibake, serta kata "pemasok"/"rekanan".
- **[Verified]** `UserFacingCopyInventoryTest.php:134-168` membandingkan fingerprint (path, baris, offset) dengan `UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl`. **File baru di folder yang dipindai akan membuat tes ini gagal** sampai ledger diregenerasi dengan `php tests/Support/user-facing-copy-audit.php` (L199–203). Lihat R2.
- Key `supplier.audit_ui` sudah dipakai untuk copy quotation (`lang/en/supplier.php:541`). Agar tidak rancu, domain baru dinamai **`supplier_audit`** (`lang/*/supplier_audit.php`).
- **Teks kriteria adalah data bisnis** (bahasa Indonesia, disimpan di DB) dan **tidak diterjemahkan** di locale `en`, sesuai AGENTS.

### 2.11 BusinessTime
- **[Verified]** `BusinessTime::today()` (`app/Support/BusinessTime.php:37`) mengembalikan `CarbonImmutable` pada awal hari di zona bisnis.
- **[Verified]** Pola overdue yang sudah ada: `LocalInvoice::isOverdue()` (`app/Models/LocalInvoice.php:180-186`) dan scope `whereDate('due_date','<', BusinessTime::today()->toDateString())` (L233–238).
- **[Verified]** `BusinessTimeGuardTest.php:46` melarang `today()` atau `now()->format…` tanpa prefix `BusinessTime::` atau anotasi `// biz-time:ignore`.
- **[Verified]** `RegionalDisplayFormatter` hanya tersedia di view yang **didaftarkan eksplisit** di `AppServiceProvider.php:74-133`. Contoh: `'local-supplier.purchase-orders.index'` (L123), `'purchasing.local-vendors.index'` (L127).

### 2.12 Status history / audit log
- **[Verified]** Tabel acuan `local_invoice_status_histories` (`2026_09_08_000002_create_local_invoice_domain.php:79-88`):
  - kolom: `local_invoice_id` (FK restrict), `from_status` (nullable), `to_status`, `actor_id`, `event`, `notes` (text nullable), dan hanya `created_at`;
  - model `LocalInvoiceStatusHistory`: `$timestamps=false`, `$guarded=['id']`, default `created_at` saat `creating`;
  - ditulis di dalam transaksi setelah `lockForUpdate`, contoh `InvoiceVerificationService.php:245-254`.
- **Keputusan:** `supplier_audit_status_histories` meniru struktur ini. `LocalFinanceAuditService` tidak dipakai karena khusus audit keuangan.

### 2.13 Pola tes dan seeder
- **[Verified]** `UserFactory` otomatis menambah scope `import` (`database/factories/UserFactory.php:43-50`).
  - Helper tes yang ada menghapus scope itu dulu, lalu membuat `Supplier` secara eksplisit (`tests/Feature/LocalInvoice/LocalInvoiceTest.php:38-57`, `LocalInvoiceScopeIsolationTest.php:23-41`).
  - Tidak ada trait bersama, dan factory selain User tidak ditambah.
- **[Verified]** Pola seeder master data: `MaterialHsCodeMasterSeeder`.
  - Data JSON di `database/data/*.json` (L88–90), cek jumlah fixture (L21), dan `DB::transaction`.
  - Seeder ini **tidak** dipanggil `DatabaseSeeder`; tes memanggil `$this->seed(...)` (`MaterialHsCodeSeederTest.php:17`).

### 2.14 Struktur Excel terlampir **[Verified, dibaca via PhpSpreadsheet]**
- **Layout:** 4 sheet (`1`…`4`), A4 portrait, tanpa fill warna.
  - Lebar kolom: A=4.3, B=2.9, C=96.4, D=5.4, E=4.9, F=14, G=27, H=21.4.
  - Judul bold 20pt, merge A1:H4. Border tipis; baris Sub Total memakai garis bawah ganda.
- **Kolom:** `No | Kriteria Audit (B:C) | Ya | Tidak | Score | Point | Temuan/Catatan`.
  - Baris judul bagian: kode di A, judul di B (merge B:H).
  - Baris kriteria: nomor di B, teks di C.
- **Bukan data:** baris contoh 1.1 memuat catatan instruksi di F8/G8/H8 (`diisi vendor 1-5`, `perkalian score dengan bobot, bobot dari procurement`, `diisi procurement`).
- **Header rusak:** `="Vendor/Supplier: "&#REF!` di keempat sheet.
- **Bagian:** 1–16. Bagian 2, 6, dan 12 tidak punya kriteria langsung, hanya sub-bagian **2.1, 6.1, 6.2, 12.1**.
- **Total 121 kriteria dalam 17 kelompok Sub Total**, bukan "±110" seperti di brief:

| Kelompok | 1 | 2.1 | 3 | 4 | 5 | 6.1 | 6.2 | 7 | 8 | 9 | 10 | 11 | 12.1 | 13 | 14 | 15 | 16 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Kriteria | 11 | 7 | 9 | 6 | 6 | 6 | 9 | 4 | 4 | 9 | 14 | 6 | 6 | 5 | 7 | 6 | 6 |

### 2.15 Jalur pengajuan invoice baru (untuk D13)
- **[Verified] Route** (`routes/web.php:102-108`): `local-supplier.invoices.create` (GET L103), `invoices.store` (POST L104, `throttle:30,1`), `invoices.revision` (L106), `invoices.resubmit` (L107), `invoices.cancel` (L108). Endpoint AJAX `purchase-orders.search` (L100) hanya dipakai form create.
- **[Verified] Otorisasi:** `LocalInvoicePolicy::create()` (`app/Policies/LocalInvoicePolicy.php:16-19`) = `is_active && hasSupplierScope('local')`. Method ini **dipakai ulang** oleh `resubmit()` (L21–24) dan `cancel()` (L26–30).
  - **Akibatnya:** blok tidak boleh ditaruh di `create()`, karena akan ikut memblokir resubmit dan cancel, bertentangan dengan keputusan user. Guard ditaruh di service (lihat §6.5).
- **[Verified] Titik pemeriksaan server:**
  - `InvoiceController@create` (L78–83): `Gate::authorize('create', …)`.
  - `@searchPurchaseOrders` (L32–34): authorize yang sama.
  - `@store` (L85–90) → `StoreLocalInvoiceRequest::authorize()` (L17–20, `can('create')`) → `InvoiceSubmissionService::submit()` (`app/Services/LocalInvoice/InvoiceSubmissionService.php:31-33`, `Gate::forUser($actor)->authorize('create', …)` di baris pertama).
  - `resubmit` (L192) dan `cancel` (L364) adalah method terpisah sehingga tidak tersentuh.
- **[Verified] Titik masuk UI ke `invoices.create`:**
  - `partials/sidebar.blade.php:157` (item "Kirim Invoice");
  - `local-supplier/dashboard.blade.php:13` dan `:92`;
  - `local-supplier/invoices/index.blade.php:13`;
  - `local-supplier/purchase-orders/index.blade.php:17`;
  - `local-supplier/purchase-orders/show.blade.php:26`;
  - Quick Access `config/quick_access.php:175-180` (`supplier_local.invoice-create`).
- **[Verified] Dukungan disabled:** `<x-ui.button>` sudah mendukung `disabled` dengan `href` (`components/ui/button.blade.php:1-10, 37-41`: href dihapus, `aria-disabled="true"`, `tabindex="-1"`). `<x-ui.sidebar-item>` (`components/ui/sidebar-item.blade.php:1`) **tidak** punya prop disabled dan selalu merender `<a href>`.

---

## 3. Desain Data

### 3.1 Migrasi (PR 1)
Satu migrasi setelah `2026_10_08_000003`, misalnya `2026_10_09_000001_create_supplier_audit_tables.php`. Migrasi ini membuat 6 tabel, dan `down()` men-drop-nya dalam urutan terbalik (aman karena tabelnya baru).

**`supplier_audit_templates`**
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigIncrements | |
| code | string(50) | contoh `ISO_14001_9001_AGC_AFC` |
| version | unsignedSmallInteger | |
| title | string(255) | |
| is_active | boolean, default false | satu versi aktif per `code`, ditegakkan seeder/service (MySQL tidak punya partial unique) |
| timestamps | | |
| | unique `[code, version]` | |

**`supplier_audit_sections`**
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigIncrements | |
| supplier_audit_template_id | foreignId → templates, cascadeOnDelete | dalam praktik tidak terhapus karena `supplier_audits` memakai FK restrict |
| parent_id | foreignId nullable → sections, restrictOnDelete | null untuk level 1 |
| code | string(10) | `1`, `2.1`, `6.2` |
| title | string(255) | |
| level | unsignedTinyInteger | 1 atau 2 |
| sheet_number | unsignedTinyInteger | sheet export (1–4 untuk v1, D16); sub-bagian mewarisi nilai induknya |
| sort_order | unsignedSmallInteger | |
| | unique `[supplier_audit_template_id, code]`; index `[supplier_audit_template_id, sort_order]` | |

**`supplier_audit_criteria`**
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigIncrements | |
| supplier_audit_section_id | foreignId → sections, cascadeOnDelete | selalu section yang punya kriteria langsung |
| number | unsignedSmallInteger | nomor tampil dalam kelompok (1..n) |
| sort_order | unsignedSmallInteger | urutan global dalam template |
| text | text | |
| | unique `[supplier_audit_section_id, number]` | |

**`supplier_audits`**
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigIncrements | |
| supplier_id | foreignId → **users.id**, restrictOnDelete | invariant owner di AGENTS |
| supplier_audit_template_id | foreignId → templates, restrictOnDelete | |
| period_label | string(100) | teks bebas, mis. `2026 Semester II` |
| due_date | date nullable | tanggal kalender murni |
| status | enum(`ASSIGNED`,`DRAFT`,`SUBMITTED`,`REVISION_REQUESTED`,`RESULT_PUBLISHED`,`CANCELLED`), default `ASSIGNED` | **Perlu verifikasi:** cocokkan gaya enum vs string dengan `local_invoices.status` |
| assigned_by | foreignId → users, restrict | |
| assigned_at | timestamp | UTC |
| submitted_at | timestamp nullable | submit terakhir |
| revision_note | text nullable | catatan revisi terakhir; riwayat lengkap ada di history |
| revision_requested_at | timestamp nullable | |
| result_published_at | timestamp nullable | publish pertama |
| cancelled_at | timestamp nullable | |
| cancel_reason | text nullable | |
| timestamps | | |
| | index `[supplier_id, status]`, `[status, due_date]`, `[period_label]` | |

**`supplier_audit_answers`**
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigIncrements | |
| supplier_audit_id | foreignId → audits, cascadeOnDelete | audit tidak punya route delete |
| supplier_audit_criterion_id | foreignId → criteria, restrictOnDelete | |
| parent_section_code_snapshot | string(10) nullable | `2` untuk 2.1; null untuk level 1 |
| parent_section_title_snapshot | string(255) nullable | |
| section_code_snapshot | string(10) | |
| section_title_snapshot | string(255) | |
| sheet_number_snapshot | unsignedTinyInteger | sheet export (D16) |
| criterion_number_snapshot | unsignedSmallInteger | |
| sort_order_snapshot | unsignedSmallInteger | |
| criterion_text_snapshot | text | |
| answer | enum(`YES`,`NO`) nullable | |
| score | unsignedTinyInteger nullable | |
| timestamps | | |
| | unique `[supplier_audit_id, supplier_audit_criterion_id]`; index `[supplier_audit_id, sort_order_snapshot]` | |

CHECK constraint lewat `DB::statement`:
```sql
(answer IS NULL AND score IS NULL)
OR (answer = 'NO' AND score IS NULL)
OR (answer = 'YES' AND (score IS NULL OR score BETWEEN 1 AND 5))
```
- Draft boleh berisi YES tanpa score (keputusan user).
- **Perlu verifikasi:** MySQL harus ≥ 8.0.16 agar CHECK ditegakkan. Pada versi lebih lama CHECK diabaikan diam-diam, dan validasi aplikasi tetap menjadi lapisan utama.

Kolom parent snapshot ditambahkan (tidak ada di usulan brief) agar export bisa mencetak judul "2 Perencanaan" sebelum "2.1 Aspek LK3" **hanya dari snapshot**, tanpa membaca template.

**`supplier_audit_status_histories`** (meniru `local_invoice_status_histories`)
| Kolom | Tipe |
|---|---|
| id | bigIncrements |
| supplier_audit_id | foreignId → audits, restrict |
| from_status | string(30) nullable |
| to_status | string(30) |
| event | string(50) |
| actor_id | foreignId → users, restrict |
| notes | text nullable |
| created_at | timestamp |
| | index `[supplier_audit_id, created_at]` |

Nilai `event`:
- `assigned`
- `draft_started` — hanya transisi pertama `ASSIGNED→DRAFT`, bukan setiap simpan draft
- `submitted`
- `revision_requested`
- `cancelled`
- `result_published`
- `result_replaced` — from = to = `RESULT_PUBLISHED`, `notes` = alasan
- `deadline_changed` — from = to = status saat itu, `notes` = `"{lama|—} → {baru|—}"` ditambah alasan opsional (D14)

### 3.2 Snapshot dibuat saat penugasan
Saat `assign`, service menyalin 121 baris jawaban (answer/score null) beserta seluruh snapshot dalam satu `insert` per audit. Alasannya:
1. **Versi template terkunci saat penugasan.** Bila v2 diaktifkan di antara penugasan dan draft pertama, audit tetap memakai v1 sesuai `template_id`.
2. Simpan draft dan submit cukup meng-update baris yang sudah ada. Tidak perlu upsert, dan baris asing tertolak oleh unique constraint dan pengecekan keanggotaan.
3. Progress "n dari N" dihitung langsung: `count(answer not null)` / `count(*)`.
4. View, export, dan halaman show Purchasing hanya membaca snapshot, sehingga tidak bergantung pada template setelah penugasan.

Biaya: 121 baris per supplier per penugasan. Menugaskan 50 supplier sekaligus berarti ±6.050 baris dalam satu transaksi; insert dilakukan per audit. **[Inferred]** Biaya ini dapat diterima.

### 3.3 Model
- **`App\Models\SupplierAuditTemplate`:** `sections()` hasMany, `criteria()` hasManyThrough, `scopeActive()`, `static activeFor(string $code)`.
- **`App\Models\SupplierAuditSection`:** `template()`, `parent()`, `children()`, `criteria()`.
- **`App\Models\SupplierAuditCriterion`** (set `$table = 'supplier_audit_criteria'`): `section()`.
- **`App\Models\SupplierAudit`** (`use HasHashids`):
  - Konstanta status: `STATUS_ASSIGNED`, `STATUS_DRAFT`, `STATUS_SUBMITTED`, `STATUS_REVISION_REQUESTED`, `STATUS_RESULT_PUBLISHED`, `STATUS_CANCELLED`.
  - Grup status:
    - `ACTIVE_STATUSES = [ASSIGNED, DRAFT, SUBMITTED, REVISION_REQUESTED]`
    - `SUPPLIER_EDITABLE_STATUSES = [ASSIGNED, DRAFT, REVISION_REQUESTED]`
    - `EXPORTABLE_STATUSES = [SUBMITTED, RESULT_PUBLISHED]`
    - `CANCELLABLE_STATUSES = ACTIVE_STATUSES`
  - Casts: `due_date => 'date'`; kolom timestamp `datetime`.
  - Relasi: `supplier()` (User via `supplier_id`), `assigner()`, `template()`, `answers()` (orderBy `sort_order_snapshot`), `statusHistories()`, `resultAttachments()` (`morphMany(Attachment::class,'attachable')->latest('id')`), `latestResult()` (`morphOne(Attachment::class,'attachable')->latestOfMany('id')`).
  - Helper: `isActive()`, `isEditableBySupplier()`, `isExportable()`, `isLate()`, `scopeActive()`, `scopeOwnedBy(User)`, `scopeLate()`.
- **`App\Models\SupplierAuditAnswer`:** konstanta `ANSWER_YES='YES'`, `ANSWER_NO='NO'`, `SCORE_MIN=1`, `SCORE_MAX=5`; relasi `audit()`, `criterion()`.
- **`App\Models\SupplierAuditStatusHistory`:** `$timestamps=false`, default `created_at` saat `creating` (meniru `LocalInvoiceStatusHistory`), relasi `actor()`.

Label Terlambat membandingkan tanggal sebagai string, sesuai aturan kolom `date` di AGENTS:
```php
public function isLate(): bool
{
    return $this->due_date !== null
        && in_array($this->status, self::SUPPLIER_EDITABLE_STATUSES, true)
        && $this->due_date->toDateString() < BusinessTime::today()->toDateString();
}
// scopeLate: whereIn('status', SUPPLIER_EDITABLE_STATUSES)->whereNotNull('due_date')
//            ->where('due_date', '<', BusinessTime::today()->toDateString())
```
"Terlambat" hanya tampil selama supplier masih harus bertindak, dan hilang setelah submit. **Definisi `isLate()` identik dengan kondisi blok invoice (D13)**, sehingga label dan blok tidak pernah berbeda. Index `[supplier_id, status]` mencakup query blok.

### 3.4 Transisi status yang sah
| Dari | Ke | Aktor | Pemicu |
|---|---|---|---|
| — | ASSIGNED | purchasing | assign |
| ASSIGNED | DRAFT | supplier | simpan draft pertama |
| DRAFT | DRAFT | supplier | simpan draft (tanpa history) |
| REVISION_REQUESTED | REVISION_REQUESTED | supplier | simpan draft; status tidak berubah agar catatan revisi tetap tampil |
| ASSIGNED / DRAFT / REVISION_REQUESTED | SUBMITTED | supplier | submit (submit langsung dari ASSIGNED diizinkan karena submit juga menyimpan jawaban; **[Inferred]**, brief tidak melarang) |
| SUBMITTED | REVISION_REQUESTED | purchasing | minta revisi (catatan wajib) |
| SUBMITTED | RESULT_PUBLISHED | purchasing | upload hasil |
| RESULT_PUBLISHED | RESULT_PUBLISHED | purchasing | ganti hasil (alasan wajib) |
| ASSIGNED / DRAFT / SUBMITTED / REVISION_REQUESTED | CANCELLED | purchasing | batalkan (alasan wajib) |
| ASSIGNED / DRAFT / SUBMITTED / REVISION_REQUESTED | (tetap) | purchasing | ubah/hapus deadline (D14); bukan transisi status, hanya history `deadline_changed` |

`RESULT_PUBLISHED` dan `CANCELLED` adalah status final. Satu-satunya perubahan setelah `RESULT_PUBLISHED` adalah penggantian file.

---

## 4. Tabel Koreksi Teks Excel (direview sebelum seeder ditulis)

**Aturan global** (tidak ditulis ulang per baris):
- **G1:** hapus line break manual di tengah kalimat dan spasi ganda/di ujung (mis. 1.2, 1.4, 1.5, 4.4, 5.2, 9.9).
- **G2:** teks instruksi di baris contoh (F8/G8/H8) tidak dimasukkan ke seeder.
- **G3:** header rusak `="Vendor/Supplier: "&#REF!` diganti di export menjadi `Vendor/Supplier: {nama perusahaan} — Periode: {period_label}`.
- **G4:** singkatan domain dipertahankan: `QLK3SR`, `LK3SR`, `LK3`, `K3`, `QCDESMS`, `SML`, `UKL/UPL/AMDAL`, `B3`, `WI`, `ISO 14001`.
- **G5:** kata "Supplier" mengikuti glosarium AGENTS (tetap bahasa Inggris).

⚠ menandai perubahan yang lebih dari ejaan (kata dibuang atau diganti). **Status: seluruh tabel, termasuk baris ⚠, disetujui user pada 9 Okt.** Judul bagian 6 dan 10 dibiarkan identik ("Penerapan dan Operasi").

### 4.1 Judul bagian
| Kode | Asli | Usulan |
|---|---|---|
| 1 | Kebijakan Lk3 | Kebijakan LK3 |
| 3 ⚠ | PerencanaPeraturan perundangan dan persyaratan LK3SR Lainan | Peraturan Perundangan dan Persyaratan LK3SR Lainnya |
| 6 | Penerapan dan operasi | Penerapan dan Operasi *(identik dengan bagian 10; dikonfirmasi user)* |
| 6.1 | Struktur organisasi dan tanggungjawab | Struktur Organisasi dan Tanggung Jawab |
| 6.2 | Pelatihan, Kemampuan dan kesadaran | Pelatihan, Kemampuan dan Kesadaran |
| 8 | Dokumentasi sistem manajemen dasar | Dokumentasi Sistem Manajemen Dasar |
| 10 | Penerapan dan operasi | Penerapan dan Operasi *(identik dengan bagian 6; dikonfirmasi user)* |
| 11 | Kesigapan dan tanggap darurat | Kesigapan dan Tanggap Darurat |
| 12 | Pengecekan dan tindakan perbaikan | Pengecekan dan Tindakan Perbaikan |
| 12.1 | Pemantauan dan pengukuran | Pemantauan dan Pengukuran |
| 13 | Ketidaksesuaian, tindak perbaikan dan pencegahan | Ketidaksesuaian, Tindak Perbaikan dan Pencegahan |
| 15 | Audit sistem manajemen LK3 | Audit Sistem Manajemen LK3 |

Judul berikut tidak berubah: 2 Perencanaan, 2.1 Aspek LK3, 4 Tujuan dan Sasaran, 5 Program Manajemen QLK3SR, 7 Komunikasi & Konsultasi, 9 Pengendalian Dokumen, 14 Catatan, 16 Tinjauan Manajemen.

### 4.2 Kriteria
| ID | Asli (bagian yang berubah) | Usulan |
|---|---|---|
| 1.1 | **Top management** sudah membuat kebijakan … | **Top manajemen** sudah membuat kebijakan … *(konsisten dengan 6.1.1, 15.1, 16.1, 16.3)* |
| 1.2 ⚠ | … perbaikan secara **continue** | … perbaikan secara **kontinu** |
| 1.9 | … didokumentasikan dan **dokomunikasikan** … | … didokumentasikan dan **dikomunikasikan** … |
| 1.10 | Kebijakan QLK3SR akan **dieprbaiki** … | Kebijakan QLK3SR akan **diperbaiki** … |
| 2.1.1 | … produk dan jasa/job safety **analisis**, … | … produk dan jasa/job safety **analysis**, … |
| 2.1.7 | … aspek penting **Lk3** yang berhubungan … | … aspek penting **LK3** yang berhubungan … |
| 3.1 | … berlaku pada **aspe** LK3SR … | … berlaku pada **aspek** LK3SR … |
| 3.2 | **perusahaan** harus secara sistematis … | **Perusahaan** harus secara sistematis … |
| 3.8 | Perusahaan tidak **memperkerjakan** karyawan **dibawah** umur | Perusahaan tidak **mempekerjakan** karyawan **di bawah** umur |
| 3.9 | … akses dan **penympanan** dokumen **peruaturan** … | … akses dan **penyimpanan** dokumen **peraturan** … |
| 4.4 | Dalam **menetaplan** … kepentingan **orperasional** … | Dalam **menetapkan** … kepentingan **operasional** … |
| 4.6 | … mempunyai **mekaniske,** dan melakukan … | … mempunyai **mekanisme** dan melakukan … |
| 5.1 | … telah **menetaplan** program … untuk **menjapaian** objektif … | … telah **menetapkan** program … untuk **mencapai** objektif … |
| 5.2 | … PIC untuk **pencapaikan** objektif … | … PIC untuk **pencapaian** objektif … |
| 5.3 ⚠ | … mencakup **secara** sarana (Financial, SDM dan physical resource) … | … mencakup sarana (Financial, SDM dan physical resource) … *(kata "secara" dibuang)* |
| 5.5 | … terjadi **pengemangan** baru … | … terjadi **pengembangan** baru … |
| 5.6 | … telah **ditinjaklanjuti** … | … telah **ditindaklanjuti** … |
| 6.1.2 ⚠ | **Lk3MR** … aturan, **tanggungjawab** dan wewenang … | **LK3MR** … aturan, **tanggung jawab** dan wewenang … |
| 6.1.3 | Aturan, **tanggungjawab** … untuk **mereapkan** … | Aturan, **tanggung jawab** … untuk **menerapkan** … |
| 6.1.5 | … sumber daya **essensial** … | … sumber daya **esensial** … |
| 6.1.6 | … sumber daya **essensial** … | … sumber daya **esensial** … |
| 6.2.4 | Telah mereview **idenifikasi** … tersedianya **traning** … | Telah mereview **identifikasi** … tersedianya **training** … |
| 6.2.6 | … potensial dari **stivitas** pekerjaannya … | … potensial dari **aktivitas** pekerjaannya … |
| 6.2.7 | Mempunyai **prgram peningkaan** kesadaran … | Mempunyai **program peningkatan** kesadaran … |
| 7.2 | … sistem pengelolaan **Lk3SR** | … sistem pengelolaan **LK3SR** |
| 7.4 ⚠ | … mekanisme konsultasi **antara dengan** pihak yang terkait | … mekanisme konsultasi **dengan** pihak yang terkait *(kata "antara" dibuang)* |
| 9.1 | … dituntut **olek** QLK3SR | … dituntut **oleh** QLK3SR |
| 9.5 | … dokumen **obselete** … keperluan **hukun** … bisa **diindetifikasi** … | … dokumen **obsolete** … keperluan **hukum** … bisa **diidentifikasi** … |
| 9.7 | … dalam **sussunan** yang baik | … dalam **susunan** yang baik |
| 9.9 | Memiliki **intruksi**/prosedur … termasuk **upd ating** … | Memiliki **instruksi**/prosedur … termasuk **updating** … |
| 10.1 | … aspek penting **Lk3** … | … aspek penting **LK3** … |
| 10.5 | … penilaian & audit **suplier** … | … penilaian & audit **supplier** … |
| 10.7 | … sesuai permintaan **costumer** | … sesuai permintaan **customer** |
| 13.1 | … mendefinisikan **tanggungjawab** … | … mendefinisikan **tanggung jawab** … |
| 13.2 | Perusahaan **mengidentikasi** … | Perusahaan **mengidentifikasi** … |
| 14.7 | … diperlukan untuk **menunjukan** kesesuaian … | … diperlukan untuk **menunjukkan** kesesuaian … |
| 15.4 | … termasuk **frekwensi** audit … | … termasuk **frekuensi** audit … |
| 15.5 | Perusahaan telah **membantuk** team audit … | Perusahaan telah **membentuk** team audit … |
| 15.6 | … frekuensi dan **metholologi**, … dan **tanggungjawab** … | … frekuensi dan **metodologi**, … dan **tanggung jawab** … |
| 16.1 | … sistem pengelolaan **QLKSR** … | … sistem pengelolaan **QLK3SR** … |

Kriteria lain (±80 baris) hanya terkena aturan G1. Teks final ke-121 kriteria disimpan di `database/data/supplier_audit_template_v1.json` pada PR 1, sehingga reviewer bisa membandingkannya langsung dengan Excel. Nomor tampil per kelompok sama persis dengan kolom B di Excel.

---

## 5. Route Catalog

Semua parameter `{supplierAudit}` memakai implicit binding ke `SupplierAudit` (`HasHashids`), sehingga integer mentah menghasilkan 404.

### 5.1 Supplier local
Di dalam grup `routes/web.php:97` (`auth, role:supplier, supplier.scope:local`):

| Method | URI | Nama | Middleware tambahan | Controller@action |
|---|---|---|---|---|
| GET | `/local-supplier/supplier-audits` | `local-supplier.supplier-audits.index` | — | `LocalSupplier\SupplierAuditController@index` |
| GET | `/local-supplier/supplier-audits/{supplierAudit}` | `local-supplier.supplier-audits.show` | — | `@show` (read-only, dengan tombol Isi/Lanjutkan bila masih bisa diedit) |
| GET | `/local-supplier/supplier-audits/{supplierAudit}/edit` | `local-supplier.supplier-audits.edit` | — | `@edit` (form; redirect ke show bila terkunci) |
| PUT | `/local-supplier/supplier-audits/{supplierAudit}` | `local-supplier.supplier-audits.update` | `throttle:30,1` | `@update` (`action=draft\|submit`) |

Download hasil memakai `attachments.show` (`routes/web.php:355`) sesuai AGENTS; lihat §2.4.

### 5.2 Purchasing
Di dalam grup `routes/web.php:441` (`auth, role:purchasing, purchasing.navigation`):

| Method | URI | Nama | Middleware tambahan | Controller@action |
|---|---|---|---|---|
| GET | `/purchasing/supplier-audits` | `purchasing.supplier-audits.index` | — | `Purchasing\SupplierAuditController@index` |
| GET | `/purchasing/supplier-audits/create` | `purchasing.supplier-audits.create` | — | `@create` |
| POST | `/purchasing/supplier-audits` | `purchasing.supplier-audits.store` | `throttle:30,1` | `@store` |
| GET | `/purchasing/supplier-audits/{supplierAudit}` | `purchasing.supplier-audits.show` | — | `@show` |
| POST | `/purchasing/supplier-audits/{supplierAudit}/revision` | `purchasing.supplier-audits.request-revision` | `throttle:30,1` | `@requestRevision` |
| POST | `/purchasing/supplier-audits/{supplierAudit}/cancel` | `purchasing.supplier-audits.cancel` | `throttle:30,1` | `@cancel` |
| POST | `/purchasing/supplier-audits/{supplierAudit}/deadline` | `purchasing.supplier-audits.deadline` | `throttle:30,1` | `@changeDeadline` (D14) |
| POST | `/purchasing/supplier-audits/{supplierAudit}/result` | `purchasing.supplier-audits.result` | `throttle:30,1` | `@uploadResult` (publish atau ganti) |
| GET | `/purchasing/export/supplier-audits/{supplierAudit}` | `purchasing.export.supplier-audits.detail` | — | `Purchasing\ExportController@supplierAuditDetail` (mengikuti L506–510; memakai `dispatchResponse` privat di L156) |

Route statis `create` didaftarkan sebelum `{supplierAudit}`. Tambahkan `purchasing.supplier-audits.index` ke `PurchasingNavigation::LIST_ROUTES`.

---

## 6. Service Layer (`app/Services/SupplierAudit/`)

Semua service memakai pola yang sama:
1. `DB::transaction` dengan `lockForUpdate()` pada baris audit.
2. Guard status di dalam lock. Ini sumber kebenaran; policy hanya gerbang awal.
3. Tulis history.
4. Kirim notifikasi lewat `NotificationService` (otomatis `afterCommit`).

Pelanggaran guard melempar `ValidationException::withMessages([...])` dengan key terjemahan. Hasilnya respons 422 atau redirect dengan error, konsisten dengan form async.

### 6.1 `SupplierAuditAssignmentService`
`assign(User $actor, array $supplierIds, string $periodLabel, ?string $dueDate, SupplierAuditTemplate $template): SupplierAuditAssignmentResult`
1. Tolak bila template tidak aktif.
2. Dalam satu transaksi:
   - Kunci supplier: `User::localEligible()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()`. Urutan by id mencegah deadlock. Lock ini menserialisasi penugasan paralel untuk supplier yang sama (D7).
   - Supplier yang masih punya audit aktif (`SupplierAudit::where('supplier_id',$id)->whereIn('status', ACTIVE_STATUSES)->exists()`) ditolak dengan alasan `active_audit`.
   - ID yang tidak lolos `localEligible` ditolak dengan alasan `not_eligible`.
   - Untuk supplier yang lolos:
     - buat `SupplierAudit` (`ASSIGNED`, `assigned_by`, `assigned_at = now()`);
     - insert 121 baris snapshot dari `template->criteria` (eager-load section + parent);
     - tulis history `assigned`;
     - kirim notifikasi `supplier_audit.assigned`.
3. Kembalikan hasil berisi `created` dan `rejected` (nama perusahaan + alasan). Bila `created` kosong, lempar `ValidationException` pada `supplier_ids` dengan daftar penolakan.

Penugasan bersifat **sebagian berhasil**: supplier yang lolos tetap dibuat auditnya, dan yang ditolak dilaporkan, sesuai brief.

### 6.2 `SupplierAuditAnswerService`
`save(User $supplier, SupplierAudit $audit, array $answers, bool $submit): SupplierAudit`
1. **Lock dan guard.** Kunci audit, lalu pastikan `(int) supplier_id === (int) $supplier->id` dan status ∈ `SUPPLIER_EDITABLE_STATUSES`. Ini mencegah double-submit dan balapan dengan aksi Purchasing.
2. **Keanggotaan.** Muat jawaban audit, di-key dengan `supplier_audit_criterion_id`. Setiap key di payload **wajib** ada di set ini; bila tidak, 422. Ini lapis pertahanan kedua setelah FormRequest.
3. **Normalisasi per baris:**
   - `NO` → `score = null` (D3, dipaksa server);
   - `YES` → score integer 1–5, atau null saat draft;
   - answer null → score null.
   Hanya baris yang berubah yang di-update.
4. **Submit.** Hitung ulang dari DB: baris dengan `answer` null, dan baris `YES` dengan `score` null.
   - Bila masih ada, lempar `ValidationException` dengan key field pertama yang bermasalah, agar `adasi:reveal-field` memindah step.
   - Bila lolos: status `SUBMITTED`, `submitted_at = now()`, history `submitted` (mencatat `from_status`), dan notifikasi `supplier_audit.submitted` ke Purchasing.
5. **Draft.**
   - Status `ASSIGNED` → `DRAFT`, dengan history `draft_started`.
   - Status `REVISION_REQUESTED` tidak berubah.

### 6.3 `SupplierAuditReviewService`
- **`requestRevision(User $actor, SupplierAudit $audit, string $note, ?string $newDueDate = null, bool $changeDueDate = false)`**
  - Guard: status `SUBMITTED`.
  - Set `REVISION_REQUESTED`, `revision_note`, `revision_requested_at`.
  - Bila `$changeDueDate`: set `due_date` (nilai baru atau null) dalam transaksi yang sama, dan tulis history `deadline_changed`. Ini mencegah supplier langsung terblokir saat deadline lama sudah lewat (D14).
  - History `revision_requested` dengan `notes = note`, lalu notifikasi `supplier_audit.revision_requested`. Isi notifikasi menyertakan deadline baru bila ada.
- **`changeDeadline(User $actor, SupplierAudit $audit, ?string $dueDate, ?string $reason)`** (D14)
  - Guard: status ∈ `ACTIVE_STATUSES`.
  - Tidak ada perubahan bila nilainya sama (no-op, tanpa history).
  - Selain itu: set `due_date` (null = hapus deadline) dan tulis history `deadline_changed`.
  - Notifikasi `supplier_audit.deadline_changed` ke supplier, berisi deadline baru atau keterangan "deadline dihapus" (D15). Bila perubahan terjadi di dalam `requestRevision`, deadline baru cukup dimuat di notifikasi revisi, tanpa notifikasi kedua.
  - Efek ke blok invoice berlaku pada request berikutnya, karena blok tidak disimpan di mana pun.
- **`cancel(User $actor, SupplierAudit $audit, string $reason)`**
  - Guard: status ∈ `CANCELLABLE_STATUSES`.
  - Set `CANCELLED`, `cancelled_at`, `cancel_reason`, lalu tulis history.
  - Notifikasi `supplier_audit.cancelled` ke supplier, berisi alasan (D15).
- **`publishResult(User $actor, SupplierAudit $audit, UploadedFile $file, ?string $reason)`**
  1. Validasi ulang ekstensi, MIME nyata, dan ukuran (pola `SupplierOverpaymentService.php:25-27`).
  2. **Di luar transaksi:** stream put ke disk `private` di `attachments/Y/m/{hashName}` (`// biz-time:ignore storage path`).
  3. **Di dalam transaksi:** kunci audit, lalu:
     - guard status `SUBMITTED` (publish) atau `RESULT_PUBLISHED` (ganti; `$reason` wajib dan tidak kosong);
     - `attachments()->create([... 'uploaded_by' => $actor->id])`;
     - bila sebelumnya `SUBMITTED`: status `RESULT_PUBLISHED` dan isi `result_published_at`;
     - history `result_published` atau `result_replaced` (notes = alasan);
     - notifikasi `supplier_audit.result_published`.
  4. `catch (Throwable)`: hapus berkas, lalu rethrow (pola L92–95).
  - Publish vs ganti ditentukan dari status yang terkunci, bukan dari input browser.

### 6.4 Template
`SupplierAuditTemplateSeeder` (PR 1) membaca `database/data/supplier_audit_template_v1.json` dengan pola `MaterialHsCodeMasterSeeder`:
- Berjalan dalam transaksi.
- Idempoten per `[code, version]`: bila sudah ada, verifikasi jumlah lalu lewati. Template yang sudah ada **tidak pernah diubah**.
- Memastikan ada 16 section L1, 4 section L2, 121 kriteria, dan 17 kelompok.
- Mengaktifkan versi ini dan menonaktifkan versi lain dengan code yang sama.

Template baru dibuat sebagai versi baru, bukan dengan mengedit versi lama. UI pengelolaan template di luar cakupan.

### 6.5 `SupplierAuditInvoiceGate` — blok invoice baru (D13)
`app/Services/SupplierAudit/SupplierAuditInvoiceGate.php`, didaftarkan `$this->app->scoped(...)` di `AppServiceProvider` sehingga hasilnya di-memoize per request.

```php
public function blockingAudit(User $user): ?SupplierAudit   // null = tidak terblokir
{
    // hanya supplier dengan scope local; selain itu null
    return SupplierAudit::query()
        ->where('supplier_id', $user->id)
        ->late()                       // status ∈ SUPPLIER_EDITABLE_STATUSES, due_date < BusinessTime::today()->toDateString()
        ->orderBy('due_date')->first(); // D7 menjamin maksimal satu audit aktif
}

public function assertCanSubmitNewInvoice(User $user): void
{
    if ($audit = $this->blockingAudit($user)) {
        throw ValidationException::withMessages([
            'supplier_audit' => __('supplier_audit.invoice_block.message', [
                'period' => $audit->period_label,
                'date' => app(RegionalDisplayFormatter::class)->date($audit->due_date), // Perlu verifikasi signature date()
            ]),
        ]);
    }
}
```

**Titik penegakan** (hanya pengajuan invoice baru; resubmit dan cancel tidak tersentuh):

| Lokasi | Perubahan |
|---|---|
| `InvoiceSubmissionService::submit()` (`app/Services/LocalInvoice/InvoiceSubmissionService.php:33`, tepat setelah `Gate::authorize`) | `app(SupplierAuditInvoiceGate::class)->assertCanSubmitNewInvoice($actor)`. **Sumber kebenaran.** Form yang dibuka sebelum tengah malam lalu disubmit setelah deadline lewat tetap ditolak. Form invoice memakai `data-async-submit`, sehingga 422 tampil di ringkasan error **tanpa** menghilangkan berkas yang sudah dipilih |
| `LocalSupplier\InvoiceController@create` (L78–83) | Bila `blockingAudit()` tidak null: `redirect()->route('local-supplier.supplier-audits.edit', $audit)->with('warning', __('supplier_audit.invoice_block.message', ...))` |
| `LocalSupplier\InvoiceController@searchPurchaseOrders` (L32–34) | Bila terblokir: `abort(403, __('supplier_audit.invoice_block.short'))`, karena endpoint ini hanya melayani form create |
| `LocalInvoicePolicy` | **Tidak diubah.** `create()` dipakai ulang oleh `resubmit()`/`cancel()` (§2.15) |

- **Mengapa di service, bukan middleware atau policy:** satu titik authoritative di jalur tulis (sama dengan guard bisnis lain di `InvoiceSubmissionService`), dan tidak mengubah kontrak policy yang dipakai bersama.
- **Mengapa tanpa row lock:** blok hanya membaca. Balapan antara "supplier submit invoice" dan "Purchasing memperpanjang deadline" pada detik yang sama tidak merusak invarian. Hasil terburuk adalah satu penolakan yang bisa dicoba ulang. **[Inferred]**
- Tidak ada flag yang disimpan. Blok otomatis hilang saat audit disubmit, dibatalkan, atau deadline diubah/dihapus.

### 6.6 Command notifikasi blok — `supplier-audits:notify-invoice-blocked` (D15)
- **Class:** `app/Console/Commands/NotifySupplierAuditInvoiceBlocked.php`.
- **Jadwal:** **[Verified]** `routes/console.php` memakai `Schedule::command(...)` (L11, 16, 21, 26; mis. `local-invoices:send-delivery-reminders`). Tambahkan `Schedule::command('supplier-audits:notify-invoice-blocked')->dailyAt('00:10')->timezone(config('app.business_timezone', 'Asia/Jakarta'))->withoutOverlapping()`, mengikuti entri di sekitarnya.
- **Isi:** `SupplierAudit::late()->with('supplier')->chunkById(100, ...)` → kirim `supplier_audit.invoice_blocked` ke supplier pemilik.
- **Idempotensi:**
  - `eventKey = supplier-audit:{id}:invoice-blocked:{due_date}`, sehingga satu notifikasi per audit per nilai deadline.
  - Run yang terlewat atau diulang tidak menggandakan notifikasi. Deadline yang diubah lalu terlewat lagi menghasilkan notifikasi baru.
  - **[Verified]** `NotificationService` melewati penerima yang sudah punya notifikasi dengan ID UUIDv5 yang sama (`app/Services/NotificationService.php:70-73, 97-100`: `whereKey($notificationId)->exists()` → `return`). Command cukup memanggil `send()` setiap hari tanpa pelacakan tambahan.
  - Bila supplier mematikan event ini lewat preferensi, notifikasi tidak tersimpan dan akan "dicoba" lagi setiap hari, tetapi selalu dibuang listener. Ini tidak berbahaya.
- **Sifat:** command hanya membaca dan mengirim notifikasi. Blok itu sendiri tetap dihitung saat request (§6.5), jadi notifikasi yang terlambat tidak memengaruhi penegakan.
- **Operasional:** produksi sudah membutuhkan cron `schedule:run` (AGENTS). Tidak ada konfigurasi baru.

---

## 7. Policy dan Otorisasi

`App\Policies\SupplierAuditPolicy` (auto-discovery). Semua method juga mensyaratkan `$user->is_active`.

| Method | Aturan |
|---|---|
| `viewAny(User)` | `isPurchasing()` **atau** (`isSupplier()` && `hasSupplierScope('local')`) |
| `view(User, SupplierAudit)` | `isPurchasing()` **atau** (`hasSupplierScope('local')` && `(int) supplier_id === (int) user->id`) |
| `fill(User, SupplierAudit)` | pemilik local (seperti sisi supplier pada `view`) && `isEditableBySupplier()` |
| `create(User)` | `isPurchasing()` |
| `requestRevision(User, SupplierAudit)` | `isPurchasing()` && status `SUBMITTED` |
| `cancel(User, SupplierAudit)` | `isPurchasing()` && status ∈ `CANCELLABLE_STATUSES` |
| `changeDeadline(User, SupplierAudit)` | `isPurchasing()` && status ∈ `ACTIVE_STATUSES` |
| `uploadResult(User, SupplierAudit)` | `isPurchasing()` && status ∈ `[SUBMITTED, RESULT_PUBLISHED]` |
| `export(User, SupplierAudit)` | `isPurchasing()` && `isExportable()` |

- Setiap aksi controller memanggil `$this->authorize()` / `Gate::authorize()`. `authorize()` di FormRequest memakai policy yang sama.
- Admin **tidak** diberi akses, karena D1 hanya menyebut Purchasing. Admin tetap bisa membuka attachment lewat aturan blanket `AttachmentPolicy` yang sudah ada.
- **Cabang baru `AttachmentPolicy`** untuk supplier:
  ```php
  SupplierAudit::class => (int) $attachable->supplier_id === (int) $user->id
      && $user->hasSupplierScope('local')
      && $attachable->status === SupplierAudit::STATUS_RESULT_PUBLISHED
      && (int) $attachable->latestResult?->id === (int) $attachment->id,
  ```
  Akibatnya supplier mendapat 403 untuk file hasil lama.
- **`EnforceSupplierDomain.php:17-20`:** tambahkan `SupplierAudit::class` ke daftar early-pass `attachments.show`. Finance/accounting tetap ditolak karena tidak ada di cabang finance `AttachmentPolicy`.
- Query daftar di sisi supplier memakai `SupplierAudit::where('supplier_id', auth()->id())`, sesuai invariant AGENTS.

**FormRequest** (`app/Http/Requests/SupplierAudit/`):
- **`StoreSupplierAuditAssignmentRequest`**
  - `supplier_ids`: required, array, min:1, max:100; `supplier_ids.*`: string, distinct.
  - `period_label`: required, string, max:100.
  - `due_date`: nullable, `date_format:Y-m-d`, `after_or_equal` hari ini menurut `BusinessTime::today()->toDateString()`.
  - Template diambil server sebagai template aktif, bukan dari input.
  - Hash supplier di-resolve dengan pola `resolveSupplierFilter()` (`Purchasing/ExportController.php:142-154`): tolak digit mentah, `resolveRouteBinding`, lalu cek role.
- **`SaveSupplierAuditAnswersRequest`**
  - `action`: required, `in:draft,submit`.
  - `answers`: required, array.
  - `answers.*.answer`: nullable, `in:YES,NO`.
  - `answers.*.score`: nullable, integer, `between:1,5`, dan `prohibited_if:answers.*.answer,NO`.
  - Bila `action=submit`: `answers.*.answer` required, dan `answers.*.score` `required_if:answers.*.answer,YES`.
  - `withValidator`: semua key `answers` harus ada di `audit->answers()->pluck('supplier_audit_criterion_id')`.
  - Nama atribut error per baris: "Bagian {kode} no. {n}".
  - FormRequest menolak score yang dikirim bersama `NO`, dan service memaksa score null untuk `NO`. Keduanya memenuhi D3 ("menolak/memaksa null").
- **`RequestSupplierAuditRevisionRequest`:** `note` required, string, max:2000; `change_due_date` boolean; `due_date` nullable, `date_format:Y-m-d`, `after_or_equal` hari ini bisnis, hanya dipakai bila `change_due_date`.
- **`ChangeSupplierAuditDeadlineRequest`:** `due_date` nullable (kosong = hapus deadline), `date_format:Y-m-d`, `after_or_equal` hari ini bisnis; `reason` nullable, string, max:500.
- **`CancelSupplierAuditRequest`:** `reason` required, string, max:2000.
- **`UploadSupplierAuditResultRequest`:**
  - `result_file`: required, file, `mimes:pdf,xlsx,jpg,jpeg,png`, `max:10240`.
  - `reason`: wajib bila status audit `RESULT_PUBLISHED`; string, max:2000.

---

## 8. UI

### 8.1 Sidebar
- **Supplier local:** di `sidebar.blade.php`, setelah item "Vendor Profile" (L161) dalam section `navigation.supplier_information`:
  ```blade
  <x-ui.sidebar-item :href="route('local-supplier.supplier-audits.index')" icon="clipboard-check" :active="request()->routeIs('local-supplier.supplier-audits.*')" :label="__('navigation.supplier_audit')">
  ```
- **Purchasing:** di section `navigation.local_invoice`, setelah Local Vendors (L236). Pakai `PurchasingNavigation::listUrl('purchasing.supplier-audits.index')`, ikon `clipboard-check`, active `purchasing.supplier-audits.*`.
- Menu selalu tampil (D2).

### 8.2 View baru

**`purchasing/supplier-audits/index.blade.php`**
- `x-ui.page-header` dengan aksi "Tugaskan Audit".
- `x-ui.data-table` dengan filter GET:
  - status (select);
  - periode (select dari `distinct period_label`);
  - supplier (`x-ui.searchable-select`, nilai berupa hash);
  - checkbox "Terlambat saja".
- Kolom: Supplier, Periode, Deadline (+ `x-ui.status-chip tone="error"` "Terlambat · Invoice diblokir"), Status (chip), Tanggal Submit.
- `paginate(20)->withQueryString()`, dengan empty state.

**`purchasing/supplier-audits/create.blade.php`**
- `x-ui.form-section` berisi:
  - pilihan banyak supplier dengan pencarian; supplier yang masih punya audit aktif tampil **disabled** dengan keterangan "Audit aktif: {status}";
  - `period_label`;
  - `<x-ui.date-picker name="due_date">` (opsional);
  - info template aktif (judul + versi, read-only).
- Sticky action bar.
- Tanpa template aktif: `x-ui.alert`, dan tombol submit nonaktif.
- Setelah sukses: flash daftar audit yang dibuat dan supplier yang ditolak.

**`purchasing/supplier-audits/show.blade.php`**
- Header: supplier, periode, deadline, chip status, chip Terlambat.
- Ringkasan per bagian: jumlah Ya / Tidak / Kosong (**hanya hitungan**).
- Jawaban read-only dikelompokkan per bagian/sub-bagian: No, Kriteria, Ya/Tidak, Score.
- Bila terlambat: chip tambahan "Invoice diblokir" (`tone="error"`), sama seperti di kolom index.
- Panel aksi:
  - Export Excel (downloader async global);
  - Ubah Deadline (`x-ui.dialog` + `<x-ui.date-picker name="due_date" id="due_date_change">` + tombol "Hapus deadline" + alasan opsional), aktif di status `ACTIVE_STATUSES` (D14);
  - Minta Revisi (`x-ui.dialog` + textarea + checkbox "Ubah deadline" yang menampilkan `<x-ui.date-picker id="due_date_revision">`; bila deadline lama sudah lewat, checkbox tercentang secara default dengan peringatan "Supplier akan langsung terblokir mengajukan invoice bila deadline tidak diubah");
  - Upload/Ganti Hasil (form `data-async-submit` + `x-ui.file-upload`; alasan wajib bila mengganti);
  - Batalkan (`x-ui.dialog` + alasan, dengan `AdasiAlert.confirmDanger`).
- Daftar file hasil: yang terbaru ditandai "Terbaru", sisanya riwayat.
- Timeline status history.

**`local-supplier/supplier-audits/index.blade.php`**
- Tanpa audit aktif: `x-ui.empty-state icon="clipboard-check" :title="__('supplier_audit.empty.no_access_title')" :description="__('supplier_audit.empty.no_access')"`, dengan teks *"Menu Supplier Audit belum diberi akses oleh Purchasing."*
- Dengan audit aktif: kartu berisi periode, deadline, label Terlambat, progres n/N, dan tombol Isi/Lanjutkan/Lihat.
- Di bawahnya tabel **Riwayat**: semua audit milik sendiri, paginated.

**`local-supplier/supplier-audits/edit.blade.php`**
- Wizard Alpine inline, mengikuti `supplier-register` (L48–50/L1522+). Tidak ada komponen stepper baru.
- 16 langkah, satu per bagian utama. Sub-bagian dirender di dalam langkah induknya.
- Stepper vertikal di desktop dan horizontal di mobile.
- Progress bar global "n dari 121 terisi", ditambah progres per langkah.
- `x-ui.alert tone="warning"` menampilkan `revision_note` bila status `REVISION_REQUESTED`.
- Per kriteria:
  - `<fieldset>` dengan radio pill Ya/Tidak (pola kuesioner L785–833);
  - 5 radio Score 1–5, `disabled` dan dikosongkan saat jawaban Tidak atau belum dijawab.
- Satu `<form method="POST" data-async-submit>` dengan `@method('PUT')`.
- Sticky action bar:
  - Kembali / Lanjut;
  - **Simpan Draft** (`name="action" value="draft"`);
  - **Submit** (`value="submit"`), dengan konfirmasi `AdasiAlert.confirm({ confirmTone: 'primary' })` dan teks dari `@js(__())`.
- Penanda error per step dari event `adasi:form-errors`; listener `adasi:reveal-field` memindah step.
- Guard `unsaved-changes` berlaku otomatis.

**`local-supplier/supplier-audits/show.blade.php`**
- Jawaban read-only (partial yang sama dengan sisi Purchasing), status, dan catatan revisi atau alasan batal.
- Tombol **Download Hasil** → `route('attachments.show', $audit->latestResult)` bila `RESULT_PUBLISHED`.

**`supplier-audits/_answers-readonly.blade.php`** (partial bersama)
- Tabel jawaban per bagian, dipakai kedua sisi.

**Catatan implementasi:**
- **Respons JSON.** Controller supplier mengembalikan `response()->json(['redirect' => ...])` saat `expectsJson()`, dengan flash disimpan di session (pola `InvoiceController.php:129-140`). Controller Purchasing melakukan hal yang sama untuk upload hasil, karena AGENTS mewajibkan `data-async-submit` pada form upload.
- **UX** (gaya ERP ringkas sesuai kontrak redesign):
  - radio Ya/Tidak dan Score berupa segmented kecil dengan hit area ≥ 32 px;
  - Score memakai `aria-label` ("Score 1 dari 5");
  - Score yang disabled diberi teks bantu lewat `aria-describedby` ("Aktif bila jawaban Ya");
  - tanpa gradient, kartu mengambang, atau dinding KPI; warna semantik hanya untuk status dan Terlambat.
- **Format tanggal.** Keenam view baru menampilkan tanggal, jadi semuanya didaftarkan ke array composer `RegionalDisplayFormatter` di `AppServiceProvider.php:74-133`. Kolom `date` memakai `$regionalFormatter->date()`, timestamp memakai `->timestamp()` / `@bizdt`.

### 8.3 StatusHelper
Tambahkan `supplierAuditTone(string $status): string` dan `supplierAuditLabel(string $status): string`, mengikuti `registrationTone`/`registrationLabel` (`app/Support/StatusHelper.php:53,74`):

| Status | Tone |
|---|---|
| ASSIGNED | `info` |
| DRAFT | `neutral` |
| SUBMITTED | `info` |
| REVISION_REQUESTED | `warning` |
| RESULT_PUBLISHED | `success` |
| CANCELLED | `neutral` |

### 8.4 UI blok invoice di sisi supplier (D13)
- **Variabel view.** View composer baru di `AppServiceProvider` mengisi `$supplierAuditInvoiceBlock` (`?SupplierAudit`, dari `SupplierAuditInvoiceGate`, dimemoize per request; hanya untuk user supplier local) untuk:
  - `partials.sidebar`
  - `local-supplier.dashboard`
  - `local-supplier.invoices.index`
  - `local-supplier.purchase-orders.index`
  - `local-supplier.purchase-orders.show`
  - `local-supplier.supplier-audits.index`
- **`<x-ui.sidebar-item>`** mendapat prop opsional `disabled` (default false) dan `disabledHint`. Perubahan ini backward-compatible: tanpa prop, markup tetap identik.
  - Bila `disabled`: render `<span role="link" aria-disabled="true" tabindex="0">` tanpa `href`, dengan class `sidebar-link is-disabled` dan tooltip `data-bs-title` berisi hint.
  - Ikon dan label tetap tampil, sehingga menu "selalu terlihat tapi nonaktif" sesuai permintaan.
  - Styling `is-disabled` memakai token yang ada (opacity + `cursor: not-allowed`) di `resources/css/app.css`.
  - **Perlu verifikasi:** JS sidebar (`data-sidebar-item`) tidak bergantung pada `href`.
- **`sidebar.blade.php:157`** (Kirim Invoice): `:disabled="(bool) $supplierAuditInvoiceBlock"` `:disabled-hint="__('supplier_audit.invoice_block.short')"`.
- **Lima tombol `invoices.create`** (`dashboard.blade.php:13,92`, `invoices/index.blade.php:13`, `purchase-orders/index.blade.php:17`, `purchase-orders/show.blade.php:26`): tambah `:disabled="(bool) $supplierAuditInvoiceBlock"`. `x-ui.button` sudah menghapus `href` dan memberi `aria-disabled` (§2.15). Tambahkan `title` berisi hint.
- **Banner.** `<x-ui.alert tone="warning">` di atas konten `dashboard` dan `invoices/index`:
  - Isi: *"Pengajuan invoice baru dinonaktifkan karena form Supplier Audit periode {periode} melewati deadline {tanggal}. Selesaikan dan submit form audit untuk membuka kembali."*
  - Tombol "Isi Supplier Audit" → `local-supplier.supplier-audits.edit`.
  - Banner yang sama tampil di halaman edit audit (bersama catatan revisi bila ada), supaya supplier paham konsekuensinya.
- **Quick Access** (`config/quick_access.php:175-180`): tidak diubah. Tautan "Kirim Invoice" di Quick Access tetap ada; klik akan di-redirect `@create` ke form audit dengan pesan peringatan. Ini menghindari perubahan `QuickAccessService` bersama. **[Inferred]** cukup, dan bisa ditingkatkan nanti.
- Halaman `invoices.revision` dan tombol resubmit/cancel **tidak** dinonaktifkan (keputusan user).

### 8.5 Key i18n baru (en + id, berpasangan)
- **`lang/*/navigation.php`:** `supplier_audit` ("Supplier Audit" di kedua locale, sesuai glosarium Supplier).
- **`lang/*/status.php`:** domain `supplier_audit` dengan `assigned, draft, submitted, revision_requested, result_published, cancelled` (id: Ditugaskan, Draf, Disubmit, Perlu Revisi, Hasil Terbit, Dibatalkan).
- **`lang/*/supplier_audit.php`** (domain baru):
  - `title`, `description`
  - `empty.no_access_title`, `empty.no_access` (id: "Menu Supplier Audit belum diberi akses oleh Purchasing."; en: "Supplier Audit has not been enabled for you by Purchasing yet."), `empty.history`
  - `fields.*`: supplier, period, due_date, status, submitted_at, template, answer, score, yes, no, criterion, section
  - `labels.late` (Terlambat / Overdue), `labels.progress` (`:filled dari :total terisi`), `labels.latest_file`, `labels.score_hint`
  - `actions.*`: assign, fill, continue, save_draft, submit, export, request_revision, cancel, upload_result, replace_result, download_result
  - `confirm.submit_title`, `confirm.submit_text`, `confirm.cancel_*`
  - `flash.*`: assigned (`:count`), rejected, draft_saved, submitted, revision_requested, cancelled, result_published, result_replaced
  - `errors.*`: active_audit, not_eligible, no_active_template, incomplete, score_required, score_prohibited, invalid_criterion, locked, invalid_status
  - `history.events.*`: 8 event (termasuk `deadline_changed`)
  - `invoice_block.message` (`:period`, `:date`), `invoice_block.short` ("Selesaikan Supplier Audit untuk mengajukan invoice"), `invoice_block.banner_action`, `invoice_block.chip` ("Invoice diblokir")
  - `deadline.*`: change_title, remove, reason, change_on_revision, revision_warning, flash_changed, flash_removed
  - `export.*`: title, vendor_header (`Vendor/Supplier: :supplier — Periode: :period`), kolom No/Kriteria Audit/Ya/Tidak/Score/Point/Temuan/Catatan, sub_total
  - `notify.*`: judul dan isi untuk 7 event, dengan placeholder `:supplier`, `:period`, `:date` (deadline), dan `:reason` (pembatalan)
- **`lang/*/notifications.php`:** `events.supplier_audit_{assigned,revision_requested,result_published,submitted}.{label,description}` dan `categories.supplier_audits`.

---

## 9. Export

### 9.1 Pendekatan: (b) bangun dari snapshot DB dengan PhpSpreadsheet, async lewat `ExportDispatcher`

**Mengapa bukan (a), mengisi salinan workbook template:**
- Template Excel memecah data ke 4 sheet dengan posisi baris tetap, sedangkan data di DB **berversi**. Template v2 dengan jumlah kriteria berbeda akan merusak pemetaan baris.
- File template punya header `#REF!` rusak dan typo yang sudah dikoreksi di DB.
- Menyimpan template di `resources/templates/` berarti ada dua sumber teks.

**Mengapa (b):** layout mengikuti snapshot audit, jadi stabil per audit dan benar untuk versi template apa pun. Presedennya `PaymentBatchTransferSheetRenderer.php:97` (`new Spreadsheet`) dan `PaymentBatchDrpExport` (tulis ke temp, lalu stream ke disk).

**Class:** `App\Exports\SupplierAuditExport implements HasLocalePreference, GeneratesWorkbook, TracksExportProgress`, memakai trait `InteractsWithExportProgress`.
- Constructor `(int $actorId, int $supplierAuditId)`: argumen scalar dan JSON-serializable, tanpa model.
- `generateWorkbook()` memanggil `authorizeActor()` lebih dulu (pola L152–159): actor aktif, berrole `purchasing`, dan audit `isExportable()`. Pemeriksaan ini diulang di worker.
- Didaftarkan **hanya** di `SUPPORTED_EXPORT_CLASSES`, bukan `LIST_EXPORT_CLASSES`, karena ini export detail di luar Advanced Export.
- Controller `Purchasing\ExportController@supplierAuditDetail`:
  1. `Gate::authorize('export', $audit)`;
  2. `ExportDispatcher::dispatch(__('supplier_audit.export.label'), SupplierAuditExport::class, [auth()->id(), $audit->getKey()], $fileName)`;
  3. `dispatchResponse`.
- Nama file: `Supplier-Audit-{nama supplier}-{period_label}-` diikuti `now()->format('Ymd-His')` dan `.xlsx`, dengan anotasi `// biz-time:ignore instant filename`. Dispatcher sudah mensanitasi nama file (L178–203).

### 9.2 Pemetaan baris/kolom — 4 sheet (D16)
Sheet dibentuk dari `sheet_number_snapshot` pada jawaban, dengan nama sheet `1`, `2`, `3`, `4` seperti template. Untuk v1: sheet 1 = bagian 1–4, sheet 2 = 5–8, sheet 3 = 9–12, sheet 4 = 13–16 (sesuai §2.14). Template versi lain bisa punya jumlah sheet berbeda; renderer cukup mengelompokkan per nilai snapshot secara berurutan.

Semua sheet: A4 portrait, fit-to-width, baris header kolom diulang saat dicetak.

**Header per sheet (meniru template):**

| Sheet | Baris | Isi |
|---|---|---|
| 1 | 1–4 | Merge A1:H4: judul template dari snapshot, bold 20pt, wrap, rata tengah |
| 1 | 5 | Merge A5:H5: `Vendor/Supplier: {suppliers.company_name} — Periode: {period_label}` (bold) |
| 1 | 6 | Header kolom |
| 2–4 | 1 | Merge A1:H1: baris Vendor/Supplier yang sama (menggantikan `#REF!`) |
| 2–4 | 2 | Header kolom |

Header kolom: A `No`, merge B:C `Kriteria Audit`, D `Ya`, E `Tidak`, F `Score`, G `Point`, H `Temuan/Catatan` (bold, border).

**Isi** (dimulai tepat setelah header kolom pada tiap sheet):

| Baris | Isi |
|---|---|
| Judul L1 | A = kode (`1`), merge B:H = judul (bold) |
| Judul L2 | A = kode (`2.1`), merge B:H = judul (bold); didahului judul L1 induk bila induknya berbeda dari baris sebelumnya |
| Kriteria | B = `criterion_number_snapshot`, C = teks (wrap), D = `✓` bila YES, E = `✓` bila NO, F = score (angka) atau kosong, **G dan H kosong** |
| Sub Total | Merge A:E `Sub Total` (bold, garis bawah ganda); F kosong; **G = `=SUM(G{first}:G{last})`** atas baris kriteria kelompok itu **di sheet yang sama**; H kosong |
| Spasi | Satu baris kosong setelah setiap Sub Total, meniru template |

- Lebar kolom meniru template: A 4.3, B 2.9, C 96.4, D 5.4, E 4.9, F 14, G 27, H 21.4.
- Semua teks melewati `SpreadsheetCellSanitizer::text()`. Rumus SUM dibangun server, bukan dari teks user.
- Label header berasal dari `lang/*/supplier_audit.php` (`export.*`) dalam locale pemohon, yang dibawa `ProcessExportJob`. Teks kriteria berasal dari snapshot dan tidak diterjemahkan.
- Data: audit, supplier (`company_name`), dan answers terurut. Satu query plus eager-load, ±121 baris.

**Keputusan user (9 Okt): 4 sheet.** Setiap kelompok kriteria selalu berada utuh dalam satu sheet, karena `sheet_number` ditetapkan per bagian level 1 dan sub-bagian mewarisinya. Akibatnya rumus SUM tidak pernah lintas sheet.

---

## 10. Notifikasi

| Event (`source_event`) | Key preferensi | Penerima | Domain | Kategori filter | URL | eventKey |
|---|---|---|---|---|---|---|
| `supplier_audit.assigned` | `supplier_audit_assigned` | supplier pemilik | `LOCAL` | `OTHER` | `local-supplier.supplier-audits.show` | `supplier-audit:{id}:assigned` |
| `supplier_audit.revision_requested` | `supplier_audit_revision_requested` (`priority => action_required`) | supplier pemilik | `LOCAL` | `OTHER` | `local-supplier.supplier-audits.edit` | `supplier-audit:{id}:revision:{historyId}` |
| `supplier_audit.result_published` | `supplier_audit_result_published` | supplier pemilik | `LOCAL` | `OTHER` | `local-supplier.supplier-audits.show` | `supplier-audit:{id}:result:{attachmentId}` (setiap penggantian menjadi notifikasi baru) |
| `supplier_audit.submitted` | `supplier_audit_submitted` | semua `User::where('role','purchasing')->where('is_active',true)` (pola `ShipmentService.php:464`) | `GLOBAL` (Purchasing tidak menerima `LOCAL`; lihat §2.6) | `OTHER` | `purchasing.supplier-audits.show` | `supplier-audit:{id}:submitted:{historyId}` |
| `supplier_audit.cancelled` *(D15)* | `supplier_audit_cancelled` | supplier pemilik | `LOCAL` | `OTHER` | `local-supplier.supplier-audits.show` | `supplier-audit:{id}:cancelled` |
| `supplier_audit.deadline_changed` *(D15)* | `supplier_audit_deadline_changed` | supplier pemilik | `LOCAL` | `OTHER` | `local-supplier.supplier-audits.show` | `supplier-audit:{id}:deadline:{historyId}` |
| `supplier_audit.invoice_blocked` *(D15)* | `supplier_audit_invoice_blocked` (`priority => action_required`) | supplier pemilik | `LOCAL` | `INVOICE` (relevan dengan filter invoice supplier local; `NotificationCategory::optionsForUser` L72–101) | `local-supplier.supplier-audits.edit` | `supplier-audit:{id}:invoice-blocked:{due_date}` (dikirim command §6.6) |

- URL memakai `route(..., absolute: false)`. Ikon `clipboard-check`. Judul dan isi berupa key terjemahan `supplier_audit.notify.*` dengan `$replace` `supplier`/`period`.
- **Katalog preferensi** (`config/notification_preferences.php`), 7 entri baru:
  - `class => SystemNotification::class`;
  - `category => 'notifications.categories.supplier_audits'`;
  - `roles => ['supplier']` + `supplier_scopes => ['local']` untuk 6 event supplier, `roles => ['purchasing']` untuk `submitted`;
  - `default => true`.
- **Kategori baru** `notifications.categories.supplier_audits` ditambahkan ke `config/notification_categories.php` setelah `registration`.
- **Tes yang diperbarui:** `NotificationCategoryOrderTest` (count 47, key list, urutan kategori) dan `UserNotificationPreferencesTest` (count 47, `$expectedKeys`).

---

## 11. Rencana Test

Semua tes baru ada di `tests/Feature/SupplierAudit/`. Setup memakai helper privat per file (pola `LocalInvoiceTest.php:38-57`: hapus scope bawaan factory, lalu buat `Supplier`) dan `$this->seed(SupplierAuditTemplateSeeder::class)`. Tidak ada factory baru.

**`SupplierAuditTemplateSeederTest`**
- 16 section L1, 4 section L2, 121 kriteria, 17 kelompok.
- `sheet_number`: bagian 1–4 = 1, 5–8 = 2, 9–12 = 3, 13–16 = 4; sub-bagian mewarisi nilai induknya.
- Idempoten: dijalankan dua kali, jumlahnya tetap.
- Hanya satu versi aktif.
- Contoh teks terkoreksi: 1.10 "diperbaiki" dan judul bagian 3.

**`SupplierAuditModelTest`** (Unit/Feature)
- Konstanta transisi.
- `isLate()` dan `scopeLate()` dengan `Carbon::setTestNow` di sekitar tengah malam Asia/Jakarta (16:59 vs 17:00 UTC).
- Tidak Terlambat setelah SUBMITTED.
- `due_date` null tidak pernah Terlambat.

**`SupplierAuditAccessTest`**
- Supplier local tanpa penugasan melihat empty state dengan teks persis.
- Supplier import-only mendapat 403 di semua `local-supplier.supplier-audits.*`.
- Supplier A mendapat 403 di show/edit/update audit milik supplier B (via hash).
- Integer mentah → 404; hash tidak valid → 404.
- Finance dan supplier mendapat 403 di `purchasing.supplier-audits.*`.
- Menu tampil di sidebar local walau belum ada penugasan.
- Riwayat (RESULT_PUBLISHED/CANCELLED) tetap tampil di index bersama empty state.

**`SupplierAuditAssignmentTest`**
- Penugasan 3 supplier sekaligus membuat 3 audit × 121 jawaban beserta snapshot.
- Supplier dengan audit aktif ditolak, dan namanya muncul di flash.
- Supplier PENDING, nonaktif, atau import-only ditolak dengan alasan `not_eligible`.
- Bila semua ditolak → 422.
- D7: audit baru boleh dibuat setelah audit lama `RESULT_PUBLISHED` atau `CANCELLED`.
- `due_date` di masa lalu ditolak.
- Digit mentah di `supplier_ids` ditolak.
- Tanpa template aktif → error.
- History `assigned` tercatat dan notifikasi terkirim.

**`SupplierAuditAnswerTest`**
- Draft:
  - Ya tanpa score **diterima**;
  - Ya dengan score 6 → 422;
  - Tidak dengan score → 422 (prohibited);
  - ASSIGNED→DRAFT dan history tercatat sekali saja.
- Submit:
  - ada answer kosong → 422 dengan key field;
  - Ya tanpa score → 422;
  - lengkap → SUBMITTED dan `submitted_at` terisi.
- Criterion milik template/audit lain → 422.
- Form terkunci setelah SUBMITTED (edit redirect; update 422/403), juga setelah RESULT_PUBLISHED dan CANCELLED.
- Siklus revisi: SUBMITTED → REVISION_REQUESTED → simpan draft (status tetap) → submit ulang → SUBMITTED.
- Respons JSON `{redirect}` saat `Accept: application/json`.

**`SupplierAuditReviewTest`**
- Revisi:
  - tanpa catatan → 422;
  - hanya boleh dari SUBMITTED.
- Batal:
  - dari 4 status aktif → OK;
  - dari RESULT_PUBLISHED → ditolak.
- Upload hasil:
  - dari SUBMITTED → RESULT_PUBLISHED, attachment tersimpan di disk `private` (`Storage::fake('private')`);
  - dari DRAFT → ditolak;
  - mime `exe` atau ukuran > 10 MB → 422;
  - berkas dihapus bila transaksi gagal.
- Ganti hasil:
  - tanpa alasan → 422;
  - dengan alasan → 2 attachment dan history `result_replaced` berisi alasan.
- Akses `attachments.show`:
  - supplier: file terbaru → 200, file lama → 403;
  - supplier lain → 403; finance → 403;
  - purchasing → 200 untuk kedua file.

**`SupplierAuditExportTest`**
- Route export → 403 untuk ASSIGNED, DRAFT, REVISION_REQUESTED, CANCELLED.
- SUBMITTED → `Queue::assertPushed(ProcessExportJob::class)` dan `ExportJob` milik purchasing.
- Isi workbook: jalankan `generateWorkbook()` ke disk fake lalu `IOFactory::load` (pola `DetailExportSecurityTest.php:336-341`), dan periksa:
  - A5 berisi nama supplier dan periode;
  - `✓` di D/E sesuai jawaban; F = score;
  - G/H kosong pada baris kriteria;
  - G di baris Sub Total = `=SUM(...)` dengan rentang benar;
  - workbook punya 4 sheet bernama `1`–`4`; sheet 1 memuat bagian 1–4, sheet 4 memuat bagian 13–16;
  - sheet 1 punya judul di A1 dan baris vendor di A5, sedangkan sheet 2–4 punya baris vendor di A1 dan header kolom di baris 2;
  - total 17 baris Sub Total, dan tidak ada rumus SUM yang merujuk sheet lain;
  - teks yang diawali `=` tersanitasi.
- Actor non-purchasing di worker → exception.

**`SupplierAuditNotificationTest`**
- 7 event sampai ke penerima yang benar dengan domain dan kategori yang benar.
- `cancelled` membawa alasan; `deadline_changed` membawa deadline baru atau "dihapus"; revisi dengan deadline baru tidak mengirim `deadline_changed` terpisah.

**`SupplierAuditInvoiceBlockedCommandTest`** (D15)
- Audit terlambat → satu notifikasi `supplier_audit.invoice_blocked`. Command dijalankan dua kali → tetap satu notifikasi (dedup UUIDv5).
- Deadline diubah lalu terlewat lagi → notifikasi baru.
- Audit SUBMITTED, CANCELLED, tanpa deadline, atau deadline hari ini → tidak ada notifikasi.
- Batas hari memakai `Carbon::setTestNow` di sekitar 17:00 UTC.
- Jadwal terdaftar: `Schedule` memuat command dengan timezone bisnis (pola tes jadwal yang ada; **Perlu verifikasi** apakah ada preseden tes untuk `routes/console.php`).
- Purchasing menerima `submitted` (domain GLOBAL).
- Supplier local-only menerima 3 event (domain LOCAL).
- Bila preferensi dimatikan, notifikasi tidak tersimpan (`ApplyNotificationPreferences`).

**`SupplierAuditInvoiceBlockTest`** (D13/D14)
- Tanpa audit atau tanpa `due_date`: `invoices.create` 200 dan `store` sukses (regresi suite LocalInvoice tetap hijau).
- Audit `ASSIGNED`/`DRAFT`/`REVISION_REQUESTED` dengan `due_date` kemarin (zona bisnis):
  - `GET invoices.create` → redirect ke `supplier-audits.edit` dengan flash warning;
  - `POST invoices.store` → 422 (JSON) dengan key `supplier_audit`, tanpa invoice dibuat dan tanpa GR yang direservasi;
  - `purchase-orders.search` → 403.
- Batas hari: `Carbon::setTestNow` 16:59:59 UTC (23:59:59 WIB, hari deadline) → tidak terblokir; 17:00:00 UTC → terblokir.
- Tetap boleh saat terblokir: `invoices.revision`, `invoices.resubmit` untuk invoice `NEED_REVISION`, dan `invoices.cancel`.
- Blok terbuka setelah: submit audit, pembatalan audit, deadline diperpanjang (`changeDeadline`), deadline dihapus, dan `requestRevision` dengan deadline baru.
- `requestRevision` tanpa mengubah deadline yang sudah lewat → supplier langsung terblokir lagi (perilaku yang disengaja).
- Supplier lain tanpa audit terlambat tidak terpengaruh.
- UI: sidebar merender item "Kirim Invoice" sebagai `aria-disabled="true"` tanpa `href`; tombol create di dashboard tanpa `href`; banner tampil. Saat tidak terblokir, markup sidebar identik dengan sebelumnya.
- Purchasing: `changeDeadline` hanya pada `ACTIVE_STATUSES`; tanggal masa lalu → 422; history `deadline_changed` tercatat; supplier → 403.

**`SupplierAuditConcurrencyTest`** (opsional, PR 3)
- Dua proses `assign` paralel untuk supplier yang sama hanya menghasilkan satu audit aktif. Memakai subprocess worker gaya `tests/Support/local-gr-reservation-worker.php`.

**Guard yang sudah ada (wajib dijalankan):**
- `TranslationParityTest`, `TranslationContentTest`.
- `HashidUrlSecurityTest`: tambahkan `SupplierAudit` ke daftar model (L138–156) dan route bernama (L161–174).
- `BusinessTimeGuardTest`.
- `UserFacingCopyInventoryTest` (lihat R2), `SurfaceHierarchyTest`, `FrontendAssetLoadingTest` (jumlah halaman DataTables tetap 15).
- `RouteContractTest`, `NotificationCategoryOrderTest`, `UserNotificationPreferencesTest`.
- `StatusHelperToneComprehensiveTest`: tambahkan kasus `supplierAuditTone`.
- `SupplierDataIsolationTest`, `LocalInvoice/LocalInvoiceScopeIsolationTest`, karena boundary attachment berubah.
- Seluruh `tests/Feature/LocalInvoice/`, `LocalInvoiceSubmissionV2Test`, dan `CashierReceiptAndExpiryTest`, karena `InvoiceSubmissionService::submit` dan view invoice berubah.
- `SidebarShellTest` dan `RenderedComponentTest`, karena `x-ui.sidebar-item` mendapat prop baru.
- `TestingEnvironmentDatabaseSafetyTest` sebelum menjalankan feature suite.

---

## 12. Pembagian PR

### PR 1 — Fondasi
**Prasyarat:** terpenuhi. Tabel koreksi §4, judul bagian, dan kode template sudah disetujui user (9 Okt).

**File:**
- Migrasi `2026_10_09_000001_create_supplier_audit_tables.php`.
- Model `SupplierAuditTemplate`, `SupplierAuditSection`, `SupplierAuditCriterion`, `SupplierAudit`, `SupplierAuditAnswer`, `SupplierAuditStatusHistory`.
- `database/data/supplier_audit_template_v1.json` dan `database/seeders/SupplierAuditTemplateSeeder.php`.
- `app/Policies/SupplierAuditPolicy.php`.
- `StatusHelper` (tone/label) dan `lang/{en,id}/status.php`.
- Tes `SupplierAuditTemplateSeederTest` dan `SupplierAuditModelTest`.
- `AGENTS.md`: skema, jumlah policy, dan daftar HasHashids.
- Panduan deploy di `docs/guides/`: `php artisan db:seed --class=SupplierAuditTemplateSeeder`.

**Selesai bila:**
- `php -l` dan Pint bersih untuk semua file baru;
- migrasi berjalan di DB test lewat suite `RefreshDatabase`;
- tes seeder/model, `TranslationParityTest`, dan `BusinessTimeGuardTest` hijau;
- belum ada route maupun UI.

### PR 2 — Penugasan dan pengisian
**File:**
- `routes/web.php` (dua grup).
- Controller: `Purchasing/SupplierAuditController` (index, create, store, show) dan `LocalSupplier/SupplierAuditController`.
- Request: `StoreSupplierAuditAssignmentRequest`, `SaveSupplierAuditAnswersRequest`.
- Service: `SupplierAuditAssignmentService`, `SupplierAuditAnswerService`.
- `PurchasingNavigation::LIST_ROUTES` dan `sidebar.blade.php`.
- 5 view + partial dari §8.2, tanpa aksi review.
- Composer view di `AppServiceProvider`.
- `lang/{en,id}/{navigation,supplier_audit}.php`.
- Notifikasi `assigned`, `submitted`, `deadline_changed`, dan `invoice_blocked`: entri katalog, kategori, serta pembaruan 2 tes katalog (count menjadi 44).
- Command `supplier-audits:notify-invoice-blocked` + jadwal di `routes/console.php` + `SupplierAuditInvoiceBlockedCommandTest`.
- Tes `SupplierAuditAccessTest`, `SupplierAuditAssignmentTest`, `SupplierAuditAnswerTest`, `SupplierAuditInvoiceBlockTest`, tambahan di `HashidUrlSecurityTest`.
- **Blok invoice (D13) dan ubah deadline (D14):**
  - `SupplierAuditInvoiceGate` (scoped binding + composer);
  - guard di `InvoiceSubmissionService::submit`;
  - redirect/abort di `LocalSupplier/InvoiceController@create` dan `@searchPurchaseOrders`;
  - prop `disabled` pada `components/ui/sidebar-item.blade.php`, sidebar L157, 5 tombol create, dan banner;
  - `SupplierAuditReviewService` dibuat dengan method `changeDeadline` saja (diperluas di PR 3);
  - `ChangeSupplierAuditDeadlineRequest`, route `purchasing.supplier-audits.deadline`, dan dialog Ubah Deadline.
  - Blok dan cara membukanya dirilis bersamaan, sehingga Purchasing tidak pernah bisa memblokir supplier tanpa bisa memperpanjang deadline.
- Regenerasi ledger copy.

**Selesai bila:**
- semua tes PR 2 dan guard di §11 hijau, termasuk seluruh `tests/Feature/LocalInvoice/`;
- `php artisan view:cache`, `route:list`, dan `npm.cmd run build` sukses;
- alur assign → isi → simpan draft → submit diuji manual di browser (dilaporkan terpisah dari tes otomatis);
- diuji manual: supplier terlambat melihat menu Kirim Invoice nonaktif dan banner; setelah submit audit (atau deadline diperpanjang), menu aktif kembali.

### PR 3 — Penilaian
**File:**
- `SupplierAuditReviewService` diperluas (`requestRevision` dengan opsi deadline baru, `cancel`, `publishResult`) beserta request revision/cancel/upload.
- Aksi Purchasing di controller dan view show.
- `app/Exports/SupplierAuditExport.php` dan `ExportDispatcher` (SUPPORTED).
- `Purchasing/ExportController@supplierAuditDetail` beserta route.
- `AttachmentPolicy` dan `EnforceSupplierDomain`.
- Tombol Download Hasil di sisi supplier.
- Notifikasi `revision_requested`, `result_published`, dan `cancelled` (katalog menjadi 47).
- Timeline history.
- Tes `SupplierAuditReviewTest`, `SupplierAuditExportTest`, `SupplierAuditNotificationTest` (+ tes concurrency opsional).
- `AGENTS.md`: tabel upload, daftar pengecualian `attachments.show`, dan export class.

**Selesai bila:**
- semua tes PR 3 dan guard hijau, termasuk `SupplierDataIsolationTest` dan `LocalInvoiceScopeIsolationTest`;
- `git diff --check` bersih;
- diuji manual: export diunduh lewat downloader async, upload dan ganti hasil, supplier mengunduh file terbaru;
- file Excel dibuka di Excel/LibreOffice, dan rumus SUM menghitung setelah Point diisi.

---

## 13. Risiko dan Hal yang Perlu Verifikasi

| # | Risiko / item | Mitigasi |
|---|---|---|
| R1 | Tes katalog notifikasi mengunci angka 40 (`NotificationCategoryOrderTest.php:75-79`, `UserNotificationPreferencesTest.php:25-50`) | Perbarui di PR yang menambah event: 44 di PR 2, 47 di PR 3 |
| R2 | `UserFacingCopyInventoryTest` gagal untuk setiap file/baris baru sampai ledger `UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl` diregenerasi. AGENTS meminta snapshot historis tidak ditulis ulang "sebagai efek samping task lain", tetapi ledger yang sudah termodifikasi di working tree menunjukkan regenerasi memang praktik saat menambah copy | Regenerasi di PR 2/PR 3 sebagai langkah eksplisit yang disebut di deskripsi PR |
| R3 | Template tidak otomatis tersedia di staging/produksi karena seeder tidak dipanggil `DatabaseSeeder` | Langkah deploy didokumentasikan; halaman create menampilkan alert dan menonaktifkan submit bila tidak ada template aktif |
| R4 | CHECK constraint baru ditegakkan pada MySQL ≥ 8.0.16 | **Perlu verifikasi** versi MySQL di dev/test/produksi; validasi aplikasi tetap lapisan utama |
| R5 | MIME xlsx bisa terdeteksi sebagai `application/zip` | **Perlu verifikasi** di environment; uji dengan file xlsx asli |
| R6 | `x-ui.multi-select` dirancang untuk pilihan PO/GR (placeholder default `choose_po`) | **Perlu verifikasi** untuk ratusan supplier dengan opsi disabled; alternatifnya daftar checkbox + filter teks di `x-ui.form-section` |
| R7 | Domain notifikasi yang salah membuat event hilang diam-diam (fallback IMPORT, `NotificationDomain.php:66`) | `domain` selalu eksplisit, dan diuji `SupplierAuditNotificationTest` |
| R8 | Pengecualian `attachments.show` memperluas permukaan shared endpoint | Cabang policy "pemilik + RESULT_PUBLISHED + file terbaru"; tes 403 untuk file lama, supplier lain, dan finance |
| R9 | Form 121 baris × 6 radio dalam satu halaman | **[Inferred]** ringan untuk DOM modern, dan `x-show` per step mencegah semua baris tampil sekaligus; verifikasi manual di tablet |
| R10 | Diskrepansi dokumentasi: AGENTS.md menyebut 11 policy, working tree punya 12 (`LocalProcurementImportPolicy` untracked) | Dilaporkan; AGENTS diperbarui di PR 1 (menjadi 13 dengan `SupplierAuditPolicy`) |
| R11 | Penugasan bisa sebagian berhasil | Flash menampilkan daftar yang dibuat dan ditolak; ada tes eksplisit |
| R12 | Gaya kolom status (enum vs string) belum dipastikan | **Perlu verifikasi** terhadap migrasi status Core 2 terbaru sebelum menulis migrasi |
| R13 | Blok invoice (D13) menyentuh jalur keuangan yang sudah berjalan (`InvoiceSubmissionService::submit`) | Guard ditempatkan setelah `Gate::authorize` dan sebelum parsing atau reservasi GR, sehingga tidak ada efek samping saat ditolak. Seluruh suite LocalInvoice wajib hijau. `resubmit`/`cancel` tidak disentuh |
| R14 | Supplier bisa sedang mengisi form invoice ketika deadline lewat (tengah malam WIB) | Ditolak 422 oleh service; form `data-async-submit` mempertahankan berkas dan menampilkan pesan + tautan ke audit |
| R15 | Purchasing meminta revisi setelah deadline lewat tanpa mengubah deadline → supplier langsung terblokir | Checkbox "Ubah deadline" tercentang default dengan peringatan di dialog revisi (§8.2); disengaja dan diuji |
| R16 | `x-ui.sidebar-item` adalah komponen bersama | Prop opsional dengan default yang mempertahankan markup lama; **Perlu verifikasi** JS sidebar/tooltip tidak bergantung `href`; jalankan `SidebarShellTest` + `RenderedComponentTest` |
| R17 | Query blok berjalan di setiap halaman supplier local yang merender sidebar | Satu query ber-index per request, dimemoize lewat `scoped` binding; hanya untuk user supplier local |
| R18 | Quick Access "Kirim Invoice" tidak tampil disabled | Server me-redirect dengan pesan; disabled visual bisa ditambah nanti di `QuickAccessService` bila diminta |

Belum diverifikasi saat menyusun rencana: eksekusi tes, build, browser, cetak Excel, dan versi DB.

---

## 14. Keputusan atas Pertanyaan Terbuka

Semua pertanyaan sudah dijawab user pada 9 Okt. Tidak ada pertanyaan yang tersisa.

| # | Pertanyaan | Jawaban user | Dampak di rencana |
|---|---|---|---|
| Q1 | Tabel koreksi §4 (termasuk baris ⚠) | **Disetujui** | Seeder PR 1 memakai teks usulan |
| Q2 | Judul bagian 6 dan 10 identik | **Biarkan identik** | "Penerapan dan Operasi" pada keduanya |
| Q3 | Kode/judul template | **Disetujui**: `ISO_14001_9001_AGC_AFC` v1, "FORM CHECKLIST AUDIT SUPPLIER & VENDOR — ISO 14001, ISO 9001 & AGC AFC" | Seeder PR 1 |
| Q4 | Notifikasi batal / deadline diubah / invoice diblokir | **Ya, perlu** | D15; 3 event tambahan (§10) dan command terjadwal (§6.6) |
| Q5 | Export 1 sheet atau 4 sheet | **4 sheet** | D16; kolom `sheet_number` / `sheet_number_snapshot` (§3.1), renderer (§9.2) |
| — | Cakupan blok invoice | **Hanya invoice baru** | D13 |
| — | Revisi setelah deadline lewat | **Purchasing bisa ubah deadline** | D14 |
| — | Simpan draft dengan Ya tanpa Score | **Draft boleh, submit menolak** | D3 |
| — | Export saat REVISION_REQUESTED | **Tidak** (hanya SUBMITTED & RESULT_PUBLISHED) | §3.3, §7 |

---

## 15. Langkah setelah rencana disetujui
1. Salin file ini ke `docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-20261008.md`.
2. Berhenti. Jangan mulai implementasi sampai user meminta PR 1 secara eksplisit.
