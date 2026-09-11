# Local Supplier Invoice Portal — Implementation Report

Date: 2026-09-09

## 1. Implementation Summary

Implemented the Local Supplier invoice/AP bounded context inside the existing ADASI Supplier Portal. Import procurement remains on its existing routes and models; Local uses dedicated routes, policies, controllers, services, tables, queries, navigation, statuses, revisions, documents, receipts, notifications, and tests.

Supplier accounts remain `role = supplier`. Import and Local access are stored as `supplier_scopes`; existing suppliers are backfilled with the Import scope. Accounting and Finance are separate internal roles with Local Invoice access. A supplier with both scopes selects a portal context, while every protected endpoint still checks the database scope and ownership server-side.

The Local workflow is implemented as:

```text
submit → WAITING_PHYSICAL_DOCUMENT → physical verification → UNDER_REVIEW
       → revision / rejection / approval → payment scheduling → completion
```

Submission and receipt numbers use a locked yearly sequence. Invoice identity and receipt identity stay stable across revisions. Each revision stores its own immutable data and private document records; resubmission invalidates the previous physical verification and requires a new verification.

## 2. Files Changed

### Migrations

- `database/migrations/2026_09_08_000001_add_supplier_business_scopes.php`
- `database/migrations/2026_09_08_000002_create_local_invoice_domain.php`

The migrations add supplier scopes and payment-term master data, backfill existing suppliers with Import, and create isolated Local invoice, revision, document, receipt, status-history, physical-verification, and sequence tables.

### Models

- `LocalInvoice`, `LocalInvoiceRevision`, `LocalInvoiceDocument`, `LocalInvoiceReceipt`, `LocalInvoiceStatusHistory`, `LocalInvoicePhysicalVerification`, `SupplierScope`
- Updated `User` and `Supplier` relationships, scope helpers, payment-term fillable data, and history protection.

### Middleware and policies

- `SupplierScopeMiddleware` and `EnforceSupplierDomain`
- `LocalInvoicePolicy`, `LocalInvoiceDocumentPolicy`, `LocalInvoiceReceiptPolicy`
- Hashid decoding for Local invoice and document route parameters.

### Requests

- `StoreLocalInvoiceRequest`
- `ResubmitLocalInvoiceRequest`
- `WorkflowRequest`
- `InvoiceFilterRequest`

Validation covers manual invoice data, per-supplier invoice-number uniqueness, required and optional documents, MIME and 10 MB limits, immutable resubmission identity, workflow reasons, and schedule dates.

### Services

- `InvoiceSubmissionService`
- `InvoiceRevision` behavior within `InvoiceSubmissionService`
- `InvoiceDocumentService`
- `InvoiceWorkflowService`
- `InvoiceQuery`
- `InvoiceNotificationService`
- `PortalContext`

The services centralize transactions, locked transitions, sequence generation, file compensation, payment-term snapshots, filtered queries, notification delivery, and domain context resolution.

### Controllers

- `LocalSupplier\InvoiceController`
- `LocalSupplier\InformationController`
- `Accounting\InvoiceController`
- `Accounting\InvoiceWorkflowController`
- `Accounting\ReportController`
- `LocalInvoiceDocumentController`
- `LocalInvoiceReceiptController`
- `SupplierContextController`
- Updated Admin user management, export download, and notification controllers.

### Routes

- `routes/supplier-local.php`
- `routes/accounting.php`
- Updated `routes/web.php` to load the bounded contexts and require Import scope on existing Supplier routes.

Explicit workflow endpoints are used for physical verification, review, revision, rejection, approval, payment scheduling, and payment completion. There is no editable status endpoint.

### Views and components

- `resources/views/local-supplier/**`
- `resources/views/accounting/**`
- `resources/views/local-invoices/**`
- `resources/views/admin/users/supplier-scopes.blade.php`
- Updated application shell, sidebar, navbar, chat drawer, admin user forms, and `resources/css/app.css`.

The Local screens reuse ADASI layout, cards, form sections, status chips, date-picker components, alerts, loading states, tables, and print styling. Invoice lists use paginated server-side query filters for invoice/submission/PO/receipt, status, supplier, submitted dates, due dates, and overdue/payment history.

### Notifications and exports

- `RevisionRequiredNotification`
- Local notification URL handling in `NotificationUrlResolver`
- `LocalInvoicesExport`
- Local export registration and domain checks in `ExportDispatcher` and `ExportDownloadController`

New submission and resubmission notify Accounting/Finance; operational transitions notify the owning supplier; revision requests also queue the required email notification. Local export workers re-check actor authorization.

