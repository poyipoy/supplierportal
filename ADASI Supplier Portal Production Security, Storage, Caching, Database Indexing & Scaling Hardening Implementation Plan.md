# ADASI Supplier Portal

## Production Security, Storage, Caching, Database Indexing & Scaling Hardening Implementation Plan

Repository:

`https://github.com/poyipoy/supplierportal`

Local project:

`C:\laragon\www\adasi_portal_supplier`

Current audited HEAD:

`45ba4888fc9b974833b2c3a45e69661afd3811c9`

Framework:

Laravel 12 / PHP 8.2 / MySQL-MariaDB compatible

Audit mode:

Read-only repository analysis followed by implementation planning.

Browser QA is excluded.

---

# 1. Executive Assessment

| Area                         | Current Assessment                                                 | Direction                                                                                      |
| ---------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| Object Storage               | PARTIAL                                                            | S3-compatible storage is configured but not active; private files remain local                 |
| CDN                          | PARTIAL                                                            | Origin-side static caching exists; no CDN deployment/integration                               |
| Caching                      | PARTIAL                                                            | Database cache is established and some application caching exists, but scalability is limited  |
| Database Indexing            | PARTIAL / GOOD BASELINE                                            | Previous evidence-based tuning exists; another growth-focused query audit is required          |
| Scaling                      | PARTIAL                                                            | Architecture is suitable for a single-node cPanel deployment, not yet horizontally scalable    |
| Input Validation             | GENERALLY GOOD, NOT COMPLETE                                       | Strong validation exists, but endpoint-wide validation/authorization matrix is still required  |
| Authentication               | STRONG BASELINE                                                    | Session, MFA, throttling, timeout, revocation and secure cookies are already implemented       |
| Authorization                | STRONG BASELINE WITH AUDIT REQUIRED                                | Core 2 policies are good; every state-changing/object endpoint still needs systematic coverage |
| Credential Exposure          | NO CONFIRMED SECRET LEAK FOUND IN REVIEWED SOURCE                  | Hard-coded financial defaults/config values should still be removed from code defaults         |
| API / JSON Endpoint Exposure | NO CONFIRMED UNPROTECTED BUSINESS JSON ENDPOINT IN REVIEWED ROUTES | Complete route-to-controller authorization matrix still required                               |

There is currently no evidence supporting a Critical unauthenticated RCE, universal authentication bypass, or direct database exposure.

However, “no confirmed critical vulnerability” is not equivalent to “fully secure”.

The remaining work should focus on eliminating security gaps at trust boundaries and making the application infrastructure-ready for higher traffic.

---

# 2. Evidence Already Present in the Repository

## 2.1 Authentication

The authentication implementation already contains several meaningful controls:

* session-based web authentication;
* MFA/TOTP support;
* MFA pending state;
* password confirmation for sensitive actions;
* session versioning;
* absolute session timeout;
* active-account enforcement;
* concurrent session management;
* login/password-reset throttling;
* secure session cookies;
* encrypted session storage;
* known-device functionality;
* authentication audit logging.

`EnforceAuthSessionSecurity` explicitly terminates sessions for deactivated users.

Therefore the previous concern that `RoleMiddleware` itself does not check `is_active` is not sufficient to classify inactive-session access as vulnerable, because `EnforceAuthSessionSecurity` runs in the web middleware stack and performs that check.

---

# 3. Authorization Assessment

## 3.1 Core 2

Core 2 uses Laravel Policies and `Gate::authorize()`.

Examples already verified:

* `LocalInvoicePolicy`
* `LocalInvoiceDocumentPolicy`
* `GaClaimDocumentPolicy`
* `SupplierMasterDocumentPolicy`
* attachment authorization
* export ownership
* supplier ownership checks

Examples:

`FinanceInvoiceController`

uses:

`Gate::authorize('view', $invoice)`

Document controllers similarly authorize the document before accessing its private file.

This is the correct architectural direction.

---

## 3.2 Supplier Isolation

The repository explicitly treats supplier ownership as a security boundary:

`supplier_id = auth()->id()`

and Core 2 policies verify supplier ownership.

