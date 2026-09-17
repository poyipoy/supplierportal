# Implementation Plan: [Task Title]

> Use this template proportionally. For routine/localized work, compress or omit sections that do not add decision value. Do not manufacture complexity.

## 1. Outcome, Scope & Reasoning Depth

- **User Intent**: [Exact requirement and desired outcome]
- **Success Criteria**: [Observable conditions that mean the task is complete]
- **Scope IN**: [Files, modules, behaviors, data flows, or interfaces that may change]
- **Scope OUT**: [Explicit non-goals; unrelated refactors/upgrades/cleanup]
- **Operational Constraints**: [Security, performance, framework/runtime versions, backward compatibility, deployment constraints]
- **Reasoning Depth**: [Routine / Standard / Deep / Maximum]
- **Escalation Trigger**: [What uncertainty/risk would justify deeper investigation]
- **External Research Needed?**: [No / Yes — exact uncertainty to resolve]

---

## 2. Evidence, Invariants & Confidence Inventory

### Relevant Evidence Inspected
- `path/to/Controller.php`: [Observed control flow]
- `path/to/Model.php`: [Observed relationships/business invariants]
- `database/migrations/...`: [Observed schema/constraints]
- `routes/...`: [Observed entry point/middleware]
- `tests/...`: [Observed existing behavioral contract]
- `[runtime/log/tool output]`: [Observed execution evidence, if applicable]

### Confidence Classification
- **[Verified]**: [Directly observed facts]
- **[Inferred]**: [Strong conclusions derived from verified facts]
- **[Assumed]**: [Working hypotheses that still require testing]

### Existing Invariants to Preserve
- [Authorization/data-isolation invariant]
- [State-transition/business-rule invariant]
- [API/route/method compatibility invariant]
- [Data integrity/transaction invariant]
- [Performance/operational invariant]

### Conflicts or Missing Evidence
- [Contradictory code/docs/runtime behavior]
- [Unknowns that could materially change the implementation]

---

## 3. Causal Model / Problem Decomposition

For debugging or non-trivial changes, describe only the causal model needed to justify the implementation.

- **Observed Symptom / Requested Behavior**: [...]
- **Trigger / Entry Point**: [...]
- **Expected Invariant**: [...]
- **Actual Execution/Data Flow**: [...]
- **Likely Root Cause / Design Constraint**: [...]
- **Evidence That Supports It**: [...]
- **Evidence That Could Falsify It**: [...]

If this is not a debugging task, replace the above with:
- **Current Behavior**: [...]
- **Desired Behavior**: [...]
- **Gap to Close**: [...]

---

## 4. Viable Approaches & Decision

Only compare alternatives when more than one genuinely viable approach exists. Do not invent a weak alternative for formality.

### Approach A: [Name]
- **Mechanism**: [...]
- **Correctness & Safety**: [...]
- **Blast Radius**: [...]
- **Maintainability / Cognitive Overhead**: [...]
- **Performance / Resource Impact**: [...]
- **Operational / Rollback Complexity**: [...]
- **Regression Risk**: [Low / Medium / High]
- **Key Assumption**: [...]

### Approach B: [Name] *(omit if not genuinely viable)*
- **Mechanism**: [...]
- **Correctness & Safety**: [...]
- **Blast Radius**: [...]
- **Maintainability / Cognitive Overhead**: [...]
- **Performance / Resource Impact**: [...]
- **Operational / Rollback Complexity**: [...]
- **Regression Risk**: [Low / Medium / High]
- **Key Assumption**: [...]

**Decision**: Select **Approach [A/B]** because [evidence-based engineering justification].

**Rejected Alternatives**: [Only list meaningful rejected options and the decisive reason.]

---

## 5. Implementation Steps

Each step should be necessary, bounded, and traceable to the requested outcome.

### Step 1: [Preparation / Schema / Contract]
- **Target file(s)**: `...`
- **Change**: [...]
- **Invariant Preserved**: [...]
- **Risk / Rollback Note**: [...]

### Step 2: [Core Logic]
- **Target file(s)**: `...`
- **Change**: [...]
- **Invariant Preserved**: [...]
- **Risk / Rollback Note**: [...]

### Step 3: [Presentation / API / Integration] *(if applicable)*
- **Target file(s)**: `...`
- **Change**: [...]
- **Invariant Preserved**: [...]
- **Risk / Rollback Note**: [...]

### Step 4: [Regression Test / Fixture / Documentation] *(only if materially useful)*
- **Target file(s)**: `...`
- **Change**: [...]
- **Why Needed**: [...]

---

## 6. Material Failure Modes & Edge Cases

Consider only scenarios that are plausible for the affected path.

- **Input / Nullability**: [Empty, null, malformed, extreme, duplicate]
- **Authorization / Isolation**: [IDOR, tenant/supplier ownership, role boundary]
- **Concurrency / Retry**: [Double submit, stale state, idempotency, race condition]
- **Data Integrity**: [Partial write, transaction boundary, uniqueness]
- **External Dependency**: [Timeout, unavailable API/storage/mailer, retry semantics]
- **Scale / Performance**: [N+1, unbounded query/payload, lock contention, memory]
- **Backward Compatibility**: [Existing callers/routes/response shape/event contract]

---

## 7. Verification Strategy

Verification depth must match the change risk. Start with the cheapest meaningful check and expand only when justified.

| Check | Command / Method | Why It Matters | Planned Status |
|---|---|---|---|
| Syntax / Parse | `php -l [modified_file]` | Detect syntax failure | Planned / N/A |
| Static / Style | `./vendor/bin/pint --test` / PHPStan | Detect style/type issues | Planned / N/A |
| Targeted Test | `php artisan test [target]` | Validate changed behavior | Planned / N/A |
| Regression Test | `[adjacent suite]` | Check shared behavior | Planned / N/A |
| Runtime Reproduction | `[manual/tool steps]` | Validate real execution path | Planned / N/A |
| Security / Concurrency Scenario | `[scenario]` | Validate high-risk invariant | Planned / N/A |

### Verification Expansion Triggers
Expand testing only if:
- implementation changes after an earlier successful check;
- a test fails;
- a new dependency/path is touched;
- a new material risk is discovered;
- project policy explicitly requires broader verification.

---

## 8. Completion Gate

Do not declare completion until the following are true or transparently blocked:

- [ ] Requested outcome is satisfied.
- [ ] Important execution/data path is understood from evidence.
- [ ] Material assumptions are verified or explicitly disclosed.
- [ ] Root cause is addressed, or a workaround is explicitly justified.
- [ ] Minimal coherent change is used; no unrelated edits.
- [ ] Authorization, data integrity, and business invariants are preserved.
- [ ] Existing contracts remain compatible unless intentionally changed.
- [ ] Appropriate verification was executed where feasible.
- [ ] Verification status is reported as **Passed / Failed / Blocked / Not Run**, never implied.
- [ ] External claims that materially affected the solution are source-backed.
- [ ] No unresolved high-impact uncertainty is hidden behind confident language.

### Stop Condition
Stop investigating when:
1. the implementation decision is supported by sufficient evidence;
2. remaining uncertainty is low-impact or explicitly disclosed; and
3. additional investigation is unlikely to materially change the solution.
