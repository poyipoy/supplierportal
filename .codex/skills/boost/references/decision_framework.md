# Decision Framework & Trade-Off Analysis Guide

This guide is used for non-trivial engineering decisions. Its purpose is to prevent premature commitment, expose material trade-offs, and choose the smallest solution that preserves correctness and system invariants.

Do not use this framework mechanically for routine tasks.

---

## 1. Decision Trigger

Use structured comparison when at least one condition applies:

- more than one implementation is genuinely viable;
- the change crosses component or data boundaries;
- the wrong decision could create regressions, security issues, data loss, or silent failure;
- the choice changes operational complexity, performance characteristics, or long-term maintainability;
- the first discovered solution depends on an unverified assumption.

If there is only one realistic, project-consistent solution, document the decisive evidence and proceed without inventing alternatives.

---

## 2. Multi-Hypothesis Formulation

Separate **diagnostic hypotheses** from **implementation alternatives**.

### A. Diagnostic Hypotheses
For debugging, form the smallest useful set of plausible causes.

For each hypothesis:
- **Hypothesis**: [What might be wrong]
- **Supporting Evidence**: [Observed facts]
- **Disconfirming Evidence**: [Facts that weaken it]
- **Falsification Test**: [What observation would prove it wrong]
- **Confidence**: [Low / Medium / High]

Do not keep a hypothesis merely because it was discovered first.

### B. Implementation Alternatives
Only after the failure mechanism or design gap is sufficiently understood, compare genuinely viable solution paths.

Common archetypes include:

1. **Direct In-Place Patch vs. Structural Extension**
   - *In-Place Patch*: Minimal change, lower blast radius, faster verification.
   - *Structural Extension*: Better separation when a real reusable responsibility exists, but increases surface area and regression risk.

2. **Synchronous Execution vs. Asynchronous Pipeline**
   - *Synchronous*: Simpler control flow and immediate feedback.
   - *Asynchronous*: Better for long-running or retryable work, but adds queue state, monitoring, idempotency, and failure-recovery complexity.

3. **Database Constraint vs. Application Guard**
   - *Database Constraint*: Strong atomic integrity under concurrency.
   - *Application Guard*: Better UX and flexibility, but may require locking/atomic guards to avoid TOCTOU races.

4. **Local Compatibility Fix vs. Contract Change**
   - *Local Fix*: Preserves current public behavior and consumers.
   - *Contract Change*: Appropriate only when the existing contract itself is the defect or explicit requirement.

---

## 3. Evidence & Source Hierarchy

Use the strongest evidence available for the decision.

Preferred order:

1. **Direct runtime or repository evidence**
2. **Tests and reproducible execution**
3. **Version-matched primary documentation/specification**
4. **Upstream source/release notes/issue tracker**
5. **High-quality secondary technical sources**
6. **Informal discussion**, only when stronger evidence is unavailable

For external dependencies, determine the actual installed/pinned version before applying current documentation.

When evidence conflicts:
1. identify the conflict;
2. check whether sources describe different versions, environments, or code paths;
3. prefer direct project/runtime evidence for current project behavior;
4. revise the hypothesis instead of forcing the first interpretation.

---

## 4. Comparative Trade-Off Evaluation Matrix

Evaluate only dimensions that are material to the choice.

| Dimension | Key Questions | Risk Indicator |
|---|---|---|
| **1. Correctness & Invariant Safety** | Does the option preserve required business/data invariants under edge cases? | Silent failure, inconsistent state, weak atomicity. |
| **2. Evidence Strength & Assumption Load** | How much of the option depends on verified facts vs. assumptions? | Critical behavior depends on an untested assumption. |
| **3. Blast Radius & Regression Risk** | How many files, consumers, shared abstractions, or state transitions are affected? | Shared base models, middleware, global config, public contracts. |
| **4. Maintainability & Simplicity** | Does this follow existing codebase idioms without unnecessary abstraction? | New layers/patterns with no concrete responsibility. |
| **5. Performance & Resource Footprint** | Could it add N+1 queries, unbounded payloads, blocking I/O, memory growth, or lock contention? | Per-record queries, large in-memory transforms, broad locks. |
| **6. Security & Authorization** | Are tenant/role/ownership boundaries enforced server-side? | Frontend-only restrictions, unscoped object lookup, IDOR risk. |
| **7. Operational & Rollback Complexity** | Can it be deployed, observed, retried, and rolled back safely? | Destructive migrations, irreversible side effects, fragile rollout. |
| **8. Implementation Effort vs. Benefit** | Does added complexity buy a concrete correctness/operational benefit? | Large refactor for a localized requirement. |