This should remain non-negotiable.

The following must continue to be protected:

* quotations;
* purchase orders;
* material claims;
* local invoices;
* local purchase orders;
* supplier master documents;
* shipments;
* shipment documents;
* conversations;
* refunds;
* attachments;
* exports.

Hashids are useful against trivial identifier enumeration but must never replace authorization.

---

## 3.3 Important Observation About FormRequest `authorize()`

Several FormRequests currently contain:

```php
public function authorize(): bool
{
    return true;
}
```

Examples include:

* `SavePrItemRequest`
* `SavePurchaseRequisitionRequest`
* `SaveHsCodeRuleRequest`
* `SaveMaterialMasterRequest`
* `MaterialCalculationRequest`

This is not automatically a vulnerability.

For example, `PrItemController` subsequently verifies:

```text
PR.created_by === authenticated user
```

before modifying the requisition.

Therefore the current implementation has authorization outside the FormRequest.

However, this creates an architectural risk:

> A future controller can accidentally reuse the FormRequest without reproducing the required authorization check.

The implementation plan should therefore improve defense-in-depth without mechanically converting every FormRequest.

---

# 4. Input Validation Assessment

Current input validation is materially better than a basic Laravel CRUD implementation.

Examples already present:

* `exists` constraints;
* `unique` constraints;
* role/scope-aware supplier queries;
* enum/allowlist validation;
* numeric minimums;
* nested array validation;
* `distinct` for repeated IDs;
* file MIME/type restrictions;
* file size restrictions;
* date format validation;
* conditional required fields;
* regex validation for monetary/tax fields;
* custom post-validation logic;
* import file extension/type restrictions.

`StoreLocalInvoiceRequest`, in particular, already demonstrates strong validation discipline.

---

## 4.1 Remaining Validation Audit

The implementation should systematically map:

```text
Route
→ Middleware
→ FormRequest / inline validation
→ Controller
→ Policy
→ Service
→ Database constraint
```

For every state-changing endpoint verify:

1. request is validated;
2. enum/action values use allowlists;
3. IDs have correct type and existence checks;
4. nested arrays use `array`, `distinct`, per-item validation and bounded size;
5. foreign IDs are constrained to resources visible to the authenticated actor;
6. file uploads have size/type restrictions;
7. dates have semantic boundaries;
8. monetary values use exact formats;
9. controller does not trust client-calculated amounts;
10. service performs business invariant validation again where necessary.

Validation must not be treated as authorization.

---

# 5. Object Storage Assessment

## Current State

`config/filesystems.php` already contains:

* `local`;
* `private`;
* `public`;
* `s3`.

The current default is:

```text
FILESYSTEM_DISK=local
```

The private disk points to:

```text
storage/app/private
```

This is appropriate for the existing single-node cPanel deployment.

The application also correctly uses the private disk for business documents.

---

## 5.1 Main Limitation

The current application is not yet storage-backend agnostic.

Several controllers rely on filesystem-local paths.

For example:

`AttachmentController`

uses:

```text
Storage::disk('private')->path(...)
response()->file(...)
```

and `ExportDownloadController` uses:

```text
Storage::disk($exportJob->disk)->path(...)
response()->download(...)
```

This means:

> Changing `FILESYSTEM_DISK=local` to `s3` is NOT sufficient.

The download implementation must support remote object storage.

---

# 6. Object Storage Implementation

## Phase OS-01 — Storage Access Audit

Inventory every storage operation:

```text
Storage::disk(...)
Storage::put(...)
Storage::get(...)
Storage::path(...)
Storage::download(...)
Storage::response(...)
readStream()
writeStream()
delete(...)
exists(...)
url(...)
temporaryUrl(...)
```

Classify every use:

```text
PUBLIC ASSET
PRIVATE BUSINESS DOCUMENT
EXPORT FILE
TEMPORARY FILE
SYSTEM FILE
```

No business document may accidentally migrate onto the public disk.

---

## Phase OS-02 — Storage Abstraction

Preserve Laravel's filesystem abstraction.

Do not introduce a large repository layer merely for S3.

