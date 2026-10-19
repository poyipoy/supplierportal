# Production dump comparison: migrations 14-15 October 2026

Inspected on 19 October 2026. Scope is the nine migration files whose names
start with `2026_10_14_` or `2026_10_15_`; this is not a full database drift audit.

## Evidence

- Input: `adaw2196_supplierportal (4).sql`, supplied by the user.
- SHA-256: `0cbefe1c00be6b7715e17b39e2fa288701af9960ee87788863bc6684ecd1a7d3`.
- Dump header: MariaDB `11.4.13-MariaDB-cll-lve`.
- 90 CREATE TABLE definitions; inspected relevant CREATE and subsequent ALTER
  statements, including indexes, auto-increment and foreign keys.
- Dump ledger: 110 rows, ending with
  `2026_10_12_000003_guard_active_supplier_audit_uniqueness`, batch 40.
- None of the nine selected migration names appears in the dump ledger.
- The user reports copying the local ledger to production. A newer live ledger
  may therefore differ from this dump and cannot establish physical schema state.

## Physical differences confirmed in the dump

| Migration | Missing change |
| --- | --- |
| `2026_10_14_000001_add_pr_number_to_local_purchase_orders` | Nullable `pr_number` VARCHAR(100) and non-unique index |
| `2026_10_14_000002_add_logistics_terms_to_purchase_requisitions` | Nullable `port_of_discharge`, `incoterm`, two canonical-case CHECK constraints |
| `2026_10_14_000003_add_logistics_terms_to_purchase_orders` | Nullable logistics snapshot columns and two CHECK constraints |
| `2026_10_14_000004_add_ordered_and_acceptance_fields_to_pr_item_awards` | Nine nullable ordered/acceptance columns, user FK, two CHECK constraints |
| `2026_10_14_000005_create_pr_item_award_events_table` | Table, history indexes, two nullable FKs |
| `2026_10_14_000006_create_material_reference_prices_table` | Table, lookup index, five FKs, seven CHECK constraints |
| `2026_10_14_000007_create_award_reference_price_snapshots_table` | Table, unique award key, five FKs, status coherence CHECK |
| `2026_10_15_000001_add_po_and_gr_to_local_invoice_documents` | Enum additions `purchase_order`, `goods_receipt`; existing four values match the required baseline |
| `2026_10_15_000100_create_master_data_audit_logs_table` | Table, history index, actor FK |

Total: four new tables, fourteen added columns on existing tables, one enum
extension, fourteen FKs and fourteen CHECK constraints, plus their indexes.
No migration in this range requires seeding, backfill or modifying existing
business rows. The SQL preserves nullable legacy semantics.

## Adjusted SQL

Use [2026_10_14_to_2026_10_15_migration.sql](../../database/2026_10_14_to_2026_10_15_migration.sql).

The script checks the physical baseline before changing schema. Existing copied
ledger entries are allowed and keep their original ID/batch. Each successfully
completed migration inserts its name only when absent; it never deletes or
updates ledger rows. Duplicate selected ledger names stop execution for review.
With exactly this dump's ledger, the nine new records would use batch 41;
otherwise the batch is calculated from the target's current MAX(batch) + 1.

The script intentionally refuses existing selected new tables/columns rather
than assuming an existing object's definition is correct. It is a baseline
catch-up script, not a general automatic schema repair or rerunnable migration.

### phpMyAdmin strict-mode preflight correction

The first reported import stopped with `#1644 - A strict SQL mode is required
before import.` This guard precedes all application table DDL and ledger writes,
so that failing CALL did not apply any of the nine stages. The helper routine
itself may remain, depending on whether the client executed the following DROP.

The corrected script saves the session's SQL mode and adds `STRICT_TRANS_TABLES`
only if neither strict mode is already enabled. This happens before CREATE
PROCEDURE because routines retain their creation-time SQL mode. It replaces only
its own helper routine to remove the old creation-time mode, then calls the new
routine and restores the session mode after successful completion. Other modes
are preserved; global settings are never changed. If the client stops on a later
error, the session can remain strict until it is closed. Only this specific early
guard failure permits restarting the full script without reconciling partial DDL
(the early version parsing failure below is also before all application DDL).

### Server version parsing correction

The next reported import stopped at `#1292 - Truncated incorrect INTEGER value:
'13-MariaDB-cll-lve'`. The initial parser cast the patch component with the server
suffix still attached. Under strict mode that conversion fails. The corrected
parser first extracts the leading numeric version triplet with `REGEXP_SUBSTR`,
validates that a triplet was found, then casts the three numeric components.
The reported version resolves to `11`, `4`, `13`; MariaDB detection still uses
the original complete VERSION() string. This failure also precedes application
DDL and ledger insertions, so the entire corrected file can be reimported after
this particular error. The file recreates its own helper procedure automatically.

## Execution and recovery limits

1. Take a fresh target backup, including data; rehearse on a restored staging
   copy. Confirm no selected physical change was applied since the supplied dump.
2. Pause writes/workers and concurrent migrations. Select the correct database.
3. Import the SQL through a client supporting DELIMITER, using an account with
   the routine/table privileges listed in the SQL header. Stop on errors.
4. Inspect the completion result, all nine physical SHOW CREATE TABLE outputs,
   and ledger rows. Then run `php artisan migrate:status` on that deployment.
   A copied ledger alone is never evidence of schema completion.
5. DDL commits independently. After a failure, inspect the actual intermediate
   schema and resume only reconciled missing operations. Do not blindly rerun,
   truncate tables, delete ledger rows, or bypass PHP down() data-loss guards.
   Prefer a reviewed roll-forward repair; restoring backup loses later writes.

## Verification performed

- Compared the selected PHP migrations with relevant dump DDL and ledger names.
- Statically checked all nine names and nine conditional ledger insertions,
  fourteen FK definitions, fourteen CHECK definitions and identifier lengths.
- Whitespace check produced no diagnostics.
- Executed the version parsing expressions under strict mode on local MySQL
  8.0.30 with `11.4.13-MariaDB-cll-lve`, `10.5.0-MariaDB`, `8.0.30`,
  `8.0.16-log`, and `8.0.15`: correct numeric triplets, zero warnings.
- No dump import, migration execution, full-script runtime/staging test, application
  data write, or live production connection was performed. MariaDB runtime compatibility,
  hosting routine privileges and lock duration remain unverified.
