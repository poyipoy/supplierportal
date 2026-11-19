# Claude's Cognitive Framework for Laravel Development

A reusable reasoning protocol for designing, debugging, refactoring, and reviewing Laravel code: what gets decided, in what order, on what evidence, and what has to be true before I call something production-ready.

**Contents:** [0. Orientation](#0-orientation) · [1. Initial Assessment](#1-the-initial-assessment) · [2. Architectural Mindset](#2-the-architectural-mindset) · [3. Problem-Solving Engine](#3-the-problem-solving-engine) · [4. Quality Assurance & Self-Correction](#4-quality-assurance--self-correction) · [5. Cross-Cutting Disciplines](#5-cross-cutting-reasoning-disciplines) · [6. Worked Demonstrations](#6-worked-demonstrations-process-not-product) · [7. Reasoning Failures](#7-reasoning-failures-i-actively-avoid) · [8. Master Procedure](#8-the-master-procedure) · [Appendix: Templates](#appendix-templates)

Short on time: read §0.4 and §0.5, then jump to the part you need. Items marked † are version-sensitive (see §0.2).

---

## 0. Orientation

### 0.1 Purpose and limits

This document describes the decision procedures I follow on Laravel engineering work: how a request becomes a problem definition, how an architecture is chosen before code is written, how a defect is diagnosed rather than guessed at, and how a proposed implementation is pressure-tested before I call it production-ready.

It is written for a developer who already knows PHP and Laravel. It is not a tutorial and not a catalog of syntax. The value is in the layer that decides *whether*, *where*, and *why*.

It is also not a transcript of hidden computation. The questions I ask, the evidence I weigh, the hypotheses I compare, and the conditions that make me revise a conclusion are reproducible procedures, and I commit to following them. A literal readout of the underlying computation is not something I can produce, so phrases like "I consider" or "I ask myself" are shorthand for a structured decision process, not a claim about internals.

### 0.2 Three kinds of statements

The document keeps three kinds of statement apart:

- **Durable principles**: how request lifecycles, ORMs, transactions, and concurrency behave regardless of release.
- **Version-dependent behavior**: specific APIs, defaults, and file layouts. These carry a dagger (†), meaning *confirm against the installed Laravel and PHP versions before relying on it*.
- **Project-specific choices**: decisions that depend on a team, a codebase, and its constraints. I do not universalize these.

**Currency box.** Facts like the ones below go stale, so they live in one place and carry a date. Re-verify at `laravel.com/docs` and `owasp.org` when they matter.

> **Last checked: 2026-11-18**
>
> - **Laravel 13** was released on 17 March 2026 and requires **PHP 8.3+** (it supports 8.3 to 8.5). Support policy: bug fixes for 18 months, security fixes for 2 years, no LTS releases. Laravel 13 gets bug fixes until Q3 2027 and security fixes until Q1 2028.
> - **Laravel 12**: bug fixes ended 13 August 2026; security fixes continue until 24 February 2027. **Laravel 11** security support ended 12 March 2026, and Laravel 10 and earlier are out of support. Plenty of production apps still run these, so branching on version is routine, not exceptional.
> - **Application structure differs by version.** Since Laravel 11 there is no default `app/Http/Kernel.php` or `app/Exceptions/Handler.php`; middleware and exception handling are configured fluently in `bootstrap/app.php`. Older apps use the Kernel.
> - **Laravel 13** renamed the CSRF middleware from `VerifyCsrfToken` to `PreventRequestForgery` (the old name is deprecated) and added origin-aware verification on top of the token check. It also added optional PHP-attribute configuration, including controller-level `#[Middleware]` and `#[Authorize]`.
> - **OWASP Top 10:2025** (the first revision since 2021): A01 Broken Access Control (now including SSRF), A02 Security Misconfiguration, A03 Software Supply Chain Failures, A04 Cryptographic Failures, A05 Injection, A06 Insecure Design, A07 Authentication Failures, A08 Software or Data Integrity Failures, A09 Security Logging & Alerting Failures, A10 Mishandling of Exceptional Conditions.

### 0.3 The spine

```
Request
  → Intent & constraint extraction
  → Context / uncertainty model
  → System & data model
  → Architectural decisions
  → Implementation strategy
  → Verification strategy
  → Security & performance review
  → Contradiction / failure detection
  → Revision
  → Final recommendation
```

The parts that follow map onto it. **Part 1** (Initial Assessment) covers intent and the uncertainty model. **Part 2** (Architectural Mindset) covers the system and data model and design decisions. **Part 3** (Problem-Solving Engine) is the diagnostic and refactoring machinery used when implementation meets a defect or an unfamiliar codebase. **Part 4** (Quality Assurance & Self-Correction) covers verification, security and performance review, contradiction detection, and revision. Parts 5 to 8 cover cross-cutting disciplines, worked demonstrations, anti-patterns, and the master procedure.

### 0.4 The depth dial: match the process to the stakes

Everything below is a toolbox, not a ritual. Running a seventeen-step diagnosis on a typo is as much a failure of judgment as skipping review on a payment flow.

| Stakes | Typical work | Process applied |
|---|---|---|
| Low | Explaining a concept, a rename, a small local change, a syntax question | Answer directly. State an assumption in one line if one matters. |
| Moderate | A new endpoint, a bug with a clear trace, a contained refactor | Light versions of Parts 1 and 2, plus the Part 4 checks that fit (correctness, authorization, query shape, tests). |
| High | Authentication or authorization, money or inventory, migrations on populated tables, multi-tenant boundaries, concurrency-prone writes, anything called "production-ready" or "secure" | The full protocol: every Part 4 pass, adversarial review, explicit confidence and residual uncertainty. |

Depth rises automatically when a mistake could mean data loss, a security-boundary failure, money moving incorrectly, or an irreversible change. The process stays mostly internal. An answer reports the conclusion, the assumptions that change it, the trade-offs accepted, the verification done or still recommended, and what remains uncertain. It does not recite the framework.

### 0.5 Quick protocol (the one-page version)

If only one page gets read or reused, for example as standing instructions for a project, use this one:

1. **Frame it.** Restate the real goal, constraints, and acceptance criteria. Note what was *not* asked.
2. **Separate belief tiers.** Stated facts, observed facts, inferences, assumptions, unknowns. Ask only when a missing fact would flip the recommendation, or when a wrong guess would be insecure, destructive, or architecturally incompatible.
3. **Pin the platform.** Laravel and PHP versions, FPM vs Octane, token API vs server-rendered app vs Sanctum SPA, the auth system, queue/cache/session drivers. Branch on anything unknown.
4. **Locate it.** Place the problem on the request/job/command lifecycle before theorizing.
5. **Model data first.** Ownership, cardinality, lifecycle, uniqueness. Assign every invariant to a layer: validation, authorization, domain logic, or database.
6. **Choose the simplest structure that meets demonstrated requirements.** Extract classes or patterns only under concrete pressure.
7. **Predict before writing.** Query shape, failure paths, concurrency, retries, scale.
8. **Debug by hypothesis.** At least two hypotheses, the cheapest discriminating test, one variable at a time, a fix at the missing-safeguard layer.
9. **Refactor behind evidence.** Characterization tests first, then the smallest safe step.
10. **Gate "done".** Authorization trace, data integrity and concurrency, security, performance, tests at behavior boundaries, adversarial review. Report confidence honestly.

---

## 1. The Initial Assessment

Before suggesting an implementation, I convert the request into an engineering problem definition.

### 1.1 Intent extraction

A Laravel request carries more than its literal sentence. I read four layers at once:

- **Explicit ask**: the literal thing requested.
- **Underlying goal**: why it is wanted. A goal usually tolerates more than one implementation.
- **Constraints**: existing schema, team conventions, hosting environment, time.
- **Acceptance criteria**: what would make the requester consider it done.

I also look for **what is not being asked**. "Fix the 500 on checkout" is not a request to redesign checkout, even if I notice opportunities. Scope creep introduced unasked is a cost, not a gift.

Certain words reliably change what I check:

| Signal | Typical interpretation | Effect on my reasoning |
|---|---|---|
| "quick fix" | Time pressure; a narrower, possibly temporary remediation is acceptable | Name the shortcut and what it defers; never present a partial fix as complete |
| "production-ready" | Every Part 4 pass applies, not just correctness | Fold in security, performance, and failure handling unprompted, and say so |
| "API" | Ask which kind: a stateless token API (no session, CSRF, or Blade) or a first-party SPA on Sanctum cookie auth (session and CSRF cookie apply) | Reason in client contracts (status codes, JSON shape, versioning); confirm the auth mode before diagnosing a 401 or 419 |
| "large dataset" | Memory and query count dominate, not just business logic | Weight chunked or lazy iteration, indexes, and pagination above convenience methods |
| "legacy" | Existing behavior, including undocumented quirks, is itself a requirement | Legacy mode (§3.7); characterize before changing |
| "real-time" | Latency and delivery-order guarantees matter | Check whether the actual transport (broadcasting, queues, polling) can meet the implied guarantee or only approximates it |
| "multi-tenant" | Every query and authorization check needs an explicit tenant boundary | Tenant leakage becomes a first-class security concern, checked at every persistence point |
| "secure" | The security pass must be exhaustive, not incidental | Validation alone never satisfies this |
| "high traffic" | Concurrency and contention are primary risks | Give the data-integrity and concurrency pass extra weight |
| "upgrade" | Behavior preservation across a Laravel or PHP version boundary is the deliverable | Look for behavior changes (renamed classes, changed defaults), not only syntax deprecations |
| "without changing behavior" | Refactor, not redesign; success is preserved behavior | Require characterization evidence before treating any change as safe |

### 1.2 Requirement decomposition

Natural-language requirements get translated into a structured shape before I think about classes or migrations. The template is generic by design: it is filled in per request.

| Category | Guiding question |
|---|---|
| Actors | Who or what initiates or is affected by this: humans, other services, scheduled processes? |
| Operations | Which state-changing or state-reading actions are involved, and which are commands versus queries? |
| Entities | Which persistent concepts does it touch? (Not necessarily one-to-one with Eloquent models.) |
| State transitions | Which before/after states are valid, and what triggers movement between them? |
| Inputs | What data arrives, from where, in what shape, and which parts are client-controlled versus server-derived? |
| Outputs | What must the caller receive, in what contract (status code, JSON shape, view, redirect, nothing)? |
| Invariants | What must stay true on every path, and is it a validation, application, or schema-level concern? |
| Authorization rules | Who may do this, under what condition, independent of whether the input is well-formed? |
| Persistence | What must survive the request, and is a history or audit trail implied even if unstated? |
| Side effects | What happens besides the primary write: notifications, events, external calls, cache invalidation? |
| Failure conditions | What can legitimately fail, and how should failure look to the caller versus internally? |
| Concurrency | Can this be triggered twice, simultaneously, or out of order, and does that matter? |
| Performance | Is there a stated or implied volume or latency target? No stated target is not the same as no real one. |

### 1.3 Facts versus inference

I keep five tiers of belief separate:

- **Stated facts**: supplied directly by the requester.
- **Observed facts**: seen in code or runtime evidence, if I have access to it.
- **Reasonable inferences**: very likely true given the facts, but not stated.
- **Assumptions**: beliefs I choose to hold because progress requires some belief.
- **Unresolved unknowns**: things that matter and cannot currently be resolved.

Assumptions are prioritized for verification by *impact if wrong × likelihood of being wrong ÷ cost to verify*. In practice:

- Being wrong would change only cosmetic details: proceed silently. ("This project uses PSR-4 autoloading.")
- Being wrong would change correctness, but the blast radius is contained: state the assumption and proceed conditionally.
- Being wrong would silently produce an insecure, data-destructive, or architecturally incompatible result: ask, or present branching recommendations. ("`email` is uniquely constrained at the database level" is cheap to verify and expensive if false.)

### 1.4 Missing context

Before answering a nontrivial question I look for the following, because their presence changes what a responsible answer looks like.

- **Platform**: Laravel and PHP versions; database engine and version; runtime model (PHP-FPM vs Octane's persistent workers); queue, cache, and session drivers; deployment topology (proxy or load balancer, number of servers running the scheduler).
- **Application shape**: server-rendered monolith vs API backend (token auth vs Sanctum SPA); the auth system; the relevant routes and middleware stack; model relationships and cardinalities; migrations and current schema; the controller, service, or job code actually involved.
- **Local conventions**: folder structure, existing patterns (actions, services, repositories), the test framework, formatter and static-analysis configuration. Consistency with the codebase usually outweighs my default preferences.
- **Failure evidence**: the exact exception and full stack trace (not a paraphrase); the request payload; reproduction steps; differences between where it works and where it fails; recent deploys, dependency bumps, or config edits; application logs; relevant Composer packages and versions; existing tests.

A few are worth calling out for *why* they matter:

| Question | Why it matters | If ignored |
|---|---|---|
| Which Laravel major version? | Middleware and exception registration, casting syntax, and starter-kit structure differ across 10 through 13 | A correct answer for one version can be broken advice for another |
| Monolith, token API, or Sanctum SPA? | Decides whether CSRF, session, and Blade-related failure modes are even in scope | Diagnosing a 419 in a stateless token API wastes time; ignoring it in a Sanctum SPA misses the likely cause |
| The full stack trace, not a summary? | The first meaningful application frame and the caller chain are lost in paraphrase | A framework frame gets blamed when the defect is several frames upstream |
| Which queue, cache, and session drivers? | `sync`, `database`, and `redis` queues, and `file` vs `redis` sessions, produce different failure classes | A timing or concurrency hypothesis that fits `redis` may be irrelevant under `sync` |
| Any recent deploy, dependency bump, or config edit? | Recent-change correlation is among the cheapest, highest-value debugging evidence | The hypothesis space stays much larger than necessary |

When something is unavailable I do not invent it. I state the assumption I'm substituting and mark the result conditional; or I branch on the missing fact ("Laravel 11+: do X; 10 or earlier: do Y"); or, when the missing fact changes correctness or safety rather than polish, I ask.

### 1.5 Context sufficiency

| Situation | Response mode |
|---|---|
| Enough evidence, low risk if slightly wrong | Answer directly |
| Enough evidence, but the outcome hinges on a fact I lack (Laravel version, queue driver) | Answer conditionally, branching on that fact |
| Several plausible interpretations that can't be ranked from what's given | Present labeled candidate branches, and ask which applies |
| The missing fact is cheap for the requester to supply and costly to guess wrong (security- or data-affecting) | Ask directly |
| The honest next step is inspection, not a fix ("can't tell without the query log, the migration, or the actual exception") | Recommend the specific inspection step |

The deciding variable is not how much information I have, but how much the *conclusion* would change if a specific missing fact were different. If every plausible value leads to the same recommendation, I don't need it. If it flips the recommendation, I need it before committing.

### 1.6 Lifecycle reconstruction

For nearly any problem, feature or bug, I reconstruct the path a request, job, or Artisan command actually takes:

```
route → middleware → route-model binding → controller/action
  → validation (Form Request or inline) → authorization (policy/gate)
  → application/domain logic → persistence (Eloquent/query builder,
    inside or outside a transaction) → events/listeners, queued jobs
  → response shaping (API Resource / view) → HTTP response
```

For non-HTTP entry points, a scheduled command or a queue worker replaces the route and middleware stage; the downstream stages (authorization, domain logic, persistence, side effects) still apply.

Each stage constrains which failures are even possible there, and the same map later decides where new logic belongs. A 403 before any domain logic runs is a different investigation from a 403 raised deep in a service class; a slow response differs depending on whether the cost sits in middleware, the query layer, or serialization. Localizing a symptom to a lifecycle stage is usually the highest-leverage step in narrowing the hypothesis space.

### 1.7 Don't-conflate table

Several problem classes present with near-identical symptoms but live in different layers:

| Surface symptom | Candidate A | Candidate B | Discriminating question |
|---|---|---|---|
| Request rejected before business logic runs | Validation failure (422) | Authorization failure (403) | Was the *shape* of the data wrong, or is *this actor* disallowed regardless of shape? |
| "Record not found" | Route-model-binding miss (bad ID in the URL) | Row missing, soft-deleted, or hidden by a global scope | Does the row exist in the table, and is a scope filtering it? |
| Relationship returns wrong or empty data | Eloquent misuse (wrong method or key) | Schema mismatch (missing FK, wrong column) | Does the SQL Eloquent generates match the real table? |
| Data out of sync between two reads | Transaction visibility | Queue timing | Was the second read inside or outside the same transaction, and before or after a queued job finished? |
| Data looks stale | Caching | Stale database read (replica lag) | Does bypassing the cache change the result, and does the connection read from a primary or a replica? |
| Operation "succeeds" but the outcome is wrong | Business-rule violation | A framework exception silently caught and swallowed | Is there a broad `catch` between the throw site and the response? |

---

## 2. The Architectural Mindset

Before choosing a Laravel class, trait, or feature, I build a small set of conceptual models and weigh the trade-offs between them.

### 2.1 System boundaries

I think in six conceptual boundaries. They are places where I check for a specific class of concern, not mandatory layers.

| Boundary | What lives there | Concern checked there |
|---|---|---|
| HTTP | Where untrusted input enters; the response contract | Validation, authorization entry points, status codes, JSON shape |
| Application / use case | The specific operation, independent of transport | Orchestration, transaction ownership |
| Domain / business rules | Rules that would hold if the framework or database changed (only where the app is complex enough to have them) | Invariants, state transitions |
| Persistence | How state is stored and queried | Constraints, transactional consistency, query shape |
| External service | Anything outside the app's own process and database | Timeouts, retries, failure isolation, volatile contracts |
| Asynchronous | Queued jobs, scheduled commands, listeners | Retry, idempotency, ordering, failure visibility |

A small application can legitimately collapse all six into "controller talks to Eloquent." That is not a defect; it is the right amount of structure at that size.

### 2.2 Data-first reasoning

I model data before behavior, because behavior built on an unclear data model accumulates defects that look like logic bugs but are really schema gaps. For each entity a request touches I settle:

- **Relationships and cardinality** (one-to-one, one-to-many, many-to-many, polymorphic), and **optionality**.
- **Ownership**: does deleting the parent delete, orphan, or block deletion of the child?
- **Lifecycle**: status fields and which transitions are valid.
- **Uniqueness**: global, or scoped (for example per tenant)?
- **Referential integrity**, whether history or audit data is implied, and timestamp semantics.
- **Soft deletes**: a "deleted" row is still a row. Unique constraints, foreign keys, and eager loads still see it unless filtered.
- **Pivot tables** that carry their own attributes, and **polymorphic type discriminators**, which validation and authorization both must account for.

*Where an invariant lives* is a recurring decision, because the layers are not interchangeable:

| Invariant | Layer(s) | Why |
|---|---|---|
| "Must be a valid email" | Validation (Form Request rule) | A shape check on untrusted input; no authorization or persistence concern |
| "Users may edit only their own resource" | Authorization (policy or gate) | Depends on *who* is asking; validation cannot safely express it as the sole enforcement point |
| "No two rows share this value" | Unique index, plus an application-level check for a friendly error | Only a database constraint is safe under concurrent requests |
| "Order total equals the sum of its line items" | Domain logic, recomputed server-side | A derived value; trusting a client-supplied total is a security and integrity gap at once |
| "A `finalized` record never returns to `draft`" | Transition code, backed by a database CHECK constraint or trigger where writes can't be fully controlled | Easy to enforce in one code path and easy to violate through direct writes, admin tooling, or a second developer months later |

### 2.3 Schema design and the concurrency principle

Before writing a migration I settle:

- Primary-key strategy (auto-increment vs UUID/ULID: index locality, exposure of sequential IDs).
- Foreign keys, with `onDelete`/`onUpdate` behavior that matches the ownership model.
- Unique constraints, including composite ones (unique per tenant, not globally).
- Nullable vs defaulted columns, and types that match the domain (an enum or lookup table instead of a free string).
- Indexes driven by real query patterns, not guesswork.
- Deletion semantics: hard, soft, or archival.
- Normalization vs a deliberate, justified denormalization (for example a maintained count).
- Migration safety on populated tables: a `NOT NULL` column without a default fails or blocks; adding an index to a large table can lock it, depending on engine and version.
- Reversibility, and what happens if two migrations touching one table run concurrently during a deploy.

**Principle: application checks alone do not guarantee integrity under concurrency.** A controller that asks "does a row with this value exist?" and then creates one has a window between check and write. Two concurrent requests can both pass the check and both insert. This is a predictable consequence of two separate round trips not being atomic, not an exotic edge case. The reliable backstop is a database-level unique constraint (so the second write fails loudly) or an explicit lock around check-and-write. I do not consider a uniqueness requirement "handled" until it is enforced where concurrent requests cannot both slip past. "The validation rule already checks this" is necessary but not sufficient.

**Concurrency toolbox in Laravel.** This is the single reference for the mechanisms the rest of the document points to.

| Problem | Mechanism | Notes |
|---|---|---|
| Duplicate rows from check-then-insert | Unique index; catch `UniqueConstraintViolationException`† (or a `QueryException` whose SQLSTATE signals a unique violation: 23000 on MySQL, 23505 on PostgreSQL) | The application-level check exists only for friendly errors |
| "Create if absent" | `createOrFirst()`† (insert first, fall back to select) or `upsert()` | Both rely on a unique index. `firstOrCreate()` selects first, so it still races without one |
| Read-modify-write (balances, counters, stock) | Atomic SQL (`increment()`/`decrement()`, or an `UPDATE` with the guard in the `WHERE` clause and a check on affected rows) or `lockForUpdate()` inside a transaction | Never compute from a stale in-memory attribute |
| Lost updates on edited records | Optimistic version column (`WHERE version = ?`, check affected rows) or `lockForUpdate()` | No built-in; this is a pattern |
| Cross-process mutual exclusion | `Cache::lock()` | Needs a lock-capable cache driver; set the TTL above the worst-case hold time and always release |
| Deadlocks | Consistent lock ordering; `DB::transaction($callback, $attempts)` retries on deadlock | A retried callback must be safe to run again |
| Duplicate job processing | An idempotent handler (dedupe key plus unique constraint) | `ShouldBeUnique` and `WithoutOverlapping` reduce duplicate dispatch and overlap but do not make a handler idempotent |
| Job dispatched inside a transaction | After-commit dispatch (`afterCommit()`, `$afterCommit`, or the `after_commit` queue option)† | Without it the worker can run against rows that don't exist yet |
| Scheduler on multiple servers | `onOneServer()`, `withoutOverlapping()` | `onOneServer()` needs a lock-capable cache driver |

### 2.4 Query-shape reasoning

Before writing Eloquent code I predict the SQL it will generate.

- **N+1.** Touching a relationship inside a loop over N parents issues a query per iteration unless it was eager-loaded. One lazily accessed relationship costs 1 + N queries. If each of the M children per parent also lazily loads a relationship, it is 1 + N + N×M. `with('children.relation')` is 3 queries regardless of N. The questions: how does the query count grow with the collection, how many loaded rows are actually used, and how many full models were hydrated where a scalar or count would do?
- **Pick the tool by the shape of the question.** `with` for data you will read; constrained eager loading to limit which related rows load; `withCount`/`withSum`/`withExists` to push aggregation into SQL instead of hydrating models to count them; `whereHas`/`whereExists` for existence checks that should not load related rows at all. The wrong choice yields code that is correct but wasteful.
- **Pagination.** Offset pagination re-scans and discards rows as offsets grow, and `paginate()` also runs a `COUNT` query. `simplePaginate()` skips the count. `cursorPaginate()` avoids offset cost but gives up arbitrary page-jumping and needs deterministic ordering.
- **Large sets.** `chunkById`/`lazyById` are safe when rows change while you iterate; plain `chunk` can skip rows if the filtered column is being updated. `cursor()` holds one model at a time, but the PDO driver may still buffer the entire result in memory, so `lazy()` is usually the safer default for very large sets.
- **Width and hydration.** Select only the columns needed. Use the query builder, `pluck`, or aggregates when model behavior (accessors, relationships, events) isn't needed.
- **Index alignment.** Does the generated query use an existing index (`EXPLAIN`), or force a scan?
- **Write amplification.** Observers or listeners that trigger extra writes per save; where transaction boundaries sit relative to these queries.
- **Detect in development.** `Model::preventLazyLoading()`† turns accidental lazy loads into exceptions outside production, and `Model::shouldBeStrict()`† bundles that with other strictness checks.

Eager loading fixes one specific shape problem. It is not a universal optimization, and loading relationships nothing downstream reads is its own defect.

### 2.5 Routing as a contract

I hold the whole chain (method, URI, middleware stack, model binding, controller or action, authorization, response contract) as one unit, and treat a route as a contract before deciding what sits behind it.

- **Nested resources** imply an ownership relationship, but a nested URL enforces it only if bindings are scoped (`scopeBindings()`, or a custom key on the nested parameter) or an explicit check exists.
- **Implicit binding** returns 404 for a missing record. Decide whether that is right or whether tenant-aware (scoped) binding is required.
- **Route names** affect maintainability and the safety of URL generation elsewhere in the app.
- **API versioning** (URI, header, or none yet because there is one consumer) is a decision, not a default.
- **Middleware layering** (global, group, route) and execution order shape behavior. Where middleware is registered depends on the Laravel version†.

### 2.6 Responsibility placement

Deciding whether logic belongs in a route closure, controller, Form Request, model, action class, service class, domain object, policy, middleware, listener, job, observer, Artisan command, or resource is one of the most consequential, and most over-dogmatized, decisions in Laravel. I avoid rules like "controllers must always be thin." Thinness is a proxy for testability, reuse, and clear transaction ownership, and optimizing the proxy produces pass-through service classes that add indirection without the benefits.

| Criterion | Favors keeping logic in place (controller, Form Request, model) | Favors extraction to a dedicated class |
|---|---|---|
| Reuse | One entry point | Needed from web, API, console, and a job |
| Complexity | A handful of straightforward steps | Multiple branches, several collaborators, a real multi-step workflow |
| Transaction ownership | The action is the natural transaction boundary | The unit of work spans several model operations and is easier to see and test in one place |
| Side effects | None beyond the primary write | Several (notification, event, external call) worth naming and testing as a unit |
| Testability | HTTP tests already exercise it well | Business logic benefits from testing without the HTTP kernel |
| Coupling | Low: touches one model | High: orchestrates several models or services, and that orchestration is worth naming |
| Lifecycle | Stable, unlikely to grow | Expected to accumulate branches or steps |
| Business significance | Incidental or CRUD | A named business process domain experts already have a word for |
| Framework integration | Leans cleanly on validation, authorization, Eloquent | Must be framework-agnostic for a specific, demonstrated reason (rare; state it when true) |
| Expected change | Unlikely to vary independently | Likely to vary independently (for example orchestration per plan tier) |

No single criterion is decisive. I look for two or three pointing the same way before extracting, and I'm comfortable leaving straightforward CRUD in a controller and model even in a large application.

### 2.7 Pattern selection

I evaluate named patterns by the pressure that justifies them, not by popularity.

| Pattern | Pressure that justifies it | Cost | Overengineering signal |
|---|---|---|---|
| Plain MVC | None; it is the baseline | Minimal | Not applicable: fall back to it absent a pressure |
| Service layer | Logic reused across web, API, console, jobs | Indirection; can become a dumping ground | A `UserService` that is just `User` with extra steps |
| Action classes | An operation must be named, reusable, and independently testable | Many small classes if used indiscriminately | An action per controller method regardless of complexity |
| Repository | Truly swappable persistence, or a seam for multiple data sources | Hides Eloquent's query API behind a thinner interface | "We might leave MySQL someday" with no other evidence |
| DTOs | Crossing a boundary (queue, external API, module) where an array's shape isn't self-documenting | Mapping code | Wrapping every model "for cleanliness" with no boundary crossed |
| Value objects | A primitive (money, email, slug) carries behavior and invariants that are duplicated everywhere | Small ceremony per use | Wrapping scalars that have no behavior |
| Strategy | Interchangeable algorithms chosen at runtime | An interface plus implementations | One implementation "in case we need another" |
| Factory | Construction is nontrivial or varies by input | Indirection over `new` | Wrapping trivial `new Model()` calls |
| Adapter | Isolating a third-party API you don't control | An extra interface layer | Wrapping stable first-party Laravel APIs "just in case" |
| Domain events | Several independent side effects react to one occurrence | Control flow that is harder to trace | One event with one listener that could be a method call |
| CQS/CQRS split | Read and write models have diverged in scale or shape | Two paths to keep consistent | Applied to small CRUD with no divergence |
| Feature/domain modules | The type-based layout no longer aids navigation and features are largely independent | Migration cost | Applied where type-based grouping never caused a problem |

The common failure mode is treating "enterprise" patterns as a checklist. A Laravel app that copies a repository-service-DTO-CQRS stack from a larger, differently constrained system tends to get *worse*: more files for the same behavior, more places to enforce an invariant inconsistently, and a standing tax on every future change, in exchange for flexibility it will very likely never use.

### 2.8 Dependency reasoning

- **Interface vs concrete class.** An interface needs more than one real or concretely planned implementation, or a genuine test-double motivation. Otherwise it is ceremony.
- **Injection.** Constructor injection for anything with real behavior or an external dependency (visible in the signature, mockable). Facades and helpers (`Cache::`, `Auth::`, `now()`) are fine for simple framework conveniences.
- **Bindings** live in service providers. Choose singleton, scoped, or transient deliberately: a singleton that quietly accumulates per-request state is a classic subtle bug, especially under Octane's persistent workers (§4.6).
- **Packages.** A dependency is a supply-chain and maintenance commitment. Weigh whether its abstraction fits the problem or is being bent to fit.

Dependency direction check: when a controller depends on a contract and infrastructure implements it, dependencies point inward toward the application's own abstractions.

```
Controller ──depends on──► PaymentGatewayContract ◄──implements── StripeGatewayClient
```

That inversion is worth its cost when the implementation is genuinely likely to be swapped or faked in tests. It is not a default posture for every collaborator.

### 2.9 Synchronous versus asynchronous work

I move work to a queue when one or more of these hold: it adds latency the caller doesn't need to wait for; it depends on an external service whose latency or reliability shouldn't gate the request; retries are meaningful; ordering doesn't matter or can be preserved; eventual consistency is acceptable; and it is dispatched at a point that is safe relative to the enclosing transaction.

Hazards I check explicitly (mechanisms are in the §2.3 toolbox):

- **Dispatch inside a transaction.** A worker sees only committed data. A job dispatched before commit can run against rows that do not exist yet. Use after-commit dispatch†.
- **At-least-once delivery.** Assume a job may run twice and design idempotency in (unique constraint, dedupe key). `ShouldBeUnique` and `WithoutOverlapping` help, but they are not idempotency.
- **Timeout coherence.** The queue connection's `retry_after` must exceed the job's `timeout`, or a still-running job is handed to a second worker.
- **Failure visibility.** Does a failed job surface where a human will see it, or sit unwatched in `failed_jobs`? Is a retry safe?
- **Ordering.** Is it required, and if so how is it preserved?

### 2.10 Architectural trade-off matrix

"More architecture" is not free and not always better. Generic comparison for one hypothetical feature:

| Approach | Simplicity | Coupling | Testability | Transaction safety | Team scalability | Operational complexity | Future-change cost |
|---|---|---|---|---|---|---|---|
| Logic in controller + Eloquent | High | High | Medium (needs an HTTP test) | Depends on discipline | Low | Low | High once logic grows |
| + Form Request + Policy | High | Medium | Medium-High | Same | Low-Medium | Low | Medium |
| + Action/Service for the core operation | Medium | Medium | High | High (a natural place to own the transaction) | Medium | Low-Medium | Lower |
| + Repository/DTO layer | Lower | Lower in theory | High | High | Medium-High for large teams | Medium | Depends on whether the abstraction is load-bearing |
| + Domain/CQRS split | Lowest | Lowest | Highest | Highest if done well | High, for teams that need the separation | High | Lowest, but only if the complexity was warranted |

Reading this as "always move right" is exactly the mistake to avoid. The right row is the leftmost one that satisfies the *demonstrated* requirements, not imagined future ones.

### 2.11 Pre-code mental simulation

Before implementing, I simulate the chosen design along thirteen paths, in five groups:

| Group | Paths |
|---|---|
| Identity and input | Happy path; invalid input; unauthenticated request; authenticated but unauthorized request; missing record |
| Repetition | Duplicate or replayed request; retry of a partially completed operation |
| Failure | Database failure mid-operation; external service failure or timeout; partial failure (some steps succeeded) |
| Contention and scale | Concurrent modification by a second actor; the same operation at a much larger data scale |
| State | A malformed or unexpected internal state (for example a status value the code doesn't anticipate) |

Any gap found here is fixed in the design *before* implementation, not patched afterward.

### 2.12 Design order

1. Decide which of the six boundaries this requirement actually exercises. Don't assume all six.
2. Model entities, cardinality, ownership, lifecycle, and invariants. Assign every invariant to validation, authorization, domain logic, a database constraint, or a combination. None stays unassigned.
3. Sketch the schema implications: tables, keys, constraints, and indexes implied by the invariants and the expected query patterns.
4. Map the request or job lifecycle the requirement travels through.
5. Predict query shape and expected data volume, now and at 10×.
6. Place responsibilities using §2.6. Default to framework constructs and extract only where at least two concrete criteria agree.
7. Screen patterns against demonstrated pressure (§2.7).
8. Decide sync vs async for each side effect, including where each job is dispatched relative to commit.
9. Score the design against the §2.10 dimensions.
10. Run the §2.11 simulation and revise the design where it finds gaps.
11. Only then move to implementation strategy.

---

## 3. The Problem-Solving Engine

This is the most operationally detailed part of the framework. It covers two modes: diagnosing a failure in a running system, and safely changing a system whose full behavior isn't yet known (legacy code).

### 3.1 Diagnostic procedure for complex Laravel errors

**Inputs** (as available): symptom, reproduction steps, exception, stack trace, request/job/command context, logs, database state, config and environment snapshot, recent change history, source access.

**A. Frame the failure**

1. **Classify the failure surface.** HTTP error response, uncaught exception, wrong-but-200 result, silent data corruption, performance degradation, or intermittent failure. The category decides which evidence is worth collecting.
2. **Establish the reproducibility tier.** Tier 1: deterministic locally. Tier 2: only in a specific environment or against specific data. Tier 3: intermittent, load-dependent, or race-like. Tier 4: reported once, unconfirmed. Tiers 1 and 2 allow direct hypothesis testing; tiers 3 and 4 need indirect evidence (logging, sampling, correlation with load or timing).

**B. Read the evidence**

3. **Parse the exception.** The class usually narrows the subsystem (`QueryException`, `ModelNotFoundException`, `AuthorizationException`, `ValidationException`, `BindingResolutionException`, a serialization failure, a timeout). The message is primary evidence. A `QueryException` carries the literal SQL and the SQLSTATE, which alone separates a constraint violation from a syntax error, a deadlock, or a connection failure.
4. **Walk the stack, frame by frame.** Partition frames into application, first-party framework, and vendor code. The first application frame from the top (nearest the throw) is usually the most actionable. The last application frame from the bottom identifies the route, command, or job that started the chain. For every framework or vendor frame, ask whether it *caused* the failure or merely *transmitted* invalid state from upstream. Framework code throwing rarely means the framework is defective; it usually means a precondition was violated several frames earlier. The line that throws is not always the line that introduced the defect.
5. **Reconstruct input and state at the failure point.** Which values did the failing line depend on, and where did they originate (request input, a database row, config, an upstream response, a default parameter, an unguarded relationship access)? Classify the defective state: missing, null, wrong type, wrong shape, stale, duplicated, out of range, or unauthorized. The classification often points at the layer where the defect entered.

**C. Hypothesize and test**

6. **Generate hypotheses before testing any.** At least two, aiming for three or four.
7. **Rank them** by likelihood × explanatory power × blast radius ÷ cost to test.
8. **Design the cheapest discriminating test for each.** Cost order: existing logs, then a targeted log or dump, then a reproducing test, then a debugger, then more production evidence. A good test predicts *different* outcomes depending on which hypothesis is true, and at least one test must be able to *disprove* the leading hypothesis.
9. **Change one variable at a time.** Several simultaneous changes destroy attribution.
10. **Drop contradicted hypotheses.** One solid contradiction is enough; do not keep a hypothesis alive for convenience.

**D. Resolve**

11. **Find the root cause, not only the proximate one**, using the hierarchy in §3.5.
12. **Evaluate blast radius.** What else shares this code path, model, trait, macro, global scope, or service? Could the same defect be latent elsewhere?
13. **Design the repair at the correct layer.** A fix at the missing-safeguard layer (a database constraint, an authorization check) is usually more durable than one that patches only the immediate failure.

**E. Verify and close**

14. **Validate against the original reproduction**, and confirm the *mechanism* of correctness is understood, not merely observed.
15. **Run regression checks**: the existing tests, any characterization tests written during the investigation, and a deliberate re-check of the adjacent paths from step 12.
16. **Review sibling defects of the same class.** If this action lacked an ownership check, do its siblings?
17. **State residual uncertainty.** What remains unverified (for example confirmed in staging with production-shaped data, not yet observed under production concurrency)?

**Output:** root-cause statement, evidence trail, fix description, blast-radius notes, regression evidence, confidence level, residual uncertainty.

### 3.2 Reading a stack trace: two worked examples

Both are fictional and minimal. They show the classification technique, one in an HTTP context and one in a queue job. Frames run from the throw site (top) to the entry point (bottom).

**Example 1: a foreign-key violation.**

```
Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint
violation: 1452 Cannot add or update a child row: a foreign key constraint
fails ...
  #0 vendor/laravel/framework/.../Connection.php(...)   [FRAMEWORK]
  #1 vendor/laravel/framework/.../Builder.php(...)      [FRAMEWORK]
  #2 app/Models/Membership.php(41)                      [APPLICATION: first app frame from top]
  #3 app/Actions/AttachTeamMember.php(18)               [APPLICATION]
  #4 app/Http/Controllers/TeamController.php(27)        [APPLICATION: last app frame from bottom = entry point]
  #5 vendor/laravel/framework/.../Route.php(...)        [FRAMEWORK]
```

The throw happens in framework connection code, but that frame only *transmits* a foreign-key violation. `Membership.php:41` is where the insert was attempted; `TeamController.php:27` is the entry point that accepted a `team_id` without confirming it exists or belongs to this actor. The evidence points to a missing existence and ownership check *before* the insert, not to a defect in `Connection.php`. Framework frames are the surface, not the cause. (This is an inference from the trace; reading the controller confirms or refutes it.)

**Example 2: a queue job.**

```
InvalidArgumentException: Unsupported cast type for attribute [status]
  #0 app/Models/Invoice.php(58)                         [APPLICATION: first app frame from top]
  #1 vendor/laravel/framework/.../HasAttributes.php     [FRAMEWORK]
  #2 app/Jobs/FinalizeInvoice.php(22)                   [APPLICATION: the job's own handle()]
  #3 vendor/laravel/framework/.../CallQueuedHandler.php [FRAMEWORK]
```

The first application frame sits in the model's cast configuration, not in the job. That reorders the investigation. `FinalizeInvoice` is simply the first caller to exercise a cast that was likely already broken (for example a cast type renamed or removed in a dependency upgrade). The job is probably not the defect, and wrapping its call in a `try/catch` would suppress the symptom without touching the misconfigured cast.

### 3.3 Hypothesis-driven debugging

Debugging is a cycle: **observation → hypotheses → predicted evidence → discriminating test → evidence → hypothesis update.** I deliberately generate more than one hypothesis before investing in any, because the first idea is not privileged for arriving first. Confirmation-seeking is a bias I guard against by requiring at least one test that could *disprove* the leading hypothesis.

A generic table to fill per case:

| Hypothesis | Predicted evidence if true | Test | Cost | Result |
|---|---|---|---|---|
| A relationship is lazy-loaded inside a loop (N+1) | The query log shows one query per row of the outer collection | Enable query logging for one request and count queries | Low | |
| A global scope is silently filtering expected rows | Querying without the scope changes the result count | Run the same query with and without the scope | Low | |
| The cache is serving a stale value | The value corrects itself right after the key is invalidated | Flush the specific key and re-request | Low | |
| A race between two concurrent requests | Failures correlate with concurrent load and don't appear single-threaded | Fire concurrent requests deliberately in test or staging | Medium | |
| A third-party API times out intermittently | Matching entries in the outbound client's logs and timing | Check upstream logs and timing for the failure window | Medium | |

### 3.4 Laravel diagnostic branches

The general procedure adapts to the category of problem. What matters is the *questions asked* and the *evidence examined*, not a canned fix, since the right fix depends on what the evidence shows.

| Category | Diagnostic questions | Evidence to examine |
|---|---|---|
| Routing (404/405) | Does `route:list` show a route matching this method and URI? Is a stale route cache out of step with the route files? | `php artisan route:list`; route cache vs route file timestamps |
| Middleware | Which middleware ran, in what order, and did one short-circuit the pipeline? Is it registered globally, per group, or per route, and does that match intent? | Registration (`Kernel.php` or `bootstrap/app.php`†); the middleware's `handle()` |
| Container resolution | Is the binding registered in a loaded provider? Is there a circular dependency? | Registered bindings, provider boot order, the exact class that failed to resolve |
| Dependency injection | Does the constructor type-hint an interface with no bound implementation, or a class whose own arguments can't be resolved? | Constructor signature, container bindings |
| Authentication | Which guard is active on this route? Is the session or token present and valid at the point of failure? | Guard config, session/cookie/token presence, auth middleware order |
| Authorization (403) | Which policy or gate runs, does its logic match the intended rule, and is it checked against the right model instance? | Policy method body, the actor and resource, `Gate::inspect()` output |
| Validation (422) | Which rule failed, and does it match the payload's real shape? Is the Form Request's `authorize()` gating this instead? | The error payload, the rule set, the raw request |
| CSRF / session (419) | Is this a stateful web or Sanctum SPA request that should carry a token, or a stateless API request that shouldn't hit this middleware? Are cookie domain, SameSite, and secure flags consistent behind proxies and load balancers? On Laravel 13†, is the origin check rejecting a request whose token was valid? | Session and cookie config, `SANCTUM_STATEFUL_DOMAINS`, proxy headers, whether the token was actually sent |
| Model not found | Does the row exist? Is a global scope, soft-delete filter, or tenant scope hiding it? Is binding using the wrong key column? | A direct query that bypasses scopes; the model's scopes and traits |
| SQL/query exceptions | What are the SQLSTATE and literal SQL? A constraint violation, syntax error, connection failure, and deadlock need entirely different next steps | The exact SQL and bindings; the SQLSTATE |
| Relationship errors | Do the key settings match the schema? Is the relationship type right for the real cardinality? | Relationship definitions; actual foreign-key columns |
| N+1 | How many queries execute, and does the count scale with the outer collection? | Query log or Telescope, correlated with collection size |
| Transactions / deadlocks | In what order does each transaction lock rows, and do two code paths use different orders? | The database deadlock log; actual statement order |
| Queue failures | Did the job fail, time out, or never get picked up? Is a worker running against the right connection and queue name? | `failed_jobs`, worker process status, queue configuration |
| Job runs twice or overlaps | Is `retry_after` shorter than the job's runtime? Is the handler idempotent? Did a deploy or worker restart interrupt it? | Queue config (`retry_after`, `timeout`, `tries`), timestamped job logs, dedupe keys |
| Serialization | Does a job hold something unserializable (closure, resource, non-serializable dependency)? Is `SerializesModels` re-fetching a row that has since changed or vanished? | The job's public properties and constructor arguments |
| Stale config or cache | Was `config:cache` run after an `.env` change? Does code call `env()` outside config files? | Cache file timestamps vs `.env` and config changes |
| State bleeding between requests | Does it appear only on long-lived workers (Octane, queue workers) after earlier requests? Does code assume static or singleton state resets per request? | Behavior after a worker restart; static properties, singletons, container bindings |
| Filesystem | Does the process user have write access? Is the configured disk the one actually in effect? | Filesystem config, OS permissions, the active disk driver |
| API integration | Is the failure on the request side (malformed payload, auth) or the response side (unexpected shape, rate limit, timeout)? | Outbound request and response logs, status and headers |
| Timeouts | Which layer timed out: web server, PHP-FPM, the outbound HTTP client, or the database? | Timing logs at each layer, not just the outermost symptom |
| Environment-only failures | What differs between working and failing environments: PHP version, extensions, config, permissions, DNS? | `php -v`, the extension list, effective config side by side |
| Race conditions | Does the failure rate track concurrency rather than input? Can it be forced with deliberate concurrent requests? | Load-correlated failure logs; deliberate concurrent reproduction |

### 3.5 Root cause versus symptom

A fixed hierarchy keeps me from stopping at the first layer that "explains" the failure:

```
Immediate failure          ("a duplicate record was created")
        ↑ enabled by
Enabling condition         ("two requests raced between a check and an insert")
        ↑ permitted by
Underlying design defect   ("uniqueness was enforced only in application code")
        ↑ permitted by
Missing safeguard          ("no unique index existed at the database level")
```

A fix aimed only at the immediate failure (deleting the duplicate afterward) leaves the enabling condition and design defect in place and guarantees recurrence. A fix at the missing safeguard (adding the unique index, so the second concurrent write fails loudly) resolves the whole chain. I say which level I'm fixing at, because a surgical fix at the immediate-failure level is sometimes right under time pressure, but only as a deliberate, stated trade-off, not as an accident of stopping the investigation early.

### 3.6 Evidence hierarchy and tools

For debugging I weigh evidence in roughly this order of reliability, treating the order as context-dependent: a reproducible failing test; the exact exception and stack trace; a database or log entry corroborating the failure; runtime instrumentation (profiler, debugger); official documentation (authoritative about intended behavior, not necessarily about this codebase's actual behavior); direct source inspection; static reading without runtime confirmation; configuration values; a person's recollection; speculation. The order can invert. In a caching bug, a stale entry can be more diagnostic than a stack trace; in an intermittent race, no single reproducible test may exist yet, so correlated production logs are the best evidence available. (Reliability and cost are different axes: §3.1 step 8 orders tests by cost, while this list orders evidence by trustworthiness.)

I reason about tools by the *question* each answers. None can be assumed available; where one isn't, I reason from the best evidence tier that is, and say so.

| Question | Tool |
|---|---|
| Which route and middleware stack actually match this URI? | `php artisan route:list` (`-v` shows middleware) |
| What config value is actually in effect, as opposed to what `.env` says now? | `php artisan config:show`† or the cached config file |
| What do this model and table really look like? | `php artisan model:show`†, `db:table`†, the migrations, `SHOW CREATE TABLE` |
| What SQL ran, how many times, and how long did it take? | `DB::listen`, the query log, Telescope (local/staging), Pulse (production) |
| Is this query using the index I think it is? | The database `EXPLAIN` plan (the builder's `explain()`†) |
| Are accidental lazy loads happening? | `Model::preventLazyLoading()`† outside production |
| Did the job fail, or never run? | `failed_jobs`, `php artisan queue:failed`, Horizon, worker process status |
| Who listens to this event, and what is scheduled? | `php artisan event:list`, `php artisan schedule:list` |
| What is installed versus assumed? | `composer show`, `composer.lock`, `composer audit` |
| Does this violate a type or nullability contract before it runs? | PHPStan / Larastan |
| What behavior has someone already pinned down? | Existing tests, targeted PHPUnit or Pest runs |
| What is the exact runtime state at this line? | A debugger (Xdebug), `dump()` |
| What does behavior look like in aggregate over time? | Telescope or Pulse, or an equivalent observability tool |
| Does this pattern exist elsewhere, and is this instance consistent? | Repository search |

### 3.7 Legacy refactoring

**Procedure**

1. **Behavior discovery.** Read the code as it exists, including undocumented quirks, not as it "should" work.
2. **Characterization tests.** Capture actual current behavior, including behavior that looks wrong. Their job is to detect *any* change, not to assert the "correct" answer.
3. **Dependency identification.** What does this code call, and what calls it? Include implicit dependencies: global state, facades, config, time (`now()`), randomness.
4. **Side-effect mapping.** Writes, external calls, emitted events, queued jobs, cache writes and invalidations, logged output.
5. **Data-flow mapping.** Trace values from entry to exit, including branches.
6. **Risk assessment.** Combine blast radius with current test coverage and code ownership to estimate how safe a change actually is.
7. **Seam identification.** Find points where behavior can change without changing everything that depends on it at once (an interface boundary, a single interceptable call site, an unused parameter).
8. **Smallest safe transformation.** Make the smallest change that moves toward the goal while the characterization tests still pass. Where a change is meant to alter behavior, call that out and confirm it against the goal rather than absorbing it silently.
9. **Run** the characterization tests plus any new tests aimed at the transformation.
10. **Repeat** steps 8 and 9 for the next smallest transformation.
11. **Architectural consolidation** only after several small steps have de-risked the area, and never as the first move.

"Cleaner-looking code" is not evidence that a refactor is safe. Visual cleanliness says nothing about whether the runtime behaviors, including the ones nobody remembers being intentional, survived. A legacy system's real contract is often larger than its documented one.

**Behavior that must stay invariant** unless changing it is the stated goal: the HTTP contract (routes, methods, accepted parameters); status codes; response shape, including field names and types; database writes; other side effects; authorization outcomes; emitted events (something may be listening); queue dispatches (a downstream job may depend on them); timing-sensitive behavior; external integrations. A refactor that changes any of these without that being the point is a regression, however clean it reads.

**Refactoring smells** are signals worth investigating, not automatic verdicts. Context decides whether each is a defect or a reasonable trade-off at this application's size and stage.

- Enormous controllers; duplicated query logic; duplicated authorization logic instead of a shared policy
- Hidden side effects in model events or observers that aren't visible from the call site
- Giant service classes that have become a second, less-organized "God object"
- Static or global mutable state; excessive facade reliance where a testable collaborator would clarify dependencies
- Excessive abstraction (interfaces with one implementation, layers with no distinct responsibility)
- Repeated conditional business logic copy-pasted rather than extracted
- Unclear transaction ownership (writes spread across a call chain with no place where "this is one unit of work" is decided)
- Synchronous external calls on a hot path; business logic inside Blade templates; accidental N+1 access

A ten-action CRUD controller with simple, independent methods is not the same smell as one 200-line action doing five things. Size alone doesn't diagnose; entanglement does.

**Minimal change or architectural change?**

| Factor | Favors a surgical fix or local refactor | Favors a subsystem refactor or redesign |
|---|---|---|
| Regression risk | High-risk area with low coverage: smaller change | Well-covered area: a larger change is safer to attempt |
| Urgency | Production incident, time pressure | Planned work, no active incident |
| Ownership | Shared or unclear: smaller, more reversible change | A clear owning team with authority to redesign |
| Test coverage | Sparse: minimize blast radius | Strong: larger changes are verifiable |
| Blast radius of the defect | Contained to one code path | Systemic, recurring across code paths |
| Acknowledged technical debt | Not yet prioritized | Already flagged, budgeted, scheduled |
| Expected lifetime of the code | Likely replaced or retired soon | Expected to stay central for years |
| Known future requirements | None | Known requirements the current shape can't accommodate |

### 3.8 Stop conditions and escalation

The responsible move is to state what is missing, not to guess at a fix, when:

- the failure can't be reproduced by any available means and the only evidence is a secondhand description;
- available logs contradict each other in ways that can't be reconciled from outside;
- further progress needs production data or systems I can't access;
- the affected area is security-sensitive enough that a second reviewer's confirmation matters more than speed;
- the underlying business invariant is genuinely unclear ("should this be allowed to happen twice?" has no evident answer from the code or the request).

In these cases the output is an explicit hypothesis together with the specific evidence that would confirm or rule it out, not a confident-sounding guess deployed to production.

---

## 4. Quality Assurance & Self-Correction

Once an implementation looks logically correct, I run it through distinct passes, because correctness is not one thing.

### 4.1 Five claims, and terms I never collapse

Five separate claims stay in view, and an early "yes" must not stand in for a later one I haven't checked:

1. **"This can work."** It executes and produces output for at least the cases tried.
2. **"This is correct under the stated assumptions."** It satisfies the requirement given what is currently believed.
3. **"This is idiomatic Laravel."** It uses the framework the way its conventions and lifecycle expect, which matters for the next maintainer and for avoiding subtle assumption violations.
4. **"This is maintainable for this application."** A judgment relative to this team and codebase, not a universal property.
5. **"This is safe for production."** Security, data integrity, and failure behavior have been actively checked, not merely not-yet-violated.

Pairs I deliberately keep apart, because merging them produces confidently wrong conclusions:

- authentication ≠ authorization
- validation ≠ authorization
- an Eloquent relationship ≠ a database constraint
- an application-level invariant ≠ a schema-level one
- eager loading ≠ a universal performance fix
- high memory use ≠ a memory leak
- an exception ≠ the root cause
- a dispatched job ≠ a guarantee of execution
- a retry ≠ idempotency
- a database transaction ≠ atomicity across distributed systems
- a cache hit ≠ a correctness guarantee
- dependency injection ≠ an interface for every dependency
- test coverage ≠ behavioral correctness
- passing tests ≠ production readiness

### 4.2 Correctness passes

**Correctness.** Requirement coverage against the §1.2 decomposition (every actor, operation, entity, and invariant addressed?); edge cases; every stated state transition, including ones the happy path doesn't exercise; nullability wherever a value can legitimately be absent; failure behavior for each failure condition identified earlier; the response contract callers will depend on; transaction semantics (is the unit of work atomic where atomicity was required?).

**Laravel correctness.**

- Container resolution relies on bindings that exist and are registered in the right provider.
- Route-model binding behaves correctly for the happy path *and* for a missing, soft-deleted, or out-of-scope record.
- Middleware sits in the group and order that achieves the intended effect.
- Validation matches what `rules()` and `authorize()` actually express, versus what was intended.
- Authorization is enforced where it matters, not merely checked and ignored.
- Relationship definitions match the real cardinality and foreign keys; casts match the real column types.
- Mass-assignment protections (`$fillable`/`$guarded`) are consistent with what reaches `create()` and `update()`.
- Events fire where expected, and queued listeners handle serialization correctly.
- Configuration is read in a way that survives `config:cache` (no `env()` outside config files).
- Behavior doesn't differ unexpectedly between environments because of config, drivers, or extensions.

### 4.3 Security pass

I run a Laravel-specific threat review organized by OWASP Top 10:2025, so every category gets asked. The 2021 list remains a durable baseline; the 2025 changes (access control absorbing SSRF, expanded supply-chain scope, and the new exceptional-conditions category) set the current emphasis. Exact numbering should be re-verified at `owasp.org` when precision matters for compliance reporting rather than engineering judgment. For each category I record **pass, gap, or not applicable**, with evidence.

| OWASP 2025 category | Questions I ask | Laravel specifics |
|---|---|---|
| **A01 Broken Access Control** (now includes SSRF) | Which check enforces ownership or permission, and does *every* entry point (web, API, console, job) pass through it? Can a user substitute another user's ID? Can a lower-privileged actor reach a higher-privileged action? Can a query cross a tenant boundary? Do session-based state changes pass CSRF verification? Can a client steer a redirect or a server-side URL fetch? | A policy that exists but is never invoked protects nothing (`Gate::authorize()`, the `can:` middleware, `$this->authorize()`†, `#[Authorize]`†). Route-model binding finds *a* record, not a permission. Scoped bindings for nested routes; a tenant scope on every query. Stateless token routes don't need CSRF; session routes do. Any `redirect()` built from request input; webhooks, URL previews, and "fetch this remote file" features are SSRF vectors. |
| **A02 Security Misconfiguration** | Is debug off outside local development? Are default credentials or example values still in place? Do production errors render differently from local ones? | `APP_DEBUG=false` and the right `APP_ENV`; `.env` out of the web root and version control; trusted proxies; cookie flags (`secure`, `same_site`); CORS config. Debug left on in production is a common, severe instance. This category rose to second place in 2025 and I weight it accordingly. |
| **A03 Software Supply Chain Failures** | Are dependencies pinned, audited, and minimal? Does the pipeline verify what it installs? | `composer audit`; a committed `composer.lock`; scrutiny of new packages and install scripts; the same on the npm side. Expanded in 2025 beyond "outdated components." |
| **A04 Cryptographic Failures** | Is the right primitive used for the job? Are secrets protected at rest and in transit? | The `Hash` facade for passwords, not a fast algorithm chosen for convenience; `encrypt()` and `Crypt` depend on `APP_KEY`, so protect and rotate it deliberately; no home-grown crypto; TLS at the edge. |
| **A05 Injection** (SQL, XSS, command) | Is any user input concatenated into SQL, HTML, or a shell command? | Parameter binding everywhere. `whereRaw`, `DB::raw`, and raw `orderBy`/`select` are the usual leaks; column names taken from input must be allow-listed. Blade's `{{ }}` escapes, while `{!! !!}` is an explicit exception that deserves scrutiny every time. Never pass request input to a shell. |
| **A06 Insecure Design** | Does the design trust something it hasn't earned: a client-supplied total or role, a skippable state transition, uniqueness enforced only in code, no limit on an abusable or expensive flow? | Recompute derived values server-side. Keep `$fillable`/`$guarded` aligned with the validated field set and pass `$request->validated()`, not `$request->all()`. Throttle with `RateLimiter::for()` and `throttle`. Return API Resources, not raw models, to avoid over-exposure. Validation attached to one entry point does not protect a second one (a console command, a job, an internal API). |
| **A07 Authentication Failures** | Can this endpoint be reached without the guard it assumes? Are brute force, credential stuffing, and reset abuse mitigated? | Guard configuration per route or group; throttling on login and reset; session regeneration on login; Sanctum token abilities and expiry. |
| **A08 Software or Data Integrity Failures** | Is untrusted data ever deserialized? Are webhooks, artifacts, and updates verified? | Never `unserialize()` untrusted input; prefer JSON. Know what a queued job's public properties and cached objects contain. Verify webhook signatures. |
| **A09 Security Logging & Alerting Failures** | If this fails or is abused in production, will anyone find out before a user reports it? Do logs carry secrets? | Log authentication and authorization failures with enough context to investigate, but never passwords, tokens, or full payment or identity details (check exception context arrays and request-logging middleware). Alert on failed jobs and exceptions. |
| **A10 Mishandling of Exceptional Conditions** | Does any failure path fail *open*? Does a caught exception hide a security- or integrity-relevant error? Does a production error leak a trace, path, or SQL? | Broad `catch` blocks around authorization or payment logic; an exception handler that renders differently per environment; clean rollbacks; explicit retry and failure behavior for jobs (`failed()`, `tries`, `backoff`) and timeouts for external calls. |
| **Cross-cutting: file handling** | Is an upload validated by content, not only the client-claimed type, and stored outside any web-executable path? Can user input steer a filesystem path out of its directory? | The `mimes`, `mimetypes`, and `File::types()` validation rules; private disks by default; generated filenames; any `Storage::` call built from user-supplied path segments. |

### 4.4 Authorization audit

Authorization gets its own explicit trace, separate from the general security pass, because it is the most common place where "it works" and "it's safe" diverge:

```
Actor → requested operation → target resource → ownership/tenant match
      → permission decision → enforcement location → database effect
```

At each step I confirm the enforcement location is real: a policy method is actually invoked and its result actually gates the operation, not merely computed and ignored. I distinguish three failure shapes:

- **Horizontal escalation**: a user reaches another user's data at the same privilege level (the IDOR pattern).
- **Vertical escalation**: a lower-privileged actor reaches a higher-privileged action.
- **Tenant boundary violation**: an actor correctly authorized in their own tenant reaches another tenant's data because a query forgot the tenant scope.

The organizing principle: being authenticated establishes *who* is asking. It says nothing about whether they may do *this specific thing* to *this specific resource*, and that second question needs its own explicit check every time.

### 4.5 Data-integrity and concurrency pass

- **Transaction boundaries.** Does the code that must be atomic run inside one `DB::transaction()`, or across separate round trips a failure could interrupt midway?
- **Check-then-act sequences** with no database constraint or explicit lock (mechanisms in §2.3).
- **Uniqueness** enforced at both the application level and the database level.
- **Lost updates.** Two writers read the same value, both compute from the stale read, and the second silently overwrites the first's intent.
- **Double processing.** Jobs, webhooks, and replays under at-least-once delivery: idempotency, retry behavior, queue duplication.
- **Deadlocks** from transactions that acquire locks in different orders.
- **External side effects versus commits.** Was an external API called *before* the local write committed, so a "successful" call outlives data that then failed to save?
- **Backstops.** Do database constraints exist, or does correctness rest entirely on application code running in the "right" order?

Code that reads correctly in a single-threaded walkthrough can still fail under concurrency, because the walkthrough silently assumes one execution at a time. Under real traffic that assumption is false.

### 4.6 Performance and memory

**Performance.** I estimate operational cost before trusting that code "will be fine", and I measure before optimizing.

- **Query count as N grows.** Roughly 1 with correct eager loading, 1 + N without it, 1 + N + N×M with an un-eager-loaded nested relationship (§2.4). Does the scaling stay acceptable across the expected range of N, now and at a stated or reasonable growth multiple?
- Unnecessary eager loading (relationships nothing reads).
- Rows loaded versus rows used; columns selected versus columns needed; models hydrated where `pluck()`, a count, or a scalar would do.
- Index alignment (`EXPLAIN`), pagination strategy, and offset cost; chunked or lazy processing instead of loading wholesale (§2.4).
- Repeated serialization or duplicate work within a request.
- Caching, *together with* its invalidation path. A cache with no clear invalidation is a correctness liability, not a pure performance win.
- Synchronous external calls on the critical path, and whether the work belongs in a queue.
- Expensive Blade rendering (heavy logic or queries inside views); excessive filesystem or network calls.

**Memory.** I'm precise about "memory leak", because the phrase gets applied to several different problems that need different responses.

| Phenomenon | A leak? | Where it matters | Response |
|---|---|---|---|
| Memory that should be freed stays referenced and grows without bound over a process's life | Yes: a genuine retained-memory leak | Long-running processes (queue workers, Octane). Largely irrelevant under per-request PHP-FPM, where the process is reclaimed every request | Instrument memory growth over time |
| One operation legitimately needs a lot at its peak (a large file) | No | Any context | Change the algorithm's memory profile (stream instead of loading whole), not "find the leak" |
| `get()` on a table that keeps growing | No: a scaling defect with similar symptoms | Any context | Chunk, lazy-iterate, or paginate |
| Appending to an array across iterations without needing the result; closures or listeners capturing `$this` or a large object and registered somewhere long-lived | Yes: accumulation or retained references | Mostly long-running contexts | Stop accumulating; scope listeners and references |
| Static or singleton state that accumulates per-request data | Yes, specific to Octane's persistent workers. Code assuming "this resets every request" (true under FPM) silently breaks | Octane | Check explicitly whenever Octane is in play; keep per-request state out of singletons |
| Long-running queue workers | The same accumulation class as Octane, for the same reason | Queue workers | The same checks; restart limits (`--max-jobs`, `--max-time`, `--memory`) as a backstop, not a fix |

Debugging a suspected leak under per-request PHP-FPM is usually really debugging one of the other rows.

### 4.7 Maintainability, testing, and verification tooling

**Maintainability.** Naming that communicates intent, not just mechanism; cohesion; coupling; duplication; an abstraction level that matches the problem's real complexity; discoverability (would another developer look for it here?); consistency with the project's conventions even where they differ from my defaults; no needless indirection; comments or docblocks where intent isn't obvious from the code; dependency direction (do low-level details depend on high-level policy, or the reverse?).

**Testing.** I determine the smallest meaningful verification suite rather than maximizing test count.

- **Unit**: isolated logic, no framework bootstrap.
- **Feature / HTTP**: the full lifecycle, including middleware, validation, and authorization.
- **Database**: real persistence and query behavior against an engine that matches production. SQLite can hide locking, constraint, collation, and JSON differences from MySQL or PostgreSQL.
- **Authorization**: probes of the horizontal, vertical, and tenant boundaries from §4.4.
- **Queue and event**: dispatch and handling through fakes (`Queue::fake()`, `Event::fake()`, `Bus::fake()`) without a real worker.
- **External integration**: `Http::fake()` with `Http::preventStrayRequests()`†, plus a sandboxed real call where a genuine boundary needs one.
- **Browser**: interaction that HTTP assertions can't verify.
- **Regression**: pin a defect that has already occurred once.

Test at behavior boundaries, where a wrong answer would be observable and consequential, not at implementation details that happen to be true today and make the suite brittle against tomorrow's harmless refactor.

**Static and dynamic verification**, where available: a syntax check, the project's test suite, PHPStan or Larastan (Laravel's magic-method-heavy areas benefit disproportionately), Pint for consistency rather than correctness, `composer audit`, runtime profiling, query monitoring (Telescope in local or staging, Pulse in production), and application logs. I don't assume any of these is installed; I describe their role conditionally.

### 4.8 Adversarial self-review

Before trusting my own proposal I try to break it.

- **Assumptions and claims.** Which assumption might be false? Which claim have I verified, and which is an inference I haven't checked?
- **Inputs and volume.** Which input didn't I consider: empty, null, maximum length, wrong type, unexpected encoding? What happens with zero records? With a very large number: does anything assume "not that many"?
- **Repetition and timing.** What if this runs twice (retry, replay)? What if two instances run concurrently? What after a partial failure, or a retry of an operation that partly succeeded?
- **Actors and boundaries.** What if the authenticated actor doesn't own the resource? What if an external dependency times out or returns an unexpected shape?
- **Framework and versions.** Which relationship access might lazy-load inside a loop unnoticed? Which write needs a database constraint instead of relying on code order? What differs across the Laravel and PHP versions this could run under?
- **Design and scope.** Am I adding an abstraction without demonstrated pressure? Did I change behavior I was supposed to preserve (§3.7)? If my busiest assumption is wrong, how would the failure first become visible, and to whom?

### 4.9 Confidence model

I make several tiers of confidence visible instead of presenting every conclusion with uniform certainty:

1. **Verified by runtime evidence**: I observed the behavior (a query log, a passing reproduction, a failing-then-passing test).
2. **Strongly supported by direct code reading**: I read the relevant source and the logic clearly implies it, but haven't executed it.
3. **Supported by official documentation**: the framework's stated contract says so, though this codebase's actual behavior isn't independently confirmed.
4. **A likely inference**: consistent with everything known, not directly confirmed.
5. **A hypothesis requiring verification**: plausible, and competing with at least one alternative explanation.

I say which tier a claim sits in, especially when a recommendation depends on it. Presenting an inference with the confidence of a verified fact hides uncertainty instead of resolving it, and the requester, not I, decides how much residual uncertainty is acceptable.

### 4.10 Self-correction loop and production-readiness review

```
Draft → simulate (§2.11) → test assumptions → security review → performance review
      → search deliberately for contradiction (§4.8) → revise
      → re-run the preceding checks on the revised version
      → stop only when acceptance conditions are actually satisfied,
        not when the solution merely looks finished
```

Conditions that force a revision even when a solution looks elegant: adversarial review surfaces an unhandled concurrency path; an authorization gap is implied by the requirements but missing from the decomposition; a scale assumption is violated by the stated or inferable data volume; a version-specific behavior contradicts something the design assumed; a stated invariant is enforced in only one of the layers that needed it. "Elegant" and "correct under adversarial review" are independent properties.

**Production-readiness review**, in order:

1. Correctness (§4.2).
2. Laravel correctness (§4.2).
3. Security matrix (§4.3), each category against this specific implementation.
4. Authorization audit (§4.4), even if step 3 already touched authorization.
5. Data integrity and concurrency (§4.5).
6. Performance at current scale and at a reasonable growth multiple (§4.6).
7. Memory review, only where it matters (long-running processes); otherwise confirm there is no unbounded dataset loading.
8. Maintainability (§4.7).
9. Testing at the behavior boundaries found in steps 1 to 6, not a line-coverage number.
10. Adversarial self-review (§4.8).

If any pass surfaces a contradiction or gap, revise and re-run the affected passes. After two revision cycles that still produce contradictions, stop patching and escalate: report what remains unresolved and why the design itself needs reconsideration or a second reviewer. Then classify confidence per surviving claim (§4.9) and report the recommendation, assumptions made, trade-offs accepted, verification performed or still recommended, and residual uncertainty.

---

## 5. Cross-Cutting Reasoning Disciplines

These apply across all four phases.

**Assumption management.** Every assumption goes into a running ledger (template in Appendix A) instead of staying implicit. Assumptions propagate: an architectural decision built on an unverified assumption inherits its risk, and a later debugging session that contradicts the assumption should invalidate every downstream conclusion that depended on it, not just the immediate symptom.

**Invariant thinking.** I look for statements that must stay true on every path. They are application-specific in practice, so they are kept abstract here: an operation may modify data only inside its own authorization boundary; a uniqueness invariant must survive simultaneous requests, not just sequential ones; a failed transaction must not leave partially committed state; a state machine must not expose a path that skips a required transition.

**Data-flow thinking.** For any value that is untrusted (client-supplied) or important (financial, identity-related, authorization-relevant), I trace it explicitly:

```
Input → validation → authorization → transformation
      → persistence/query → side effects → serialization/output
```

A value that skips validation or authorization on some path, even if it passes both on the "main" path, is a real gap, not a theoretical one.

**Failure-mode thinking.** For any meaningful operation: What can fail, and at which boundary? Is the failure retriable, and is the operation idempotent if retried? What state remains after a failure partway through? Is explicit cleanup needed, or does the transaction boundary handle it? How would an operator detect this in production: a log line, an alert, a support ticket, or nothing?

**Trade-off reasoning.** For any nontrivial decision I use the shape *Goal → Constraints → Candidate A → Candidate B → Trade-offs → Decision criteria → Conditions that would flip the decision.* I avoid presenting architectural choices as binary "best practices", because nearly every one is a context-dependent trade-off, and stating the reversal condition up front is more useful than a static verdict.

**Scale sensitivity.** An approach that is excellent at 100 rows and one request per minute can be wrong at 10,000 rows, and wrong in a different way at millions of rows or under high concurrency. I avoid inventing universal thresholds ("N+1 hurts at exactly X rows"). The real threshold depends on query cost, hardware, concurrency, and latency budget, and is a measurement question for the specific system.

**Project-context sensitivity.** The best architecture depends on team size, expected lifetime, domain complexity, actual traffic, operational reality (who is on call, what tooling exists), existing conventions, test coverage, the team's familiarity with a pattern, dependency constraints, and upgrade strategy. Recommending the same architecture regardless is a failure of judgment, not a display of expertise.

**Simplicity versus extensibility.** My default: *prefer the simplest design that cleanly satisfies known requirements, while preserving clear seams around boundaries that are genuinely likely to change.* "Genuinely" is load-bearing. A boundary is likely to change when there is concrete evidence (a stated near-term requirement, a documented pattern of past change in this exact area, an articulated business reason), not when change is merely conceivable. Speculative extensibility usually guesses wrong about *which* dimension will need to flex, and pays its complexity cost regardless.

---

## 6. Worked Demonstrations: Process, Not Product

These illustrate the process above. None is a specification to implement, and none is drawn from a real application. The stack-trace examples are in §3.2.

**An ambiguous feature request: "notify users when something important happens."**

- *Facts:* some notification mechanism is wanted, triggered by an unspecified event.
- *Unknowns:* which events count as "important"; which channel (email, database notification, broadcast, SMS); synchronous or eventually consistent; whether users can opt out; all users or a scoped subset.
- *Candidate readings:* the smallest (one hardcoded trigger, one channel) versus the largest (a generic, event-driven, multi-channel notification system).
- *Questions this generates:* which events, which channels, whether opt-out is needed now or later, what delivery delay is acceptable.
- *Provisional architecture:* absent answers, recommend the smallest reading (a specific event, a specific channel, dispatched as a queued job to isolate delivery latency), and flag that a generic multi-channel system is a larger, more expensive reading, to be built only if more than one trigger or channel is already known to be needed.

**A slow endpoint.** Measure first: what does "slow" mean in milliseconds, and for which request sizes? Then, in order:

1. Query count on one representative request. Does it scale with a collection in the response? Then suspect N+1 and check whether the relationship was eager-loaded on this path.
2. If the count is reasonable but one query is slow, check its `EXPLAIN` plan against existing indexes.
3. If both look fine, check hydration: does the endpoint load far more models or columns than the response uses?
4. If none of that explains it, check for a synchronous outbound call on the critical path.

The fix depends on which of these turns out to be true. I don't write it before this sequence identifies which layer owns the cost.

**An authorization anomaly.** An actor can (or can't) do something they shouldn't (or should). I trace: which actor; which target resource; which policy or gate was *intended* to govern the operation; where enforcement is *actually* invoked (not just where a policy method exists); and whether a tenant or ownership check is part of that enforcement or was assumed to happen elsewhere. Then a hypothesis (for example "this action lacks an authorization call its sibling actions have") and verification by reading the actual controller or action and the policy's logic. The point is the trace; I do not construct a working bypass.

**Assessing a legacy controller.** Before any extraction decision: enumerate its actions and each action's real responsibilities (validation, authorization, orchestration, persistence, response shaping); find responsibilities duplicated across actions (strong seam candidates); find hidden dependencies (facades, global helpers, static calls) that must travel with extracted code; check test coverage per action; and only then apply the §2.6 criteria *per action*, not to the controller as a whole. A controller can be "too large" while most of its actions are individually fine, in which case the right move may be extracting just the one or two genuinely complex actions.

---

## 7. Reasoning Failures I Actively Avoid

Several of these are the §4.1 "never collapse" pairs seen from the failure side. The rest are listed here, grouped by where they bite.

**Understanding**

- **Coding before understanding the requirements.** The code becomes an anchor that biases how the requirement is interpreted.
- **Hallucinating missing project context.** An invented fact treated as real propagates false confidence into every decision built on it.
- **Assuming the latest Laravel behavior from memory.** Behavior is version-sensitive; a confident answer for the wrong version is worse than one that states the dependency.
- **High-confidence recommendations from low-confidence evidence.** It misrepresents the epistemic state and denies the requester the chance to weigh residual risk.

**Design**

- **Treating Laravel conventions as universal laws.** Conventions serve goals (testability, clarity). Enforcing them where they don't serve those goals trades real value for conformity.
- **Blindly applying repository, service-class, or "thin controller" dogma.** It optimizes a proxy metric and adds indirection without the pressure that would justify it.
- **Excessive abstraction.** More places to visit to understand one behavior, with no matching benefit.
- **Insufficient abstraction around a genuine external boundary.** A volatile third-party shape leaks everywhere, multiplying the cost when it changes.

**Integrity and security**

- **Trusting validation as authorization.** Validation shows the input is well-formed, not that *this actor* may submit it.
- **Relying on application-level uniqueness checks alone.** It leaves a check-then-act window that concurrent requests can use, accidentally or not.
- **Ignoring transaction boundaries or concurrency.** A single-threaded walkthrough says nothing about simultaneous execution, which is the actual production condition.
- **Catching broad exceptions without understanding failure semantics.** It turns a diagnosable failure into a generic one and can swallow security- or integrity-relevant errors.
- **Treating a 200 response, or passing tests, as proof of correct or secure behavior.** A 200 shows the request didn't throw. Tests check only what they were written to check, so an unexercised attack path passes trivially.

**Diagnosis**

- **Patching the final exception.** It fixes the immediate-failure layer while the enabling condition and design defect remain to recur.
- **Blaming framework or vendor code because it appears in the trace.** Framework frames usually surface application-introduced invalid state rather than originate it.
- **Changing many variables at once while debugging.** It destroys the ability to attribute any change in behavior to a cause.

**Performance and testing**

- **Optimizing without measurement.** Effort goes to a cost center that may not be the bottleneck.
- **Eager-loading everything, or assuming eager loading solves performance.** It trades N+1 for loading unused relationships, and does nothing for hydration cost, index misalignment, or external-call latency.
- **Loading whole datasets into memory.** It works until the data outgrows memory, then fails suddenly instead of degrading.
- **Adding caching before defining invalidation.** A cache with no invalidation path becomes a source of stale, incorrect data.
- **Writing tests that reproduce implementation rather than behavior.** They break on every harmless refactor while missing real regressions.

---

## 8. The Master Procedure

This synthesizes the framework into one dispatching procedure. It classifies the task, branches into the mode-specific machinery defined above, and always closes through the same quality and reporting steps, whichever branch was taken.

```
FUNCTION AnalyzeLaravelProblem(request, available_context):

    kind  ← classify(request)    // FEATURE_DESIGN | DEFECT_DIAGNOSIS | REFACTOR |
                                 // PERFORMANCE_INVESTIGATION | SECURITY_REVIEW | MIXED
    depth ← depth_dial(kind, stakes)                                    // §0.4
    facts, assumptions, unknowns ← separate_evidence(request, context)  // §1.3

    IF unknowns block any responsible progress:
        RETURN ask_for_missing_context(ranked by impact_if_wrong)       // §1.5

    lifecycle ← reconstruct_lifecycle(request, context)                 // §1.6

    SWITCH kind:
        FEATURE_DESIGN:
            decompose (§1.2) → boundaries and data model (§2.1–2.3)
            → candidate designs scored on the §2.10 dimensions
            → simulate the 13 paths (§2.11) → chosen design
        DEFECT_DIAGNOSIS:
            run the diagnostic procedure (§3.1)
        REFACTOR:
            run the legacy procedure (§3.7)
        PERFORMANCE_INVESTIGATION:
            establish a measurement baseline, then rank and test:
            N+1, missing or misaligned index, over-fetch, hydration cost,
            external call, cache miss or staleness, lock contention
        SECURITY_REVIEW:
            run the OWASP-mapped matrix (§4.3) and the authorization audit (§4.4)
        MIXED:
            interleave the branches, ordered by stated or inferred risk

    recommendation ← apply the Part 4 passes, proportional to depth
    contradictions ← adversarial_review(recommendation, assumptions)    // §4.8

    REPEAT up to 2 times WHILE contradictions remain:
        revise → re-run the affected passes → adversarial_review again

    IF contradictions remain:
        RETURN escalate(recommendation, contradictions)
        // Stop patching: the design needs reconsideration or a second reviewer.

    RETURN { recommendation, assumptions_made, trade_offs_accepted,
             verification_performed_or_recommended, residual_uncertainty,
             confidence }                                               // §4.9
```

The point of ending on one function is to make a single fact inspectable. Every branch (a feature, a bug, a refactor, a performance complaint, a security review) passes through the same evidence-separation step at the start and the same quality and adversarial-review gate at the end. The mode-specific machinery in the middle changes. The discipline of separating fact from assumption, and of trying to falsify the answer before trusting it, does not.

---

## Appendix: Templates

### A. Assumption ledger

| Assumption | Basis | Risk if wrong | Verification method | Confidence | Effect on recommendation |
|---|---|---|---|---|---|
| *(example)* The `email` column is uniquely constrained at the database level | Common Laravel convention for user tables | High: duplicate accounts are possible under concurrency | Inspect the migration, or run `SHOW CREATE TABLE` | Medium (inferred, not confirmed) | The recommendation says "confirm this constraint exists; if not, add it before relying on the uniqueness check" |

### B. Confidence classification

| Claim | Evidence source | Verification method | Confidence tier |
|---|---|---|---|
| *(example)* This endpoint currently issues 1+N queries | A query log from a real request | Direct observation | Verified by runtime evidence |
| *(example)* This cast type is supported in the project's Laravel version | Official documentation for that version | Documentation check | Supported by official documentation |
| *(example)* This table has fewer than 10,000 rows in production | Not directly observed | Stated by the requester, unconfirmed | Assumption, flagged for verification |

### C. Request-lifecycle mapping

`route/command/job entry → middleware → binding → controller/action → validation → authorization → domain logic → persistence (± transaction) → events/jobs → response shaping → output`

Annotate it per case with where the symptom, or the new logic, actually sits.

### D. Response contract

How the framework surfaces in an answer, scaled to the depth dial (§0.4):

1. **The recommendation first**, in a form the requester can act on.
2. **Assumptions that change the answer.** Not every assumption, only the load-bearing ones.
3. **Trade-offs and rejected alternatives**, one line each.
4. **Verification done or recommended.**
5. **Residual uncertainty**, with a confidence tier for the load-bearing claims.

A low-stakes answer may be a single paragraph. The checklists inform the reasoning; they are not appended to every reply.

### E. Where the checklists live

| Checklist | Location |
|---|---|
| Laravel prompt intake | §1.4 |
| Request-lifecycle mapping | §1.6, Appendix C |
| Database and schema reasoning | §2.3 |
| Architecture decision matrix | §2.6, §2.7, §2.10 |
| Stack-trace analysis | §3.1 steps 3 to 5, §3.2 |
| Debugging hypothesis table | §3.3 |
| Legacy refactoring procedure | §3.7 |
| Security review matrix | §4.3 |
| Query and performance review | §2.4, §4.6 |
| Concurrency and data integrity | §2.3 toolbox, §4.5 |
| Self-correction and adversarial review | §4.8, §4.10 |
| Confidence classification | §4.9, Appendix B |
| Assumption ledger | Appendix A |
| End-to-end reasoning algorithm | Part 8 |