Refactor only the local-path-dependent consumers.

Required behavior:

```text
Local disk:
authorized request
→ application reads private object
→ response

S3:
authorized request
→ application authorizes object
→ streamed object response / controlled temporary delivery
```

---

## Phase OS-03 — Private Object Policy

When S3 is activated:

* bucket/container remains private;
* no public-read ACL for business documents;
* no direct unauthenticated document URL;
* object keys must not contain unsafe user-controlled path traversal;
* authorization occurs before delivery;
* expiration applies to temporary access if direct signed URLs are introduced.

Business authorization must remain in Laravel.

---

## Phase OS-04 — Export Compatibility

Update export downloads so that:

```text
local private disk
```

and:

```text
S3 private disk
```

both work correctly.

`ExportJob.disk` must continue to determine the actual storage location.

Verify:

```text
queued
→ processing
→ completed
→ authorized download
→ expiry
→ inaccessible after expiry
```

---

# 7. CDN Assessment

## Current State

The repository already has useful origin cache headers:

```text
/build/assets/*
→ max-age=31536000
→ immutable

/assets/*
→ max-age=86400
→ must-revalidate
```

This is correct for content-hashed assets.

There is currently no mandatory CDN.

That is acceptable for a single-node deployment.

---

# 8. CDN Implementation

CDN should be implemented only for public static assets.

Allowed:

```text
/build/assets/*
/assets/*
```

Potentially:

```text
public images
public SVG
public fonts
```

Do NOT cache:

```text
/login
/admin/*
/purchasing/*
/supplier/*
/qc/*
/finance/*
/ga/*
/accounting/*
notifications/*
conversations/*
private downloads
exports/*
CSRF-sensitive requests
authenticated HTML
```

The CDN must never transform an authenticated application response into a shared public cache entry.

Recommended future CDN policy:

```text
Cloudflare / equivalent
Full (strict) TLS
+
static-asset caching only
+
authenticated route bypass
+
private-storage bypass
```

CDN adoption should be measured against actual bandwidth and latency data rather than treated as a mandatory dependency.

---

# 9. Caching Assessment

## Current State

Production baseline:

```text
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

This is deliberately compatible with shared hosting.

Application-level caching already exists.

Example:

`ExchangeRate::latestRate()`

uses a 60-minute cache.

Supplier dashboard widgets also use cache.

---

## 9.1 Strength

Database cache provides:

* shared cache between requests;
* deployment simplicity;
* compatibility with cPanel;
* shared locks for scheduler/coordination.

---

## 9.2 Limitation

Under increasing traffic:

```text
web request
→ DB
→ cache table
```

can create database contention.

This becomes especially relevant because:

* sessions are database-backed;
* cache is database-backed;
* queues are database-backed;
* scheduler locking uses the cache mechanism.

Therefore the database becomes a shared coordination layer for multiple unrelated workloads.

---

# 10. Cache Implementation

## Phase CACHE-01 — Cache Inventory

Inventory every cache operation and classify:

```text
REFERENCE DATA
USER-SPECIFIC DATA
DASHBOARD DATA
AUTH SECURITY DATA
LOCK
IDEMPOTENCY MARKER
RATE LIMIT
TEMPORARY COMPUTATION
```

Do not cache business-critical state without an explicit invalidation rule.

---

## Phase CACHE-02 — Key Discipline

All application cache keys must be:

* namespaced;
* deterministic;
* user/scope aware where appropriate;
* invalidated when their source data changes.

Example categories:

```text
exchange-rate:{currency}
supplier-dashboard:{supplier_id}
...
```

Never use one generic key for multiple authorization domains.

---

## Phase CACHE-03 — Redis Evaluation

Redis should remain an optional next-stage architecture.

Do not migrate:

```text
cache
session
queue
locks
```

simultaneously.

Measure first.

If Redis is adopted:

1. move cache first;
2. verify invalidation;
3. measure database load;
4. evaluate sessions separately;
5. evaluate queue separately;
6. verify every distributed lock;
7. keep export transaction assumptions intact.

The existing export architecture has a same-database-connection requirement for its atomic queue handoff.

Therefore:

> Do NOT switch the queue architecture to Redis/SQS/etc. merely because Redis is being introduced for cache.

That would require a separate queue/outbox/idempotency review.

---

# 11. Database Indexing Assessment

The repository already contains evidence-based performance tuning.

The previous program:

* removed verified redundant indexes;
* retained unique indexes;
* verified composite-index coverage;
* used `EXPLAIN`;
* deliberately avoided speculative indexes.

This is the correct methodology.

Do not create an “index every foreign key/filter” migration.

---

# 12. Database Indexing Implementation

## Phase DB-01 — Growth-Critical Query Inventory

Prioritize:

```text
local_invoices
local_invoice_revisions
local_invoice_documents
payment_batches
payment_groups
payment_items
local_invoice_vouchers
local_invoice_payments
export_jobs
notifications
messages
conversations
jobs
failed_jobs
```

Also inspect high-traffic Core 1 tables:

```text
purchase_requisitions
pr_items
quotations
quotation_items
purchase_orders
po_quotations
shipments
shipment_items
qc_inspections
```

---

## Phase DB-02 — EXPLAIN Audit

For each important query record:

```text
query
WHERE predicates
ORDER BY
JOIN
LIMIT/OFFSET
current index
EXPLAIN
rows examined
possible keys
chosen key
temporary/filesort
execution time
```

Use representative data.

Do not use a tiny empty database to justify an index.

---

## Phase DB-03 — Forecast-Specific Index

The new Ready-to-Pay forecasting feature is a specific candidate.

Inspect actual query shape around:

```text
local_invoices.ready_to_pay_at
local_invoices.status
supplier_id
```

Then determine through `EXPLAIN` whether a simple or composite index is justified.

Do not automatically assume:

```text
INDEX(status, ready_to_pay_at)
```

is optimal.

The query shape determines the correct index order.

---

## Phase DB-04 — Duplicate Index Audit

Continue the previous policy:

Before creating any new index:

1. inspect existing indexes;
2. identify left-most prefix coverage;
3. identify unique-index equivalence;
4. run `EXPLAIN`;
5. measure before/after;
6. add migration only when justified.

---

# 13. Scaling Assessment

## Current Architecture

The project is optimized around:

```text
Single cPanel node
+
Laravel application
+
MySQL/MariaDB
+
database cache
+
database session
+
database queue
+
Cron-based workers
+
Pusher
+
local private storage
```

This is a reasonable single-node deployment model.

It is NOT yet fully horizontally scalable.

---

# 14. Horizontal Scaling Blockers

The main blockers are:

### 1. Private local storage

Multiple application nodes cannot reliably share:

```text
storage/app/private
```

unless the filesystem is shared.

Object storage solves this.

### 2. Database-backed coordination

Cache/session/queue are centralized in MySQL.

This works, but database load increases with application-node count.

### 3. Queue worker topology

Short-lived cPanel Cron workers are appropriate for the current architecture but are not equivalent to a continuously supervised worker fleet.

### 4. Deployment/runtime state

Every node must have:

* matching application source;
* matching Vite build;
* matching configuration;
* matching cache state;
* access to the same database;
* shared private objects.

---

# 15. Scaling Implementation

## Phase SCALE-01 — Stateless Application Readiness

Ensure application instances do not depend on local mutable runtime state.

Move or externalize:

```text
business documents
exports
sessions
shared cache
queue state
logs
```

where required.

---

## Phase SCALE-02 — Shared Storage

Adopt private object storage for:

```text
invoice documents
supplier documents
QC attachments
shipment documents
exports
refund proofs
other business attachments
```

Do not blindly migrate historical files.

Implement:

```text
read old local
+
write new object storage
+
controlled migration
+
verification
+
cutover
```

before deleting old files.

---

## Phase SCALE-03 — Shared Session/Cache

Evaluate Redis or another managed shared store.

Session migration requires explicit validation for:

* login;
* logout;
* session revocation;
* MFA;
* absolute timeout;
* concurrent-session eviction;
* password confirmation;
* known-device logic.

---

## Phase SCALE-04 — Worker Scaling

For a VPS/container environment:

```text
Supervisor/systemd/container worker
```

can replace Cron-based short-lived workers.

For cPanel:

```text
Cron + flock + stop-when-empty
```

remains acceptable.

Do not run multiple workers blindly when they can overlap.

---

# 16. Input Validation Hardening Plan

Create a route validation matrix.

Required columns:

```text
Route
Method
Role
FormRequest
Inline validation
Policy
Ownership
CSRF
Throttle
File validation
Database constraint
Service invariant
Tests
```

Every state-changing endpoint must have an explicit row.

---

## High-Risk Input Categories

Pay special attention to:

```text
IDs
nested arrays
role/action fields
supplier IDs
PR IDs
PO IDs
invoice IDs
payment batch IDs
payment group IDs
export job IDs
file uploads
monetary amounts
dates
return_url
query filters
import spreadsheets
```

For IDs, validation should not stop at:

```text
exists:table,id
```

when ownership or scope matters.

Use scoped existence or subsequent policy authorization.

---

# 17. Authentication Hardening Plan

Authentication is already strong.

The implementation should focus on verification and regression rather than replacement.

Verify:

```text
inactive user
expired session
revoked session
MFA user
remember-device user
password reset
password confirmation
concurrent session eviction
login throttling
MFA throttling
password-reset throttling
secure cookie attributes
session invalidation
```

Do not replace the existing authentication architecture with Sanctum/JWT/etc.

This is a server-rendered web application and does not require a token-based API authentication architecture at this time.

---

# 18. Authorization Hardening Plan

Construct an authorization matrix for every resource:

| Resource     | View                        | Create     | Update     | Delete     | State Change | Download |
| ------------ | --------------------------- | ---------- | ---------- | ---------- | ------------ | -------- |
| PR           | owner/role                  | owner      | owner      | owner      | owner        | role     |
| Quotation    | supplier/purchasing         | supplier   | supplier   | workflow   | purchasing   | role     |
| PO           | supplier/purchasing         | purchasing | purchasing | restricted | purchasing   | scoped   |
| Invoice      | supplier/finance/purchasing | supplier   | workflow   | restricted | finance      | scoped   |
| DRP          | finance/admin               | finance    | finance    | restricted | finance      | finance  |
| Export       | owner/domain                | role       | owner      | owner      | owner        | owner    |
| Attachment   | policy                      | actor      | actor      | actor      | —            | policy   |
| Conversation | member                      | member     | member     | —          | member/role  | member   |

The exact matrix must be generated from current controllers/policies, not guessed from the table.

---

# 19. Specific Authorization Audit Targets

Inspect especially:

```text
PurchaseRequisitionController
PrItemController
QuotationListController
QuotationController
PurchaseOrderController
ShipmentController
QcInspectionController
MaterialClaimController
FinanceInvoiceController
FinanceDrpController
LocalInvoiceSettlementController
FinanceVendorController
LocalProcurementController
ExportDownloadController
ConversationMessageController
AttachmentController
supplier document controllers
registration review controllers
```

For each action verify:

```text
route role
+
object policy/ownership
+
state transition authorization
```

A role check is not sufficient when the resource itself has an owner.

---

# 20. Credential Exposure Assessment

## Current Evidence

The repository correctly excludes:

```text
.env
.env.*
.env.production
auth.json
```

from Git.

`.env.example` uses placeholders for:

```text
database password
mail password
Pusher secret
AWS credentials
application key
Turnstile secret
```

No confirmed real secret was identified in the source reviewed.

---

# 21. Credential Hardening Required

The main issue is not an exposed password.

It is production-like default configuration inside application source.

`config/finance.php` currently contains fallback values for:

* ADASI bank account configuration;
* finance contact information;
* transfer debit account.

These should not be production-sensitive defaults.

Change the design to:

```text
environment/config required
+
no sensitive production fallback
+
fail fast in production when missing
```

Example conceptual rule:

```text
Production:
required configuration

