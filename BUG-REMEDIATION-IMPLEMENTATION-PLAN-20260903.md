# Bug Remediation Implementation Plan

**Project:** ADASI Portal Supplier  
**Repository:** `C:\laragon\www\adasi_portal_supplier`  
**Framework:** Laravel 12 / PHP 8.2 / MySQL 8  
**Plan Date:** 2026-09-03  
**Basis:** Repository-wide bug audit, second-pass verification, and confirmed business-rule decisions.

---

## 1. Purpose

This document defines the implementation plan for the verified defects and approved business-rule changes identified during the repository-wide audit.

The implementation objectives are:

- improve application correctness;
- preserve business-rule consistency;
- strengthen database integrity;
- prevent duplicate or inconsistent procurement transactions;
- improve validation and lifecycle handling;
- prevent regressions through targeted and full-suite testing;
- keep the implementation scope limited to the approved findings.

This is an implementation and remediation plan, not a new broad repository audit.

---

## 2. Required Agent Skills

Use the following skills during implementation:

```text
/systematic-debugging
/test-driven-development
/backend-security-coder
/find-bugs
/laravel-security-audit
```

Optional, only when an implementation issue becomes ambiguous:

```text
/bug-hunt-swarm
```

### Skill Responsibilities

| Skill | Primary Use |
|---|---|
| `/systematic-debugging` | Re-verify current behavior and trace affected code paths before changing them |
| `/test-driven-development` | Add or update regression tests around each approved change |
| `/backend-security-coder` | Handle transactions, validation, concurrency, data integrity, and backend safeguards |
| `/find-bugs` | Review the final implementation branch diff for regressions and risky changes |
| `/laravel-security-audit` | Final Laravel-specific review after implementation |
| `/bug-hunt-swarm` | Optional deep investigation if a verified assumption becomes inconsistent during implementation |

Do not use broad discovery-oriented skills as the primary implementation workflow. The discovery and verification phases are already complete.

---

## 3. Approved Scope

| ID | Final Treatment | Priority |
|---|---|---:|
| BUG-005 | Confirmed defect — implement fix | P0 |
| BUG-001 | Approved business-rule change — implement | P1 |
| BUG-007 | Confirmed defect — implement fix | P1 |
| BUG-004 | Confirmed validation defect — implement fix | P1 |
| BUG-003 | Partially confirmed — targeted lifecycle fix | P2 |
| BUG-002 | Defensive hardening + split-award design gate | P2 |
| BUG-006 | Defensive consistency only; not a runtime bug | P3 |

---

## 4. Confirmed Business Rules

The implementation must treat the following decisions as authoritative.

### 4.1 Quotation Availability

If every quotation item is `Not Available`:

- the quotation remains a valid supplier response;
- the quotation must no longer remain in `submitted`;
- the system must automatically assign a new status: `all_unavailable`;
- Purchasing does not need a manual action to move the quotation into this state;
- Purchasing must still be able to request a revision;
- after revision, the quotation status must be recalculated from the new availability state;
- if all invited suppliers are unable to supply, Purchasing proceeds with revision/re-bid.

### 4.2 Quotation Revision

For one supplier and one PR:

- there is only one quotation record;
- quotation revision updates the existing quotation;
- revisions must not create a second quotation record for the same supplier + PR.

### 4.3 PO Consolidation

Current approved behavior:

- one PO may combine quotations originating from different PRs;
- the quotations consolidated into one PO must continue to follow the current same-supplier requirement unless separately redesigned;
- a single PR may conceptually be split across multiple suppliers, but this must not be implemented implicitly as part of this bug-remediation batch without confirming the existing item-level allocation architecture.

### 4.4 Quotation-to-PO Invariant

A quotation may belong to a maximum of one PO.

This is a hard business/data-integrity invariant.

### 4.5 Notification ID Comparison

The previous BUG-006 runtime issue was disproved.

Integer casting may still be added for defensive consistency, but it must be treated as hardening rather than a defect fix.

---

## 5. Engineering Rules

All changes must follow these rules:

1. Apply the smallest correct change.
2. Preserve existing behavior unless this plan explicitly changes it.
3. Fix root causes instead of hiding symptoms.
4. Protect important invariants at both application and database layers where practical.
5. Avoid unrelated refactoring.
6. Avoid new dependencies unless strictly necessary.
7. Every defect/change must have regression coverage.
8. Database changes must use migrations.
9. Do not silently alter or delete existing production data.
10. Verify current code before applying assumptions from this document.
11. Complete and verify one work package before proceeding to the next.
12. Do not claim completion based only on code inspection.

