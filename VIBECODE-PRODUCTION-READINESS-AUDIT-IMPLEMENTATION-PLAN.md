# Implementation Plan — Audit Codebase Vibecode Sebelum Merge/Deploy

## 1. Tujuan

Melakukan audit menyeluruh terhadap codebase `poyipoy/supplierportal` sebelum merge ke branch utama atau deploy production.

Audit tidak boleh berhenti pada pertanyaan “apakah aplikasi berjalan?”. Audit harus membuktikan, berdasarkan evidence repository:

- kode sesuai requirement dan business rule;
- authentication dan authorization benar;
- data antar supplier terisolasi;
- input, upload, export, dan endpoint aman;
- database dan migration menjaga integrity;
- queue/realtime/integrasi eksternal resilient;
- query dan resource usage masuk akal;
- test benar-benar memverifikasi behavior;
- deployment dapat di-rollback dan di-recover;
- issue kritis tidak lolos ke production.

### Prinsip utama

1. **Audit-first, fix-later.**
   Jangan mengubah source code selama fase audit kecuali user secara eksplisit meminta remediation.
2. **Diff-first.**
   Baca seluruh diff perubahan terlebih dahulu sebelum menilai implementasi.
3. **Evidence over assumption.**
   Bedakan:
   - VERIFIED — dibuktikan dari source/test/config;
   - OBSERVED — terlihat di code tetapi belum diuji runtime;
   - INFERRED — kesimpulan logis dari implementation;
   - ASSUMPTION — informasi yang belum dikonfirmasi;
   - NOT VERIFIED — belum dapat diverifikasi.
4. **Prioritas risiko:**
   Security > Business Logic > Reliability/Data Integrity > Performance > Maintainability > UI/SEO.
5. **Tidak menganggap test hijau = production ready.**
6. **Jangan memperbaiki simptom saja.**
   Bila ada issue, identifikasi root cause, enabling condition, dan missing safeguard.

---

## 2. Konteks Repository yang Harus Dijadikan Baseline

Repository:
`https://github.com/poyipoy/supplierportal`

Branch utama:
`master`

HEAD yang ditemukan saat penyusunan plan:
`afd7f67acdb867236672fa86de8263144ac5cd06`

Commit HEAD:
`feat(claims): improve qc navigation in same tab and enhance interactive image lightbox gestures`

Tidak ditemukan open Pull Request pada repository saat audit awal. Karena itu, audit harus mendukung dua mode:

### Mode A — Ada PR / feature branch

Gunakan:

```bash
git fetch --all --prune
git diff --stat <BASE_SHA>...<HEAD_SHA>
git diff --name-status <BASE_SHA>...<HEAD_SHA>
git diff --find-renames <BASE_SHA>...<HEAD_SHA>
git diff --find-renames --unified=80 <BASE_SHA>...<HEAD_SHA>
```

`BASE_SHA` harus branch utama/commit yang benar-benar menjadi target merge.

### Mode B — Tidak ada PR

Gunakan current `master` sebagai deploy candidate dan tentukan change window yang disepakati.

Minimal:

```bash
git log --oneline --decorate -30
git show --stat --summary HEAD
git diff HEAD^...HEAD
```

Jangan menyebut “audit diff” seolah-olah ada PR apabila PR memang tidak ada. Nyatakan dengan jelas bahwa audit dilakukan terhadap current production candidate atau commit range yang dipilih.

---

## 3. Baseline Arsitektur yang Harus Dipahami Sebelum Audit

Repository saat ini teridentifikasi sebagai aplikasi Laravel dengan karakteristik berikut:

- PHP 8.2+
- Laravel 12
- Blade
- Bootstrap compatibility layer + prefixed Tailwind utilities
- jQuery/AJAX
- Alpine.js
- MySQL
- Laravel Queue menggunakan database driver untuk export async
- Pusher/Laravel Echo sebagai realtime, dengan polling fallback
- SMTP untuk email
- Laravel Excel untuk import/export
- google2fa untuk 2FA
- Cloudflare Turnstile untuk anti-bot/auth hardening
- Hashids untuk public route key
- role: `admin`, `purchasing`, `supplier`, `qc`

File arsitektur/instruksi penting:

- `AGENTS.md`
- `CLAUDE.md`
- `.agents/rules/*.md`
- `.codex/skills/...`
- `routes/web.php`
- `routes/auth.php`
- `bootstrap/app.php`
- `app/Policies/*`
- `app/Http/Middleware/*`
- `app/Http/Requests/*`
- `app/Models/*`
- `database/migrations/*`
- `app/Jobs/*`
- `app/Exports/*`
- `app/Imports/*`
- `resources/views/*`
- `resources/js/*`
- `resources/css/*`
- `tests/Feature/*`
- `tests/Unit/*`
- `.env.example`
- `.cpanel.yml`
- `Dockerfile`
- `deployment/*`

### Business domain yang harus dipahami

Core workflow:

```text
Purchasing
  → Purchase Requisition
  → Supplier Invitation
  → Supplier Quotation
  → Quotation Review
  → Purchase Order
  → QC Inspection
  → Material Claim
```

Selain itu:

```text
Authentication
  → Login
  → Session Security
  → 2FA
  → Password Reset
  → Session Revoke
```

dan:

```text
Operational Data
  → Export Job
  → Database Queue
  → Excel Export
  → Private Storage
  → Download
```

---

# 4. Phase 0 — Audit Initialization

## 4.1 Baca instruction files

Wajib dibaca terlebih dahulu:

```text
AGENTS.md
CLAUDE.md
claudes-cognitive-framework-for-laravel-development.md
.agents/rules/*.md
```

Tujuan:

- memahami business invariants;
- memahami naming;
- memahami auth/RBAC;
- memahami supplier isolation;
- mengetahui command verification;
- memahami keputusan arsitektur yang sudah dipilih.

### Acceptance Criteria

- [ ] Semua instruction file yang berlaku teridentifikasi.
- [ ] Konflik antara dokumentasi dan implementation dicatat.
- [ ] Tidak ada asumsi framework yang menggantikan fakta repository.

---

# 5. Phase 1 — Baca Seluruh Diff Sebelum Menilai

## 5.1 Inventarisasi perubahan

Untuk setiap file:

- path;
- status added/modified/deleted/renamed;
- line count;
- perubahan API;
- perubahan schema;
- perubahan business logic;
- perubahan permission;
- perubahan behavior;
- perubahan dependency;
- perubahan deployment.

Kelompokkan ke:

```text
A. Security-sensitive
B. Business-critical
C. Database/schema
D. Queue/background job
E. External integration
F. Frontend/JS
G. Test
H. Documentation
I. Deployment artifact
```

## 5.2 Jangan skip file besar

Perhatian khusus karena repository ini memiliki perubahan besar pada:

- `app/Http/Controllers/Supplier/QuotationController.php`
- `app/Imports/QuotationItemsImport.php`
- `app/Models/QuotationItem.php`
- `resources/views/...`
- migration terbaru
- export flow
- auth hardening
- deployment ZIP
- `auth-hardening.patch`
- generated/documentation artifacts

### Acceptance Criteria

- [ ] Semua changed file masuk inventory.
- [ ] Tidak ada diff besar yang dilewati karena “hanya UI”.
- [ ] Binary/archive artifacts tetap diperiksa dari sisi supply-chain/security.
- [ ] Diff yang menyentuh schema punya mapping ke migration dan model.
- [ ] Diff yang menyentuh route punya mapping ke authorization/test.

---

# 6. Phase 2 — Verifikasi Project Integrity

Jalankan:

```bash
php -v
composer --version
node --version
npm --version
php artisan --version
php artisan about
php artisan route:list
php artisan migrate:status
```

Periksa:

```bash
git status --short
git diff --check
git ls-files | sort
```

Cari:

- file generated yang seharusnya tidak tracked;
- temporary HTML;
- patch file;
- backup file;
- deployment ZIP;
- duplicate migration;
- duplicate docs;
- test fixture yang tampak production data;
- credential-looking string.

### Special review

Repository saat ini memiliki indikasi artifacts non-source seperti:

```text
auth-hardening.patch
custom-datepicker.html
deployment/*.zip
UI-REDESIGN-RESULT/database/*
```

Audit harus menentukan apakah masing-masing:

1. memang source-of-truth;
2. hanya artifact historis;
3. mengandung code yang seharusnya tidak berada di repository;
4. dapat mengandung secret/vendor/package;
5. berpotensi digunakan keliru saat deployment.

---

# 7. Phase 3 — Audit A: Security

## A1. Input validation

Inventaris semua endpoint yang:

- menerima `Request`;
- menerima upload;
- menerima ID/hash;
- menerima filter/search;
- menerima import;
- menerima action/status.

Audit:

```text
FormRequest
Validator::make
$request->validate()
Rule::exists
Rule::in
integer
numeric
date
boolean
array
distinct
max
file
mimes/types
```

Cari endpoint yang langsung melakukan:

```php
$request->all()
$request->input()
$request->get()
```

kemudian menyimpan data tanpa validator.

### Special attention

`app/Http/Controllers/Supplier/QuotationController.php`

Periksa:

- `action`;
- `currency`;
- `estimated_delivery`;
- `validity_period`;
- `items.*`;
- `pr_item_id`;
- amount/price;
- availability;
- offered weight;
- dimension ranges;
- MTC upload.

### Pass condition

Setiap trust boundary memiliki validation layer yang eksplisit.

---

## A2. SQL Injection

Cari:

```bash
grep -RniE "DB::raw|whereRaw|orderByRaw|selectRaw|havingRaw|groupByRaw|statement\(" app routes
```

Tidak semua raw SQL adalah issue.

Untuk setiap occurrence:

- tentukan input source;
- tentukan apakah binding digunakan;
- tentukan apakah expression static atau dynamic;
- cek `orderByRaw` terhadap user-controlled column;
- cek dynamic table/column name.

### Pass condition

Tidak ada user input yang disisipkan ke raw SQL tanpa strict allow-list/binding.

---

## A3. CSRF

Periksa:

- semua POST/PATCH/PUT/DELETE web forms;
- AJAX/fetch POST;
- logout;
- security actions;
- password change;
- 2FA;
- upload;
- quotation;
- claims;
- exports;
- notifications.

Pastikan request AJAX mengirim token sesuai mekanisme Laravel.

### Pass condition

Tidak ada mutating web endpoint yang bypass CSRF tanpa alasan resmi.

---

## A4. Secrets

Scan:

```bash
git grep -nEi \
'AKIA[0-9A-Z]{16}|BEGIN (RSA|OPENSSH|EC|PGP) PRIVATE KEY|api[_-]?key|secret|password|token|bearer'
```

Kemudian review false positives satu per satu.

Audit khusus:

- `.env.example`
- JS bundles/source
- Blade
- config
- test fixtures
- seeders
- deployment ZIP
- patch files
- SQL snapshots
- docs/screenshots

### Critical rule

Jangan hanya memeriksa current files.

Audit juga:

```bash
git log --all --full-history -- .env
git log -S"sk-" --all
git log -S"api_key" --all
```

karena secret yang pernah committed tetap menjadi exposure.

---

## A5. Route authorization

Inventory:

```bash
php artisan route:list --except-vendor
```

Untuk setiap route:

```text
HTTP verb
URI
controller
middleware
policy
role
ownership check
```

Minimum pattern:

```text
auth
→ role
→ ownership/policy
→ validation
→ mutation
```

### High-risk routes

- `/attachments/{id}`
- `/exports/{exportJob}/status`
- `/exports/{exportJob}/cancel`
- `/exports/{exportJob}/download`
- shared PDF
- supplier quotation
- purchase order
- claims
- conversations
- session revoke
- 2FA actions
- user management
- exchange rates
- auth audit logs

### Supplier isolation

Mandatory invariants:

```text
Supplier A MUST NOT
  view
  download
  mutate
  export
  infer
  enumerate
Supplier B data
```

Gunakan pola yang sudah ditetapkan repository:

```php
->where('supplier_id', auth()->id())
```

atau scope/policy khusus bila model memang tidak memiliki `supplier_id`.

---

# 8. Phase 4 — Authorization/IDOR Deep Dive

Fokus pada:

```text
Quotation
PurchaseOrder
MaterialClaim
PurchaseRequisition
Conversation
Attachment
ExportJob
QcInspection
```

Untuk setiap controller method:

1. apakah route param cukup untuk mengambil record?
2. apakah record langsung `findOrFail()` lalu digunakan?
3. apakah policy dipanggil?
4. apakah ownership scope diterapkan?
5. apakah related object dapat dipakai untuk melompati ownership?

### Test matrix

Untuk setiap owner-bound model:

| Actor | Own data | Other supplier data |
|---|---:|---:|
| Supplier A | 200/OK | 403/404 |
| Supplier B | 200/OK | 403/404 |
| Purchasing | sesuai policy | sesuai policy |
| QC | sesuai policy | sesuai policy |
| Admin | sesuai policy |

Jangan hanya test “view”.

Test juga:

```text
download
export
cancel
submit
accept
reject
request revision
upload
delete
respond
mark read
quick action
```

---

# 9. Phase 5 — File Upload & Storage Security

Target:

- `AttachmentController`
- quotation MTC upload
- QC evidence
- claim attachments
- conversation attachments
- storage configuration
- attachment model/policy

Current architecture menggunakan polymorphic `attachments`.

Audit:

### H1. MIME validation

Jangan mengandalkan:

```text
extension only
```

Periksa:

- actual MIME;
- extension;
- file signature;
- size;
- filename;
- storage path.

### H2. Filename safety

Pastikan:

```text
basename()
path normalization
CRLF stripping
path traversal protection
null byte handling
```

### H3. Public exposure

Pastikan file sensitif berada di:

```text
storage/app/private
```

bukan:

```text
public/
```

### H4. Access control

Attachment download harus melalui policy/ownership.

### H5. Active content

Review:

- SVG;
- HTML;
- JS;
- polyglot files;
- executable content.

Jangan mengizinkan file yang tidak benar-benar dibutuhkan oleh workflow.

---

# 10. Phase 6 — Business Logic Audit

Ini merupakan fase paling penting setelah security.

## B1. Requirement traceability

Untuk setiap perubahan:

```text
Requirement
→ UI
→ Route
→ Controller
→ Service/Model
→ DB
→ Notification/Event
→ Test
```

Jangan menerima implementation yang hanya “kelihatan benar dari UI”.

---

## B2. Quotation workflow

Verifikasi state machine:

```text
draft
submitted
revision_requested
accepted
rejected
```

Audit:

- state transition valid;
- transition ilegal ditolak;
- submitted quotation tidak dapat diedit;
- revision_requested dapat diedit;
- rejected semantics jelas;
- accepted quotation tidak dapat dimutasi sembarangan;
- action endpoint tidak hanya mengandalkan hidden button di UI.

---

## B3. Offer availability

Fokus pada perubahan terbaru:

```text
is_available
price_per_kg nullable
available dimensions
offered_weight_per_unit
offered_weight_source
```

Verifikasi invariant:

```text
Unavailable
→ price boleh null
→ offer numeric fields tidak disimpan sebagai data valid
→ UI menampilkan unavailable
```

dan:

```text
Available
→ price valid
→ availability data konsisten
→ amount dihitung server-side
```

Jangan percaya nilai `amount` dari frontend.

---

## B4. Calculations

Verifikasi:

- rounding;
- decimal precision;
- currency conversion;
- quantity multiplication;
- total weight;
- nullability;
- zero;
- negative;
- very large number;
- floating point behavior.

Untuk setiap formula, dokumentasikan:

```text
Input
Formula
Precision
Rounding mode
Expected result
Boundary cases
```

---

## B5. Concurrent requests

Audit operasi:

```text
submit quotation
accept quotation
reject quotation
request revision
create PO
confirm arrival
resolve claim
generate document number
create export
cancel export
```

Pertanyaan wajib:

> Apa yang terjadi bila dua user mengirim request yang sama dalam waktu hampir bersamaan?

Cari:

```text
check then act
```

contoh berbahaya:

```php
if (!$record->exists()) {
    create();
}
```

tanpa:

```text
unique index
transaction
lock
upsert
```

---

# 11. Phase 7 — Hallucinated API / Non-existent Method Audit

Karena repository dibuat dengan vibecoding, lakukan explicit hallucination pass.

Cari seluruh call chain:

```text
Controller → Model → Service → Support → Job → Import/Export
```

Untuk setiap method call non-framework:

- method benar-benar ada?
- signature benar?
- return type sesuai?
- property benar-benar ada?
- relationship benar-benar ada?
- scope benar-benar ada?
- route name benar?
- view variable benar?
- event/job class benar?
- interface implemented?
- helper imported benar?

### High-value checks

- Eloquent relation names;
- `scopeVisibleToSupplier`;
- `isVisibleToSupplier`;
- `canBeRevisedBySupplier`;
- `latestRate`;
- export progress APIs;
- queue chain methods;
- attachment relations;
- policy mappings.

