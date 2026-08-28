# VIBECODE PRODUCTION READINESS AUDIT REPORT

**Repository:** `poyipoy/supplierportal`  
**Target Environment:** Production (cPanel / PHP 8.2 + Laravel 12 + MySQL)  
**Audit Date:** 28 August 2026  
**Auditor Roles:** Senior Software Engineer, Security Reviewer, Production Readiness Auditor  
**Audit Protocol:** `VIBECODE-PRODUCTION-READINESS-AUDIT-IMPLEMENTATION-PLAN.md`  
**Mode:** Read-Only Codebase Audit (No Code Changes Applied)

---

## 1. Executive Summary

| Parameter | Value |
|---|---|
| **Target Repository** | `https://github.com/poyipoy/supplierportal` |
| **Branch** | `master` |
| **HEAD SHA** | `afd7f67acdb867236672fa86de8263144ac5cd06` |
| **Base Baseline** | `08ee54c` (Phase 2 UI Finalization) / `master` Deploy Candidate |
| **Active Migrations** | 54 migrations (all applied) |
| **Targeted Security Tests** | 56 tests passed (658 assertions) |
| **Auth Hardening Tests** | 115 tests passed (632 assertions) |
| **Full Suite Execution** | 321 tests passed, **2 failed** (stale fixtures in `NotificationDeliveryTest`) |
| **Composer Audit** | 0 vulnerabilities (4 unbound wildcard constraints detected) |
| **NPM Audit** | 0 vulnerabilities, build successful (`vite v7.3.6`) |
| **Final Merge/Deploy Gate** | 🔴 **BLOCK — Do not deploy to production until blocking items are remediated** |

### Summary of Verdict
A comprehensive, adversarial read-only audit of the codebase was conducted across 17 distinct categories (A–Q) covering security, supplier data isolation, business logic invariants, queue reliability, database integrity, and operational deployment.

The application demonstrates strong security foundations in RBAC, Hashid encoding, Polymorphic attachment authorization, Turnstile/2FA integration, and multi-device session governance. Core material calculations and price snapshot conversions are strictly enforced on the backend.

However, **production deployment must be blocked** due to:
1. **Deployment Asset Sync & Configuration Gap (`.cpanel.yml`):** The automated cPanel deployment script omits the `public/build` directory containing compiled Vite CSS/JS assets, uses hardcoded user directory paths (`/home/adaw2196`), and lacks post-deployment hook orchestration (`migrate`, `cache`, `queue:restart`).
2. **Database Migration Rollback Block (`2026_08_28_000002`):** The rollback method `down()` throws an unhandled `RuntimeException` if any quotation item has `price_per_kg IS NULL`, permanently disabling automated zero-downtime rollback once unavailable item offers are saved.
3. **Stale Test Suite Regressions (`NotificationDeliveryTest.php`):** Two tests in the full test suite fail because test fixtures did not populate `QuotationItem` records, colliding with newly enforced `hasAvailableItems()` business validation rules.
4. **Repository & Supply Chain Hygiene:** Direct inclusion of 16.6 MB vendor zip files in `deployment/`, dangling patch files (`auth-hardening.patch`), untracked root prototypes (`custom-datepicker.html`), and wildcard `*` Composer dependencies.

---

## 2. Status per Category A–Q

