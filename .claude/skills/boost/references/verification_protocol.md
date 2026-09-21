# Verification & Falsification Protocol

This protocol enforces empirical validation, adversarial self-critique, and transparent reporting. Verification effort must be proportional to the risk and scope of the change.

The objective is not to run every possible check. The objective is to obtain enough evidence to trust the requested outcome without misrepresenting what was actually tested.

---

## 1. Verification Status Vocabulary

Use these statuses consistently:

- **[PASSED]** — Executed and completed successfully.
- **[FAILED]** — Executed and produced a failing result.
- **[BLOCKED]** — Could not execute because a required environment, dependency, permission, credential, service, fixture, or resource was unavailable.
- **[NOT RUN]** — Intentionally not executed because it was not relevant or not justified by the change risk.
- **[INSPECTED]** — Directly read/observed source code, schema, configuration, logs, or tool output.
- **[INFERRED]** — Strong conclusion derived from inspected/passed evidence but not directly executed.
- **[ASSUMED]** — Working hypothesis that remains unverified.

Never use **[PASSED]** or **[VERIFIED]** for something that was only inspected or inferred.

---

## 2. Risk-Proportional Verification Ladder

Start with the cheapest meaningful check. Escalate only when justified.

```text
Inspect Relevant Path
        ↓
Syntax / Parse / Build
        ↓
Targeted Automated Test
        ↓
Regression / Adjacent Integration Test
        ↓
Runtime Reproduction / Manual Scenario
        ↓
Adversarial / Security / Concurrency Validation
```

Not every task requires every tier.

### Tier 0: Inspection & Baseline Understanding
Before testing, know what behavior the check is meant to validate.

- inspect the modified path and relevant callers;
- inspect existing tests before creating new ones;
- inspect framework/dependency version when behavior is version-sensitive;
- establish a pre-change baseline only when it materially helps distinguish pre-existing failures from regressions.

### Tier 1: Syntax, Parse, Build & Static Checks
Run checks appropriate to the modified artifacts.

Examples:

```bash
php -l path/to/file.php
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
npm run build
```

Do not run language-specific checks on untouched or irrelevant files merely by habit.

### Tier 2: Targeted Automated Testing
Prefer the narrowest test that exercises the changed behavior or invariant.

```bash
php artisan test tests/Feature/SpecificSubsystemTest.php
```

For a bug fix, add or run a regression test when it meaningfully protects the violated behavior.

A good regression test should validate observable behavior or an invariant, not merely mirror the implementation.

### Tier 3: Adjacent Regression & Integration
Expand to nearby/shared behavior when the change touches:

- shared models or traits;
- global middleware;
- event/listener chains;
- authorization policies;
- database schema/constraints;
- queues/jobs;
- public APIs/contracts;
- common reusable components.

Do not run the full suite automatically if a smaller affected-area suite provides sufficient evidence.

### Tier 4: Runtime / Scenario Validation
Use when automated tests cannot fully establish real behavior.

Examples:
- reproduce the original bug;
- submit the relevant HTTP flow;
- verify a generated query/result;
- inspect a migration on a representative database;
- validate an external integration in an authorized environment.

### Tier 5: Security, Concurrency & High-Risk Validation
Use when the change affects high-impact invariants.

Examples:
- cross-tenant / cross-supplier access attempts;
- unauthorized role access;
- duplicate submission;
- concurrent state transitions;
- retry/idempotency behavior;
- rollback after partial failure;
- migration/data-loss safety;
- sensitive-data exposure.

---

## 3. Verification Expansion Rules

Broaden verification when:

- an earlier check fails;
- implementation changes after a successful check;
- a shared dependency or public contract is modified;
- new evidence reveals a wider affected path;
- a high-impact invariant is involved;
- project policy requires broader validation.

Do not repeatedly rerun successful checks when nothing relevant changed.

---

## 4. Falsification Protocol

Before presenting a non-trivial solution, try to disprove it.

### A. Assumption Attack
Ask:
- What assumption am I making that could be false?
- Is that assumption **[Verified]**, **[Inferred]**, or **[Assumed]**?
- What is the cheapest observation/test that would falsify it?

