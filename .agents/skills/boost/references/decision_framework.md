# Decision Framework & Trade-Off Analysis Guide

This guide outlines the systematic methodology for evaluating multiple implementation approaches, balancing trade-offs, and stress-testing edge cases.

---

## 1. Multi-Hypothesis Formulation

When encountering a non-trivial engineering task, avoid settling immediately on the most intuitive or first-discovered approach. Formulate at least **two genuinely viable alternative approaches**.

### Archetypes of Alternative Approaches:
1. **Direct In-Place Patch vs. Structural Extension**
   - *In-Place Patch*: Minimal change, fast, low disruption, but may accumulate technical debt if the abstraction is stretched.
   - *Structural Extension*: Introducing dedicated classes, traits, or strategies; cleaner long-term, but carries higher blast radius and refactoring risk.
2. **Synchronous Execution vs. Asynchronous Pipeline**
   - *Synchronous*: Immediate feedback, simpler error handling, but risks blocking the HTTP request lifecycle or timing out.
   - *Asynchronous (Queues/Jobs)*: Resilient, scalable, non-blocking, but requires failure retries, job monitoring, and event-driven state tracking.
3. **Database-Level Constraint vs. Application-Level Guard**
   - *Database-Level*: Atomic, guarantees data integrity across concurrent requests, immune to race conditions, but harder to migrate or customize error messages.
   - *Application-Level*: Flexible, rich validation messages, easier to test, but susceptible to TOCTOU (Time-of-Check to Time-of-Use) race conditions without DB locks.

---

## 2. Comparative Trade-Off Evaluation Matrix

For complex architectural decisions, score and evaluate candidates against these 6 core dimensions:

| Dimension | Key Questions | Risk Indicator |
|---|---|---|
| **1. Correctness & Invariant Safety** | Does this approach guarantee business rules under all edge cases? | Any risk of silent failure or inconsistent state. |
| **2. Blast Radius & Regressions** | How many existing files, tests, or consumers will be impacted? | Modifying shared base models or global middleware without isolation. |
| **3. Maintainability & Simplicity** | Does this follow established codebase idioms, or introduce unnecessary abstraction? | Introducing enterprise design patterns (CQRS, Repository, DTO) where simple MVC suffices. |
| **4. Performance & Resource Footprint** | Will this introduce N+1 queries, memory bloat, or lock contention? | Loops containing database queries or unbounded file streaming. |
| **5. Security & Authorization** | Does this respect strict tenant/role boundaries and prevent IDOR? | Relying only on frontend/route guards without model-level authorization checks. |
| **6. Operational & Rollback Complexity** | Can this change be easily rolled back or safely migrated in production? | Destructive database migrations without a backwards-compatible transition phase. |

---

## 3. Failure Mode & Edge-Case Analysis Matrix

Before committing code, systematically run your logic through these failure scenarios:

### A. Input Boundaries & Nullability
- **Empty / Null States**: What happens if the collection is empty, the string is blank, or the foreign key is null?
- **Extreme Inputs**: Negative numbers, massive payloads exceeding memory limits, unexpected Unicode characters.
- **Malformed Inputs**: Partial payloads missing mandatory nested keys.

### B. Concurrency & Race Conditions
- **Double Submission**: What happens if a user double-clicks a submit button within 50ms?
  - *Mitigation*: Database transactions with `lockForUpdate()`, idempotency keys, or unique composite constraints.
- **State Invalidation**: Can status transition from `draft` to `submitted` twice?
  - *Mitigation*: Atomic state transition guards (`UPDATE ... WHERE status = 'draft'`).

### C. Authorization & Ownership (Tenant/Supplier Isolation)
- **IDOR (Insecure Direct Object Reference)**: Can User A access or mutate User B's resource by guessing the ID or hashid?
  - *Rule*: Always scope queries to the authenticated user's ownership (e.g. `->where('supplier_id', auth()->id())`).
- **Privilege Escalation**: Can a lower-tier role trigger an endpoint intended for admins or purchasing?

### D. Distributed & External Failure
- **Storage / Disk Outages**: What if disk storage is full during an upload stream?
- **Network Timeouts**: What happens if an external API (currency rates, mailer, Pusher) fails or drops connection?
- **Transaction Boundaries**: Are database writes wrapped in `DB::transaction()` so partial failures do not corrupt data?