---

# 6. Phase 0 — Baseline and Current-State Verification

Before changing source code:

```text
/systematic-debugging
```

Read/reinspect the current implementation, including at minimum:

```text
AGENTS.md
README.md
composer.json
package.json

app/Models/Quotation.php
app/Models/PurchaseOrder.php
app/Models/PurchaseRequisition.php
app/Models/QuotationItem.php
app/Models/Attachment.php
app/Models/User.php

app/Http/Controllers/Supplier/QuotationController.php
app/Http/Controllers/Purchasing/PurchaseOrderController.php
app/Http/Controllers/Purchasing/QuotationListController.php
app/Http/Controllers/ConversationMessageController.php
app/Http/Controllers/ProfileController.php
app/Http/Controllers/Purchasing/PoDocumentController.php

app/Services/NotificationUrlResolver.php

relevant migrations
relevant policies
relevant Blade views
relevant tests
```

Capture a baseline:

```bash
git status
git diff
php artisan test
```

Record:

- passed tests;
- failed tests;
- skipped tests;
- error count;
- duration;
- any pre-existing failures.

Pre-existing failures must not be incorrectly attributed to this implementation.

---

# 7. WP-01 — BUG-005: Enforce One Quotation → One PO

**Priority:** P0  
**Type:** Confirmed data-integrity/concurrency defect

## 7.1 Objective

Guarantee:

```text
ONE QUOTATION
      ↓
MAXIMUM ONE PO
```

The invariant must hold even when multiple requests arrive concurrently.

## 7.2 Transaction Boundary

The current conceptual anti-pattern is:

```text
SELECT quotation
↓
check existing PO
↓
BEGIN TRANSACTION
↓
create PO
```

Change it to:

```text
BEGIN TRANSACTION
↓
reload quotation rows
↓
lock rows
↓
revalidate
↓
create PO
↓
attach quotations
↓
update statuses
↓
COMMIT
```

Critical state checks must occur inside the transaction.

## 7.3 Deterministic Pessimistic Locking

Load selected quotations using deterministic ordering before locking:

```text
orderBy('id')
→ lockForUpdate()
```

This reduces inconsistent lock ordering when two requests contain overlapping quotation sets.

## 7.4 Revalidate Inside the Lock

After locking, verify again:

- every requested quotation still exists;
- loaded quotation count matches the request;
- quotation IDs are distinct;
- quotation status is still eligible;
- all selected quotations satisfy the current supplier-consistency rule;
- no selected quotation is already attached to another PO.

Do not rely on a pre-transaction eligibility check for mutable database state.

## 7.5 Database-Level Protection

Add a migration enforcing uniqueness of:

```text
po_quotations.quotation_id
```

The existing composite uniqueness:

```text
UNIQUE(po_id, quotation_id)
```

does not enforce the approved rule.

Target invariant:

```text
UNIQUE(quotation_id)
```

## 7.6 Migration Preflight

Before adding the unique constraint, verify existing data:

```sql
SELECT quotation_id, COUNT(*) AS total
FROM po_quotations
GROUP BY quotation_id
HAVING COUNT(*) > 1;
```

If duplicate records exist:

```text
STOP MIGRATION / DEPLOYMENT
```

Do not automatically delete or consolidate them.

Report the affected records for manual/business reconciliation.

## 7.7 Graceful Conflict Handling

The DB constraint is the last line of defense.

If a stale/concurrent request reaches a uniqueness violation:

- do not expose a raw SQL exception;
- do not create a partial PO;
- rollback the transaction;
- return a business-level message such as:

```text
The selected quotation has already been assigned to a Purchase Order.
Please refresh the page and try again.
```

## 7.8 Regression Tests

Add/update coverage for:

- normal single-quotation PO creation;
- multi-PR consolidation with the same supplier;
- quotation already attached to a PO;
- duplicate quotation ID in request;
- DB-level duplicate `quotation_id` rejection;
- repeated PO submission;
- transaction rollback on failure;
- no partial pivot records after failure;
- existing PO numbering behavior remains correct.