### B. Root-Cause Attack
Ask:
- Does the proposed fix explain the observed symptom?
- Could the symptom disappear while the underlying invariant remains broken?
- Am I fixing the trigger, proximate cause, or root cause?
- If this is a workaround, is the residual root cause explicit?

### C. Concurrency & State Attack
Ask:
- Can two requests/jobs observe the same state and both proceed?
- Is the transition atomic?
- Can retries duplicate side effects?
- Is idempotency required?
- Would `DB::transaction()`, a unique constraint, atomic `UPDATE`, or `lockForUpdate()` be justified by the invariant?

Do not introduce locks/transactions unless evidence shows they are needed.

### D. Authorization & Isolation Attack
Ask:
- Can a user access/mutate another user's, tenant's, or supplier's resource by changing an identifier?
- Is authorization enforced server-side?
- Are model lookups properly scoped?
- Could serialized responses expose sensitive fields?

### E. Scale & Performance Attack
Ask:
- What happens at 10x or 100x the expected dataset?
- Is there an N+1 query?
- Is a large collection loaded into memory unnecessarily?
- Is payload size unbounded?
- Could locking or synchronous I/O become a bottleneck?

### F. Contract & Compatibility Attack
Ask:
- Did I change a route parameter, public method signature, event shape, database semantic, or API response contract?
- Which existing callers/consumers depend on it?
- Is the change backward compatible or explicitly required?

### G. External Evidence Attack
When external documentation or research materially affects the solution:
- Does the source match the installed dependency/framework version?
- Is it primary/authoritative?
- Do runtime/repository facts conflict with the docs?
- Is a second source or upstream code needed for a high-impact claim?

---

## 5. Contradiction Resolution

When verification evidence conflicts:

1. record the conflicting observations;
2. check whether they come from different environments, versions, fixtures, or execution paths;
3. reproduce the conflict with the smallest discriminating test;
4. prefer direct runtime/project evidence for current behavior;
5. update the hypothesis rather than forcing the evidence to fit the original theory.

A failed expectation is information, not noise.

---

## 6. Verification Reporting Template

Use concise reporting for substantial engineering work:

```markdown
### Verification

- **[PASSED]** `php -l app/.../File.php`
  - Result: No syntax errors detected.

- **[PASSED]** `php artisan test tests/Feature/...Test.php`
  - Result: 8 tests passed.

- **[FAILED]** `php artisan test tests/Feature/AdjacentTest.php`
  - Result: 1 failure.
  - Assessment: [Caused by this change / pre-existing / under investigation]

- **[BLOCKED]** Runtime mailer validation
  - Reason: SMTP credentials are unavailable in this environment.

- **[NOT RUN]** Full project suite
  - Reason: Change is localized and targeted + adjacent tests provide sufficient coverage.
```

Never state "all tests passed" unless all tests you are referring to were actually executed and passed.

Never call work "production-ready" while critical checks are failed, blocked, or unperformed.

---

## 7. Completion Gate

Do not declare substantive engineering work complete until:

- [ ] **Requirement Coverage**: The requested outcome is implemented or answered.
- [ ] **Evidence Grounding**: The solution is based on actual code/runtime/source evidence.
- [ ] **Assumption Control**: Material assumptions are verified or disclosed.
- [ ] **Root Cause / Design Gap**: The fix addresses the actual failure mechanism, or the workaround is explicitly justified.
- [ ] **Minimal Change**: No unrelated refactor, cleanup, dependency, or abstraction was introduced.
- [ ] **Invariant Preservation**: Security, authorization, database, business, and compatibility invariants remain intact.
- [ ] **Risk-Proportional Verification**: Appropriate checks were executed where feasible.
- [ ] **Failure Accounting**: Failed checks were investigated; blocked checks are explicitly reported.
- [ ] **Truthful Reporting**: No command/test/build/runtime result is claimed without execution.
- [ ] **Residual Risk**: Any material unresolved risk is stated clearly.

A task may still be implementation-complete with a **[BLOCKED]** verification step, but it must not be represented as fully verified.

---

## 8. Stop Condition

Stop verification when:

1. the changed behavior and material invariants have sufficient evidence;
2. no unresolved failure points to a likely regression;
3. remaining checks are redundant or low-value relative to risk; and
4. any blocked or unverified high-impact condition has been transparently disclosed.

Verification should converge. Repetition without new information is not rigor.