### Acceptance Criteria

Tidak ada:

- undefined method;
- undefined relation;
- wrong parameter order;
- wrong route name;
- wrong model property;
- wrong constructor dependency;

yang hanya kebetulan belum dieksekusi oleh test.

---

# 12. Phase 8 — Code Quality & Maintainability

Periksa:

- duplikasi validation;
- duplikasi authorization;
- giant controller;
- helper yang dipakai lintas domain;
- dead imports;
- dead methods;
- dead views;
- obsolete backup files;
- copied logic;
- commented-out production code;
- duplicated routes;
- duplicated business formula.

### Jangan over-refactor

Tujuan audit bukan menjadikan semua code “clean architecture”.

Refactor hanya direkomendasikan bila:

- bug risk tinggi;
- authorization duplication menciptakan security risk;
- business formula tersebar dan tidak konsisten;
- concurrency protection sulit dipastikan;
- testability sangat buruk.

---

# 13. Phase 9 — Performance & Query Audit

## D1. N+1

Audit controller dan Blade untuk:

```text
foreach
  relation access
```

Contoh:

```php
foreach ($periods as $period) {
    $period->purchaseRequisitions...
}
```

Pastikan data dapat di-eager-load atau dibatch.

### Profiling

Jalankan targeted request dengan query listener / Telescope-equivalent bila tersedia.

Bila belum ada profiler:

```php
DB::listen(...)
```

sementara pada test/diagnostic environment.

---

## D2. Query count

Untuk endpoint kritis:

```text
Supplier quotation list
Supplier quotation period
Quotation show
Purchasing quotation list
Purchase order show
Price comparison
Dashboard
Export preparation
```

Catat:

```text
request
rows
query count
query time
memory
response size
```

---

## D3. Large-table query

Audit index pada:

```text
quotations
quotation_items
purchase_requisitions
purchase_orders
pr_items
qc_inspections
material_claims
attachments
messages
notifications
export_jobs
auth_audit_logs
```

Gunakan:

```sql
EXPLAIN
EXPLAIN ANALYZE
```

untuk query terpenting.

### Red flags

- full table scan pada table besar;
- wildcard search `%term%`;
- ordering tanpa index;
- filtering kolom tidak terindex;
- `whereHas` berulang;
- `pluck()` dalam loop;
- query per row;
- loading unnecessary relation columns.

---

# 14. Phase 10 — Export / Queue Reliability Audit

Target:

- `ExportDispatcher`
- `ProcessExportJob`
- `FinalizeExportJob`
- `MarkExportFailed`
- export classes
- `ExportProgressService`
- `ExportDownloadController`

Audit:

### Queue handoff

Verifikasi atomicity:

```text
export_jobs row
+
queue job
```

harus konsisten.

Tidak boleh:

```text
row exists, job missing
```

atau:

```text
job exists, row missing
```

### Retry

Uji:

```text
first attempt fails
second attempt succeeds
all attempts fail
worker dies mid-job
DB timeout
storage failure
file write failure
```

### Idempotency

Job re-run tidak boleh menyebabkan:

- duplicate data;
- corrupted file;
- stale progress;
- impossible status;
- multiple finalization.

### Cancellation

Periksa race:

```text
User cancels
vs
worker starts
vs
worker finishes
```

State machine harus deterministic.

---

# 15. Phase 11 — Database & Migration Audit

Inventory seluruh migration.

Untuk setiap migration:

- `up()`;
- `down()`;
- nullable changes;
- data migration;
- index changes;
- foreign key;
- cascade;
- unique;
- compatibility with existing production data.

### Reversibility

Jalankan pada disposable DB:

```bash
php artisan migrate:fresh
php artisan migrate
php artisan migrate:rollback
```

Untuk migration kritis:

```text
forward migration
→ seed representative data
→ rollback
→ verify schema/data
```

### Critical latest migration

`database/migrations/2026_08_28_000002_add_offer_fields_to_quotation_items_table.php`

Periksa khusus:

- `price_per_kg` berubah dari NOT NULL menjadi nullable;
- `down()` menolak rollback jika ada null;
- MySQL/MariaDB compatibility;
- existing data compatibility;
- index impact;
- migration deployment order.

### Data migration policy

Jangan menganggap “down() berjalan” berarti migration benar.

Periksa apakah rollback:

- aman;
- data-loss aware;
- reversible secara bisnis;
- dapat dijalankan setelah production traffic.

---

# 16. Phase 12 — Session & Auth Audit

Target:

```text
routes/auth.php
app/Http/Controllers/Auth/*
app/Http/Middleware/*
config/auth.php
config/session.php
.env.example
```

Verifikasi:

### Login

- rate limiting;
- credential validation;
- inactive user rejection;
- generic error;
- session regeneration;
- audit logging;
- MFA challenge.

### Password reset

- enumeration resistance;
- rate limit;
- token expiry;
- single-use token;
- password hashing;
- session invalidation.

### Multi-device session

- maximum active session policy;
- revoke own session only;
- logout-other-devices;
- stale session handling;
- session row authorization.

### 2FA

- setup protected;
- QR secret protected;
- confirmation throttled;
- recovery codes protected;
- disable requires strong re-auth;
- recovery code handling is not logged.

---

# 17. Phase 13 — Error Handling & Recoverability

Cari:

```bash
grep -RniE "catch\s*\(|report\(|abort\(|throw new|dd\(|dump\(|var_dump\(" app routes
```

Untuk setiap exception handler:

- apakah error diserap diam-diam?
- apakah user mendapat message yang aman?
- apakah stack trace bocor?
- apakah error cukup dilog?
- apakah operation transaction rollback?
- apakah retry dilakukan pada layer yang tepat?