Where concurrency behavior is tested, use an isolated test database rather than persistent development data.

## 7.9 Acceptance Criteria

The following must always hold:

```text
COUNT(po_quotations WHERE quotation_id = X) <= 1
```

The invariant must not depend exclusively on UI behavior.

---

# 8. WP-02 — BUG-001: Add Automatic `all_unavailable` Quotation State

**Priority:** P1  
**Type:** Approved business-rule change / state-machine correction

## 8.1 New State

Introduce:

```text
all_unavailable
```

as a valid quotation state.

The implementation must first identify the current authoritative status definition:

- DB ENUM;
- PHP enum;
- model constants;
- validation rules;
- query/filter lists.

Do not create multiple competing sources of truth.

## 8.2 Automatic Status Determination

When supplier submission/re-submission is finalized:

```text
Has at least one available item?
          │
      ┌───┴───┐
     YES      NO
      │        │
 submitted  all_unavailable
```

The supplier must not manually select `all_unavailable`.

Reuse existing domain logic such as `hasAvailableItems()` if it correctly represents the rule.

## 8.3 Revision Behavior

Required transition:

```text
all_unavailable
      ↓
Request Revision
      ↓
revision_requested
```

After supplier re-submission:

```text
revision_requested
        ↓
re-evaluate items
        ↓
   ┌────┴────┐
available    none
   ↓          ↓
submitted  all_unavailable
```

## 8.4 Reject Behavior

Do not simply replace the current rule with:

```text
all_unavailable → rejected
```

The agreed behavior is automatic classification, not manual rejection.

Purchasing does not require a Reject action solely to close an all-unavailable quotation.

## 8.5 Purchasing UI and Presentation

Review/update all status presentation paths:

- quotation list;
- quotation detail;
- DataTables;
- filters;
- dashboard counters;
- badges;
- conversation quick actions;
- notification text/links where status-sensitive;
- reporting/export queries if they group quotation statuses.

Display label:

```text
All Unavailable
```

Do not display it as `Submitted`.

## 8.6 PR-Level Behavior

If:

```text
Supplier A → all_unavailable
Supplier B → all_unavailable
Supplier C → all_unavailable
```

the system must not treat the PR as successfully completed merely because all quotations have reached a non-submitted state.

The PR must remain actionable for Purchasing to perform revision/re-bid.

Prefer preserving the existing PR model/status architecture unless a new PR state is genuinely required.

## 8.7 Existing Tests

An existing test currently codifies the old behavior.

Do not merely delete it.

Replace/update it to represent the approved rule.

## 8.8 Regression Tests

Cover:

- all available → `submitted`;
- partially available → `submitted`;
- all unavailable → `all_unavailable`;
- all-unavailable status is system-generated;
- `all_unavailable` cannot be accepted;
- `all_unavailable` can receive Request Revision;
- revised quotation with available item → `submitted`;
- revised quotation still unavailable → `all_unavailable`;
- no manual reject is required;
- dashboard/list/filter behavior recognizes the new status;
- relevant conversation quick actions remain correct.

---

# 9. WP-03 — BUG-007: Safe Account Self-Deletion

**Priority:** P1  
**Type:** Confirmed functional/data-integrity defect

## 9.1 Objective

Current dangerous order:

```text
logout
↓
delete user
↓
DB may reject deletion
```

Required order:

```text
validate deletion eligibility
↓
attempt deletion safely
↓
success?
 ├─ no → preserve session + show error
 └─ yes → logout + invalidate session
```

## 9.2 Dependency Pre-check

Before logout, identify whether the user participates in transactional records that prevent hard deletion.

The pre-check exists for UX.

Do not consider it a replacement for database constraints.

## 9.3 Database Exception Fallback

If deletion is rejected despite the pre-check:

- catch the relevant failure at an appropriate boundary;
- keep the user authenticated;
- preserve the user row;
- return a controlled validation/business error;
- do not expose internal database details.

## 9.4 Preserve Clean-Account Behavior

Unless separate product requirements say otherwise, a clean user with no blocking relationships should retain the existing self-deletion capability.

Do not silently redesign account lifecycle into soft-delete/deactivation as part of this work package.

## 9.5 Regression Tests

Cover:

- clean user can delete account;
- user with quotation cannot self-delete;
- user with relevant procurement history cannot self-delete;
- blocked deletion does not produce HTTP 500;
- blocked deletion preserves authentication;
- successful deletion logs the user out;
- session invalidation happens only after successful deletion;
- database constraint fallback returns a controlled response.