Do not assign numeric scores unless the dimensions are sufficiently comparable. Narrative comparison is often more accurate.

---

## 5. Decision Rules

Prefer the option that:

1. satisfies the requirement completely;
2. is supported by stronger evidence;
3. preserves material invariants;
4. has the smallest coherent blast radius;
5. preserves existing contracts unless change is required;
6. introduces the least new operational state and cognitive overhead;
7. can be verified proportionally to its risk.

### Tie-Breaker Order
When options remain close:

**Correctness & Safety → Evidence Strength → Regression Risk → Reversibility → Simplicity → Maintainability → Performance → Implementation Effort**

Change the order only when the task explicitly makes another dimension dominant.

---

## 6. Failure Mode & Edge-Case Stress Test

Apply only relevant categories.

### A. Input Boundaries & Nullability
- Empty/null/missing values
- Negative or extreme values
- Oversized payloads
- Malformed nested data
- Duplicate/replayed input

### B. State, Concurrency & Retry
- Double submission
- Duplicate jobs/messages
- Lost update / stale read
- TOCTOU
- Idempotency
- Atomic state transition
- Lock scope and contention

### C. Authorization & Isolation
- IDOR / guessed identifiers
- Tenant/supplier/user ownership
- Privilege escalation
- Hidden/sensitive field exposure
- Route/middleware vs. model/policy enforcement

### D. Data Integrity & Partial Failure
- Multi-table partial writes
- Unique/foreign-key violations
- Transaction boundaries
- External side effects that cannot be rolled back
- Retry after partial success

### E. Distributed / External Failure
- API timeout
- storage exhaustion
- mailer/webhook failure
- rate limiting
- eventual consistency
- duplicate external delivery

### F. Performance & Scale
- N+1 queries
- unbounded result sets
- memory-heavy loops
- missing pagination/chunking
- lock contention
- long synchronous request duration

---

## 7. Adversarial Decision Review

Before committing to the chosen approach, attack it with these questions:

- What critical assumption could still be false?
- What evidence would make this decision wrong?
- Is this fixing the root cause or only muting the symptom?
- Did I overfit to the first plausible implementation?
- Could the same goal be achieved with a smaller coherent change?
- Did I introduce a new state, dependency, abstraction, or failure mode unnecessarily?
- Does the option still work under concurrency, retries, and partial failure?
- Does it preserve authorization/data-isolation boundaries?
- Am I relying on documentation that does not match the installed version?
- Did I change an existing contract without a requirement to do so?

If an answer exposes a material unresolved risk, return to evidence gathering or choose a different option.

---

## 8. Decision Record Template

```markdown
### Decision
Selected: **[Approach]**

### Why
- [Decisive evidence]
- [Invariant preserved]
- [Why blast radius is acceptable]
- [Why complexity is justified]

### Rejected Alternatives
- **[Alternative]**: [Decisive reason]
- **[Alternative]**: [Decisive reason]

### Residual Risk
- [Remaining risk, or "None material identified"]

### Verification Required
- [Specific checks needed to validate this decision]
```

---

## 9. Stop Condition

Stop comparing alternatives when:
- one option clearly dominates on correctness/evidence/risk;
- remaining differences are low-impact;
- additional alternatives would be artificial rather than genuinely viable; and
- more analysis is unlikely to change the decision.

Deep reasoning is not equivalent to endless comparison.