### User-facing errors

Production response tidak boleh membocorkan:

```text
SQL query
filesystem path
stack trace
credentials
internal host
secret
debug context
```

---

# 18. Phase 14 — Third-Party Integration Audit

Integrasi utama:

```text
Pusher
SMTP
Turnstile
Laravel Reverb/Echo
Storage
Excel/PDF libraries
```

Untuk masing-masing:

### Timeout

Pastikan request tidak infinite.

### Retry

Retry hanya untuk failure yang memang transient.

### Backoff

Gunakan bounded backoff.

### Circuit breaker/fallback

Untuk provider yang tidak wajib real-time:

```text
Pusher down
→ polling fallback
```

Untuk provider mandatory:

```text
fail fast
→ user-safe response
→ log/alert
```

### Secrets

Tidak boleh masuk JS bundle.

### Contract

Dokumentasikan:

```text
request
response
auth
timeout
retry
rate limit
failure mode
fallback
```

---

# 19. Phase 15 — Accessibility, SEO, Usability

Walaupun bukan prioritas utama, tetap audit:

### Accessibility

- label input;
- aria state;
- keyboard navigation;
- focus management;
- modal accessibility;
- image alt;
- disabled state;
- error message association.

### Public pages

- title;
- description;
- canonical bila diperlukan;
- no sensitive data in HTML.

### Responsive

Test:

```text
320px
375px
768px
1024px
1440px
```

dan browser representative.

---

# 20. Phase 16 — Testing Audit

Jangan hanya mengecek apakah test suite exists.

## Test quality

Untuk setiap test:

- apa behavior yang diverifikasi?
- apakah assertion meaningful?
- apakah test dapat false-positive?
- apakah fixture realistis?
- apakah test menguji authorization?
- apakah test menguji failure path?

## Critical test suites

Existing repository already has tests around:

- supplier data isolation;
- hashid security;
- auth rate limiting;
- quotation availability;
- price comparison performance;
- calendar;
- import;
- material automation;
- shell/sidebar.

Pertahankan dan perluas coverage pada perubahan kritis.

### Minimum security matrix

```text
unauthenticated → protected route
wrong role → role route
supplier A → supplier B resource
supplier B → supplier A resource
tampered hash
integer instead of hash
deleted/soft-deleted resource
missing related record
```

### Business matrix

```text
draft
submitted
revision_requested
accepted
rejected
unavailable item
available item
null price
zero price
negative price
duplicate item
missing item
extra item
concurrent submit
```

### Upload matrix

```text
valid PDF
valid JPG
valid PNG
wrong extension
wrong MIME
oversized file
double extension
path traversal filename
null byte
SVG/HTML
```

---

# 21. Phase 17 — Dependency / Supply Chain Audit

## Composer

Run:

```bash
composer validate --strict
composer audit
composer outdated --direct
```

Review:

- known CVE;
- abandoned package;
- package license;
- wildcard dependency.

### Important repository observation

`composer.json` menggunakan beberapa requirement yang terlalu longgar seperti:

```text
barryvdh/laravel-dompdf = *
laravel/reverb = *
maatwebsite/excel = *
vinkla/hashids = *
```

Karena `composer.lock` tersedia, install reproducibility saat ini dibantu lockfile. Namun audit harus menentukan apakah broad constraints terlalu longgar untuk maintenance jangka panjang.

Keputusan yang disarankan:

- jangan upgrade otomatis saat remediation audit;
- terlebih dahulu audit versi lock;
- pin/range-kan direct dependency secara sadar;
- pastikan lockfile di-update hanya setelah verification.

## NPM

Run:

```bash
npm ci
npm audit
npm audit --audit-level=high
npm run build
```

Review:

- direct dependency;
- transitive vulnerability;
- dependency mismatch;
- unused dependency;
- frontend package accidentally exposing secrets.

---

# 22. Phase 18 — Deployment & Runtime Audit

Target:

```text
.cp​anel.yml
Dockerfile
.env.example
deployment/*
docs/guides/*
```

## Production configuration