---

# 10. WP-04 — BUG-004: Validate PO Document Status Server-Side

**Priority:** P1  
**Type:** Confirmed validation defect

## 10.1 Allowed Values

Current valid statuses:

```text
pending
received
verified
issued
processing
done
```

## 10.2 Implementation

Server-side validation must enforce the allowed values.

Use an existing central definition if one exists.

If none exists and reuse is limited, a simple:

```php
Rule::in([...])
```

is sufficient.

Do not introduce a new enum abstraction only for stylistic reasons.

## 10.3 Regression Tests

Cover all valid values.

Also test:

```text
invalid_value
null
empty string
integer value
unexpected string
```

Expected invalid behavior:

```text
validation error / HTTP 422
DB unchanged
no QueryException
```

---

# 11. WP-05 — BUG-003: Correct MTC Replacement Lifecycle

**Priority:** P2  
**Type:** Partially confirmed attachment lifecycle defect

## 11.1 Preserve Existing Relinking

Standard quotation edits already preserve existing MTC attachments by re-linking them to recreated quotation items.

Do not break this behavior.

## 11.2 Replacement Scenario

Required lifecycle when a supplier uploads a replacement MTC:

```text
existing MTC
     +
new MTC upload
     ↓
new attachment becomes current
     ↓
old attachment becomes superseded
     ↓
old DB attachment cleaned
     ↓
old physical file cleaned safely
```

## 11.3 Transaction Safety

Do not delete the existing physical file before the database operation is known to have succeeded.

Filesystem actions do not automatically roll back with a DB transaction.

Preferred sequence:

```text
capture existing attachment metadata
↓
perform quotation/item DB operations
↓
store/register replacement
↓
successful DB completion
↓
remove superseded attachment record using project lifecycle conventions
↓
perform physical cleanup after safe commit boundary
```

If physical cleanup fails after a successful business transaction:

- do not corrupt/rollback the valid quotation;
- log the cleanup failure;
- keep authorization intact;
- avoid showing raw filesystem errors to supplier.

## 11.4 Regression Tests

Cover:

- initial MTC upload;
- normal edit without replacement retains attachment;
- normal item recreation correctly re-links attachment;
- replacement creates a new valid attachment;
- superseded DB attachment is removed;
- superseded file is cleaned;
- failed quotation operation does not destroy the previously valid attachment;
- attachment download authorization remains correct.

---

# 12. WP-06 — BUG-002: Defensive PO Consolidation Hardening

**Priority:** P2  
**Type:** Defensive correctness hardening

The verified defect requires an abnormal condition: multiple quotations for the same supplier + PR.

The normal supplier workflow maintains one quotation per supplier per PR.

The implementation should harden the backend without pretending this is a normal business path.

## 12.1 Request-Level Hardening

Ensure quotation IDs themselves are distinct.

After loading quotation models, verify the batch does not contain invalid duplicate domain combinations.

At minimum reject a batch containing multiple quotations with the same:

```text
supplier_id + pr_id
```

when that combination violates current data rules.

## 12.2 Selected Quotations Must Never Be Auto-Rejected

Auto-rejection logic must never include quotations selected for the PO.

Instead of excluding only the current loop item, derive:

```text
selectedIds
```

and ensure rejection queries exclude all selected quotation IDs.

Conceptual invariant:

```text
Selected for PO
→ cannot be auto-rejected by the same PO creation transaction
```

## 12.3 Preserve Multi-PR Consolidation

Do not break the existing ability to combine quotations from different PRs into one PO when they satisfy the current same-supplier rule.

## 12.4 Split-Award Design Gate

The confirmed business requirement states that one PR may be divided across multiple suppliers.

However, this can require substantial item-level domain support.

Before changing the current PR completion or auto-rejection behavior, inspect whether the repository already supports:

- item-level award selection;
- awarded quantity;
- remaining quantity;
- partial PR completion;
- per-item selected supplier;
- per-item PO allocation.

### If item-level split-award support already exists

Integrate the PO status/rejection logic with that existing architecture.

Do not reject a quotation merely because another supplier wins a different PR item.

Do not mark a PR completed until the actual item-level completion condition is satisfied.

### If item-level split-award support does not exist