Local:
explicit test/demo configuration
```

Do not put real banking credentials or operational financial account details into source control.

---

# 22. Secret Scanning

Perform three levels of scanning.

## Current working tree

Search for:

```text
password
secret
token
bearer
api_key
apikey
AWS_
PUSHER_
TURNSTILE_
MAIL_PASSWORD
DB_PASSWORD
PRIVATE KEY
BEGIN RSA
BEGIN OPENSSH
```

## Git history

Search historical commits for secrets.

The fact that `.env` is currently ignored does not prove that a credential was never committed historically.

## Dependency/runtime

Run:

```bash
composer audit
npm audit
```

where the project dependency policy permits it.

A historical secret leak requires credential rotation, not merely deleting the file.

---

# 23. API / JSON Endpoint Assessment

`CLAUDE.md` explicitly states that the application does not have a conventional API layer.

The JSON endpoints are primarily:

* DataTables;
* export polling;
* notifications;
* chat;
* search/preview endpoints.

Therefore `api/index.php` should not automatically be interpreted as a public REST API.

It simply forwards to the Laravel front controller.

---

# 24. JSON Endpoint Findings

The endpoints already inspected show appropriate protections.

Examples:

```text
notifications/*
→ auth + role

exports/*
→ auth + role + ownership/domain checks

conversation endpoints
→ authenticated route group
+ conversation policy/membership

finance/*
→ auth + finance/admin role

supplier-local/*
→ auth + supplier + local scope
```

The public receipt verification endpoints are intentionally public but require a valid signed URL and are throttled.

---

# 25. API Hardening Plan

Generate a complete route inventory.

For each JSON-capable route record:

```text
URI
method
controller
response type
authentication
authorization
throttle
CSRF
object ownership
input validation
sensitive output
```

Then test adversarially:

```text
unauthenticated
wrong role
same role / foreign object
invalid hash
raw integer identifier
foreign supplier ID
oversized payload
invalid array
invalid action
expired object
deleted object
```

Expected outcome:

```text
401 / 403 / 404 / 422
```

depending on the security boundary.

---

# 26. Export Endpoint Security

`ExportDownloadController` already performs an important ownership check:

```text
exportJob.user_id === authenticated user ID
```

and additionally applies domain restrictions.

The implementation plan must preserve this.

Do not allow:

```text
hashid knowledge
+
valid export ID
```

to become sufficient for downloading another user's file.

---

# 27. Public Endpoint Review

Intentional public endpoints currently include:

```text
/
/supplier/register
/supplier/registration/access
/verify-receipt/*
```

These must receive dedicated abuse controls.

Review:

* rate limits;
* enumeration;
* sensitive output;
* response timing;
* signed URL expiration;
* invalid signature behavior;
* repeated access;
* registration abuse;
* password/access-code brute force.

---

# 28. `api/index.php` Deployment Review

The repository contains:

```text
api/index.php
```

which forwards to:

```text
public/index.php
```

This is not inherently an authentication bypass.

However, the deployment invariant must be enforced:

```text
DOCUMENT_ROOT = application/public
```

The project source root must never be web-accessible.

If the deployment topology does not require `api/`, it should be removed or excluded from the public document root to reduce unnecessary attack surface.

---

# 29. Error Handling Security

Audit all controllers for:

```text
$e->getMessage()
```

being sent directly to users.

Expected pattern:

```text
report($exception)
+
safe user-facing message
```

Avoid returning:

* SQL errors;
* local filesystem paths;
* stack details;
* credentials;
* upstream service payloads.

This is especially important on upload, import, export, payment, and QC paths.

---

# 30. Rate-Limit Strategy

Existing throttling is good but should be standardized.

High-value endpoints:

```text
login
MFA
password reset
supplier registration
registration access
invoice submission
invoice resubmission
chat/message submission
exports
spreadsheet previews
search endpoints
security actions
```

should have explicit limits based on:

```text
IP
authenticated user
resource
action
```

where appropriate.

Avoid using a single global throttle value for all endpoint types.

---

# 31. Database Constraint Security

Application validation is not enough for critical invariants.

Database constraints should protect:

```text
unique document numbers
unique supplier relationships
unique payment references
unique voucher relationships
payment-item ownership
polymorphic association indexes
shipment-item uniqueness
financial settlement uniqueness
```

Application checks should remain, but critical invariants should have database enforcement where feasible.

---

# 32. Scaling Observability

Before claiming that the system can support higher concurrency, add measurable operational indicators.

Track:

```text
request duration P50/P95/P99
database query count
database query duration
slow queries
PHP memory
queue depth
oldest queue job
failed jobs
export completion time
cache hit/miss
HTTP 429
HTTP 5xx
storage failures
authentication failures
authorization failures
```

Do not infer production capacity from local Laravel timings.

---

# 33. Production Measurement

The existing performance documentation correctly identifies several values that are still environment-dependent.

These must be measured on staging/production:

```text
OPcache
PHP-FPM/LiteSpeed limits
MySQL connection limits
DB CPU
DB memory
query latency
CloudLinux CPU
CloudLinux RAM
I/O
disk quota
queue backlog
worker duration
HTTP/2
gzip/Brotli
CDN cache hit ratio
static asset latency
object-storage latency
```

---

# 34. Implementation Order

## Phase 0 — Evidence Baseline

No code change.

Deliver:

```text
route inventory
JSON endpoint inventory
FormRequest map
policy map
storage operation map
cache operation map
query/index inventory
secret scan
dependency audit
```

Status of every item:

```text
VERIFIED
PARTIALLY VERIFIED
NOT VERIFIED
```

---

## Phase 1 — Security Hardening

Prioritize:

1. authorization matrix;
2. object-level authorization gaps;
3. public endpoint audit;
4. validation completeness;
5. safe error handling;
6. credential/default cleanup;
7. secret history scan;
8. rate-limit gaps.

---

## Phase 2 — Storage Readiness

Implement:

```text
storage backend-neutral downloads
private object-storage support
safe object key handling
export storage compatibility
attachment streaming compatibility
migration tooling
```

Do not make S3 mandatory before the environment is ready.

---

## Phase 3 — Cache Hardening

Implement:

```text
cache key namespace
invalidation rules
cache inventory
metrics
measured Redis evaluation
```

Database cache remains supported.

---

## Phase 4 — Database Performance

Implement only evidence-backed changes:

```text
EXPLAIN audit
growth-critical indexes
forecast-specific query optimization
redundant-index cleanup
query regression tests
```

Do not add speculative indexes.

---

## Phase 5 — CDN

Implement only public static delivery:

```text
immutable Vite assets
stable public assets
gzip/Brotli where provider supports it
CDN static cache
authenticated-route bypass
private-download bypass
```

---

## Phase 6 — Scaling Readiness

Implement:

```text
shared object storage
shared session/cache option
worker topology
deployment consistency
central logging
health/readiness checks
connection/resource monitoring
```

Do not introduce Kubernetes, microservices, or other large infrastructure changes without measured need.

---

# 35. Required Tests

Create or extend focused security tests.

## Authentication

```text
inactive user rejected
expired session rejected
revoked session rejected
MFA challenge enforced
remembered MFA session rejected/redirected correctly
password confirmation required
rate limiting works
```

## Authorization

```text
foreign supplier record rejected
foreign invoice rejected
foreign PO rejected
foreign attachment rejected
foreign document rejected
foreign conversation rejected
foreign export rejected
foreign payment object rejected
wrong role rejected
```

## Validation

```text
invalid IDs
foreign IDs
oversized arrays
duplicate IDs
invalid enums
invalid dates
invalid amounts
invalid files
oversized files
invalid nested payloads
unexpected fields where relevant
```

## Public endpoints

```text
invalid signature rejected
expired signature rejected
rate limit enforced
sensitive fields not exposed
```

## Storage

```text
private file cannot be downloaded anonymously
authorized file can be downloaded
foreign file denied
unsafe path denied
expired export denied
S3-compatible storage behavior
```

## Secrets/configuration

```text
production missing sensitive configuration fails safely
real defaults do not exist in production source
```

---

# 36. Performance Tests

For high-value queries:

```text
query count regression
EXPLAIN regression
response time regression
large dataset behavior
memory behavior
```

Especially:

```text
Finance Dashboard
Ready-to-Pay Forecast
Invoice DataTable
DRP list
DRP export
Master Invoice
Supplier Dashboard
PR DataTable
PO DataTable
Notification summary
Chat
```

---

# 37. Verification Commands

Minimum backend verification:

```bash
php artisan test --filter=Security
php artisan test --filter=Authorization
php artisan test --filter=SupplierDataIsolationTest
php artisan test --filter=HashidUrlSecurityTest
php artisan test --filter=PaymentForecastAndReportingTest
```

Then the directly affected feature suites.

Formatting:

```bash
vendor/bin/pint --test
```

Dependency/security:

```bash
composer audit
```

Build:

```bash
npm run build
```

Application configuration:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Route inspection:

```bash
php artisan route:list
```

Then run the broader suite:

```bash
composer test
```

Remember the documented baseline contains two known pre-existing view assertion failures. They must remain separately classified from regressions introduced by this implementation.

Do NOT use Browser QA.

---

# 38. Completion Criteria

The implementation is complete only when:

### Security

* every protected route has authentication;
* every role-sensitive route has correct role middleware;
* object-level authorization is enforced;
* supplier isolation is preserved;
* state-changing requests are CSRF protected;
* input validation is complete for every affected endpoint;
* public endpoints are explicitly documented and protected;
* no sensitive exception details reach users;
* no credentials exist in source;
* no production-sensitive fallback defaults remain where they should be secret/configuration;
* secret history is reviewed.

### Storage

* all business files remain private;
* local and S3-compatible storage can both serve authorized objects;
* local-path-dependent code is removed from portable storage paths;
* exports continue working.

### CDN

* only public static assets are cacheable;
* authenticated/private responses cannot be cached publicly;
* immutable asset policy remains correct.

### Cache

* cache keys are scoped;
* invalidation rules are explicit;
* no incorrect personalized-data caching;
* database cache remains operational;
* Redis is introduced only when evidence justifies it.

### Database

* new indexes are justified with EXPLAIN;
* redundant indexes are not reintroduced;
* critical queries have regression protection.

### Scaling

* application runtime is stateless enough for the chosen deployment model;
* private files can be shared across nodes;
* session/cache/queue architecture supports the intended number of nodes;
* workers cannot overlap unsafely;
* database connection capacity is measured;
* observability exists for bottlenecks.

---

# 39. Engineering Guardrails

Do NOT:

* blindly migrate everything to S3;
* blindly install Redis;
* add indexes to every foreign key;
* add a CDN cache-everything rule;
* expose S3 objects publicly;
* replace Laravel session authentication with JWT without a requirement;
* add Sanctum merely because an `api/` directory exists;
* convert every FormRequest `authorize(): true` mechanically;
* trust Hashids as authorization;
* make production-sensitive values default silently;
* rewrite working Core 1/2 architecture;
* introduce microservices;
* introduce Kubernetes;
* use browser automation;
* claim production capacity based on local benchmarks.

---

# 40. ECC Skills

Primary skills:

`boost`

`search-first`

`laravel-security-audit`

`vulnerability-scanner`

`backend-security-coder`

`laravel-patterns`

`laravel-verification`

For implementation work involving tests:

`laravel-tdd`

For any UI/cache/header work:

`frontend-design-direction`

`design-system`

The primary security workflow should be:

```text
search-first
→ boost
→ laravel-security-audit
→ vulnerability-scanner
→ backend-security-coder
→ laravel-patterns
→ laravel-tdd
→ laravel-verification
```

---

# 41. Final Engineering Principle

The desired target is not:

> “the application uses S3, Redis, CDN, and many indexes.”

The desired target is:

> “every infrastructure component exists because repository evidence and production measurements justify it, and every security boundary is explicitly enforced.”

The implementation sequence remains:

```text
Evidence
→ Security Boundaries
→ Correctness
→ Performance
→ Infrastructure Readiness
→ Verification
→ Measured Scaling
```

The current repository is already substantially beyond a baseline Laravel CRUD application. The next phase should therefore be controlled hardening and scale-readiness, not architectural replacement.
