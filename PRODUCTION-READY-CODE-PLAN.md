# ADASI Supplier Portal

## Production Code-Ready Implementation Plan

### Security Hardening, Storage Portability, Caching, Database Performance & Scaling Readiness

Repository:

`https://github.com/poyipoy/supplierportal`

Local workspace:

`C:\laragon\www\adasi_portal_supplier`

Baseline audited HEAD:

`45ba4888fc9b974833b2c3a45e69661afd3811c9`

Stack:

* Laravel 12
* PHP 8.2
* MySQL/MariaDB
* Blade / server-rendered web application
* cPanel-oriented production deployment
* Pusher realtime
* Database cache/session/queue baseline

Execution target:

> Produce code that is production-ready from a correctness, security, storage, performance, and maintainability perspective without introducing infrastructure dependencies that are not justified by evidence.

Browser QA is explicitly excluded.

---

# 1. Production-Ready Definition

Production-ready does NOT mean:

```text
S3 enabled
Redis installed
CDN enabled
many indexes added
architecture replaced
```

Production-ready means:

```text
Security boundaries verified
+
business invariants preserved
+
storage access is backend-compatible
+
queries are evidence-backed
+
cache behavior is scoped and deterministic
+
configuration is safe
+
tests cover changed behavior
+
rollback exists
+
deployment prerequisites are explicit
+
no known regression introduced
```

The implementation must not claim production readiness solely because tests pass.

---

# 2. Engineering Rules

All implementation must follow:

```text
Evidence
→ Root Cause
→ Minimal Change
→ Focused Test
→ Regression Test
→ Verification
```

For every significant change distinguish:

```text
EVIDENCE
INFERENCE
ASSUMPTION
```

Never turn an inference into a confirmed finding.

Do not refactor unrelated working code.

Do not introduce a new abstraction unless the current implementation makes the abstraction necessary.

Do not change infrastructure and application architecture simultaneously unless the change is inseparable.

---

# 3. Implementation Priority

Execute in this order:

```text
P0 — Security & Exposure
P1 — Storage Correctness
P2 — Validation & Authorization Coverage
P3 — Database Query/Index Hardening
P4 — Cache Correctness
P5 — Production Observability / Configuration
P6 — Scaling Readiness
P7 — Final Regression & Release Gate
```

Security and correctness take precedence over performance optimization.

---

# 4. P0 — Security & Exposure Hardening

## Objective

Close externally reachable security gaps before infrastructure optimization.

---

## P0.1 Route Security Inventory

Build a complete route inventory from the current repository.

Inspect:

```text
routes/*.php
bootstrap/*
app/Http/Controllers/*
app/Http/Middleware/*
app/Policies/*
```

For every route record:

```text
URI
HTTP method
controller/action
middleware
authentication
role
policy authorization
ownership check
FormRequest
inline validation
throttle
CSRF exposure
JSON response
sensitive response data
```

Do not rely on route naming conventions.

The inventory must be generated from the actual repository.

---

## P0.2 Protected Route Audit

For every non-public route verify:

```text
authentication
+
role authorization
+
object authorization
+
ownership / tenant isolation
```

A role check alone is insufficient.

Example:

```text
auth
+
role:supplier
```

does NOT prove that:

```text
supplier A
```

can access only:

```text
supplier A's resource
```

Object ownership must still be enforced.

---

## P0.3 High-Risk Controllers

Perform explicit review of:

```text
PurchaseRequisitionController
PrItemController
QuotationController
QuotationListController
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

For each state-changing action verify:

```text
route role
→ object lookup
→ policy/ownership
→ business state
→ service invariant
→ persistence
```

---

## P0.4 FormRequest Authorization

Do not mechanically replace:

```php
authorize(): true
```

Instead inspect each FormRequest and determine where authorization currently lives.

For each case:

### Case A

Authorization already occurs correctly in controller/policy.

Action:

```text
leave architecture unchanged
add regression coverage if missing
```

### Case B

Authorization is missing.

Action:

```text
add policy/Gate/ownership enforcement
```

### Case C

Authorization exists but is duplicated or inconsistent.

Action:

```text
centralize only where repository patterns justify it
```

---

# 5. P0.5 Supplier Isolation

Supplier identity must remain:

```text
supplier_id = auth()->id()
```

not:

```text
suppliers.id
```

Audit all supplier-facing queries for:

```text
where('supplier_id', auth()->id())
```

or equivalent policy-based enforcement.

Explicitly test:

```text
supplier A → supplier A object = allowed
supplier A → supplier B object = denied
```

Test using both:

```text
Hashid
raw integer identifier
```

where applicable.

Hashids are never authorization.

---

# 6. P0.6 JSON / Endpoint Exposure

Inventory every endpoint returning:

```text
application/json
```

or DataTables-compatible JSON.

Inspect:

```text
notifications
exports
chat
search
preview
forecast
finance JSON
supplier-local JSON
verification endpoints
```

For each endpoint verify:

```text
authenticated?
correct role?
object ownership?
request validation?
throttle?
sensitive output?
```

Explicit public endpoints must remain intentionally public only where the current product behavior requires it.

---

# 7. P0.7 Public Endpoint Hardening

Explicit public endpoints include:

```text
/
/supplier/register
/supplier/registration/access
/verify-receipt/*
```

Verify:

```text
rate limit
enumeration resistance
signature validation
expiration
safe error response
minimal response data
```

For signed receipt verification:

```text
invalid signature → reject
expired signature → reject
modified identifier → reject
high request volume → throttled
```

---

# 8. P0.8 Error Disclosure

Repository-wide search:

```text
$e->getMessage()
$exception->getMessage()
->getTrace()
->getFile()
->getLine()
stack trace
```

Determine whether any exception details reach HTTP responses, JSON responses, validation messages, exports, or views.

Required production behavior:

```text
internal exception
→ report/log internally
→ safe public message
```

Never expose:

```text
SQL error
database host
filesystem path
source code location
stack trace
credential
upstream secret
```

---

# 9. P0.9 Credential & Configuration Hardening

Audit:

```text
.env.example
config/*
deployment files
documentation examples
tests
fixtures
seeders
Git history
```

Production-sensitive configuration must not silently fallback to realistic credentials.

Especially inspect:

```text
config/finance.php
```

Production-sensitive financial configuration must follow:

```text
environment/config supplied explicitly
+
no real production fallback
+
fail fast when mandatory production configuration is missing
```

Local development may have explicit fake/test values, but they must be clearly non-production.

---

# 10. P0.10 Secret Scan

Run current-tree secret search for:

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

Then inspect Git history.

If a historical real secret is discovered:

```text
DO NOT only delete the value
→ identify credential owner
→ rotate/revoke credential
→ remove from history when operationally appropriate
→ verify repository no longer exposes it
```

---

# 11. P0.11 Dependency Security

Run:

```bash
composer audit
npm audit
```

Classify findings:

```text
production-relevant
development-only
false positive / not applicable
requires dependency upgrade
```

Do not upgrade unrelated packages merely to achieve a clean audit.

Dependency changes require their own regression testing.

---

# 12. P1 — Object Storage Production Readiness

## Objective

Make business-file delivery compatible with private local storage and S3-compatible storage.

---

# 13. P1.1 Storage Inventory

Search the entire repository for:

```text
Storage::
Storage::disk
->path(
->download(
->response(
temporaryUrl(
readStream(
writeStream(
put(
putFile(
putFileAs(
delete(
exists(
```

Classify all storage usage:

```text
PUBLIC_ASSET
PRIVATE_BUSINESS_FILE
EXPORT
TEMPORARY
SYSTEM
TEST
```

Deliver a storage matrix.

---

# 14. P1.2 Local-Path Dependency Refactor

Identify all code equivalent to:

```php
Storage::disk(...)->path(...)
```

that is later passed into:

```php
response()->file(...)
response()->download(...)
```

These paths are local-filesystem dependent.

Refactor only where required so both:

```text
local
```

and:

```text
s3
```

work.

Do not create a generic filesystem repository unless multiple consumers genuinely require it.

---

# 15. P1.3 Attachment Download

Review:

```text
AttachmentController
```

Required behavior:

```text
authenticate
→ authorize attachment
→ resolve storage disk
→ verify object existence
→ stream/respond using backend-compatible mechanism
```

Never bypass authorization to simplify storage delivery.

---

# 16. P1.4 Export Download

Review:

```text
ExportDownloadController
```

Preserve:

```text
exportJob.user_id === auth user
```

and all domain authorization.

The download path must support:

```text
local private file
```

and:

```text
S3 private object
```

without exposing an object publicly.

---

# 17. P1.5 S3 Security

When S3 is enabled:

```text
bucket private
ACL public-read disabled
object access controlled
no unauthenticated business-file URL
```

Application authorization remains responsible for deciding who may access an object.

S3 signed URLs are delivery mechanisms, not authorization mechanisms.

---

# 18. P1.6 Safe Object Keys

Audit object-key construction.

Ensure user-controlled values cannot create:

```text
../
absolute paths
unexpected filesystem prefixes
cross-user paths
```

Prefer deterministic server-generated object keys.

Example conceptual structure:

```text
supplier/{supplier_id}/documents/{uuid}
exports/{user_id}/{uuid}
```

Do not expose raw storage paths unnecessarily.

---

# 19. P1.7 Historical File Migration

Do not perform a blind migration.

Use:

```text
existing local files
+
new object-storage writes
+
controlled migration
+
integrity verification
+
cutover
+
retention period
```

Migration tooling must be:

```text
idempotent
resumable
logged
verifiable
rollback-aware
```

Do not delete local originals during the first migration step.

---

# 20. P2 — Input Validation & Authorization

## Objective

Make every state-changing endpoint explicitly defensible.

---

# 21. P2.1 Validation Matrix

Create:

| Route | Request | Role | Policy | Ownership | Throttle | File Validation | Service Invariant | Tests |
| ----- | ------- | ---- | ------ | --------- | -------- | --------------- | ----------------- | ----- |

Every state-changing endpoint must be represented.

---

# 22. P2.2 High-Risk Inputs

Audit:

```text
IDs
supplier IDs
PR IDs
PO IDs
invoice IDs
payment batch IDs
payment group IDs
export IDs
action fields
status fields
nested arrays
file uploads
amounts
dates
search filters
imports
redirect URLs
```

---

# 23. P2.3 Scoped IDs

Avoid assuming:

```text
exists:table,id
```

is sufficient.

For tenant-owned resources prefer:

```text
scoped query
+
policy authorization
```

Example conceptual rule:

```text
resource exists
AND
belongs to current supplier/domain
```

---

# 24. P2.4 Nested Arrays

For nested request structures verify:

```text
array
max size
required keys
per-item validation
distinct IDs
allowed action values
numeric bounds
existence
ownership
```

Prevent unbounded payloads.

---

# 25. P2.5 Monetary Input

For money-related fields:

```text
do not trust browser-calculated total
do not trust browser-calculated tax
do not trust browser-calculated subtotal
```

Server-side business logic remains authoritative.

Use exact decimal rules already established by the project.

Avoid introducing floating-point arithmetic into financial state.

---

# 26. P2.6 File Uploads

Verify:

```text
MIME/type
extension
maximum size
maximum count
filename handling
storage destination
authorization
```

For sensitive uploads:

```text
private storage
+
non-executable location
```

Do not rely exclusively on client filename extensions.

---

# 27. P3 — Database Query & Index Production Hardening

## Objective

Improve growth-critical queries without speculative indexing.

---

# 28. P3.1 Query Inventory

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

Then Core 1:

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

# 29. P3.2 Existing Index Audit

Before adding any index:

```text
SHOW INDEX
→ inspect composite indexes
→ inspect unique indexes
→ inspect left-most prefixes
→ inspect query shape
→ EXPLAIN
```

Never create an index simply because a column appears in:

```text
WHERE
JOIN
ORDER BY
```

---

# 30. P3.3 EXPLAIN Evidence

For every proposed index record:

```text
query
data volume
current execution plan
possible keys
chosen key
rows examined
extra
execution timing
proposed index
post-index plan
post-index timing
```

An index is accepted only when it has a measurable reason.

---

# 31. P3.4 Ready-to-Pay Forecast

For forecast queries inspect actual usage of:

```text
ready_to_pay_at
status
supplier_id
```

Determine index order from actual query predicates and ordering.

Do not automatically implement:

```text
INDEX(status, ready_to_pay_at)
```

without EXPLAIN evidence.

---

# 32. P3.5 Query Regression Protection

For changed high-value queries add tests or instrumentation that protect against:

```text
N+1
unexpected joins
query explosion
unbounded result sets
```

Priority screens:

```text
Finance Dashboard
Payment Forecast
Invoice DataTable
DRP
DRP Export
Supplier Dashboard
PR DataTable
PO DataTable
Notification summary
Chat
```

---

# 33. P4 — Cache Production Hardening

## Objective

Ensure current database-backed caching remains correct before considering Redis.

---

# 34. P4.1 Cache Inventory

Classify each cache use:

```text
REFERENCE
USER-SPECIFIC
DASHBOARD
SECURITY
LOCK
RATE LIMIT
IDEMPOTENCY
TEMPORARY
```

For every cache key determine:

```text
owner
scope
TTL
invalidation event
fallback behavior
```

---

# 35. P4.2 Cache Key Rules

Keys must be:

```text
deterministic
scoped
namespaced
collision-resistant
```

User-specific data must include relevant identity/scope.

Example:

```text
supplier-dashboard:{supplier_id}
```

is preferable to:

```text
supplier-dashboard
```

when the response contains supplier-specific data.

---

# 36. P4.3 Invalidation

For each cached business result document:

```text
source data
write operation
invalidation trigger
TTL
stale-data tolerance
```

Do not cache data with no defined invalidation strategy when correctness requires immediate consistency.

---

# 37. P4.4 Redis Decision Gate

Redis is NOT part of mandatory P0-P4 implementation.

Redis may be introduced only after:

```text
DB cache load measured
+
DB contention identified
+
memory/CPU capacity verified
+
deployment environment supports Redis
```

If introduced:

```text
cache first
→ measure
→ session evaluation
→ queue evaluation
```

Do not migrate all subsystems simultaneously.

---

# 38. P4.5 Queue Safety

Preserve the current queue transaction design.

Any change to:

```text
QUEUE_CONNECTION
```

must separately review:

```text
transaction boundaries
export handoff
idempotency
job duplication
failure recovery
```

Do not switch to Redis/SQS/etc. merely because Redis becomes available.

---

# 39. P5 — Production Configuration & Observability

## Objective

Prevent deployment surprises and make production performance measurable.

---

# 40. P5.1 Production Configuration Audit

Inspect:

```text
.env.example
config/*
bootstrap/*
deployment documentation
storage configuration
queue configuration
session configuration
cache configuration
mail configuration
Pusher configuration
```

Every production-sensitive setting must have:

```text
required source
default behavior
failure behavior
```

---

# 41. P5.2 Required Production Checks

Verify:

```text
APP_ENV
APP_DEBUG
APP_URL
APP_KEY
SESSION_DRIVER
CACHE_STORE
QUEUE_CONNECTION
FILESYSTEM_DISK
MAIL configuration
Pusher configuration
database configuration
security secrets
```

Production must never operate with:

```text
APP_DEBUG=true
```

or missing required cryptographic/application keys.

---

# 42. P5.3 Runtime Observability

Production should expose measurable internal metrics for:

```text
HTTP P50/P95/P99
HTTP 4xx/5xx
HTTP 429
DB query count
DB latency
slow queries
PHP memory
queue depth
oldest queue job
failed jobs
export duration
cache hit/miss
storage errors
authentication failures
authorization failures
```

Do not expose sensitive observability data to normal users.

---

# 43. P5.4 Health Checks

Define lightweight health/readiness checks for:

```text
application
database
cache
queue
storage
critical external integrations
```

Health checks must not expose:

```text
passwords
connection strings
stack traces
secrets
```

---

# 44. P6 — Scaling Readiness

## Objective

Remove architectural dependencies that prevent controlled scaling without prematurely redesigning the system.

---

# 45. P6.1 Stateless Runtime

Identify mutable local runtime state.

Target:

```text
business files
export artifacts
shared session state
shared cache
queue state
```

Business data must not depend on one application's local disk when multiple application nodes are expected.

---

# 46. P6.2 Shared Storage

Move business-file persistence toward:

```text
private object storage
```

while keeping authorization inside Laravel.

---

# 47. P6.3 Session Strategy

Current:

```text
database session
```

is valid for the current deployment.

For horizontal scaling, evaluate:

```text
shared database session
vs
Redis session
```

Do not change merely for theoretical scalability.

---

# 48. P6.4 Worker Strategy

Current cPanel model remains:

```text
Cron
+
flock
+
short-lived worker
```

For VPS/container deployment, evaluate:

```text
Supervisor
systemd
container worker
```

Workers must not overlap in unsafe ways.

---

# 49. P6.5 Deployment Consistency

For multiple application nodes verify:

```text
same code revision
same configuration contract
same database
same asset build
same storage
same session/cache strategy
```

No node should rely on undocumented local state.

---

# 50. Migration Policy

Every database migration must include:

```text
why
affected table
estimated data size
locking risk
index build risk
rollback strategy
verification
```

Avoid destructive migrations in the same release as behavior changes unless operationally unavoidable.

For production:

```text
backup
→ migration
→ verification
```

must be explicit.

---

# 51. File Migration Policy

Storage migration must have:

```text
source
destination
object count
checksum/integrity strategy
retry behavior
resume behavior
logging
rollback/retention
```

Historical originals should remain until migration verification is complete.

---

# 52. Code Change Policy

Each implementation change should be narrow.

Preferred:

```text
one concern
+
one reason
+
focused tests
```

Avoid:

```text
large refactor
+
storage migration
+
Redis migration
+
auth rewrite
```

in one change set.

---

# 53. Test Strategy

## Layer 1 — Unit

Test:

```text
validation
services
storage path/key generation
forecast calculations
authorization helpers
cache key generation
```

## Layer 2 — Feature

Test:

```text
HTTP status
authorization
database effects
JSON responses
file delivery
state transitions
```

## Layer 3 — Regression

Run existing affected feature suites.

## Layer 4 — Full Suite

Run:

```bash
composer test
```

Classify failures as:

```text
PRE-EXISTING
INTRODUCED BY CHANGE
ENVIRONMENTAL
UNVERIFIED
```

---

# 54. Mandatory Security Tests

At minimum:

```text
unauthenticated request denied
wrong role denied
foreign supplier denied
foreign invoice denied
foreign PO denied
foreign attachment denied
foreign export denied
foreign conversation denied
foreign payment object denied
invalid Hashid denied
raw ID cannot bypass authorization
inactive user denied
expired/revoked session denied
invalid signature denied
expired signature denied
rate-limited endpoint throttles
```

---

# 55. Mandatory Validation Tests

At minimum:

```text
invalid ID
foreign ID
duplicate ID
oversized array
invalid enum
invalid action
invalid amount
invalid date
invalid file
oversized file
invalid nested structure
missing required field
```

---

# 56. Mandatory Storage Tests

For every private business file:

```text
unauthenticated → denied
wrong user → denied
wrong role → denied
authorized user → allowed
missing object → safe 404/failure
invalid path/key → denied
```

Repeat for:

```text
local storage
S3-compatible storage abstraction
```

where the implementation supports both.

---

# 57. Mandatory Cache Tests

Verify:

```text
same scope → same expected cached data
different scope → different cache namespace
mutation → invalidation
TTL → expiration
cache miss → correct fallback
```

No personalized result may leak between users.

---

# 58. Mandatory Database Verification

For every new/changed index:

```text
EXPLAIN before
→ migration
→ EXPLAIN after
→ query test
```

Record:

```text
rows examined
chosen key
timing
```

Do not ship an index whose benefit is purely theoretical.

---

# 59. Static / Build Verification

Run:

```bash
vendor/bin/pint --test
npm run build
```

Then:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Verify cached configuration does not break environment-dependent behavior.

---

# 60. Laravel Verification

Run:

```bash
php artisan test --filter=Security
php artisan test --filter=Authorization
php artisan test --filter=SupplierDataIsolationTest
php artisan test --filter=HashidUrlSecurityTest
php artisan test --filter=PaymentForecastAndReportingTest
```

Then execute affected feature suites.

Finally:

```bash
composer test
```

---

# 61. Production-Like Verification

Before production deployment run in a staging environment using production-like configuration:

```text
APP_ENV=production
APP_DEBUG=false
production-like cache
production-like session
production-like storage
production-like database
production-like queue
```

At minimum verify:

```text
login
MFA
logout
authorization
document upload
document download
export
queue processing
notifications
chat
invoice flow
payment flow
forecast
```

Browser QA remains excluded from this plan; use existing automated tests and server-side verification.

---

# 62. Release Gate

The implementation is NOT release-ready if any of these remain unresolved without explicit classification:

```text
unauthorized resource access
supplier isolation failure
private-file exposure
credential exposure
production-sensitive secret fallback
unsafe exception disclosure
broken state transition
missing critical validation
new high-severity dependency vulnerability
migration integrity failure
queue duplication/loss
cache cross-user contamination
regression in financial workflow
```

---

# 63. Production Readiness Status Model

Each work item must end with one of:

```text
READY — VERIFIED
READY — VERIFIED WITH ENVIRONMENT PREREQUISITE
PARTIALLY READY
BLOCKED
NOT VERIFIED
REGRESSION INTRODUCED
```

Do not use:

```text
DONE
```

without verification evidence.

---

# 64. Required Final Report

Final report must contain:

## A. Executive Status

```text
Security:
Storage:
Validation:
Authorization:
Authentication:
Database:
Cache:
Scaling:
Configuration:
```

## B. Files Changed

For every changed file:

```text
path
change
reason
risk
test coverage
```

## C. Database Changes

```text
migration
index
constraint
estimated impact
rollback
```

## D. Configuration Changes

```text
environment variable
old behavior
new behavior
production requirement
```

## E. Security Verification

```text
route audit
authorization audit
supplier isolation
secret scan
dependency audit
error disclosure audit
```

## F. Performance Verification

```text
query
EXPLAIN
index
before
after
```

## G. Tests

```text
focused tests
feature tests
full suite
lint
build
```

## H. Remaining Risks

Only list risks supported by evidence.

---

# 65. Rollback Strategy

Every production-impacting change must have a rollback plan.

## Application code

```text
revert release
```

## Database

Prefer:

```text
expand
→ migrate
→ verify
→ contract later
```

rather than destructive immediate changes.

## Storage

Maintain old source files during migration.

## Cache

Cache changes must tolerate cache flush/rebuild.

## Configuration

Provide explicit old/new configuration values.

---

# 66. Definition of Production Code-Ready

The code may be classified:

```text
PRODUCTION CODE READY
```

only when all of the following are true:

### Security

```text
route authorization verified
object authorization verified
supplier isolation tested
public endpoints reviewed
credential scan clean
safe error handling verified
rate limits verified
```

### Application correctness

```text
business invariants preserved
state transitions preserved
financial calculations preserved
existing Core 1/Core 2 behavior preserved
```

### Storage

```text
private storage remains private
local storage works
S3-compatible path works where implemented
downloads are authorization-aware
exports remain functional
```

### Database

```text
new indexes justified
EXPLAIN verified
no redundant indexes introduced
critical query regressions absent
```

### Cache

```text
cache keys scoped
invalidation documented
no cross-user contamination
database cache remains supported
```

### Scaling

```text
no unsafe local-state dependency introduced
worker behavior remains safe
session/cache/queue assumptions documented
```

### Verification

```text
focused tests pass
affected suites pass
full suite classified
Pint passes
build passes
production config cache passes
route cache passes
view cache passes
```

---

# 67. Explicit Non-Goals

This implementation does NOT automatically include:

```text
Kubernetes
Docker migration
microservices
Kafka
RabbitMQ
Redis migration
JWT migration
Sanctum adoption
API rewrite
full CDN architecture
full cloud migration
database sharding
read replicas
CQRS
event sourcing
```

These require separate evidence and architectural approval.

---

# 68. Final Execution Sequence

The execution agent should follow exactly:

```text
1. Read repository rules and relevant skills
2. Confirm current HEAD / working tree
3. Build route/security/storage/query inventory
4. Record EVIDENCE / INFERENCE / ASSUMPTION
5. Confirm actual gaps
6. Implement P0 security changes
7. Add focused security tests
8. Implement P1 storage portability
9. Add storage tests
10. Implement P2 validation/authorization gaps
11. Add regression tests
12. Run EXPLAIN-based P3 database changes
13. Verify cache correctness
14. Apply only justified production configuration changes
15. Add/verify observability
16. Run focused suites
17. Run broader regression
18. Run full suite
19. Run static/build/config verification
20. Perform final independent self-review
21. Produce production-readiness report
```

At every stage:

```text
stop
→ verify
→ then continue
```

Do not stack unverified assumptions across phases.

---

# 69. Core Engineering Principle

The final implementation should embody:

```text
Understand first
→ Change minimally
→ Verify explicitly
→ Preserve existing behavior
→ Measure before scaling
```

The objective is not to make the application look “enterprise”.

The objective is to make the existing application safe, deterministic, maintainable, measurable, and deployable in production without introducing unnecessary architectural risk.