| Category | Status | Critical | High | Medium | Low | Notes / Summary |
|---|---|:---:|:---:|:---:|:---:|---|
| **A. Security** | `OK` | 0 | 0 | 0 | 0 | Robust RBAC, strict session controls, Turnstile, rate limiting, and SQL injection safety verified. |
| **B. Business Logic** | `ISSUE` | 0 | 0 | 1 | 0 | Core logic sound; availability validation correctly enforced server-side, but collided with legacy test fixtures. |
| **C. Maintainability** | `ISSUE` | 0 | 0 | 1 | 1 | Committed zip archives (16.6 MB vendor zip), dangling patch/prototype files in root, duplicate seeders in docs. |
| **D. Performance** | `OK` | 0 | 0 | 0 | 0 | Server-side DataTables, snapshot rate joins, subqueries for PO dashboard, redundant indexes removed. |
| **E. NFR / Scalability** | `NEED-REVIEW` | 0 | 0 | 0 | 0 | cPanel shared architecture lacks cloud elasticity; provisional capacity models S1/S2/S3 apply. |
| **F. Error Handling** | `OK` | 0 | 0 | 0 | 1 | Database transaction rollbacks sound; minor stream resource cleanup enhancement in upload controllers. |
| **G. Testing Quality** | `ISSUE` | 0 | 0 | 1 | 0 | 321 tests pass, 2 fail in `NotificationDeliveryTest` due to bare Quotation fixtures without items. |
| **H. File Upload & Storage** | `OK` | 0 | 0 | 0 | 1 | Private disk storage, mime/size checks, polymorphic policy checks verified; stream try-finally recommended. |
| **I. Third-Party Integrations** | `OK` | 0 | 0 | 0 | 0 | Pusher Echo with 30s polling fallback; Turnstile timeouts handled; SMTP mail client configured. |
| **J. Database & Migrations** | `ISSUE` | 0 | 1 | 0 | 0 | Migration `2026_08_28_000002` `down()` aborts rollback if null prices exist in production. |
| **K. Accessibility & UI** | `OK` | 0 | 0 | 0 | 0 | Prefixed `tw-` Tailwind with Bootstrap compatibility layer, Lucide icon component, no compiler leakage. |
| **L. Rate Limit & Session** | `OK` | 0 | 0 | 0 | 0 | Multi-device 3-session cap, absolute 8h timeout, lockout alerts, and device fingerprinting active. |
| **M. Observability & Logging** | `ISSUE` | 0 | 0 | 0 | 1 | `RoleMiddleware` logs 403 at ERROR severity with raw `fullUrl()`, generating noise in log monitoring. |
| **N. Backup & DR** | `NEED-REVIEW` | 0 | 0 | 0 | 0 | No automated cron backup runbook in repository; DB rollback vs App rollback distinction must be operated manually. |
| **O. Legal & PDP** | `NEED-REVIEW` | 0 | 0 | 0 | 0 | PII (NPWP, phone, email, logs) processed; requires formal data retention and business privacy policy signoff. |
| **P. Documentation** | `OK` | 0 | 0 | 0 | 1 | Extensive guides in `docs/`; minor architecture drift in `AGENTS.md` noted and documented in `CLAUDE.md`. |
| **Q. Infrastructure Cost** | `OK` | 0 | 0 | 0 | 0 | Low operational footprint on cPanel / MySQL; background jobs isolated to database queue. |

---

## 3. All Issues (Ranked by Severity)