Do not silently build it as a side effect of BUG-002.

Document:

```text
Separate functional enhancement required:
Split-award / multi-supplier allocation within one PR
```

Keep the current remediation limited to defensive batch correctness.

## 12.5 Regression Tests

Cover:

- normal single quotation PO;
- multi-PR PO consolidation;
- selected quotation IDs remain accepted;
- non-selected eligible quotation behavior remains correct;
- duplicate request IDs rejected;
- invalid same-supplier/same-PR duplicate batch rejected;
- current same-supplier PO rule remains intact;
- no existing consolidation test regresses.

---

# 13. WP-07 — BUG-006: Defensive ID Casting Consistency

**Priority:** P3  
**Type:** Hardening / code consistency

BUG-006 was disproved as a runtime defect.

Do not describe the change as correcting a production failure.

Apply defensive casting only to the relevant ownership comparisons in `NotificationUrlResolver`:

```php
(int) $model->supplier_id === (int) $user->id
```

Preserve existing null-handling semantics.

Do not run a repository-wide mechanical replacement of strict ID comparisons.

## Regression Testing

The complete existing `NotificationUrlResolverTest` must continue to pass.

No test should falsely assert that a previously reproduced runtime bug existed.

---

# 14. Test and Verification Strategy

## 14.1 Level 1 — Work-Package Tests

After each implementation package, run the relevant targeted tests.

Examples:

```bash
php artisan test --filter=PurchaseOrderReferenceRemarkTest
php artisan test --filter=QuotationAvailabilityTest
php artisan test --filter=ProfileTest
php artisan test --filter=NotificationUrlResolverTest
```

Run newly added regression tests as part of the same package.

Do not proceed when a new unexplained failure exists.

## 14.2 Level 2 — Subsystem Regression

After PO work:

- Purchase Order tests;
- Quotation tests;
- PR workflow tests;
- relevant document tests.

After quotation-state work:

- quotation tests;
- conversation tests;
- PR tests;
- notification tests;
- dashboard/filter tests where applicable.

After account deletion work:

- profile/account tests;
- authentication/session tests;
- admin user-management tests if related behavior is shared.

## 14.3 Level 3 — Full Suite

Run:

```bash
php artisan test
```

Record:

```text
tests
assertions
passed
failed
errors
skipped
duration
```

Every failure must be categorized as:

```text
new regression
pre-existing failure
environment/setup issue
```

Do not report the suite as passing if failures exist.

## 14.4 Level 4 — Final Diff Review

After implementation:

```text
/find-bugs
```

Review the complete final branch diff for:

- functional regressions;
- transaction mistakes;
- state-machine inconsistencies;
- validation gaps;
- data-integrity regressions;
- forgotten tests;
- accidental unrelated changes.

Then perform:

```text
/laravel-security-audit
```

as the final Laravel-specific review of the changed implementation.

---

# 15. Migration Strategy

Expected migration areas:

```text
BUG-005
→ unique quotation_id on po_quotations

BUG-001
→ quotation status schema change if statuses are DB ENUM-based
```

## 15.1 Deployment Pre-checks

Before migration:

1. Confirm backup/recovery procedure.
2. Check duplicate `quotation_id` rows in `po_quotations`.
3. Verify the final production schema rather than relying on a historical migration.
4. Verify current quotation status values.
5. Confirm application and migration deployment order.

## 15.2 Existing Data

Do not automatically rewrite historical quotation statuses to `all_unavailable` unless there is a defined migration rule.

If backfilling is desired, first produce a query/report identifying rows where:

```text
quotation currently submitted
AND
all related quotation items unavailable
```

Require explicit approval before performing broad historical data transformation.

## 15.3 Rollback Safety

Migration `down()` behavior must account for possible rows already using:

```text
all_unavailable
```

Do not create a rollback path that fails or silently changes production states.

---

# 16. Implementation Sequence

Recommended execution order:

```text
PHASE 0
Baseline + current-state verification
        ↓

PHASE 1
BUG-005
PO concurrency + database invariant
        ↓

PHASE 2
BUG-001
all_unavailable state machine
        ↓

PHASE 3
BUG-007
safe account deletion
        ↓

PHASE 4
BUG-004
PO document validation
        ↓

PHASE 5
BUG-003
MTC replacement lifecycle
        ↓

PHASE 6
BUG-002
consolidation hardening
+ split-award design gate
        ↓

PHASE 7
BUG-006
defensive casting only
        ↓

PHASE 8
Targeted + subsystem + full regression
        ↓

/find-bugs
        ↓

/laravel-security-audit
        ↓

FINAL REVIEW
```