### Tests

- `tests/Feature/LocalInvoice/LocalInvoiceTest.php`
- `tests/Feature/LocalInvoice/LocalInvoiceMigrationTest.php`
- `tests/Feature/LocalInvoice/LocalInvoiceConcurrencyTest.php`
- `tests/Support/local-invoice-concurrency-worker.php`
- Browser smoke bootstrap, fixture, and router helpers under `tests/Support/`.

### Existing files modified

`UserController`, `User`, `Supplier`, `AppServiceProvider`, `bootstrap/app.php`, `StatusHelper`, `UserFactory`, `DatabaseSeeder`, shell and navigation views, export and notification infrastructure, and `resources/css/app.css` were changed only where needed to add scopes, internal roles, Local navigation, Local URL resolution, private export filtering, or shared context behavior.

## 3. Requirement Closure

| Area | Status | Evidence |
|---|---|---|
| Supplier scopes | PASS | Migration backfill, admin configuration, and Import/Local/BOTH matrix tests |
| Context isolation | PASS | Scope middleware, global shared-route guard, direct URL tests |
| Local invoice submission | PASS | Authoritative service transaction and submission tests |
| Private documents | PASS | Dedicated revision documents, private disk, MIME/size checks, authorization, compensation tests |
| Receipt | PASS | Stable receipt identity, private no-store receipt route, ownership tests |
| Physical verification | PASS | Required-file check, actor policy, verification history, transition tests |
| Review | PASS | Explicit start-review action and server-authoritative state checks |
| Revision | PASS | Immutable historical revisions, stable submission/receipt identity, fresh physical verification |
| Rejection | PASS | Required reason and terminal-state tests |
| Approval | PASS | Required valid physical documents and locked approval transition |
| Due-date calculation | PASS | Approval timestamp plus payment-term snapshot; master-term mutation test |
| Payment schedule | PASS | Manual scheduled date, filtered payment queue, repeat-action tests |
| Completion | PASS | Manual completion action and terminal-state tests |
| Notifications | PASS | In-app event keys, role-specific URLs, revision email test |
| Accounting/Finance | PASS | Read routes for Accounting/Finance and write routes limited to those roles |
| UI consistency | PASS | ADASI components, view compilation, production build, headless create-page smoke |
| Supplier isolation | PASS | Cross-supplier invoice, document, receipt, revision, and resubmit denial tests |
| Import regression | PASS | Full existing suite passed without Import-domain failures |

Technical deviations are deliberate: Local documents use the isolated table explicitly recommended by the plan to preserve revision history; invoice lists use the repository’s paginated server-rendered pattern because the plan makes DataTables optional; Admin has read access through policy while workflow writes remain Accounting/Finance-only.

## 4. Verification Evidence

Executed in the local MySQL test database `adasi_portal_test`:

- `php artisan test tests/Feature/LocalInvoice --compact` — **37 passed, 295 assertions, 31.80 seconds**.
- `composer run-script --timeout=0 test` — **595 passed, 5,419 assertions, 402.45 seconds**.
- `php artisan view:cache` — passed.
- `npm.cmd run build` — Vite production build passed.
- PHP syntax checks on 81 changed/new PHP files — passed.
- `php vendor/bin/pint --test` on 79 new/touched PHP files — passed. Existing CRLF-preserving `StatusHelper` and `DatabaseSeeder` files were excluded from the formatting-only check to avoid unrelated line-ending churn.
- `php artisan route:list --path=local --except-vendor` — 10 Local routes listed.
- `php artisan route:list --path=accounting --except-vendor` — 15 Accounting/Finance routes listed.
- `git diff --check` — passed; only normal Git CRLF conversion warnings were reported for existing files.

The migration test exercised supplier Import backfill, Local schema presence, uniqueness indexes, and the absence of an Import purchase-order foreign key. The concurrency tests used separate PHP worker processes to verify locked numbering and one-winner workflow transitions. A headless browser smoke loaded the authenticated Local submission page with the existing CDN design assets, found no failed requests or page errors, and confirmed the custom date-picker controls; no additional screenshots were generated for this verification pass.

The first full-suite attempt was stopped by Composer’s default 300-second process timeout without a reported test failure. It was rerun with `--timeout=0` and completed with the result above.

## 5. Remaining Risks

- The migrations were verified against the isolated test database, but they were not applied to a production or staging database during this task. Deployment still needs the normal migration/backfill change window and post-migration schema verification.
- No manual cross-browser visual review was performed; the UI evidence is the existing component contract suite, Blade cache/build checks, and the headless smoke described above.