### [HIGH] DB-001: Migration Rollback Block in `2026_08_28_000002_add_offer_fields_to_quotation_items_table.php`
- **Category:** J (Database & Migrations) / N (Backup & Disaster Recovery)
- **Status:** `ISSUE`
- **Severity:** `HIGH`
- **Location:** [`database/migrations/2026_08_28_000002_add_offer_fields_to_quotation_items_table.php:40-46`](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_08_28_000002_add_offer_fields_to_quotation_items_table.php#L40-L46)
- **Evidence:**
  ```php
  $nullPrices = DB::table('quotation_items')->whereNull('price_per_kg')->count();
  if ($nullPrices > 0) {
      throw new RuntimeException(
          'Cannot restore quotation_items.price_per_kg to NOT NULL while unavailable rows have null prices. '
          .'Resolve those rows or deploy the forward schema again before rolling back.'
      );
  }
  ```
- **Impact:** Once suppliers submit quotations with unavailable items (`is_available = false`, saving `price_per_kg = NULL`), executing `php artisan migrate:rollback` will throw a fatal `RuntimeException` and abort the rollback operation midway.
- **Root Cause:** The `down()` method enforces a strict pre-condition check to avoid MySQL errors when altering the column back to `NOT NULL`, but halts automated deployment/rollback scripts without a fallback data sanitization strategy.
- **Recommendation:**
  1. In the deployment runbook, document that rollback of this migration requires a data backfill script (e.g. updating `NULL` prices to `0` before rolling back) or archival.
  2. Implement an optional `--force-backfill` pattern or explicit data cleanup step in rollback procedures.
- **Verification Method:**
  `VERIFIED`: Static inspection of migration code and verification of exception path against populated database rows.
- **Merge/Deploy Gate:** `BLOCK FOR PRODUCTION ROLLBACK AUTOMATION`

---

### [HIGH] DEP-001: Incomplete Deployment Synchronization in `.cpanel.yml`
- **Category:** Deployment / Runtime / Maintainability
- **Status:** `ISSUE`
- **Severity:** `HIGH`
- **Location:** [`.cpanel.yml:5-35`](file:///c:/laragon/www/adasi_portal_supplier/.cpanel.yml#L5-L35)
- **Evidence:**
  ```yaml
  - export APPPATH=/home/adaw2196/supplierportal
  - export WEBPATH=/home/adaw2196/public_html
  ...
  - /bin/cp public/.htaccess $WEBPATH/.htaccess
  - /bin/cp public/favicon.ico $WEBPATH/favicon.ico
  - /bin/cp public/robots.txt $WEBPATH/robots.txt
  - /bin/mkdir -p $WEBPATH/assets
  - /bin/cp -a public/assets/. $WEBPATH/assets/
  ```
- **Impact:**
  1. `public/build/` (compiled Vite assets containing `manifest.json`, `app-*.css`, `app-*.js`, `cally-*.js`) is **not copied** to `$WEBPATH`, causing 404 asset failures and unstyled pages in production if deployed via cPanel Git version control.
  2. Hardcoded path `/home/adaw2196/` causes deployment failure if deployed to any other cPanel user or staging account.
  3. No post-deploy commands exist to trigger `php artisan optimize:clear`, `php artisan config:cache`, `php artisan route:cache`, or `php artisan queue:restart`.
- **Root Cause:** Deployment YAML was written for static asset directories only (`public/assets`) prior to Vite bundle integration.
- **Recommendation:**
  1. Update `.cpanel.yml` to create `$WEBPATH/build` and copy `public/build/.` to `$WEBPATH/build/`.
  2. Parameterize or document account-specific paths.
  3. Include necessary cache and queue worker restart hooks in the deployment guide.
- **Verification Method:**
  `VERIFIED`: File comparison between `public/build` output and `.cpanel.yml` copy directives.
- **Merge/Deploy Gate:** `BLOCK FOR CPANEL AUTOMATED DEPLOY`

---

### [MEDIUM] TEST-001: Test Fixture Incompatibility in `NotificationDeliveryTest.php`
- **Category:** G (Testing Quality) / B (Business Logic)
- **Status:** `ISSUE`
- **Severity:** `MEDIUM`
- **Location:** [`tests/Feature/NotificationDeliveryTest.php:196-202`](file:///c:/laragon/www/adasi_portal_supplier/tests/Feature/NotificationDeliveryTest.php#L196-L202) and [`tests/Feature/NotificationDeliveryTest.php:233-246`](file:///c:/laragon/www/adasi_portal_supplier/tests/Feature/NotificationDeliveryTest.php#L233-L246)
- **Evidence:**
  ```text
  FAILED Tests\Feature\NotificationDeliveryTest > direct quotation review actions notify supplier
  Failed asserting that actual size 1 matches expected size 3. (at tests\Feature\NotificationDeliveryTest.php:212)

  FAILED Tests\Feature\NotificationDeliveryTest > po arrival and document events have entity payloads and same status is silent
  ModelNotFoundException: No query results for model [App\Models\PurchaseOrder]. (at vendor\laravel\framework\src\Illuminate\Database\Eloquent\Builder.php:780)
  ```
- **Impact:** Full test suite reports 2 test failures out of 323 tests during automated CI/CD runs.
- **Root Cause:** Commit `9b0d580` introduced strict quotation availability invariant checks: `Quotation::hasAvailableItems()` in `QuotationListController@accept`, `reject`, and `PurchaseOrderController@store`. The legacy test created bare `Quotation` records without any `QuotationItem` children, causing `accept`/`reject` to return redirect with error (no notification dispatched) and `PurchaseOrderController@store` to reject PO creation without saving a PO record.
- **Recommendation:**
  Update test fixtures in `NotificationDeliveryTest.php` to include valid `QuotationItem` rows so the test reflects real-world domain requirements.
- **Verification Method:**
  `VERIFIED`: Reproduced in test run task-57 and confirmed via code inspection of `QuotationListController.php` and `PurchaseOrderController.php`.
- **Merge/Deploy Gate:** `BLOCK FOR MERGE TO MASTER / CI GREEN REQUIREMENT`

---

### [MEDIUM] DEP-002: Unbound Wildcard Version Constraints in `composer.json`
- **Category:** Dependency / Supply Chain
- **Status:** `ISSUE`
- **Severity:** `MEDIUM`
- **Location:** [`composer.json:20-25`](file:///c:/laragon/www/adasi_portal_supplier/composer.json#L20-L25)
- **Evidence:**
  ```json
  "barryvdh/laravel-dompdf": "*",
  "laravel/reverb": "*",
  "maatwebsite/excel": "*",
  "vinkla/hashids": "*"
  ```
  `composer validate --strict` output:
  ```text
  - require.barryvdh/laravel-dompdf : unbound version constraints (*) should be avoided
  - require.laravel/reverb : unbound version constraints (*) should be avoided
  - require.maatwebsite/excel : unbound version constraints (*) should be avoided
  - require.vinkla/hashids : unbound version constraints (*) should be avoided
  ```
- **Impact:** While `composer.lock` currently pins versions for standard installation, accidental `composer update` or lockfile regeneration can pull breaking upstream major versions unexpectedly.
- **Root Cause:** Wildcard packages added during early scaffolding.
- **Recommendation:** Pin exact semantic version constraints matching `composer.lock` (e.g. `^3.1` for dompdf, `^3.1` for excel, `^12.0` for hashids).
- **Verification Method:** `VERIFIED` via `composer validate --strict`.
- **Merge/Deploy Gate:** `NEED-REMEDIATION BEFORE NEXT COMPOSER UPDATE`

---

### [MEDIUM] REP-001: Large Binary Archives & Loose Files in Repository Root
- **Category:** C (Maintainability) / Security & Supply Chain
- **Status:** `ISSUE`
- **Severity:** `MEDIUM`
- **Location:** [`deployment/`](file:///c:/laragon/www/adasi_portal_supplier/deployment), [`auth-hardening.patch`](file:///c:/laragon/www/adasi_portal_supplier/auth-hardening.patch), [`custom-datepicker.html`](file:///c:/laragon/www/adasi_portal_supplier/custom-datepicker.html)
- **Evidence:**
  - `deployment/adasi-production-vendor-hotfix-20260823.zip` (16.6 MB vendor zip committed to git).
  - 4 other deployment zip hotfixes in `deployment/`.
  - 1,113-line `auth-hardening.patch` file in root.
  - 499-line standalone prototype `custom-datepicker.html` in root.
  - Absence of `.dockerignore` causes `Dockerfile` (`COPY . .`) to copy these large archives and `.git` into container builds.
- **Impact:** Repository bloat, risk of deploying stale vendor binaries, potential credential or metadata leakage in docker images.
- **Recommendation:**
  1. Remove binary zip files from Git tracking; store release archives in GitHub Releases or external artifact storage.
  2. Move or clean up `auth-hardening.patch` and `custom-datepicker.html`.
  3. Create `.dockerignore` excluding `.git`, `deployment/`, `tests/`, `docs/`, and `node_modules/`.
- **Verification Method:** `VERIFIED` via directory inspection and git tracking verification.
- **Merge/Deploy Gate:** `PRE-DEPLOY CLEANUP`

---

### [LOW] OBS-001: Excessive Log Severity & URL Query Logging in `RoleMiddleware`
- **Category:** M (Observability & Logging)
- **Status:** `ISSUE`
- **Severity:** `LOW`
- **Location:** [`app/Http/Middleware/RoleMiddleware.php:24-28`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Middleware/RoleMiddleware.php#L24-L28)
- **Evidence:**
  ```php
  Log::error('RoleMiddleware 403:', [
      'user_role' => $request->user()->role,
      'expected_roles' => $roles,
      'url' => $request->fullUrl(),
  ]);
  ```
- **Impact:**
  1. Legitimate unauthorized attempts (e.g. user navigation mistakes) trigger `error` level logs, generating noise in error monitoring alarms (Sentry / Log watchdogs).
  2. `fullUrl()` logs raw query parameters, which could inadvertently capture sensitive query values.
- **Recommendation:**
  Change log level to `warning` or `info`, and log `$request->path()` instead of `$request->fullUrl()`.
- **Verification Method:** `VERIFIED` via static code review of `RoleMiddleware.php`.
- **Merge/Deploy Gate:** `NON-BLOCKING QUALITY IMPROVEMENT`

---

### [LOW] UPL-001: File Stream Resource Exception Safety in Upload Handlers
- **Category:** H (File Upload & Storage) / F (Error Handling)
- **Status:** `ISSUE`
- **Severity:** `LOW`
- **Location:** [`app/Http/Controllers/Supplier/QuotationController.php:867-870`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Supplier/QuotationController.php#L867-L870) and [`app/Http/Controllers/Supplier/ClaimController.php:101-105`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Supplier/ClaimController.php#L101-L105)
- **Evidence:**
  ```php
  $stream = fopen($file->getPathname(), 'r');
  if ($stream) {
      Storage::disk('private')->put($path, $stream);
      fclose($stream);
  }
  ```
- **Impact:** If `Storage::disk('private')->put()` throws an unhandled exception (e.g. disk quota full), `$stream` handle remains open until PHP garbage collection. (In contrast, `QcInspectionController.php:364-368` correctly uses `try ... finally { fclose($stream); }`).
- **Recommendation:** Standardize upload handling across all controllers using `try ... finally { fclose($stream); }`.
- **Verification Method:** `VERIFIED` via code comparison between controllers.
- **Merge/Deploy Gate:** `NON-BLOCKING CODE QUALITY`

---

## 4. Top 3 Blocking Issues

| Rank | Issue ID | Component | Risk Factor (Impact × Likelihood × Exposure) |
|---|---|---|---|
| **#1** | **DEP-001** | `.cpanel.yml` / Deployment | **Broken Production Frontend & Assets** (Vite build assets omitted from sync, hardcoded paths) |
| **#2** | **DB-001** | Migration `2026_08_28_000002` | **Irreversible Automated Rollback** (RuntimeException on rollback when null prices exist) |
| **#3** | **TEST-001** | `NotificationDeliveryTest.php` | **Failing CI/CD Full Test Suite** (Stale test fixtures failing domain availability invariants) |

### Detailed Breakdown of Top 3

#### 1. DEP-001: `.cpanel.yml` Missing Vite Build Asset Sync
- **Exact Location:** [`.cpanel.yml:31-35`](file:///c:/laragon/www/adasi_portal_supplier/.cpanel.yml#L31-L35)
- **Failure Scenario:**
  Developer pushes changes to `master` and triggers cPanel Git deployment. The script syncs PHP files and `public/assets/`, but **does not create or sync `$WEBPATH/build/`**. When users visit the site, browsers request `/build/assets/app-*.css` and `/build/assets/app-*.js` and receive HTTP 404. All enterprise UI styling, Alpine.js reactivity, and interactive dialogs fail immediately.
- **Affected Users:** All users across all roles (Purchasing, Supplier, QC, Admin).
- **Root Cause:** Outdated `.cpanel.yml` configuration from before Vite bundler integration.
- **Recommended Fix Layer:** Deployment automation configuration (`.cpanel.yml`).
- **Required Regression Test:** Verify that `$WEBPATH/build/manifest.json` and bundled assets exist post-deployment.
- **Release Verification:** Staging cPanel deployment test verifying that stylesheet and JavaScript bundles load with HTTP 200.

#### 2. DB-001: Migration Rollback Crash with Existing Unavailable Quotation Items
- **Exact Location:** [`database/migrations/2026_08_28_000002_add_offer_fields_to_quotation_items_table.php:40-46`](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_08_28_000002_add_offer_fields_to_quotation_items_table.php#L40-L46)
- **Failure Scenario:**
  In production, a supplier creates a quotation marking an item as `is_available = false` (`price_per_kg = NULL`). If an emergency rollback of the recent release is initiated via `php artisan migrate:rollback`, the migration's `down()` method counts `price_per_kg IS NULL` rows (>0) and throws `RuntimeException`. The rollback process terminates abruptly, leaving database migrations in an inconsistent partial state.
- **Affected Data:** `quotation_items` table and entire schema migration state.
- **Root Cause:** Strict guard clause in `down()` halts execution rather than providing a documented operational data migration path.
- **Recommended Fix Layer:** Migration script / Disaster recovery operational runbook.
- **Required Regression Test:** Unit/Feature test verifying migration rollback under populated database scenarios with documented data cleanup strategy.
- **Release Verification:** Execute rollback on a staging database populated with test data containing null prices.

#### 3. TEST-001: CI/CD Test Suite Failure in `NotificationDeliveryTest`
- **Exact Location:** [`tests/Feature/NotificationDeliveryTest.php:196-202`](file:///c:/laragon/www/adasi_portal_supplier/tests/Feature/NotificationDeliveryTest.php#L196-L202)
- **Failure Scenario:**
  Continuous Integration (CI) pipeline runs `php artisan test` before deployment. The suite fails with 2 errors in `NotificationDeliveryTest`, preventing automated build validation and blocking automated delivery pipelines.
- **Affected Users:** Engineering and DevOps release pipeline.
- **Root Cause:** Newly introduced business logic correctly requires quotations to possess at least one available `QuotationItem` before being accepted, rejected, or converted to a PO. Test fixtures created bare `Quotation` records without items.
- **Recommended Fix Layer:** Test suite fixtures (`tests/Feature/NotificationDeliveryTest.php`).
- **Required Regression Test:** Run `php artisan test tests/Feature/NotificationDeliveryTest.php` and verify 100% passing status.
- **Release Verification:** Full test suite execution returning `0` failures across all 323+ tests.

---

## 5. Security & Invariant Verification Deep Dive

### 5.1 Supplier Data Isolation
- **Status:** `VERIFIED - SECURE`
- **Invariant:** Suppliers can only view, download, mutate, and export their own records (`supplier_id = auth()->id()`).
- **Evidence:**
  - `QuotationController`: Scoped via `where('supplier_id', auth()->id())` and `Gate::authorize('view', $quotation)`.
  - `SupplierPurchaseOrderController`: Scoped via `where('supplier_id', auth()->id())`.
  - `Supplier/ClaimController`: Scoped via `where('supplier_id', auth()->id())`.
  - `SupplierPriceHistoryController` / `SupplierPriceHistoryBuilder`: Strictly restricts supplier queries to `auth()->id()`.
  - `AttachmentPolicy`: Explicitly validates attachable ownership matching `$user->id`.
  - `SupplierDataIsolationTest`: 11 feature tests executed and **100% PASSED**.

### 5.2 Authorization & Hashid Route Security
- **Status:** `VERIFIED - SECURE`
- **Invariant:** Models using `HasHashids` reject raw integer parameters and forged route keys with HTTP 404.
- **Evidence:**
  - `DecodeHashids` middleware strictly inspects `HASHED_PARAM_KEYS` (`id`, `pr_id`, `po_id`, `quotation_id`, `inspection_id`, `supplier_id`, `requisition`, `quotation`, `claim`, `user`).
  - Raw digits passed to hashed parameters trigger deliberate `abort(404)`.
  - `HashidUrlSecurityTest`: 5 feature tests executed and **100% PASSED**.

### 5.3 Authentication & Session Hardening
- **Status:** `VERIFIED - SECURE`
- **Evidence:**
  - Multi-device concurrent session limit (3 active sessions) enforced by `EnforceAuthSessionSecurity`.
  - Absolute session timeout (8 hours / 480 min) strictly validated.
  - Two-Factor Authentication (2FA TOTP with encrypted secrets and 8 single-use recovery codes) verified.
  - Password reset enumeration resistance and inactive account blocking verified.
  - `tests/Feature/Auth/`: 115 tests executed and **100% PASSED**.

### 5.4 Financial & Currency Conversion Invariants
- **Status:** `VERIFIED - SECURE`
- **Formula:**
  `Input: (price_per_kg, offered_total_weight, snapshot_rate_to_idr)`  
  `Formula: round(offered_total_weight * price_per_kg, 4) * snapshot_rate_to_idr`  
  `Rounding Mode: PHP_ROUND_HALF_UP (4 decimals)`
- **Authoritative Integrity:** Client-supplied `amount` is discarded; server recalculates amount based on authoritative weight and validated price.
- **Snapshot Integrity:** Price comparison and historical reports join `exchange_rates` on `exchange_rate_id` stored on the quotation/PO snapshot, preventing historical price fluctuations when current market rates change.

### 5.5 Queue & Async Export Reliability
- **Status:** `VERIFIED - RESILIENT`
- **Evidence:**
  - `ExportDispatcher`: Validates class against strict allowlist, enforces scalar arguments, and performs atomic transaction handoff on the database queue driver.
  - `ProcessExportJob`: Query chunking, progress tracking, and idempotent finalization verified.
  - `AsyncExportQueueTest`: 23 feature tests executed and **100% PASSED**.

---

## 6. Non-Functional Requirements (NFR) & Scalability Baseline

Since production traffic telemetry is not yet established, the following evaluation model is documented:

### Provisional Capacity Scenarios

| Parameter | Scenario S1 (Normal) | Scenario S2 (Busy) | Scenario S3 (Stress / Peak) | Status |
|---|:---:|:---:|:---:|---|
| **Concurrent Active Users** | 20 | 50 | 100+ | `ASSUMED` |
| **Aggregate RPS** | 1–2 req/s | ~5 req/s | 10+ req/s | `ASSUMED` |
| **Largest PR Line Items** | 10–50 items | 50–200 items | 500+ items | `ASSUMED` |
| **Simultaneous Exports** | 1–2 jobs | 3–5 jobs | 10+ jobs | `ASSUMED` |
| **Attachment Size Limit** | 5 MB (MTC) / 10 MB (NG) | 5 MB / 10 MB | 5 MB / 10 MB | `CONFIRMED (config)` |
| **Session Lifetime** | 480 min (8h) | 480 min (8h) | 480 min (8h) | `CONFIRMED (config)` |
| **Database Engine** | MySQL 8.0+ / MariaDB | MySQL 8.0+ / MariaDB | MySQL 8.0+ / MariaDB | `CONFIRMED` |
| **Database Queue Worker** | 1 single process | 1–2 processes | Multiple workers | `CONFIRMED (cPanel cron)` |
| **Cloud Elasticity / Autoscaling** | None | None | None | `CONFIRMED (Not Available)` |

> [!NOTE]
> On shared cPanel hosting, compute elasticity is unavailable. If aggregate load exceeds server cGroup/LVE limits, queue execution and PDF generation will experience queue latency. Capacity limits must be accepted operationally.

---

## 7. Verification Performed vs. Not Verified

### Verification Performed
- [x] Full Git diff inspection from base baseline (`08ee54c`) through HEAD (`afd7f67`).
- [x] Static inspection of all changed controllers, models, policies, migrations, routes, and config files.
- [x] `composer validate --strict` executed.
- [x] `composer audit` executed (0 CVE vulnerabilities).
- [x] `npm.cmd audit` executed (0 vulnerabilities).
- [x] `npm.cmd run build` executed (Vite production build verified in 3.42s).
- [x] `php artisan route:list` executed (172 routes mapped and inspected).
- [x] `php artisan migrate:status` executed (all 54 migrations confirmed).
- [x] Targeted test suites executed:
  - `tests/Feature/SupplierDataIsolationTest.php` (11 passed)
  - `tests/Feature/HashidUrlSecurityTest.php` (5 passed)
  - `tests/Feature/QuotationAvailabilityTest.php` (17 passed)
  - `tests/Feature/AsyncExportQueueTest.php` (23 passed)
  - `tests/Feature/Auth/` (115 passed)
- [x] Full test suite executed (`php artisan test` — 321 passed, 2 failed due to stale fixtures).

### Not Verified (Out of Scope for Local Audit)
- [ ] Real external SMTP delivery against a live mail server.
- [ ] Live Pusher WebSocket connection under network partition / outage.
- [ ] Live cPanel Cron runner under high production concurrency.
- [ ] Production database restore drill from physical mysqldump backup.
- [ ] Third-party external penetration test.

---

## 8. Final Merge / Deploy Gate Decision

### Verdict: 🔴 BLOCK — Do not deploy to production

**Conditions for Unblocking Production Deployment:**
1. **Fix `.cpanel.yml` (DEP-001):** Add `$WEBPATH/build` directory creation and sync `public/build/.` into `$WEBPATH/build/`.
2. **Update Test Fixtures (TEST-001):** Update `tests/Feature/NotificationDeliveryTest.php` to attach valid `QuotationItem` rows so full test suite passes with 0 failures.
3. **Document Migration Rollback Runbook (DB-001):** Include explicit data remediation steps for `2026_08_28_000002` in operational deployment documentation.
4. **Repository Cleanup (REP-001):** Remove `deployment/*.zip`, `auth-hardening.patch`, and `custom-datepicker.html` from Git tracking and add `.dockerignore`.
5. **Pin Composer Dependencies (DEP-002):** Replace wildcard `*` constraints in `composer.json` with explicit semantic versions matching `composer.lock`.

Once the 5 remediation items above are completed, the repository will meet all criteria for **✅ PASS — Production Ready**.
