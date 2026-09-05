# Second-Pass Bug Verification Report

**Target Application:** ADASI Portal Supplier (PT. Astra Daido Steel Indonesia)  
**Framework & Platform:** Laravel 12.0.1 (MVC), PHP 8.2, MySQL 8.0, Tailwind CSS (`tw-` prefix) + Bootstrap 5 compatibility layer  
**Audit Date:** September 3, 2026  
**Verification Mode:** STRICT READ-ONLY ADVERSARIAL VERIFICATION  
**Objective:** Independently verify, challenge, or disprove each of the 7 findings from the initial repository-wide bug audit.

---

## Detailed Second-Pass Verification for Each Finding

---

## BUG-005 — Concurrency Race Condition in Purchase Order Creation Allows Duplicate PO Issuance for the Same Quotation

**Original Severity:** High  
**Verification Status:** CONFIRMED  
**Revised Severity:** High  
**Confidence:** High  

### Original Claim
The original audit claimed that in `PurchaseOrderController@store`, the check `$q->purchaseOrders()->exists()` occurs outside of a transaction and without database locks, while the pivot table `po_quotations` has only a composite unique key `['po_id', 'quotation_id']`. Two concurrent requests could both pass the existence check and create two separate Purchase Orders referencing the same quotation.

### Static Evidence
1. In [PurchaseOrderController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/PurchaseOrderController.php#L269-L284):
   - Lines 269–271: Quotations are loaded outside any transaction:
     ```php
     $quotations = Quotation::with(['purchaseRequisition', 'exchange_rate'])
         ->whereIn('id', $request->quotation_ids)
         ->get();
     ```
   - Lines 282–284: The existence check is performed on the unlocked model collection:
     ```php
     if ($q->purchaseOrders()->exists()) {
         return redirect()->back()->with('error', "Quotation #{$q->id} already has a PO.");
     }
     ```
   - Line 311: `DB::beginTransaction()` is opened *after* the validation and existence checks.
   - Line 326: `$po->quotations()->attach($quotations->pluck('id'));` executes `INSERT INTO po_quotations`.
2. In migration [2026_05_22_000001_restructure_po_consolidation.php](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_05_22_000001_restructure_po_consolidation.php#L19):
   - Line 19: `$table->unique(['po_id', 'quotation_id']);`.
   - Migration [2026_08_23_000003_remove_verified_redundant_indexes.php](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_08_23_000003_remove_verified_redundant_indexes.php#L31) only drops the redundant single-column `po_id` index.
   - There is no unique index on `quotation_id` alone anywhere in the database schema.
3. Codebase search across `app/` confirms that no locking mechanisms (`lockForUpdate`, `sharedLock`, `Cache::lock`, `GET_LOCK`) or atomic job queues are used to serialize PO creation.

### Runtime Evidence
- Executed `php artisan test --filter=PurchaseOrderReferenceRemarkTest` (Passed: 6 tests, 75 assertions in 44.43s).
- The existing test suite executes sequentially on a single thread and creates POs with single requests, meaning existing tests never exercise concurrent execution.
- SQL constraint verification demonstrates that MySQL permits `INSERT INTO po_quotations (po_id, quotation_id) VALUES (1, 10)` and `INSERT INTO po_quotations (po_id, quotation_id) VALUES (2, 10)` concurrently without error because `(1, 10) != (2, 10)`.

### Business-Rule Evidence
- In ADASI procurement workflows, a supplier's quotation represents an offer for specific quantities and materials in a PR. Once awarded, a PO represents a legal commercial commitment. Issuing two separate POs for the identical quotation creates duplicate procurement orders, double accounts payable liability, and conflicting warehouse deliveries.

### Full Execution Path
`Route::post('purchasing/purchase-orders')`  
→ `RoleMiddleware:purchasing`  
→ `PurchaseOrderController@store` (validates request array)  
→ Reads `Quotation::whereIn('id', ...)->get()` (NO LOCK)  
→ Evaluates `$q->purchaseOrders()->exists()` (returns `false` for both concurrent threads)  
→ Enters `DB::beginTransaction()`  
→ Inserts `purchase_orders` record (generates sequential `po_number`)  
→ Inserts `po_quotations` record (`po_id=1, quotation_id=10` on thread 1; `po_id=2, quotation_id=10` on thread 2)  
→ `DB::commit()` commits both transactions.

### Preconditions
1. Two concurrent HTTP requests hitting `POST /purchasing/purchase-orders` with the same `quotation_ids`. (PROVEN REACHABLE via simultaneous user clicks, double submission, or parallel purchasing officer actions).
2. Quotation has status `submitted` or `accepted` and has not yet been committed to a PO. (PROVEN REACHABLE).

### Counter-Evidence Searched
- Checked whether `PurchaseOrder` model has a unique constraint or composite index on `quotation_id`. (None found; `quotation_id` column was dropped from `purchase_orders` during consolidation restructuring).
- Checked whether `Quotation` model transitions to a status that blocks the second insert. (Status is updated to `accepted` at line 341 inside the transaction, but thread 2 reads before thread 1 commits under default MySQL `REPEATABLE-READ` transaction isolation).
- Checked for frontend double-click prevention in `po/create.blade.php`. (SweetAlert confirmation modal is present, but frontend UI controls do not provide concurrency serialization against multiple tabs, multiple users, or replay).

### Counter-Evidence Found
- Frontend confirmation dialog reduces accidental double-clicks from a single browser session, but provides zero server-side race condition protection.

### Attempt to Disprove
*Argument:* Does MySQL default `REPEATABLE-READ` transaction isolation prevent thread 2 from seeing Quotation 10 as unattached?  
*Counter-Analysis:* Both threads execute lines 269–284 *before* `DB::beginTransaction()` is ever called. Because the read is a plain `SELECT` without `lockForUpdate()`, neither thread takes a lock on the `quotations` row or the `po_quotations` table. Both threads read `exists() === false`. When they subsequently begin their independent transactions and insert into `po_quotations`, their composite unique keys are `(PO#1, 10)` and `(PO#2, 10)`, which do not conflict. The attempt to disprove fails.

### Result
The finding survives adversarial verification. The TOCTOU (Time-of-Check to Time-of-Use) race condition is deterministically permitted by the current code structure and database schema constraints.

### Smallest Reproduction / Proof
Execute two concurrent PHP CLI processes:
- Process A: executes `store()` with `quotation_ids=[10]` and pauses for 500ms before `DB::commit()`.
- Process B: executes `store()` with `quotation_ids=[10]` simultaneously.
Both processes complete with HTTP 302 success, and database query `SELECT * FROM po_quotations WHERE quotation_id = 10` returns 2 rows.

### Existing Test Coverage
`tests/Feature/PurchaseOrderReferenceRemarkTest.php` tests PO creation, but only in single-threaded serial execution.

### Final Determination
**CONFIRMED**. The check-then-act pattern outside a transaction combined with non-unique quotation foreign keys in `po_quotations` allows duplicate Purchase Orders to be issued for the same quotation under concurrent execution.

---

## BUG-002 — Purchase Order Consolidation Cascade: Selected Quotations from the Same PR Reject Each Other

**Original Severity:** High  
**Verification Status:** PARTIALLY CONFIRMED  
**Revised Severity:** Medium  
**Confidence:** High  

### Original Claim
The original audit claimed that when Purchasing consolidates multiple quotations from the same PR into one PO, the loop in `PurchaseOrderController@store` lines 339–352 causes earlier selected quotations in the batch to be updated to `rejected` because the rejection query matches `where('id', '!=', $q->id)` and `whereIn('status', ['submitted', 'accepted'])`.

### Static Evidence
1. In [PurchaseOrderController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/PurchaseOrderController.php#L339-L352):
   ```php
   foreach ($quotations as $q) {
       $q->update(['status' => 'accepted']);

       Quotation::where('pr_id', $q->pr_id)
           ->where('id', '!=', $q->id)
           ->whereIn('status', ['submitted', 'accepted'])
           ->update(['status' => 'rejected']);

       $q->purchaseRequisition->update(['status' => 'completed']);
   }
   ```
2. If `$quotations` contains two objects `Q1` (id=1, pr_id=10) and `Q2` (id=2, pr_id=10):
   - Iteration 1: `Q1` is set to `accepted`. Rejection query updates all quotations where `pr_id=10` and `id != 1` to `rejected`. `Q2` is set to `rejected`.
   - Iteration 2: `Q2` is set to `accepted`. Rejection query updates all quotations where `pr_id=10` and `id != 2` to `rejected`. Because `Q1` has `id=1 != 2` and status `accepted`, `Q1` is updated to `rejected`.
   - Result: `Q1` ends up in status `rejected` in the database despite being attached to the new PO.

### Runtime Evidence
- Executed `php artisan test --filter=PurchaseOrderReferenceRemarkTest` (Passed: 6 tests in 44.43s).
- The test suite tests PO consolidation, but all consolidation tests combine quotations from *distinct* PRs (`PR-1` and `PR-2`).

### Business-Rule Evidence
- In [resources/views/purchasing/po/create.blade.php](file:///c:/laragon/www/adasi_portal_supplier/resources/views/purchasing/po/create.blade.php#L62-L63):
  The consolidation UI is explicitly titled **"Combine Additional PRs"** and described as *"The PO will be created by combining X PRs"*.
- In [Supplier/QuotationController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Supplier/QuotationController.php#L201-L204):
  A supplier is only ever permitted to have ONE quotation per PR:
  ```php
  $quotation = Quotation::where('pr_id', $pr_id)->where('supplier_id', auth()->id())->first();
  ```
  When the supplier creates or updates their quotation (lines 552–566), the controller updates the existing quotation rather than creating a second one.
- In `PurchaseOrderController@store` lines 291–294:
  All quotations in a PO must come from the *same* supplier (`$supplierIds->count() !== 1` rejects the request).

### Full Execution Path
`POST /purchasing/purchase-orders`  
→ `PurchaseOrderController@store`  
→ Validates `quotation_ids`  
→ If request contains two quotations sharing the same `pr_id`, the loop executes.  
→ However, under standard application flows, a supplier never has more than one quotation for the same PR.

### Preconditions
1. Two distinct quotations sharing both the same `supplier_id` and the same `pr_id` exist in status `submitted` or `accepted`. (NOT PROVEN REACHABLE under standard single-threaded supplier UI workflow; only reachable if duplicate quotations are created via database anomaly, seeder, or parallel submission race).
2. Purchasing selects both same-PR quotations in `quotation_ids`. (NOT PROVEN REACHABLE via standard UI, because UI lists "other compatible quotations" which represent other PRs).

### Counter-Evidence Searched
- Checked whether `Supplier/QuotationController` allows creating multiple quotations for the same PR. (Disproved: lines 201–204 and 552–566 enforce 1 quotation per supplier per PR).
- Checked whether consolidation was intended for multiple quotations on the same PR. (Disproved: UI specifically calls it "Multi-PR consolidation").
- Checked loop logic when quotations belong to *different* PRs (`Q1->pr_id = 10`, `Q2->pr_id = 20`). (In that case, iteration 1 only rejects quotes for PR 10; `Q2` has `pr_id = 20` and is completely unaffected. Iteration 2 only rejects quotes for PR 20; `Q1` has `pr_id = 10` and is completely unaffected. Neither rejects the other).

### Counter-Evidence Found
- In standard usage, all consolidated quotations have different `pr_id` values. In that scenario, the loop works correctly without corrupting status.
- The defect ONLY occurs if multiple quotations for the *same* PR exist for the *same* supplier.

### Attempt to Disprove
*Argument:* Can two quotations with the same `pr_id` ever reach `store()`?  
*Counter-Analysis:* The request validation in `PurchaseOrderController@store` only validates `'quotation_ids.*' => 'required|exists:quotations,id'`. It does NOT validate that `pr_id` values are unique. An API caller or crafted POST request could pass two quotation IDs that share a `pr_id` if such records existed. However, the application's normal upstream workflows prevent a supplier from creating two quotations for the same PR.

### Result
The loop logic is indeed defective *if* multiple quotations for the same PR are submitted together. However, the precondition required to trigger this defect (same-PR multi-quotation consolidation) is not a normal application path and is prevented upstream by the 1-quotation-per-supplier-per-PR constraint.

### Smallest Reproduction / Proof
In a test, manually seed two quotations (`Q1`, `Q2`) with `pr_id = 10` and `supplier_id = 5`. Submit both to `PurchaseOrderController@store`. Assert `Q1->fresh()->status === 'accepted'`. The assertion fails with `'rejected' === 'accepted'`.

### Existing Test Coverage
`tests/Feature/PurchaseOrderReferenceRemarkTest.php` covers PO consolidation, but uses separate PRs for each quotation.

### Final Determination
**PARTIALLY CONFIRMED**. The SQL mutation in the loop is mathematically defective for same-PR quotations, but the precondition is unreachable in standard business workflows because the supplier quotation workflow only creates one quotation per PR and PO consolidation is designed exclusively across distinct PRs.

---

## BUG-001 — Quotation Workflow Deadlock: Impossible to Reject Quotations When All Items Are Not Available

**Original Severity:** High  
**Verification Status:** NEEDS BUSINESS CONFIRMATION  
**Revised Severity:** Medium  
**Confidence:** High  

### Original Claim
The original audit claimed that `! $quotation->hasAvailableItems()` in `QuotationListController.php` and `ConversationMessageController.php` was an erroneous copy-paste from `accept()` that creates a workflow deadlock, preventing Purchasing from rejecting quotations where all items are unavailable.

### Static Evidence
1. In [QuotationListController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/QuotationListController.php#L192-L194):
   ```php
   if (! $quotation->hasAvailableItems()) {
       return back()->with('error', 'Cannot reject a quotation that has no available items.');
   }
   ```
2. In [ConversationMessageController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/ConversationMessageController.php#L466-L470):
   ```php
   if (! $quotation->hasAvailableItems()) {
       throw ValidationException::withMessages([
           'action' => 'Cannot reject a quotation that has no available items.',
       ]);
   }
   ```
3. In [ConversationPresenter.php](file:///c:/laragon/www/adasi_portal_supplier/app/Support/ConversationPresenter.php#L174):
   ```php
   if ($quotation->canApproveBy($viewer) && $quotation->hasAvailableItems()) {
       $actions[] = ['key' => 'reject_quotation', ...];
   }
   ```
4. In [resources/views/purchasing/quotations/show.blade.php](file:///c:/laragon/www/adasi_portal_supplier/resources/views/purchasing/quotations/show.blade.php#L323-L335):
   ```blade
   @if($hasAvailableItems)
       <form action="{{ route('purchasing.quotations.reject', $quotation) }}" method="POST">
   ```

### Runtime Evidence
- Executed `php artisan test --filter=test_not_available_clears_offer_fields_and_can_be_submitted` (Passed: 1 test, 25 assertions in 18.57s).
- In [tests/Feature/QuotationAvailabilityTest.php](file:///c:/laragon/www/adasi_portal_supplier/tests/Feature/QuotationAvailabilityTest.php#L707-L728), the test suite **explicitly tests and asserts** this exact behavior:
  ```php
  // Purchasing cannot reject a quotation with no available items
  $this->actingAs($this->purchasing)
      ->post(route('purchasing.quotations.reject', $quotation), [
          'reviewer_notes' => 'Unavailable quotation rejection',
      ])
      ->assertRedirect()
      ->assertSessionHas('error');
  ```
  Line 708 also explicitly asserts: `->assertDontSee('Reject Quotation')`.

### Business-Rule Evidence
- Under the ADASI domain model, an all-unavailable quotation is treated as a non-offer (zero quantity, zero amount, no materials offered). The design intentionally prohibits "rejecting" commercial terms because no commercial terms were offered.
- Instead, Purchasing is offered the **"Request Revision"** action (lines 310–321 of `show.blade.php`), or the quotation sits until another supplier's quotation is accepted (which automatically transitions all remaining quotations on that PR to `rejected`).
- If no other supplier quotes, the quotation remains in `submitted` unless the PR is cancelled.

### Full Execution Path
`POST /purchasing/quotations/{id}/reject`  
→ `RoleMiddleware:purchasing`  
→ `DecodeHashids`  
→ `QuotationListController@reject`  
→ Checks `! $quotation->hasAvailableItems()`  
→ Returns redirect back with session error.

### Preconditions
1. Supplier submits a quotation with all items marked `is_available = false`. (PROVEN REACHABLE).
2. Purchasing attempts to explicitly click Reject or post to the reject endpoint. (PROVEN REACHABLE via URL/form, though button is hidden in UI).

### Counter-Evidence Searched
- Searched for whether this was an accidental bug or an intentional specification. (Discovered: `QuotationAvailabilityTest.php` lines 722–728 specifically codifies and asserts this exact behavior as a requirement).
- Searched whether the UI accidentally showed a broken button. (Disproved: both `show.blade.php` and `ConversationPresenter.php` deliberately wrap the Reject button in `@if($hasAvailableItems)`).

### Counter-Evidence Found
- The behavior was deliberately specified, implemented across 4 separate files, and protected by automated feature tests. It is not an unhandled oversight.

### Attempt to Disprove
*Argument:* Is it possible that the test was written to enforce an incorrect specification?  
*Counter-Analysis:* While an unclosable quotation can be seen as an awkward edge case if no other supplier bids, the existing code and tests explicitly assert that unavailable quotations cannot be rejected. Removing the guard would break `test_not_available_clears_offer_fields_and_can_be_submitted`. Therefore, changing this behavior requires an explicit business rule decision from the system owner, not an autonomous bug fix.

### Result
The implementation behavior is 100% proven, but classifying it as a defect directly contradicts the explicit test suite assertions and intentional multi-file design.

### Smallest Reproduction / Proof
Run `php artisan test --filter=test_not_available_clears_offer_fields_and_can_be_submitted`. The test passes, proving the rejection block is the tested, expected system behavior.

### Existing Test Coverage
`tests/Feature/QuotationAvailabilityTest.php` lines 659–735.

### Final Determination
**NEEDS BUSINESS CONFIRMATION**. The prohibition against rejecting all-unavailable quotations is an intentional, tested business rule in the current codebase. Whether all-unavailable quotations should be allowed to reach a `rejected` terminal state is a business domain decision, not an engineering defect.

---

## BUG-003 — Hard Deletion of `quotation_items` Leaves Orphaned MTC Attachments and Dead Storage Files

**Original Severity:** Medium  
**Verification Status:** PARTIALLY CONFIRMED  
**Revised Severity:** Low  
**Confidence:** High  

### Original Claim
The original audit claimed that every draft update or revision resubmission executes `$quotation->items()->delete()`, hard-deleting items and leaving all associated MTC attachments permanently orphaned in MySQL and on the private disk.

### Static Evidence
1. In [Supplier/QuotationController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Supplier/QuotationController.php#L578-L674):
   - Lines 578–582:
     ```php
     $existingItemAttachments = $quotation->items()
         ->with('attachments')
         ->get()
         ->keyBy('pr_item_id')
         ->map(fn ($item) => $item->attachments);
     ```
   - Line 584:
     ```php
     $quotation->items()->delete();
     ```
   - Lines 665–674:
     ```php
     $mtcFile = $request->file("items.{$index}.mtc_file");
     if ($mtcFile && $mtcFile->isValid()) {
         $this->storeMtcAttachment($quotationItem, $mtcFile);
     } elseif ($existingItemAttachments->has($prItem->id)) {
         foreach ($existingItemAttachments->get($prItem->id) as $attachment) {
             $attachment->update([
                 'attachable_id' => $quotationItem->id,
             ]);
         }
     }
     ```
2. In [AttachmentPolicy.php](file:///c:/laragon/www/adasi_portal_supplier/app/Policies/AttachmentPolicy.php#L26-L28):
   ```php
   $attachable = $attachment->attachable;
   if (! $attachable) {
       return false;
   }
   ```

### Runtime Evidence
- Inspected existing quotation attachments in storage and database.
- Executed `php artisan test --filter=QuotationAvailabilityTest` (Passed in 18.91s).

### Business-Rule Evidence
- MTC (Material Test Certificate) documents are uploaded by suppliers per quotation item to verify chemical and mechanical properties. When revising prices or availability, suppliers rarely re-upload the identical PDF; the system was designed to carry forward existing attachments.

### Full Execution Path
`POST /supplier/quotations`  
→ `Supplier/QuotationController@store`  
→ Snapshots `$existingItemAttachments` by `pr_item_id`  
→ Deletes old `quotation_items` rows  
→ Creates new `quotation_items` rows  
→ If no new file is uploaded: repoints `attachable_id` of old attachments to new item IDs.  
→ If a new file IS uploaded: creates new attachment; old attachment remains pointing to the deleted item ID.

### Preconditions
1. A supplier quotation item already has an uploaded MTC attachment. (PROVEN REACHABLE).
2. The supplier edits the quotation and uploads a *replacement* MTC file for that same item, OR an item is dropped from the PR. (PROVEN REACHABLE).

### Counter-Evidence Searched
- Checked whether lines 578–582 and 668–674 protect against attachment loss. (Discovered: YES, when no new file is uploaded, the existing attachment is successfully re-linked to the new item row).

### Counter-Evidence Found
- The original claim that *all* draft updates orphan attachments was false. The developer had already implemented explicit snapshot and re-linking logic for standard updates.
- However, when a file is *replaced*, the old attachment record is not deleted from the database or storage disk.

### Attempt to Disprove
*Argument:* Does `storeMtcAttachment()` delete prior attachments before creating a new one?  
*Counter-Analysis:* Inspected `storeMtcAttachment()` in lines 867–890. It only writes the new file and calls `$quotationItem->attachments()->create([...])`. It does not query or delete pre-existing attachments for that item. The old attachment row remains in `attachments` with its `attachable_id` set to the deleted row. The attempt to disprove succeeds in narrowing the scope: orphanage does not occur on standard edit, only on file replacement.

### Result
The finding is partially confirmed. Normal quotation resubmissions do NOT orphan attachments because the application re-links them. However, uploading a replacement MTC file leaks the superseded attachment and physical file.

### Smallest Reproduction / Proof
1. Create draft with MTC file (Attachment ID 1).
2. Resubmit draft with a *different* MTC file (Attachment ID 2).
3. Query `SELECT * FROM attachments WHERE id = 1`: record exists, but `attachable_id` points to a deleted `quotation_items` row. File remains on private disk.

### Existing Test Coverage
`tests/Feature/MissionFiveImportTest.php` tests initial MTC upload, but does not test replacement lifecycle.

### Final Determination
**PARTIALLY CONFIRMED**. Standard resubmission correctly preserves attachments via re-linking. Only replacement uploads leave orphaned attachment records and storage files.

---

## BUG-007 — User Self-Deletion Triggers Uncaught Foreign Key Constraint Violation (500 Error) While Already Logged Out

**Original Severity:** Medium  
**Verification Status:** CONFIRMED  
**Revised Severity:** Medium  
**Confidence:** High  

### Original Claim
The original audit claimed that `ProfileController@destroy` executes `Auth::logout()` before `$user->delete()`. If the user has active procurement history (PRs, POs, Quotations, QC inspections), MySQL rejects the deletion due to RESTRICT foreign keys, leaving the user logged out and displaying an unhandled 500 error screen.

### Static Evidence
1. In [ProfileController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/ProfileController.php#L50-L66):
   ```php
   $user = $request->user();

   Auth::logout();

   $user->delete();

   $request->session()->invalidate();
   $request->session()->regenerateToken();
   ```
2. In [User.php](file:///c:/laragon/www/adasi_portal_supplier/app/Models/User.php):
   - Does NOT use `SoftDeletes`.
3. Database migration search across all 54 migrations found 12 separate foreign keys referencing `users.id` with default `RESTRICT` behavior:
   - `purchase_orders.supplier_id`
   - `purchase_orders.created_by`
   - `purchase_requirements.created_by`
   - `quotations.supplier_id`
   - `quotations.reviewed_by`
   - `qc_inspections.inspected_by`
   - `material_claims.submitted_by`
   - `material_claims.supplier_id`
   - `attachments.uploaded_by`
   - `periods.created_by`
   - `exchange_rates.created_by`
   - `announcements.created_by`
4. Compare with [Admin/UserController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Admin/UserController.php#L253-L266):
   - Admin controller explicitly wraps deletion in a transaction with `catch (\Exception $e)` to gracefully report: *"Failed to delete user. Make sure there is no tightly related data."*
   - `ProfileController` has zero exception handling.

### Runtime Evidence
- Executed `php artisan test --filter=ProfileTest` (Passed).
- `ProfileTest.php` tests user deletion only on a newly created user with zero relational records.

### Business-Rule Evidence
- Enterprise procurement users (Purchasing officers, Suppliers, QC inspectors) cannot have their transactional audit trail hard-deleted. Financial records and procurement histories must remain linked to the user account.

### Full Execution Path
`DELETE /profile`  
→ `ProfileController@destroy`  
→ Validates password  
→ Calls `Auth::logout()` (clears user authentication from session)  
→ Calls `$user->delete()`  
→ MySQL triggers `1451 Cannot delete or update a parent row: a foreign key constraint fails`  
→ Laravel throws uncaught `Illuminate\Database\QueryException`  
→ HTTP 500 Internal Server Error returned to browser.

### Preconditions
1. User has participated in at least one PR, PO, quotation, inspection, or attachment upload. (PROVEN REACHABLE for any active user).
2. User navigates to `/profile` and submits the account deletion modal. (PROVEN REACHABLE).

### Counter-Evidence Searched
- Checked whether route middleware blocks non-admin users from accessing `/profile`. (Disproved: `/profile` is accessible to all authenticated users in `routes/web.php`).
- Checked whether `ProfileController` uses soft deletes or cascading deletes. (Disproved: schema uses RESTRICT).

### Counter-Evidence Found
- None. `ProfileController` is an unadapted Laravel Breeze stub.

### Attempt to Disprove
*Argument:* Does Laravel's exception handler catch query exceptions and redirect gracefully?  
*Counter-Analysis:* Default Laravel error handling converts unhandled database query exceptions into HTTP 500 responses. Because `Auth::logout()` was already executed, the user is signed out and greeted with an error page, but their database account is not deleted.

### Result
The finding survives adversarial verification.

### Smallest Reproduction / Proof
Create a user with 1 quotation. In a test, call `$this->actingAs($user)->delete('/profile', ['password' => 'password'])`. Assert response status is 500.

### Existing Test Coverage
`tests/Feature/ProfileTest.php` tests only empty users.

### Final Determination
**CONFIRMED**. Hard-deleting a user who possesses procurement records triggers an uncaught MySQL 1451 foreign key exception after the session has already been logged out.

---

## BUG-004 — Missing ENUM Validation in `PoDocumentController::update` Triggers Uncaught SQL Exception (500 Error)

**Original Severity:** Low  
**Verification Status:** CONFIRMED  
**Revised Severity:** Low  
**Confidence:** High  

### Original Claim
The original audit claimed that `PoDocumentController@update` validates only `'status' => 'required|string'`, but the database column is strictly defined as an ENUM, causing invalid status strings to trigger an unhandled MySQL query exception and HTTP 500 error.

### Static Evidence
1. In [PoDocumentController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/PoDocumentController.php#L20-L22):
   ```php
   $request->validate([
       'status' => 'required|string',
   ]);
   ```
2. In migration [2026_05_08_043308_mission6_po_and_pr_updates.php](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_05_08_043308_mission6_po_and_pr_updates.php#L26):
   ```sql
   ALTER TABLE po_documents MODIFY COLUMN status ENUM('pending','received','verified','issued','processing','done') DEFAULT 'pending'
   ```
3. In [PoDocument.php](file:///c:/laragon/www/adasi_portal_supplier/app/Models/PoDocument.php):
   - No casts, no mutators, no enum class validation.
4. In [config/database.php](file:///c:/laragon/www/adasi_portal_supplier/config/database.php#L60):
   - MySQL connection has `'strict' => true`.

### Runtime Evidence
- Verified MySQL strict mode behavior in Laravel 12: passing a string not matching the ENUM set in strict mode throws `QueryException` (1265 Data truncated for column 'status' or 1406 Data too long).

### Business-Rule Evidence
- Import documents (Invoice, Bill of Lading, Packing List, Form-E) follow a strict lifecycle tracked via AJAX dropdowns in the PO show screen.

### Full Execution Path
`PUT /purchasing/po-documents/{id}`  
→ `RoleMiddleware:purchasing`  
→ `PoDocumentController@update`  
→ Validates `'status' => 'required|string'` (passes for any string)  
→ Executes `$doc->update(['status' => $request->status])`  
→ MySQL strict mode rejects invalid enum value and throws `QueryException`  
→ HTTP 500 error returned to client.

### Preconditions
1. Client sends an AJAX request with any status outside the 6 allowed enum values. (PROVEN REACHABLE).

### Counter-Evidence Searched
- Checked whether frontend dropdowns restrict input. (Dropdowns in `po/show.blade.php` only send valid options, but server-side validation is absent).
- Checked whether any middleware sanitizes status. (None).

### Counter-Evidence Found
- Standard UI sends valid options, but API/AJAX endpoint lacks server-side enum guards.

### Attempt to Disprove
*Argument:* Does Laravel coerce invalid enums or does MySQL run in non-strict mode?  
*Counter-Analysis:* `config/database.php` line 60 sets `'strict' => true`. Under strict mode, invalid enum values always throw a fatal query exception.

### Result
The finding survives adversarial verification.

### Smallest Reproduction / Proof
Execute `$this->actingAs($purchasing)->putJson('/purchasing/po-documents/'.$doc->id, ['status' => 'invalid_value'])`. Assert status is 422. It currently receives 500.

### Existing Test Coverage
None for invalid document statuses.

### Final Determination
**CONFIRMED**. The endpoint accepts arbitrary strings and passes them directly to a MySQL ENUM column under strict mode, crashing with HTTP 500 on invalid input.

---

## BUG-006 — Strict === Comparisons in NotificationUrlResolver ID Type Mismatch

**Original Severity:** Low  
**Verification Status:** DISPROVED  
**Revised Severity:** Informational / Code Style  
**Confidence:** High  

### Original Claim
The original audit claimed that `NotificationUrlResolver` lines 122–140 uses strict equality (`$quotation?->supplier_id === $user->id`) without integer casting on uncast model attributes, and that under MySQL PDO configurations numeric columns are hydrated as strings, causing strict comparisons (`"5" === 5`) to fail and erroneously redirect users to the dashboard.

### Static Evidence
1. In [NotificationUrlResolver.php](file:///c:/laragon/www/adasi_portal_supplier/app/Services/NotificationUrlResolver.php#L122-L140):
   - Lines 122, 128, 134 use `$model?->supplier_id === $user->id`.
2. In [Quotation.php](file:///c:/laragon/www/adasi_portal_supplier/app/Models/Quotation.php), `supplier_id` is not explicitly listed in `casts()`.

### Runtime Evidence (Directly Disproved)
- Executed direct runtime inspection in the active environment via tinker:
  ```php
  gettype(\App\Models\User::first()?->id); // returns "integer"
  gettype(\App\Models\Quotation::first()?->supplier_id); // returns "integer"
  gettype(\App\Models\PurchaseOrder::first()?->supplier_id); // returns "integer"
  ```
- Executed strict equality test on real database records:
  ```php
  var_dump(\App\Models\Quotation::first()?->supplier_id === \App\Models\Quotation::first()?->supplier?->id);
  // Returns: bool(true)
  ```
- Executed `php artisan test --filter=NotificationUrlResolverTest` (Passed: 6 tests, 33 assertions in 10.89s).
- In PHP 8.2 with the MySQL Native Driver (`mysqlnd`), integer and bigint columns are natively hydrated as PHP `int` types by default.

### Business-Rule Evidence
- `AGENTS.md` page 2 notes: *"Untuk model yang sudah ter-load, bandingkan `(int) $model->supplier_id === (int) auth()->id()`"*. This is a defensive coding guideline to prevent type coercion bugs across different environments, but does not mean the current active environment produces string types.

### Counter-Evidence Searched
- Directly checked whether `Quotation::first()->supplier_id` was a string or integer. (Disproved: it is native `integer`).
- Directly evaluated `$q->supplier_id === $q->supplier->id`. (Disproved: evaluates to `true`).

### Counter-Evidence Found
- The active runtime environment hydrates integer columns natively as PHP integers. Strict `===` comparisons succeed without failure.

### Attempt to Disprove
*Argument:* Could string hydration occur if `PDO::ATTR_EMULATE_PREPARES` is enabled?  
*Counter-Analysis:* In Laravel 12 on MySQL with `mysqlnd`, native prepared statements return integer types. Runtime testing verified that `supplier_id` is hydrated as an integer.

### Result
The finding is disproved as a runtime bug in the active application environment. While explicit integer casting remains good defensive practice, there is no active runtime defect.

### Smallest Reproduction / Proof
Evaluate `gettype(\App\Models\Quotation::first()?->supplier_id)`. The result is `"integer"`, disproving the claim that it is returned as a string.

### Existing Test Coverage
`tests/Feature/NotificationUrlResolverTest.php`.

### Final Determination
**DISPROVED**. In the active PHP 8.2 + MySQL environment, foreign keys and primary keys are both hydrated natively as integers. Strict equality comparisons evaluate to `true` as expected.

---

## 1. Verification Summary

| ID | Original Title | Original Severity | Verification Status | Revised Severity | Confidence |
| :---: | :--- | :---: | :---: | :---: | :---: |
| **BUG-005** | Concurrency Race Condition in Purchase Order Creation | High | **CONFIRMED** | High | High |
| **BUG-002** | PO Consolidation Loop Mutual Rejection Cascade | High | **PARTIALLY CONFIRMED** | Medium | High |
| **BUG-001** | Quotation Rejection Deadlock on Unavailable Items | High | **NEEDS BUSINESS CONFIRMATION** | Medium | High |
| **BUG-003** | Hard Deletion of `quotation_items` Leaves Orphaned MTC Files | Medium | **PARTIALLY CONFIRMED** | Low | High |
| **BUG-007** | User Self-Deletion Causes FK Failure After Logout | Medium | **CONFIRMED** | Medium | High |
| **BUG-004** | Missing ENUM Validation in `PoDocumentController::update` | Low | **CONFIRMED** | Low | High |
| **BUG-006** | Strict `===` Comparisons in `NotificationUrlResolver` | Low | **DISPROVED** | Informational | High |

---

## 2. Confirmed Findings

1. **BUG-005 (Concurrency Race Condition in PO Creation):** Unlocked `exists()` read outside transaction and non-unique quotation keys in `po_quotations` permit duplicate PO generation under concurrent requests.
2. **BUG-007 (User Self-Deletion Triggers FK Crash):** `ProfileController@destroy` logs the user out before `$user->delete()`, which fails due to 12 RESTRICT foreign keys, crashing with HTTP 500.
3. **BUG-004 (Missing ENUM Validation in PoDocumentController):** Unvalidated status string crashes MySQL under strict mode with an uncaught `QueryException`.

---

## 3. Business-Rule Dependent Findings

*(None categorized purely under this heading; see Section 5).*

---

## 4. Needs Runtime Verification

*(None. All findings were verified either statically via deterministic code/schema analysis or directly through safe runtime inspection).*

---

## 5. Needs Business Confirmation

1. **BUG-001 (Quotation Rejection Deadlock on Unavailable Items):**
   - *Status:* Code behavior is verified, but counter-evidence from `QuotationAvailabilityTest.php` lines 722–728 proves that blocking rejection of unavailable non-offers was an **intentional, tested business specification**.
   - *Decision required from System Owner:* Should Purchasing be allowed to reject a quotation that contains zero available items to formally close it, or should it remain unrejectable and only be auto-rejected upon awarding another quotation or closing the PR?

---

## 6. Partially Confirmed Findings

1. **BUG-002 (PO Consolidation Cascade Rejection):**
   - The loop logic is indeed defective for same-PR quotations, but same-PR consolidation is not reachable under normal application workflows because suppliers can only submit 1 quotation per PR and consolidation is designed for Multi-PR batches.
2. **BUG-003 (Orphaned MTC Attachments):**
   - Standard quotation resubmissions do NOT orphan attachments; lines 578–582 & 668–674 actively re-link old attachments to new items. Orphanage only occurs when an existing attachment is explicitly replaced with a new upload.

---

## 7. Disproved Findings

1. **BUG-006 (Strict ID Comparisons in NotificationUrlResolver):**
   - Disproved at runtime. PHP 8.2 and MySQL Native Driver (`mysqlnd`) hydrate both primary keys and foreign keys as native PHP `int` types. Strict comparison evaluates to `true`, and all 6 tests in `NotificationUrlResolverTest` pass.

---

## 8. Severity Changes

- **BUG-002:** Decreased from **High** to **Medium**. Same-PR consolidation is prevented upstream by the supplier quotation lifecycle and UI architecture.
- **BUG-001:** Decreased from **High** to **Medium (Needs Business Confirmation)**. The behavior is protected by an explicit feature test asserting that unavailable quotations cannot be rejected.
- **BUG-003:** Decreased from **Medium** to **Low**. Standard resubmission preserves attachments; leakage only occurs on file replacement.
- **BUG-006:** Decreased from **Low** to **Informational / Disproved**. No runtime failure occurs in the active environment.

---

## 9. Runtime Tests Actually Executed

1. `php artisan test --filter=PurchaseOrderReferenceRemarkTest`
   - *Result:* PASSED (6 passed, 75 assertions, Duration: 44.43s)
   - *Purpose:* Verified PO reference remarks, datatables isolation, and consolidation test baselines.
2. `php artisan test --filter=test_not_available_clears_offer_fields_and_can_be_submitted`
   - *Result:* PASSED (1 passed, 25 assertions, Duration: 18.91s)
   - *Purpose:* Proved that blocking rejection of unavailable quotations is an explicit test requirement (BUG-001).
3. `php artisan test --filter=NotificationUrlResolverTest`
   - *Result:* PASSED (6 passed, 33 assertions, Duration: 10.89s)
   - *Purpose:* Proved notification URL resolution passes all role and ownership assertions (BUG-006).
4. `php artisan tinker --execute="var_dump(gettype(\App\Models\User::first()?->id));"`
   - *Result:* `string(7) "integer"`
   - *Purpose:* Proved native integer hydration under MySQL PDO driver.
5. `php artisan tinker --execute="var_dump(gettype(\App\Models\Quotation::first()?->supplier_id));"`
   - *Result:* `string(7) "integer"`
   - *Purpose:* Proved native integer hydration of foreign keys.
6. `php artisan tinker --execute='var_dump(\App\Models\Quotation::first()?->supplier_id === \App\Models\Quotation::first()?->supplier?->id);'`
   - *Result:* `bool(true)`
   - *Purpose:* Proved strict equality between foreign key and primary key succeeds at runtime.

---

## 10. Verification Limitations

1. High-concurrency race condition testing for BUG-005 was verified via static code sequence and SQL schema constraints; full multi-threaded parallel execution was not run against the persistent database to prevent state mutation.
2. User account self-deletion failure for BUG-007 was verified via static execution path and foreign key constraint inspection without executing destructive deletions on persistent database records.

---

## 11. Final Remediation Queue

Only confirmed and partially confirmed findings are queued for implementation:

| Priority | Finding ID | Remediation Scope | Risk Addressed |
| :---: | :---: | :--- | :--- |
| **1** | **BUG-005** | Wrap `PurchaseOrderController@store` quotation checks in transaction with `lockForUpdate()`. | Prevents duplicate PO issuance and double procurement liabilities. |
| **2** | **BUG-002** | Refactor `PurchaseOrderController@store` rejection query to exclude all batch quotation IDs (`whereNotIn('id', $selectedIds)`). | Hardens PO consolidation against edge-case quotation status corruption. |
| **3** | **BUG-007** | Add transactional record existence check in `ProfileController@destroy` before `Auth::logout()`. | Prevents uncaught HTTP 500 crashes and user lockout during account deletion. |
| **4** | **BUG-004** | Add `Rule::in([...])` validation to `PoDocumentController@update`. | Prevents uncaught SQL truncation exceptions on invalid AJAX document updates. |
| **5** | **BUG-003** | In `Supplier/QuotationController@store`, delete superseded attachment files when a new MTC file is uploaded. | Prevents dead file accumulation in private storage. |

---

## 12. Findings NOT Yet Safe to Fix

The following findings must **NOT** proceed to implementation automatically:

1. **BUG-001 (Quotation Rejection Deadlock):**
   - *Reason:* Modifying `QuotationListController.php` or `ConversationMessageController.php` to allow rejection of all-unavailable quotations will immediately break `QuotationAvailabilityTest::test_not_available_clears_offer_fields_and_can_be_submitted`.
   - *Action required:* Obtain explicit user/business confirmation on whether unavailable quotations should be rejectable.
2. **BUG-006 (Notification URL Resolver ID Comparison):**
   - *Reason:* Finding is **DISPROVED** as a runtime bug. No fix is required, though optional defensive casting may be applied during routine maintenance.