---

# 17. Definition of Done

The remediation batch is complete only when all applicable items below are satisfied.

## BUG-005

- quotation-to-PO uniqueness is enforced inside the application transaction;
- selected quotations are locked and revalidated;
- database prevents one quotation being assigned to multiple POs;
- duplicate data preflight exists for migration/deployment;
- duplicate/concurrent requests do not leave partial data;
- user receives controlled feedback rather than raw SQL errors.

## BUG-001

- `all_unavailable` exists as a valid quotation state;
- status is assigned automatically;
- partially/fully available quotation still becomes `submitted`;
- Purchasing can request revision from `all_unavailable`;
- revised quotation status is recalculated;
- UI/filter/reporting paths recognize the new state;
- PR remains actionable for revision/re-bid when appropriate.

## BUG-007

- dependency-blocked self-deletion does not produce HTTP 500;
- blocked deletion does not log the user out;
- successful deletion still invalidates the session correctly;
- DB integrity remains protected.

## BUG-004

- only defined PO document statuses are accepted;
- invalid values produce controlled validation errors;
- database state is unchanged after invalid input.

## BUG-003

- normal attachment relinking still works;
- replacement MTC does not leave stale DB attachments;
- superseded physical files are safely cleaned;
- transaction failure cannot destroy the previously valid file.

## BUG-002

- selected quotation IDs cannot reject one another;
- malformed duplicate domain combinations are rejected;
- current valid multi-PR consolidation remains functional;
- split-award support is either integrated with existing item-level architecture or explicitly deferred as a separate enhancement.

## BUG-006

- casting changes are limited to defensive consistency;
- existing resolver behavior remains unchanged;
- resolver test suite passes.

## Overall

- targeted tests pass;
- relevant subsystem tests pass;
- full test suite is evaluated;
- every failure is accounted for;
- `/find-bugs` finds no unresolved significant regression in the implementation diff;
- `/laravel-security-audit` finds no significant Laravel-specific regression caused by the changes;
- `git diff` contains no unrelated refactoring;
- no debug statements or temporary instrumentation remain;
- all migrations are reviewed for production-data safety.

---

# 18. Antigravity Implementation Prompt Header

Use this header when executing the plan:

```text
/systematic-debugging
/test-driven-development
/backend-security-coder

Implement the approved defect-remediation plan for the current
ADASI Portal Supplier Laravel repository.

This is an authorized internal application maintenance task.

Primary goals:
- application correctness
- business-rule consistency
- database integrity
- concurrency safety
- input validation
- attachment lifecycle correctness
- regression prevention

Do not perform a new broad repository audit.
Do not expand the scope beyond the approved findings.
Do not redesign unrelated architecture.
Do not introduce unnecessary dependencies.

Before changing each subsystem:
1. inspect the current implementation;
2. verify relevant assumptions against the current codebase;
3. implement the minimal correct change;
4. add or update regression tests;
5. run the relevant tests;
6. review the result before continuing.

Approved work:
- BUG-005: enforce one quotation → one PO;
- BUG-001: implement automatic all_unavailable quotation state;
- BUG-007: make account self-deletion safe;
- BUG-004: enforce valid PO document statuses;
- BUG-003: safely clean superseded MTC attachments;
- BUG-002: defensively harden PO consolidation, subject to the
  split-award design gate;
- BUG-006: defensive ID-casting consistency only; this is NOT
  a runtime bug.

Preserve existing behavior unless the implementation plan explicitly
changes it.

Do not claim completion until targeted tests and the full regression
suite have been evaluated.
```

---

# 19. Final Review Commands

After implementation:

```text
/find-bugs
```

Then:

```text
/laravel-security-audit
```

Do not begin unrelated remediation discovered during these final reviews without first reporting it separately.

---

# 20. Expected Planning Artifact Location

Recommended project location:

```text
C:\laragon\www\adasi_portal_supplier\docs\audits\BUG-REMEDIATION-IMPLEMENTATION-PLAN-20260903.md
```

This plan should remain the implementation reference for the remediation batch.