Verify:

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY stable
session secure
HTTP only
same-site
queue configured
cache configured
mail configured
storage private
trusted proxies explicit
```

## cPanel

Periksa:

- document root;
- storage symlink;
- queue worker;
- cron;
- writable directories;
- vendor lifecycle;
- build lifecycle;
- cache clear;
- migration order.

## Container

Periksa:

- non-root user;
- image base;
- PHP extensions;
- OS packages;
- healthcheck;
- runtime command;
- process supervisor;
- signal handling.

---

# 23. Phase 19 — Scalability & NFR Audit

Kategori E tidak boleh diberi status final berdasarkan asumsi silent.

## Mandatory assumptions section

Sebelum memberi verdict scalability, catat:

```text
Concurrent users:
Requests/sec:
Peak requests/sec:
Read/write ratio:
Largest PR:
Largest quotation:
Largest export:
Largest file upload:
Expected DB rows:
Worker count:
CPU:
Memory:
Storage:
Network:
```

### Provisional evaluation model

Sampai angka real diberikan, gunakan tiga skenario:

#### Scenario S1 — Normal

```text
20 concurrent users
1–2 req/s aggregate
small/medium datasets
```

#### Scenario S2 — Busy

```text
50 concurrent users
5 req/s aggregate
larger lists
multiple exports
```

#### Scenario S3 — Stress

```text
100+ concurrent users
10+ req/s aggregate
large export
concurrent quotation/PO activity
```

**Catatan:** angka di atas adalah test assumptions, bukan klaim traffic production.

### Metrics

Ukur:

```text
p50 latency
p95 latency
p99 latency
error rate
DB query count
DB query latency
CPU
memory
queue wait time
job execution time
storage throughput
```

### Availability

Audit:

- DB single point of failure;
- app single instance;
- queue single worker;
- local file storage;
- Pusher dependency;
- SMTP dependency;
- deployment interruption.

Untuk setiap SPOF:

```text
criticality
failure mode
current mitigation
recovery method
RTO
RPO
```

### Elasticity

Jika runtime cPanel/shared hosting:

- autoscaling mungkin TIDAK tersedia.

Status jangan dipaksa menjadi PASS.

Gunakan:

`NEED-REVIEW — architecture does not provide cloud elasticity; capacity policy must be explicitly accepted.`

---

# 24. Phase 20 — Backup & Disaster Recovery

Audit:

```text
DB backup
file storage backup
environment config backup
migration recovery
rollback
deployment artifact retention
```

Minimum operational runbook:

```text
1. Backup DB
2. Put app in maintenance if needed
3. Deploy
4. Run migration
5. Run smoke test
6. Monitor
7. Rollback app if needed
8. Rollback DB only when migration is safely reversible
```

### Important distinction

App rollback != DB rollback.

Jangan merollback application code ke versi lama jika schema baru tidak backward-compatible.

---

# 25. Phase 21 — PDP / Legal / Compliance

Data yang harus dipetakan:

```text
name
email
phone
company
NPWP
audit logs
login history
IP address
session metadata
uploaded documents
supplier commercial data
quotation prices
purchase order
claim data
```

Tentukan:

```text
data owner
purpose
retention
access role
storage location
deletion policy
backup retention
```

### UU PDP

Audit tidak menggantikan legal review.

Status dapat:

`NEED-REVIEW — technical controls inspected; legal basis/retention/processing purpose requires business/legal confirmation.`

---

# 26. Phase 22 — Observability

Minimum production observability:

```text
application logs
auth audit logs
queue failed jobs
exception tracking
health endpoint
deployment logs
DB backup status
```

Periksa `/up` dan health behavior.

### Logging rule

Jangan log:

```text
password
2FA secret
recovery code
full token
session cookie
API secret
uploaded sensitive payload
```

Audit `RoleMiddleware` khususnya:

- apakah `fullUrl()` berpotensi memasukkan sensitive query params;
- apakah severity `error` terlalu tinggi untuk normal authorization denial;
- apakah logging menyebabkan noise/cost;
- apakah log schema cukup untuk incident response.

---

# 27. Phase 23 — README & Documentation Audit

README minimal harus menjawab:

```text
What is the system?
Requirements
Setup
Environment variables
Database setup
Migrations
Storage
Queue
Frontend build
Testing
Production deployment
Rollback
Troubleshooting
```

Jangan menerima README yang hanya berupa hasil scaffolding Laravel.

Dokumentasi audit yang dihasilkan harus berada di:

```text
docs/audits/
```

Bukan root repository, kecuali dokumen memang entrypoint.

---

# 28. Phase 24 — Evidence Collection

Setiap temuan wajib memiliki:

```text
ID
Severity
Category
Status
File:line
Evidence
Impact
Root cause
Recommendation
Verification method
Owner suggestion
Blocking/not blocking
```

Format:

```text
SEC-001
Severity: CRITICAL
Category: Security
Status: ISSUE
Location: app/Http/Controllers/...
Evidence: ...
Impact: ...
Recommendation: ...
Verification: ...
Merge gate: BLOCK
```

---

# 29. Status Semantics

## OK

Gunakan hanya bila evidence cukup.

Contoh:

```text
OK
routes/auth.php
All mutating auth routes are covered by auth/throttle middleware and behavior is covered by feature tests.
```

## ISSUE

Gunakan bila ada defect yang jelas dan evidence tersedia.

## NEED-REVIEW

Gunakan bila:

- requirement belum dikonfirmasi;
- production traffic unknown;
- external contract belum tersedia;
- legal decision diperlukan;
- runtime verification belum dapat dilakukan;
- repository evidence tidak cukup.

Jangan menggunakan `OK` untuk sesuatu yang sebenarnya hanya “terlihat benar”.

---

# 30. Severity

## CRITICAL

Dapat menyebabkan:

- account takeover;
- supplier data leak;
- unauthorized mutation;
- secret exposure;
- irrecoverable data corruption;
- production-wide outage;
- severe business transaction corruption.

**Wajib diperbaiki sebelum merge/deploy.**

## HIGH

Serious security, business, or reliability issue dengan realistic exploitation/failure path.

**Wajib diperbaiki sebelum production.**

## MEDIUM

Tidak ideal dan memiliki meaningful risk, tetapi blast radius terbatas.

## LOW

Quality / maintainability / UX issue tanpa material production risk.

## INFO

Observation / improvement.

---

# 31. Top 3 Merge/Deploy Gate

Setelah seluruh kategori A–Q selesai, pilih tepat tiga issue terpenting.

Ranking formula:

```text
Risk = Impact × Likelihood × Exposure × Recoverability
```

Prioritas default:

1. Security / authorization / data isolation
2. Business logic / data integrity
3. Reliability / availability / recovery
4. Performance
5. Maintainability
6. UX/documentation

Untuk setiap Top 3:

```text
Why blocking
Attack/failure scenario
Affected users
Affected data
Exact file:line
Fix layer
Required test
Release verification
```

---

# 32. Specific Areas That Must Receive Extra Scrutiny

## 32.1 Supplier Data Isolation

Files:

```text
app/Http/Controllers/Supplier/*
app/Models/PurchaseRequisition.php
app/Models/Quotation.php
app/Models/PurchaseOrder.php
app/Models/MaterialClaim.php
app/Policies/*
tests/Feature/SupplierDataIsolationTest.php
```

Tidak boleh ada bypass melalui:

- direct `find()`;
- export;
- attachment;
- PDF;
- relation traversal;
- notification link;
- conversation;
- AJAX endpoint.

---

## 32.2 Attachment Authorization

Files:

```text
app/Http/Controllers/AttachmentController.php
app/Policies/AttachmentPolicy.php
app/Models/Attachment.php
resources/views/components/ui/image-lightbox.blade.php
```

Audit chain:

```text
URL
→ Attachment::find
→ policy
→ attachable
→ owner
→ private disk
→ response
```

Test semua polymorphic attachable types.

---

## 32.3 Export Isolation

Files:

```text
app/Support/ExportDispatcher.php
app/Jobs/ProcessExportJob.php
app/Http/Controllers/ExportDownloadController.php
app/Models/ExportJob.php
app/Exports/*
tests/*Export*
```

Mandatory invariant:

```text
Supplier A can only inspect/download/cancel jobs created for Supplier A.
```

Jangan percaya hanya pada `export_job_id`.

---

## 32.4 Quotation Import

Files:

```text
app/Imports/QuotationItemsImport.php
app/Http/Controllers/Supplier/QuotationController.php
app/Support/SpreadsheetImportReader.php
```

Review:

- row count;
- malformed row;
- duplicate row;
- missing PR item;
- unexpected PR item;
- numeric overflow;
- range parser;
- formula injection;
- encoded payload;
- spreadsheet parser error;
- upload size;
- memory exhaustion.

---

## 32.5 Authentication Hardening

Files:

```text
routes/auth.php
bootstrap/app.php
app/Http/Controllers/Auth/*
app/Http/Middleware/*
tests/Feature/Auth/*
```

Do not assume because the code is called “hardening” that it is secure. Treat every control as independently testable.

---

## 32.6 Latest Offer Migration

File:

```text
database/migrations/2026_08_28_000002_add_offer_fields_to_quotation_items_table.php
```

Test:

```text
fresh install
forward migration
legacy data
rollback with null prices
rollback without null prices
MySQL
MariaDB if supported
concurrent write
```

---

# 33. Verification Command Matrix

## Static / syntax

```bash
php -l <file>
php artisan route:list
composer validate --strict
git diff --check
```

## Formatting

```bash
vendor/bin/pint --test
```

## PHP tests

```bash
php artisan test
```

Targeted first:

```bash
php artisan test tests/Feature/SupplierDataIsolationTest.php
php artisan test tests/Feature/Auth
php artisan test tests/Feature/QuotationAvailabilityTest.php
php artisan test tests/Feature/HashidUrlSecurityTest.php
```

## JS/build

```bash
npm ci
npm run build
```

## Dependencies

```bash
composer audit
npm audit --audit-level=high
```

## Migration

Pada disposable DB:

```bash
php artisan migrate:fresh
php artisan migrate
php artisan migrate:rollback
```

## Production-like checks

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:failed
php artisan about
```

Jangan menjalankan command yang dapat mengubah production DB tanpa explicit approval dan backup.

---

# 34. Manual Security Test Matrix

## Authentication

```text
wrong password
inactive user
wrong MFA
expired reset token
reused reset token
session revoke other user
logout-other-devices
CSRF missing
CSRF replay
```

## Authorization

```text
Supplier A → own resource
Supplier A → Supplier B resource
Supplier A → Purchasing resource
Purchasing → Admin resource
QC → Supplier-only mutation
Admin → restricted security endpoint
```

## Hashids

```text
valid hash
invalid hash
raw integer
truncated hash
foreign hash
soft-deleted record
```

## File

```text
valid file
oversized
wrong MIME
wrong extension
path traversal
double extension
HTML
SVG
null byte
```

## Concurrency

```text
double submit
double accept
double reject
cancel while processing
download while finalizing
two workers on same export
```

---

# 35. Non-Functional Requirement Confirmation Checklist

Sebelum final verdict E:

Konfirmasi ke tim:

- [ ] concurrent active users
- [ ] peak RPS
- [ ] peak login attempts
- [ ] maximum export rows
- [ ] average export rows
- [ ] average quotation size
- [ ] largest expected attachment
- [ ] DB size today
- [ ] DB growth/month
- [ ] acceptable p95 latency
- [ ] acceptable export completion time
- [ ] RTO
- [ ] RPO
- [ ] backup frequency
- [ ] maintenance window
- [ ] maximum acceptable downtime
- [ ] worker capacity
- [ ] storage quota
- [ ] expected supplier count

### Rule

Tanpa data tersebut:

- Scalability = `NEED-REVIEW`
- Elasticity = `NEED-REVIEW` bila tidak ada autoscaling
- Availability = provisional
- Capacity planning = provisional

Jangan menilai NFR sebagai PASS berdasarkan “aplikasi kecil”.

---

# 36. Final Audit Output Structure

Final report harus persis memiliki struktur:

## 1. Executive Summary

```text
Audit target
Commit / PR
Overall verdict
Blocking issues
Known limitations
```

## 2. Status per Category A–Q

| Category | Status | Critical | High | Medium | Low | Notes |
|---|---|---:|---:|---:|---:|---|
| A Security | ... | ... | ... | ... | ... | ... |
| B Business Logic | ... | ... | ... | ... | ... | ... |
| C Maintainability | ... | ... | ... | ... | ... | ... |
| D Performance | ... | ... | ... | ... | ... | ... |
| E NFR | ... | ... | ... | ... | ... | ... |
| F Error Handling | ... | ... | ... | ... | ... | ... |
| G Testing | ... | ... | ... | ... | ... | ... |
| H Upload | ... | ... | ... | ... | ... | ... |
| I Third Party | ... | ... | ... | ... | ... | ... |
| J Database | ... | ... | ... | ... | ... | ... |
| K Accessibility/SEO/Usability | ... | ... | ... | ... | ... | ... |
| L Rate Limit/Session | ... | ... | ... | ... | ... | ... |
| M Observability | ... | ... | ... | ... | ... | ... |
| N Backup/DR | ... | ... | ... | ... | ... | ... |
| O Legal/PDP | ... | ... | ... | ... | ... | ... |
| P Documentation | ... | ... | ... | ... | ... | ... |
| Q Cost | ... | ... | ... | ... | ... | ... |

## 3. All Issues

Sort:

```text
CRITICAL
HIGH
MEDIUM
LOW
INFO
```

Each issue:

```text
ID
Severity
Category
Status
Location
Evidence
Impact
Root Cause
Recommendation
Verification
Merge/Deploy Gate
```

## 4. Top 3 Blocking Issues

Exactly three.

## 5. NFR Assumptions

Explicit numbers and which are:

```text
confirmed
assumed
unknown
```

## 6. Verification Performed

Jangan mengklaim command/test yang tidak benar-benar dijalankan.

## 7. Not Verified

Contoh:

```text
production SMTP behavior
real Pusher outage
real traffic profile
real production DB size
backup restore drill
external pentest
```

## 8. Final Gate

Gunakan salah satu:

```text
✅ PASS — Ready for merge/deploy
🟡 CONDITIONAL PASS — Merge allowed, production deploy blocked pending items
🔴 BLOCK — Do not merge/deploy
```

---

# 37. Definition of Done

Audit dianggap selesai hanya bila:

### Scope

- [ ] seluruh diff dibaca;
- [ ] seluruh changed file diinventarisasi;
- [ ] security-sensitive file diperiksa;
- [ ] business-critical file diperiksa;
- [ ] migration diperiksa;
- [ ] dependency diperiksa;
- [ ] deployment artifact diperiksa.

### Security

- [ ] auth;
- [ ] RBAC;
- [ ] IDOR;
- [ ] supplier isolation;
- [ ] CSRF;
- [ ] SQL injection;
- [ ] secrets;
- [ ] upload;
- [ ] XSS;
- [ ] rate limiting;
- [ ] session security.

### Business

- [ ] quotation state machine;
- [ ] offer availability;
- [ ] amount calculation;
- [ ] currency;
- [ ] PO flow;
- [ ] QC;
- [ ] claim;
- [ ] notification;
- [ ] export.

### Reliability

- [ ] transaction;
- [ ] concurrency;
- [ ] queue;
- [ ] retries;
- [ ] failure handling;
- [ ] recoverability;
- [ ] rollback.

### Performance

- [ ] N+1;
- [ ] index;
- [ ] query shape;
- [ ] pagination;
- [ ] export memory;
- [ ] queue capacity.

### Verification

- [ ] full test suite attempted;
- [ ] critical targeted tests run;
- [ ] build run;
- [ ] composer audit run;
- [ ] npm audit run;
- [ ] migration validation run where safe;
- [ ] manual security checks documented.

---

# 38. Recommended Execution Order

Jangan menjalankan A–Q secara acak.

Urutan wajib:

```text
0. Initialization
↓
1. Read full diff
↓
2. Build architecture map
↓
3. Security
↓
4. Authorization / supplier isolation
↓
5. Business logic
↓
6. DB / migration / concurrency
↓
7. Reliability / queue / recovery
↓
8. File upload
↓
9. Third-party integration
↓
10. Performance
↓
11. Testing quality
↓
12. Deployment / observability / backup
↓
13. Accessibility / usability / documentation
↓
14. Cost / legal
↓
15. Re-run targeted verification
↓
16. Rank all findings
↓
17. Produce Top 3 blockers
↓
18. Final merge/deploy gate
```

---

# 39. Important Rule About Remediation

Pada audit pertama:

**JANGAN langsung memperbaiki code.**

Output hanya:

```text
Observation
Evidence
Impact
Recommendation
Verification
Priority
```

Setelah audit report disetujui, remediation dapat dibuat pada phase terpisah:

```text
Audit
→ Prioritize
→ Remediation Plan
→ Implement
→ Test
→ Re-audit
→ Merge
→ Deploy
```

Jangan mencampur audit dan remediation karena:

- issue dapat tertutup tanpa evidence awal;
- diff audit menjadi tidak stabil;
- root cause dapat hilang;
- reviewer sulit mengetahui perubahan awal;
- regression risk meningkat.

---

# 40. Final Instruction for the Implementing Agent

Gunakan plan ini sebagai **read-only production readiness audit**.

Agent yang menjalankan audit:

1. Baca seluruh instruction project.
2. Identifikasi base/head yang benar.
3. Baca seluruh diff sebelum menyimpulkan.
4. Audit Security dan Business Logic terlebih dahulu.
5. Audit Reliability/Data Integrity setelahnya.
6. Jangan menyebut sesuatu `OK` tanpa evidence.
7. Gunakan `NEED-REVIEW` ketika konteks production belum cukup.
8. Jangan mengubah code.
9. Jangan membuat migration baru.
10. Jangan mengubah dependency.
11. Jangan memperbaiki test supaya “hijau”.
12. Jangan menghapus failing test untuk membuat suite lolos.
13. Jangan mengubah deployment config saat audit.
14. Catat setiap ISSUE dengan file:line.
15. Prioritaskan exploitability, business impact, data exposure, dan recoverability.
16. Akhiri dengan Top 3 blocking issues dan final merge/deploy gate.

## Expected final artifact

```text
docs/audits/VIBECODE-PRODUCTION-READINESS-AUDIT-<YYYYMMDD>.md
```

Isi final report harus dapat digunakan reviewer manusia untuk mengambil keputusan:

```text
MERGE
MERGE WITH CONDITIONS
atau
DO NOT MERGE / DO NOT DEPLOY
```
