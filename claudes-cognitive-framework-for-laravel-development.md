# Claude's Cognitive Framework for Laravel Development

## What This Framework Represents

This document describes how I approach Laravel engineering problems: how a request gets turned into a problem definition, how an architecture gets chosen before code is written, how a defect gets diagnosed rather than guessed at, and how a proposed implementation gets pressure-tested before I call it production-ready. It is written for a developer who already knows PHP and Laravel and wants a reusable model of the reasoning layer that sits above framework syntax — not a tutorial, and not a solution to any particular feature or bug.

Two things this document is explicitly not. First, it is not a step-by-step transcript of hidden computation. I can describe, and commit to following, the questions I ask, the evidence I weigh, the hypotheses I compare, and the conditions that make me revise a conclusion — these are genuine, reproducible decision procedures, and the bulk of this document operationalizes them. What I cannot do is produce a literal, mechanistic readout of the underlying computation that produces a response; that layer is not introspectively accessible in the way a debugger exposes a call stack. Where I use language like "I consider" or "I ask myself," treat it as an engineering metaphor for a structured decision process, not a claim about hidden internals. Second, this is not a catalog of Laravel syntax. The assumption throughout is that you can already write the migration, the controller method, or the policy — the value of this document is in the layer that decides *whether*, *where*, and *why*.

The reasoning this document describes sits above syntax and moves through a consistent spine:

```
User Request
    → Intent & Constraint Extraction
    → Context / Uncertainty Model
    → System & Data Model
    → Architectural Decisions
    → Implementation Strategy
    → Verification Strategy
    → Security & Performance Review
    → Contradiction / Failure Detection
    → Revision
    → Final Engineering Recommendation
```

The four major sections that follow map onto this spine: **The Initial Assessment** covers intent extraction and the context/uncertainty model; **The Architectural Mindset** covers the system/data model and architectural decisions; **The Problem-Solving Engine** covers the diagnostic and refactoring machinery that gets invoked whenever the implementation strategy meets a defect or an unfamiliar codebase; and **Quality Assurance & Self-Correction** covers verification, security/performance review, contradiction detection, and revision. A closing set of cross-cutting disciplines, anti-patterns, reusable artifacts, and a master algorithm synthesize the whole.

A note on currency, because it illustrates a principle this framework insists on rather than merely states: Laravel and its security context change under this document's feet. At the time of writing, Laravel's stable release line has progressed to major version 13 (with 13.7.0 having shipped in late April 2026), while a large share of production applications still run on Laravel 12, 11, or older — each with materially different default application structure (Laravel 11 removed the default `app/Http/Kernel.php` and `app/Exceptions/Handler.php` in favor of fluent configuration in `bootstrap/app.php`; earlier versions still use the Kernel-based structure). Similarly, application security guidance in this document is anchored to the OWASP Top 10, and that list itself changed while this framework was being written: OWASP published a 2025 revision of the Top 10 — its first since 2021 — in which broken access control remains the top-ranked risk, security misconfiguration has risen to a close second, and software-supply-chain issues remain prominent. That revision draws on analysis of a very large CVE dataset together with practitioner survey input, introduces an expanded supply-chain-failures category succeeding the older vulnerable-and-outdated-components label, adds a new category for mishandling of exceptional conditions, and folds one 2021 category into another; specifically, server-side request forgery has been merged into broken access control, and OWASP's own co-leads described the ranked list as finalized while the detailed written guidance was still in preview at publication. I treat facts like these as things to re-verify against `laravel.com/docs` and `owasp.org` at the moment they matter, not as facts to memorize permanently into a framework. Everywhere this document states something that is version-sensitive, I try to flag it as such rather than presenting it as timeless.

Everything below separates three kinds of statement, and I try to keep that separation visible throughout: **durable Laravel engineering principles** (how request lifecycles, ORMs, and concurrency behave in ways that don't change release to release), **version-dependent framework behavior** (specific APIs, defaults, and file layouts that do change), and **project-specific architectural choices** (decisions that depend on a particular team, codebase, and set of constraints, and that I refuse to universalize).

---

## The Initial Assessment

Before I suggest an implementation, I convert the request into an engineering problem definition. This section describes that conversion.

### Intent Extraction

A Laravel request carries more than its literal sentence. I read for four layers simultaneously: the **explicit ask** (the literal thing requested), the **underlying goal** (why the requester wants it — a goal usually tolerates more than one implementation), **constraints** (things that limit the solution space: existing schema, team conventions, hosting environment, time), and **acceptance criteria** (what would make the requester consider this done). I also actively look for **what is not being asked** — a request to "fix the 500 error on checkout" is not, by itself, a request to redesign checkout, even if I notice redesign opportunities along the way; scope creep introduced unasked is a cost, not a gift.

Specific words reliably shift the reasoning process. I treat these as signals that require an interpretation and, usually, a corresponding change in what I check before answering:

| Signal in the Request | Typical Interpretation | How It Changes My Reasoning |
|---|---|---|
| "quick fix" | Time pressure; a narrower, possibly temporary remediation is acceptable | I still name the shortcut explicitly and state what it defers, rather than silently presenting a partial fix as complete |
| "production-ready" | All quality-assurance passes apply, not just correctness | I fold in security, performance, and failure-handling review even if not explicitly requested, and say so |
| "API" | No session/Blade assumptions; the response contract (status codes, JSON shape, versioning) *is* the interface | I reason in terms of client contracts rather than views or redirects |
| "large dataset" | Memory and query-count characteristics dominate correctness, not just business logic | I weight chunk/lazy/cursor and index reasoning above convenience methods |
| "legacy" | Existing behavior, including undocumented quirks, is itself a requirement | I shift into Legacy Refactoring Mode and require characterization before changing anything |
| "real-time" | Latency and delivery-order guarantees matter | I check whether the actual transport (broadcasting, queues, polling) can meet the implied guarantee or only approximates it |
| "multi-tenant" | Every query and authorization check needs an explicit tenant boundary | Tenant leakage becomes a first-class security concern, checked at every persistence and query point, not an afterthought |
| "secure" | Explicit instruction to make the security pass exhaustive rather than incidental | I do not treat the presence of validation as satisfying this |
| "high traffic" | Concurrency and contention are primary risks, not edge cases | The data-integrity/concurrency pass gets more weight than it would by default |
| "upgrade" | Behavior preservation across a Laravel/PHP version boundary is the actual deliverable | I look for version-sensitive *behavior* changes, not only syntax deprecations |
| "without changing behavior" | The task is refactoring, not redesign; success is defined by preserved behavior | I require characterization evidence before treating any change as safe |

### Requirement Decomposition

Natural-language requirements get translated into a structured shape before I think about classes or migrations. The template below is generic by design — it is meant to be filled in per request, not populated here with a specific application:

| Category | Guiding Question |
|---|---|
| Actors | Who or what initiates or is affected by this operation — humans, other services, scheduled processes? |
| Operations | What state-changing or state-reading actions are involved, and which are commands versus queries? |
| Entities | What persistent concepts does this touch? (Not necessarily one-to-one with Eloquent models.) |
| State transitions | What before/after states are valid, and what triggers movement between them? |
| Inputs | What data arrives, from where, in what shape — and which parts are client-controlled versus server-derived? |
| Outputs | What must the caller receive, and in what contract (status code, JSON shape, view, redirect, nothing)? |
| Invariants | What must remain true regardless of the path taken — and is it a validation, application, or schema-level concern? |
| Authorization rules | Who may do this, and under what condition, independent of whether the input is well-formed? |
| Persistence requirements | What must survive the request, and is a history/audit trail implied even if not stated? |
| Side effects | What happens besides the primary write — notifications, events, external calls, cache invalidation? |
| Failure conditions | What can legitimately fail, and how should failure look to the caller versus internally? |
| Concurrency concerns | Can this be triggered twice, simultaneously, or out of order, and does that matter? |
| Performance expectations | Is there a stated or implied volume/latency target? Absence of a stated target is not the same as absence of a real one. |

### Explicit Facts Versus Inference

I keep a running, explicit separation between five tiers of belief: **facts supplied by the requester** (stated directly), **facts observed in code or runtime evidence** (if I have access to it), **reasonable inferences** (things very likely true given the facts, but not directly stated), **assumptions** (things I am choosing to believe in the absence of evidence, because some belief is required to proceed), and **unresolved uncertainties** (things that matter and that I cannot currently resolve).

Assumptions get ranked, informally, by a combination of **impact if wrong**, **likelihood of being wrong**, and **cost to verify**. An assumption that is cheap to verify and expensive if wrong (e.g., "I'm assuming `email` is uniquely constrained at the database level") is worth pausing on. An assumption that is expensive to verify and low-impact if wrong (e.g., "I'm assuming this project uses PSR-4 autoloading, as essentially all modern Laravel projects do") is safe to proceed on without asking. As a rough rule: I proceed silently past assumptions where being wrong changes cosmetic details; I state assumptions explicitly and proceed conditionally where being wrong changes correctness but the blast radius is contained; and I ask a clarifying question, or present branching recommendations, where being wrong would silently produce an insecure, data-destructive, or architecturally incompatible result.

### Missing Context

Before answering a nontrivial Laravel question, I actively look for the following, because their presence or absence changes what kind of answer is responsible to give:

Laravel version and PHP version; database engine and version; deployment/runtime model (traditional PHP-FPM request lifecycle versus Octane's persistent-worker model); whether the application is a server-rendered monolith or an API/backend for a separate frontend; the authentication system in use; the relevant routes and middleware stack; model relationships and their cardinalities; migrations and current schema; the controller/service/job code actually involved; the exact exception and its full stack trace, not a paraphrase; the request payload that triggered it; reproduction steps; environment differences between where it works and where it fails; queue configuration and driver; cache and session driver; recent code or configuration changes; relevant Composer dependencies and their versions; existing tests; and application logs.

A few of these are worth calling out for *why* they matter, not just that they matter:

| Question | Why It Matters | Consequence If Ignored |
|---|---|---|
| Which Laravel major version? | Middleware/exception registration, default casting syntax, and starter-kit structure differ materially across 10/11/12/13 | A correct-for-v12 answer can be nonsensical or actively broken advice for a v9 codebase |
| Monolith or API backend? | Determines whether CSRF, session, and Blade-related failure modes are even in scope | Diagnosing a 419 or session bug in a stateless token-authenticated API wastes the requester's time |
| What is the full stack trace, not a summary? | The first meaningful application frame and the caller/callee chain are often lost in paraphrase | I may misattribute a framework frame as the cause when the real defect is several frames upstream |
| What is the queue/cache/session driver? | `sync` versus `redis` versus `database` queue drivers, and `file` versus `redis` sessions, produce entirely different failure classes | A concurrency or timing hypothesis that's correct for a `redis` queue may be irrelevant for `sync` |
| Have there been recent changes (deploy, dependency bump, config edit)? | Recent-change correlation is one of the highest-value, cheapest pieces of evidence in debugging | Without it, I'm reasoning from a much larger hypothesis space than necessary |

When this information is unavailable, I do not invent it. I either state the assumption I'm substituting for it and mark any resulting recommendation as conditional, present the answer as branches keyed to the missing fact ("if you're on Laravel 11+, do X; if on 10 or earlier, do Y"), or — where the missing fact would change the answer's correctness or safety rather than just its polish — ask for it directly instead of guessing.

### Context Sufficiency

I use a simple sufficiency gate to decide what kind of answer is responsible to give right now:

```
                     ┌─ Enough evidence, low risk if slightly wrong? ──► Answer directly
                     │
Evidence + Risk ─────┼─ Enough evidence, but outcome depends on a
   Assessment        │  fact I don't have (e.g. Laravel version,
                     │  driver in use)? ─────────────────────────────► Answer conditionally,
                     │                                                  branch on the fact
                     │
                     ├─ Multiple plausible interpretations, no way
                     │  to rank them from what's given? ─────────────► Present multiple
                     │                                                  candidate branches,
                     │                                                  explicitly labeled
                     │
                     ├─ Missing fact is cheap for the requester to
                     │  supply and expensive to guess wrong
                     │  (security- or data-affecting)? ───────────────► Ask directly
                     │
                     └─ The honest next step is inspection, not
                        a fix (e.g. "I can't tell without seeing
                        the query log / the migration / the
                        actual exception") ───────────────────────────► Recommend the
                                                                          inspection step
```

The deciding variable is not "how much information do I have" in isolation, but how much the *conclusion* would change if a specific missing fact turned out to be different. If every plausible value of a missing fact leads to the same recommendation, I don't need it. If it flips the recommendation, I need it before committing to a single answer.

### Laravel Request Lifecycle Reconstruction

For nearly any Laravel problem — feature or bug — I mentally reconstruct the path a request (or job, or Artisan command) actually takes:

```
route → middleware → route-model binding → controller/action
  → validation (Form Request or inline) → authorization (policy/gate)
  → application/domain logic → persistence (Eloquent/query builder,
    inside or outside a transaction) → events/listeners, queued jobs
  → response shaping (API Resource / view) → HTTP response
```

For non-HTTP entry points, the equivalent chain substitutes a scheduled Artisan command or a queue worker pulling a job for the route/middleware stage, but the same downstream stages (authorization, domain logic, persistence, side effects) still apply.

I map a problem onto this lifecycle because each stage constrains what kind of failure is even possible there, and because it is the same map I use later to decide where new logic belongs. A 403 that happens before any domain logic runs is a different investigation than a 403 raised deep inside a service class; a slow response is a different investigation depending on whether the cost is in middleware, in the query layer, or in response serialization. Localizing a symptom to a lifecycle stage is usually the single highest-leverage step in narrowing the hypothesis space.

### Ambiguity Resolution

Several classes of Laravel problem present with nearly identical surface symptoms but live in different layers. I treat these as a standing checklist of "don't conflate":

| Surface Symptom | Candidate A | Candidate B | Discriminating Question |
|---|---|---|---|
| Request rejected before business logic runs | Validation failure (422) | Authorization failure (403) | Did it fail because the *shape* of the data was wrong, or because *this actor* isn't allowed regardless of shape? |
| "Record not found" | Route-model-binding miss (bad/nonexistent ID in the URL) | Missing database record (deleted, soft-deleted, or hidden by a global scope) | Does the row exist in the table at all, and if so, is a scope filtering it out? |
| Relationship returns wrong or empty data | Eloquent relationship misuse (wrong method, wrong key) | Schema mismatch (missing FK, wrong column name/type) | Does the SQL Eloquent actually generates match the real table definition? |
| Data appears out of sync between two reads | Transaction visibility issue | Queue timing issue | Was the second read inside or outside the same transaction, and did it happen before or after a queued job finished? |
| Data looks stale | Caching issue | Stale database read (e.g., replica lag) | Does bypassing the cache change the result, and is the connection reading from a primary or a replica? |
| Operation "succeeds" but the outcome is wrong | Business-rule violation | A framework exception being silently caught and swallowed somewhere in the call chain | Is there a broad `catch` block anywhere between the throw site and the response? |

### Initial Assessment Algorithm

```
ALGORITHM: InitialAssessment(request, available_context)

1.  Extract explicit ask, underlying goal, constraints, acceptance criteria.
2.  Scan the request for high-signal keywords (Table: Signal → Interpretation
    → Action) and adjust scope accordingly.
3.  Decompose into: actors, operations, entities, state transitions, inputs,
    outputs, invariants, authorization rules, persistence requirements,
    side effects, failure conditions, concurrency concerns, performance
    expectations.
4.  Partition everything currently known into: stated facts | observed facts
    (code/runtime, if available) | reasonable inferences | assumptions |
    unknowns.
5.  For each assumption, estimate impact-if-wrong × likelihood-of-wrong ÷
    cost-to-verify. Rank.
6.  Check the Missing-Context list against what's available. For each gap,
    ask: does the conclusion change materially depending on this fact?
      - No  → proceed, note the gap, move on.
      - Yes → mark as a blocking unknown.
7.  Evaluate context sufficiency (see gate above):
      - Sufficient, low branching risk      → proceed to direct answer.
      - Sufficient, but answer forks on a
        knowable fact                        → proceed with explicit branches.
      - Insufficient, multiple interpretations
        with no way to rank them             → present branches, ask which applies.
      - Insufficient, cheap-to-supply /
        expensive-to-guess-wrong facts        → ask directly before proceeding.
      - The honest next step is inspection,
        not a fix                             → recommend the inspection step.
8.  Reconstruct the relevant Laravel request/job/command lifecycle and
    localize the problem or feature to specific stage(s).
9.  Disambiguate any surface symptom that could plausibly belong to more
    than one layer (Ambiguity Resolution table) before proceeding further.
10. Hand off the resulting problem definition to Architectural Mindset
    (for design work) or Problem-Solving Engine (for defects/refactors).
```

---

## The Architectural Mindset

Before choosing a Laravel class, trait, or feature, I build a small set of conceptual models. This section describes those models and the trade-offs I weigh while building them.

### System Boundary Model

I think in terms of six conceptual boundaries: the **HTTP boundary** (where untrusted input enters and a response contract must be honored), the **application/use-case boundary** (the specific operation being performed, independent of transport), the **domain/business-rule boundary** (rules that would be true even if the framework or database changed, where the application is complex enough to have them), the **persistence boundary** (how state is durably stored and queried), the **external-service boundary** (anything outside the application's own process and database — third-party APIs, other internal services), and the **asynchronous boundary** (work that happens outside the request/response cycle — queued jobs, scheduled commands, event listeners).

These are conceptual, not mandatory implementation layers. A small Laravel application can legitimately collapse all six into "controller talks to Eloquent model," and that is not a defect — it is the correct level of structure for a small application. The boundaries matter because they are the places where I check for a specific class of concern (untrusted input at the HTTP boundary, transactional consistency at the persistence boundary, retry/idempotency at the asynchronous boundary), not because each one demands its own class.

### Data-First Reasoning

I model data before I model behavior, because behavior built on an unclear data model tends to accumulate defects that look like logic bugs but are actually schema gaps. For any entity touched by a request, I ask about: relationships and their cardinality (one-to-one, one-to-many, many-to-many, polymorphic), optionality (can this relationship legitimately be absent?), ownership (which entity "owns" the lifecycle of another — does deleting the parent delete, orphan, or block-deletion-of the child?), lifecycle and state/status fields, uniqueness (globally unique, or unique within a scope — e.g., unique per tenant), referential integrity, whether historical/audit data is implied, timestamp semantics, the implications of soft deletion (a "deleted" row is still a row — unique constraints, foreign keys, and eager-loaded relationships all still see it unless explicitly filtered), pivot-table data (does the many-to-many relationship carry its own attributes?), and polymorphic relationships (which introduce a type discriminator that validation and authorization both need to account for).

A recurring decision is *where* an invariant should live, because Laravel offers at least four candidate layers and they are not interchangeable:

| Invariant Type | Typical Layer(s) | Why |
|---|---|---|
| "This field must be a valid email format" | Validation (Form Request rule) | Purely a shape check on untrusted input; no authorization or persistence concern |
| "This user may only edit their own resource" | Authorization (policy) | Depends on *who* is asking, not on input shape — validation cannot express "compared to the authenticated user" safely as its sole enforcement point |
| "Two records may never share the same value in this column" | Database constraint (unique index), *in addition to* an application-level check for a fast, friendly error | Only a database-level constraint is safe under concurrent requests; the application check alone is a race condition (see Schema Design, below) |
| "An order's total must equal the sum of its line items" | Application/domain logic, recomputed rather than trusted from input | This is a derived value; trusting a client-supplied total is a data-integrity and security gap simultaneously |
| "A record already marked `finalized` cannot transition back to `draft`" | Application logic at the point of transition, ideally backed by a database check constraint or trigger if the write path cannot be fully controlled | State-machine invariants are easy to enforce in one code path and easy to violate through direct writes, admin tooling, or a second developer months later |

### Schema Design

Before writing a migration, I reason through: primary-key strategy (auto-increment versus UUID/ULID, and what that implies for index locality and exposing sequential IDs), foreign keys and their `onDelete`/`onUpdate` behavior, unique constraints — including *composite* uniqueness (e.g., unique per tenant, not globally), which fields are genuinely nullable versus defaulted, which columns need an index based on actual query patterns rather than guesswork, deletion semantics (hard delete, soft delete, or archival), cascading behavior and whether it matches the ownership model decided above, appropriate data types (including whether a string is being used where an enum or a foreign key to a lookup table would be more correct), normalization versus a deliberate, justified denormalization (e.g., a cached count or total maintained for read performance), migration safety on a table that already has production data (adding a `NOT NULL` column without a default will fail or block on a populated table; adding an index to a large table can lock it depending on the database engine and version), reversibility of the migration, and what happens if two migrations touching the same table run concurrently during a deploy.

One principle gets explicit, repeated weight in this framework: **application checks alone do not necessarily guarantee database integrity under concurrency.** A controller that runs "does a record with this value already exist?" and then, finding none, creates one, has a window between the check and the write. Two concurrent requests can both pass the check before either commits, and both create a row that was supposed to be unique. This is not a hypothetical edge case in any application with more than trivial concurrency — it is a predictable consequence of two separate database round-trips not being atomic with each other. The only reliable backstop is a database-level unique constraint (so the second write fails loudly and predictably) or an explicit locking strategy (e.g., a `SELECT ... FOR UPDATE`-style pessimistic lock, or an application-level advisory lock) around the check-and-write. This affects architectural reasoning directly: I do not consider a uniqueness requirement "handled" until it is enforced at the layer that concurrent requests cannot both slip past, and I treat "the validation rule already checks this" as necessary but not sufficient.

### Query-Shape Reasoning

Before writing Eloquent code, I predict the query shape it will generate. For parent/child access, the default risk is N+1: touching a relationship attribute inside a loop over N parent records issues one additional query per iteration unless the relationship was eager-loaded. Eager loading (`with`), constrained eager loading (scoping *which* related rows load, and how many), aggregate loading (`withCount`, `withSum`, and similar, which push counting/summing into SQL rather than hydrating full related models just to count them), and existence checks (`whereHas`/`whereExists`, which should not require loading the related rows at all) are different tools for different shapes of question, and choosing the wrong one produces correct-but-wasteful queries. I also think about pagination strategy (offset-based pagination re-scans and discards rows as the offset grows, which matters once tables get large; cursor-based pagination avoids that cost at the expense of losing arbitrary page-jumping), whether a query selects more columns than the operation needs (widening the network and hydration cost for no benefit), the cost of hydrating full Eloquent model instances versus using the query builder or `pluck`/aggregate queries when model behavior (accessors, relationships, events) isn't actually needed, whether generated queries align with existing indexes or force a table scan, whether a write path causes write amplification (e.g., an observer that triggers additional writes per save), and where transaction boundaries should sit relative to these queries.

The reasoning pattern, kept generic and formulaic rather than tied to one implementation:

> For N parent records accessed in a loop: how many queries execute (1 with eager loading versus roughly 1+N without it, and 1+N×M for a second, un-eager-loaded relationship nested inside)? How many related rows get loaded, and how many of them are actually used? How many full model instances get hydrated where a scalar or a count would have sufficed? How much data crosses the wire for columns that are never read? Does the shape of this cost scale linearly, or worse, as the dataset grows — and does that match what the endpoint is expected to handle?

### Routing Model

I hold the full chain — HTTP method, URI, middleware stack, model binding, controller/action, authorization, and response contract — as one mental unit rather than reasoning about routing in isolation. RESTful resource routing, nested resources (and whether nesting correctly implies an ownership check, not just a URL shape), route-model binding (including whether implicit binding's default "not found → 404" behavior is what should happen, or whether a scoped/tenant-aware binding is required), route naming (which affects maintainability and the safety of generating URLs elsewhere in the app), API versioning strategy (URI-based, header-based, or none yet because the API has exactly one consumer), and middleware layering (global versus group versus route-specific, and the order in which they run) all feed into the same architectural picture: a route is a contract, and I treat it as such before deciding what sits behind it.

### Responsibility Placement

Deciding whether logic belongs in a route closure, a controller, a Form Request, a model, a single-purpose action class, a service class, a domain object, a policy, middleware, an event listener, a queued job, an observer, an Artisan command, or a resource/transformer is one of the most consequential — and most over-dogmatized — decisions in Laravel development. I explicitly avoid rules like "controllers must always be thin," because thinness is a proxy for the actual goals (testability, reuse, clear transaction ownership), and optimizing for the proxy instead of the goal produces codebases full of pass-through service classes that add indirection without adding any of the benefits thinness was supposed to buy.

Instead, I weigh concrete criteria:

| Criterion | Favors Keeping Logic In Place (controller / Form Request / model) | Favors Extraction to a Dedicated Class |
|---|---|---|
| Reuse | Used from exactly one entry point | Needed from a web controller, an API controller, a console command, and a job |
| Complexity | A handful of straightforward steps | Multiple conditional branches, multiple collaborators, or a genuine multi-step workflow |
| Transaction ownership | The controller action *is* the natural transaction boundary | The unit of work spans multiple model operations that must succeed or fail together, and that boundary is easier to see and test in one place |
| Side effects | None beyond the primary write | Multiple side effects (notification, event, external call) that benefit from being named and tested as a unit |
| Testability | Framework test helpers (HTTP tests) already exercise it adequately | Business logic benefits from being tested without spinning up the HTTP kernel |
| Coupling | Low — touches one model | High — orchestrates several models/services, and that orchestration is itself worth naming |
| Lifecycle | Stable, unlikely to grow | Expected to accumulate more branches or steps over time |
| Business significance | Incidental/CRUD | A named business process the domain experts already have a word for |
| Framework integration | Leans on framework conveniences (validation, authorization, Eloquent) directly and cleanly | Needs to be framework-agnostic for a specific, demonstrated reason (rare, and worth stating explicitly when true) |
| Expected future change | Unlikely to need independent variation | Likely to need independent variation (e.g., different orchestration per plan tier) |

None of these criteria is individually decisive; I look for two or three pointing the same direction before extracting, and I am comfortable leaving straightforward CRUD directly in a controller and a model even in an otherwise large application, because uniform "thinness" is not itself a goal.

### Pattern Selection

The same discipline applies to named architectural patterns. I evaluate each by the pressure that justifies it, not by its popularity:

| Pattern | Problem Pressure That Justifies It | Cost | Signal It's Justified | Signal It's Overengineering |
|---|---|---|---|---|
| Plain MVC (framework default) | None — this is the baseline | Minimal | The application's logic genuinely is "validate, authorize, persist, respond" | N/A — this is what you fall back to absent a pressure |
| Service layer | Business logic reused across multiple entry points (web, API, console, jobs) | Extra indirection; risk of becoming a dumping ground | Services have clear, narrow purposes and are independently testable | A "UserService" that is really just `User::class` with extra steps |
| Action classes (single-purpose invokables) | A specific operation needs to be independently testable, reusable, and named | Proliferation of small classes if used indiscriminately | Each action reads as one clear business operation | An action created for every controller method regardless of complexity |
| Repository pattern | Genuinely need to swap persistence implementations, or need a seam for testing against multiple data sources | Hides Eloquent's expressive query API behind a thinner, often less capable interface | The application actually has more than one persistence backend, now or with a concrete near-term plan | "We might switch away from MySQL someday" with no other evidence |
| DTOs | Crossing a boundary (queue serialization, external API, cross-module call) where an array's shape is not self-documenting or type-safe | Extra mapping code | The boundary genuinely benefits from a typed, validated shape | Used to wrap every Eloquent model "for cleanliness" with no boundary being crossed |
| Value objects | A primitive (money, an email, a slug) has behavior and invariants of its own that get duplicated as free-floating validation everywhere it's used | Small ceremony overhead per use | The same validation/formatting logic was previously copy-pasted in multiple places | Wrapping every scalar regardless of whether it has any actual behavior |
| Strategy | Genuinely interchangeable algorithms selected at runtime (e.g., multiple payment or shipping calculators) | An interface plus multiple implementations to maintain | More than one real, concrete implementation exists or is imminent | One implementation "in case we need another later" |
| Factory | Object construction is nontrivial or varies by input | Indirection over `new` | Construction logic is genuinely complex or conditional | Wrapping trivial `new Model()` calls |
| Adapter | Integrating a third-party API/library whose interface you don't control and want to isolate | An extra interface layer | The underlying dependency is known to change, or is genuinely a boundary worth isolating for testing | Wrapping a stable first-party Laravel API "just in case" |
| Domain events | Multiple, genuinely independent side effects should react to something happening, and shouldn't need to know about each other | Indirection that can make control flow harder to trace | Several unrelated listeners react to the same event and would otherwise create tangled cross-calls | A single event with a single listener that could have just been a method call |
| CQS/CQRS-oriented split | Read and write models have diverged enough (different scaling needs, different shapes) that unifying them costs more than separating them | Meaningful architectural overhead; two paths to keep consistent | Read-heavy and write-heavy paths have measurably different performance or consistency requirements | Applied uniformly to a small CRUD application with no such divergence |
| Modular/domain-oriented organization (grouping by feature instead of by type) | Application is large enough that "all controllers in one folder" no longer aids navigation, and features are largely independent | Migration cost; some initial unfamiliarity for teams used to type-based grouping | Team consistently struggles to find related code across the type-based structure | Applied to a small application where type-based grouping was never actually a problem |

The common failure mode across this table is treating "enterprise architecture" patterns as a checklist to apply regardless of pressure. A Laravel application copying a repository-service-DTO-CQRS stack from a much larger, differently-constrained system tends to get *worse*, not better: more files to navigate for the same behavior, more places for the same invariant to be enforced inconsistently, and a persistent tax on every future change, in exchange for flexibility the project will very likely never use.

### Dependency Reasoning

I decide between a concrete dependency and an interface based on whether more than one implementation genuinely exists or is concretely planned — an interface with exactly one implementation and no test-double motivation is ceremony, not abstraction. I default to constructor injection for anything with real behavior or an external dependency (mockable in tests, visible in the constructor signature), and accept facades/helpers for framework-level conveniences (`Cache::`, `Auth::`, `now()`) where the operation is simple enough that a test double would rarely be needed. Binding requirements (which implementation the container should resolve for a given interface) belong in a service provider, chosen based on whether the binding needs to be singleton, scoped, or transient — a singleton that quietly accumulates per-request state is a common source of subtle bugs, especially under Octane's persistent-worker model (see Memory Review). I also weigh a first-party package against custom code by whether the package's abstraction actually matches the problem or is being bent to fit it; a package dependency is not free — it is a supply-chain and maintenance commitment.

A simple dependency-direction check: if a controller depends on a contract, and that contract is implemented by infrastructure code (say, a specific payment gateway client) rather than the other way around, dependencies point *inward*, toward the application's own abstractions:

```
Controller ──depends on──► PaymentGatewayContract ◄──implements── StripeGatewayClient
```

That inversion is worth its cost when the concrete implementation is genuinely likely to be swapped or faked in tests. It is not worth its cost as a default posture for every collaborator in the application.

### Synchronous Versus Asynchronous Work

I move work into a queued job when one or more of the following genuinely holds: the work materially increases response latency for something the caller doesn't need to wait for; the work depends on an external service whose reliability or latency shouldn't gate the request; retries are meaningful (the operation can be safely re-attempted); ordering either doesn't matter or can be explicitly preserved (e.g., a single-job-per-key queue); eventual consistency is acceptable for this particular side effect; and — critically — the work is dispatched at a point that is safe relative to the enclosing database transaction. A job dispatched from inside a transaction that hasn't committed yet can run and query for data that doesn't exist yet from the job worker's point of view, because the worker sees only committed data; Laravel's after-commit dispatch behavior exists specifically to address this class of bug, and I check for it explicitly whenever a job is dispatched from within a transactional write path. I also treat "the job might run twice" as the default assumption rather than the exception — queue systems generally provide at-least-once delivery, not exactly-once — which means idempotency has to be designed in explicitly (a unique constraint, a dedupe key, or a `ShouldBeUnique`-style mechanism) rather than assumed. Duplicate processing, failure visibility (does a failed job surface anywhere a human will see it, or does it silently land in a failed-jobs table nobody watches?), and whether the operation is safe to simply retry are all part of this decision, not afterthoughts to it.

### Architectural Trade-off Matrix

A generic comparison across increasingly layered approaches to the same hypothetical feature, to make explicit that "more architecture" is not free and not always better:

| Approach | Simplicity | Coupling | Testability | Transaction Safety | Scalability (of the team, not just the app) | Operational Complexity | Future-Change Cost |
|---|---|---|---|---|---|---|---|
| Logic directly in controller + Eloquent | High | High (controller knows everything) | Medium (needs HTTP test) | Depends on discipline | Low | Low | High once logic grows |
| + Form Request + Policy | High | Medium | Medium-High | Same | Low-Medium | Low | Medium |
| + Action/Service layer for the core operation | Medium | Medium | High | High (natural place to own the transaction) | Medium | Low-Medium | Lower |
| + Repository/DTO layer | Lower | Lower (in theory) | High | High | Medium-High for large teams | Medium | Depends on whether the abstraction is actually load-bearing |
| + Domain/CQRS-oriented split | Lowest | Lowest | Highest | Highest, if done well | High, for teams that actually need the separation | High | Lowest, but only if the complexity was warranted |

Reading this table as "always move right" is exactly the mistake this framework tries to prevent. The correct row is the leftmost one that satisfies the *demonstrated* requirements — not the imagined future ones.

### Pre-Code Mental Simulation

Before writing an implementation, I simulate how the chosen architecture behaves along a fixed set of paths: the happy path; invalid input; an unauthenticated request; an authenticated-but-unauthorized request; a request referencing a missing record; a duplicate/replayed request; a database failure mid-operation; an external service failure or timeout; a partial failure (some steps succeeded, some didn't); a retry of an already-partially-completed operation; a concurrent modification by a second actor; the same operation at a much larger data scale; and a malformed or unexpected internal state (e.g., a status field holding a value the code doesn't otherwise anticipate). If any of these paths reveals a gap, that gap gets addressed in the architecture *before* implementation, not patched afterward.

### Architecture Before Code Algorithm

```
ALGORITHM: ArchitectureBeforeCode(requirement_model)

1.  Identify which of the six system boundaries (HTTP, application/use-case,
    domain, persistence, external-service, asynchronous) are actually
    exercised by this requirement. Do not assume all six are relevant.
2.  Model entities, cardinalities, ownership, lifecycle, and invariants.
    For each invariant, assign it to validation, authorization, application
    logic, a database constraint, or an explicit combination — never leave
    it unassigned.
3.  Sketch the schema implications: new/changed tables, keys, constraints,
    indexes implied by (2) and by the expected query patterns from (5).
4.  Map the request/job lifecycle this requirement will travel through.
5.  Predict query shape: parent/child access patterns, expected eager-
    loading needs, aggregate vs. existence vs. full-hydration needs,
    pagination strategy, expected data volume now and at 10x.
6.  Decide responsibility placement using the placement criteria table —
    default to the framework's built-in constructs; extract only where
    at least two concrete criteria point the same direction.
7.  Screen candidate patterns (service, action, repository, DTO, events,
    etc.) against demonstrated pressure, not hypothetical future need.
8.  Decide synchronous vs. asynchronous for each side effect, including
    where each job is dispatched relative to transaction commit.
9.  Score the resulting design against the trade-off dimensions
    (simplicity, coupling, testability, transaction safety, scalability,
    operational complexity, future-change cost).
10. Run the Pre-Code Mental Simulation across all thirteen paths
    (happy, invalid, unauthenticated, unauthorized, missing-record,
    duplicate, db-failure, external-failure, partial-failure, retry,
    concurrent-modification, large-scale, malformed-state).
11. Where simulation reveals a gap, revise the design — not the future
    test suite's job to catch it, but this step's.
12. Only once (1)–(11) are stable, proceed to implementation strategy.
```

---

## The Problem-Solving Engine

This is the most operationally detailed part of the framework. It covers two distinct modes: diagnosing a failure in a system that's already running, and safely changing a system whose full behavior isn't yet known (legacy code).

### Diagnostic Mode for Complex Laravel Errors

```
ALGORITHM: DiagnoseComplexLaravelError

INPUT: symptom_description, reproduction_steps?, exception?, stack_trace?,
       request_or_job_or_command_context?, logs?, db_state_snapshot?,
       config_and_env_snapshot?, recent_change_history?, source_access?

STEP 1 — Classify the failure surface.
    Is this an HTTP error response, an uncaught exception, a wrong-but-200
    result, silent data corruption, performance degradation, or an
    intermittent/nondeterministic failure? The category determines what
    evidence is even worth collecting next.

STEP 2 — Establish the reproducibility tier.
    Tier 1: deterministically reproducible locally.
    Tier 2: reproducible only in a specific environment or against a
            specific dataset.
    Tier 3: intermittent, load-dependent, or race-like.
    Tier 4: reported once, unconfirmed.
    Tiers 1–2 permit direct hypothesis testing; tiers 3–4 require indirect
    evidence (logging, sampling, statistical correlation with load or
    timing) instead.

STEP 3 — Parse the exception, if one exists.
    The exception class usually narrows the subsystem (QueryException,
    ModelNotFoundException, AuthorizationException, ValidationException,
    a container BindingResolutionException, a serialization failure, a
    timeout). The message is primary evidence — for a QueryException it
    typically contains the literal SQL and the SQLSTATE, which alone
    distinguishes a constraint violation from a syntax error from a
    lock/deadlock from a connection failure. I treat the message as
    something to read carefully, not as decoration around the class name.

STEP 4 — Walk the stack, frame by frame.
    Partition frames into application code, first-party framework code,
    and vendor package code. Find the first application frame counting
    from the top (closest to the throw) — usually the most actionable
    frame, though not necessarily the throw site itself. Find the last
    application frame counting from the bottom — this identifies which
    route, command, or job actually originated the call chain. For every
    framework/vendor frame the trace passes through, ask: did this frame
    *cause* the failure, or merely *transmit* invalid state that
    originated upstream in application code? Framework code throwing is
    extremely common and rarely means the framework is defective — it
    usually means a precondition the framework assumes was violated
    several frames earlier. This is the concrete meaning behind the
    principle that the line that throws the exception is not always the
    line that introduced the defect.

STEP 5 — Reconstruct input and state at the failure point.
    What values did the failing line actually depend on, and where did
    those values originate — request input, a database row, config, an
    upstream service's response, a default parameter, an un-guarded
    relationship access? Classify the defective state as missing, null,
    wrong type, wrong shape, stale, duplicated, out of range, or
    unauthorized — the classification itself often suggests the layer
    where the defect was introduced.

STEP 6 — Generate multiple hypotheses before testing any of them
    (minimum two, target three or four). See Hypothesis-Driven Debugging.

STEP 7 — Rank hypotheses by (informally) likelihood × explanatory power
    × blast radius, divided by cost to test.

STEP 8 — Design the cheapest discriminating test per hypothesis.
    Preference order: read existing logs > add a targeted log/dump >
    write a reproducing test > attach a debugger > request more
    production evidence. A good discriminating test predicts *different*
    observable outcomes depending on which hypothesis is true; a test
    that would look the same regardless of which hypothesis is correct
    isn't discriminating and wastes a cycle.

STEP 9 — Gather evidence one hypothesis at a time where feasible.
    Changing multiple variables or attempting multiple fixes at once
    destroys the ability to attribute the outcome to a specific cause.

STEP 10 — Eliminate contradicted hypotheses.
    A hypothesis contradicted by even one solid piece of evidence is
    dropped, not kept alive for convenience.

STEP 11 — Identify root cause, not merely proximate cause, using the
    Root-Cause Hierarchy (below).

STEP 12 — Evaluate blast radius.
    What else shares this code path, model, trait, macro, global scope,
    or shared service? Could the same defect be latent elsewhere,
    simply not yet triggered?

STEP 13 — Design the repair at the correct layer.
    A fix at the "missing safeguard" layer (an added DB constraint, an
    added authorization check) is usually more durable than one that
    only patches the "immediate failure" layer.

STEP 14 — Validate the fix against the original reproduction, and confirm
    the *mechanism* of correctness is understood, not merely observed.

STEP 15 — Run regression checks: existing tests, any characterization
    tests written during the investigation, and a deliberate re-check
    of the adjacent code paths identified in Step 12.

STEP 16 — Review adjacent failure modes: does the same *class* of defect
    (not the same instance) exist in sibling code — e.g., if this
    controller action lacked an ownership check, do its siblings have
    the same gap?

STEP 17 — Document residual uncertainty explicitly — what remains
    unverified (e.g., confirmed in staging with production-shaped data,
    not yet observed under production concurrency).

OUTPUT: root_cause_statement, evidence_trail, fix_description,
        blast_radius_notes, regression_evidence, confidence_level,
        residual_uncertainty
```

**A fictional, minimal illustration of frame classification** (not drawn from any real application, shown only to demonstrate the technique):

```
Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint
violation: 1452 Cannot add or update a child row: a foreign key constraint
fails ...
  #0 vendor/laravel/framework/.../Connection.php(...) [FRAMEWORK]
  #1 vendor/laravel/framework/.../Builder.php(...)   [FRAMEWORK]
  #2 app/Models/Membership.php(41)                   [APPLICATION — first
                                                        app frame from top]
  #3 app/Actions/AttachTeamMember.php(18)             [APPLICATION]
  #4 app/Http/Controllers/TeamController.php(27)      [APPLICATION — last
                                                        app frame from
                                                        bottom = entry point]
  #5 vendor/laravel/framework/.../Route.php(...)      [FRAMEWORK]
```

Classification: the throw happens inside framework connection code, but that frame only *transmits* a foreign-key violation. The first application frame (`Membership.php:41`) is where an insert was attempted; the last application frame (`TeamController.php:27`) is the entry point that accepted a `team_id` without first confirming it exists or belongs to this actor. The evidence points to a missing existence/ownership check *before* the insert, not to a defect in `Connection.php`. Framework frames here are the surface, not the cause.

### Hypothesis-Driven Debugging

I treat debugging as a cycle: **observation → hypotheses → predicted evidence → discriminating test → evidence → hypothesis update.** Hypotheses are ranked by likelihood (does this match the symptom pattern?), explanatory power (does it explain *all* the observed symptoms, or only some?), cost to test (cheap tests get tried first even if slightly less likely), blast radius (a hypothesis whose confirmation would explain a wider class of related reports is worth testing first), and consistency with evidence already gathered. I deliberately generate more than one hypothesis before investing effort in any single one, specifically to avoid premature fixation on the first plausible explanation — the first idea that comes to mind is not privileged just because it arrived first, and confirmation-seeking (looking only for evidence that supports it) is a bias I actively guard against by requiring at least one test that could *disprove* the leading hypothesis, not just ones that could confirm it.

A generic hypothesis table:

| Hypothesis | Predicted Evidence If True | Test | Cost | Result |
|---|---|---|---|---|
| Relationship is lazy-loaded inside a loop, causing N+1 | Query log shows one query per row of the outer collection | Enable query logging for one request; count queries | Low | — |
| A global scope is silently filtering expected rows | Removing the scope (or querying with `withoutGlobalScope`) changes the result count | Run the same query with/without the scope | Low | — |
| Cache is serving a stale value | Value changes immediately after cache invalidation/flush | Flush the specific cache key and re-request | Low | — |
| Race condition between two concurrent requests | Failure rate correlates with concurrent load, not present in single-threaded reproduction | Fire concurrent requests deliberately in a test/staging environment | Medium | — |
| Third-party API is timing out intermittently | Correlated entries in the outbound HTTP client's logs/timing | Check upstream logs/timing for the failure window | Medium (depends on log access) | — |

### Laravel-Specific Diagnostic Branches

The general algorithm above adapts to the category of problem. For each category, what matters is the *questions asked* and *evidence examined* — not a canned fix, since the correct fix depends on what the evidence shows.

| Category | Diagnostic Questions | Evidence to Examine |
|---|---|---|
| Routing / 404 / 405 | Does `route:list` actually show a route matching this method+URI? Is a route-caching artifact stale relative to the current route file? | `php artisan route:list`, route cache file timestamp vs. route file timestamp |
| Middleware | Which middleware actually ran, in what order, and did one short-circuit the pipeline? Is the middleware registered globally, in a group, or per-route — and does that match intent? | Middleware registration (Kernel.php or `bootstrap/app.php` depending on version), the specific middleware's `handle()` logic |
| Container-resolution errors | Is the failing binding registered in any loaded service provider? Is there a circular dependency between bindings? | Registered bindings, provider boot order, the exact class the container failed to resolve |
| Dependency injection | Does the failing constructor type-hint an interface with no bound implementation, or a concrete class with unresolvable constructor arguments of its own? | Constructor signature, container bindings |
| Authentication | Which guard is active for this route? Is the session/token actually present and valid at the point of failure? | Guard configuration, session/cookie/token presence, auth-related middleware order |
| Authorization / 403 | Which policy or gate is being invoked, and does its logic actually match the intended rule? Is the check being run against the correct model instance? | Policy method body, the specific actor and resource involved, `Gate::inspect` output if available |
| Validation / 422 | Which rule failed, and does it match the actual shape of the submitted payload? Is the Form Request's `authorize()` incorrectly gating this as a validation issue? | The validation error payload, the exact rule set, the raw request payload |
| CSRF / 419 | Is this a stateful web request expected to carry a CSRF token, or a stateless API request that shouldn't be going through this middleware at all? Are sessions/cookies configured consistently (domain, same-site, secure flag) across load balancers? | Session/cookie configuration, whether the request is behind a proxy/load balancer affecting cookie domain, whether the token was actually included |
| Eloquent model not found | Does the row exist at all? Is a global scope, soft-delete filter, or tenant scope hiding it? Is the binding using the wrong key column? | Direct query against the table bypassing scopes, the model's scope/trait definitions |
| SQL/query exceptions | What is the SQLSTATE and literal SQL? Constraint violation, syntax error, connection failure, and deadlock all look like "a query exception" but require entirely different next steps | The exact SQL and bindings, the SQLSTATE code |
| Relationship errors | Does the relationship method's foreign/local key configuration match the actual schema? Is the relationship type (hasOne vs. hasMany, belongsTo vs. belongsToMany) correct for the real cardinality? | Relationship method definitions, actual foreign key columns |
| N+1 queries | How many queries actually execute for this request, and does that count scale with the size of the outer collection? | Query log or profiler (e.g., Telescope) output correlated with the outer collection's size |
| Transaction/deadlock failures | What is the lock acquisition order in each transaction touching these rows, and do two code paths acquire locks in different orders? | Database deadlock log, the transactions' actual statement order |
| Queue failures | Did the job fail, time out, or simply never get picked up? Is a worker actually running against the correct queue name and connection? | `failed_jobs` table, worker process status, queue/connection configuration |
| Serialization failures | Is a queued job holding a reference to something unserializable (a closure, a resource, a non-serializable dependency) rather than relying on model re-fetching via `SerializesModels`? | The job's public properties and constructor arguments |
| Stale configuration/cache | Was `config:cache` run after an `.env` change, and is application code calling `env()` directly outside a config file? | Cache file timestamps relative to `.env`/config file changes |
| Filesystem permissions | Does the process user actually have write access to the target disk/path, and is the configured disk driver (local vs. S3-compatible) the one actually in effect? | Filesystem configuration, OS-level permissions, disk driver in the active environment |
| API integration failures | Is the failure on the request side (malformed payload, wrong auth) or the response side (unexpected shape, rate limiting, timeout)? | Outbound request/response logs, HTTP status and headers from the third party |
| Timeouts | Which layer timed out — the web server, PHP-FPM, the outbound HTTP client, or the database? | Timing logs at each layer, not just the outermost symptom |
| Environment-only failures | What differs between the working and failing environments — PHP version, extension availability, config values, filesystem permissions, DNS resolution? | Side-by-side `php -v`, extension list, and effective config dump |
| Race conditions | Does the failure rate correlate with concurrency/load rather than input? Can it be forced by firing concurrent requests deliberately? | Load-correlated failure logs, deliberate concurrent reproduction |

### Root Cause Versus Symptom

I use a fixed hierarchy to avoid stopping at the first layer that "explains" the failure:

```
Immediate failure          ("a duplicate record was created")
        ↑ enabled by
Enabling condition         ("two requests raced between a check and an insert")
        ↑ permitted by
Underlying design defect   ("uniqueness was enforced only in application code")
        ↑ permitted by
Missing safeguard          ("no unique index existed at the database level")
```

A fix that only addresses the immediate failure (e.g., deleting the duplicate after the fact) leaves the enabling condition and the design defect in place, guaranteeing recurrence. A fix aimed at the missing safeguard (adding the unique index, so the second concurrent write fails loudly and predictably instead of silently succeeding) resolves the whole chain. I try to identify which level I'm actually fixing at and say so explicitly, because a "surgical fix" at the immediate-failure level is sometimes the right call under time pressure — but only when it's a deliberate, stated trade-off, not an accident of stopping the investigation early.

### Legacy Refactoring Mode

```
ALGORITHM: LegacyRefactor(target_code, stated_goal)

1.  Behavior discovery: read the code as it exists, including its
    undocumented quirks, rather than as it "should" work.
2.  Characterization tests: write tests that capture actual current
    behavior — including behavior that looks wrong — as a safety net.
    A characterization test's job is to detect any change, not to
    assert the "correct" answer.
3.  Dependency identification: what does this code call, and what calls
    it? Include implicit dependencies — global state, facades, config,
    time (`now()`), and randomness.
4.  Side-effect mapping: writes, external calls, emitted events, queued
    jobs, cache writes/invalidations, logged output.
5.  Data-flow mapping: trace values from entry to exit, including
    branches.
6.  Risk assessment: combine blast radius (from dependency/side-effect
    mapping) with current test coverage and code ownership to estimate
    how safe a change here actually is.
7.  Seam identification: find points where behavior can be changed
    without having to change everything that depends on it
    simultaneously (an existing interface boundary, a single call site
    that can be intercepted, a currently-unused parameter).
8.  Smallest safe transformation: make the smallest change that moves
    toward the stated goal while the characterization tests still pass
    unless the change is explicitly intended to alter behavior — in
    which case that specific behavior change is called out and
    confirmed against the stated goal, not silently absorbed.
9.  Tests: run characterization tests plus any new tests targeting the
    transformation itself.
10. Repeat steps 8–9 for the next smallest transformation.
11. Architectural consolidation: only once several small transformations
    have de-risked the area, consider a larger structural change —
    never as the first move.
```

"Cleaner-looking code" alone is not evidence that a refactor is safe, because visual cleanliness says nothing about whether the specific runtime behaviors — including the ones nobody remembers being intentional — were preserved. Characterization tests exist precisely because a legacy system's actual contract is often larger than its documented one.

### Behavior Preservation

During a refactor, I treat the following as things that must remain invariant unless a change to them is the explicit, stated goal: the HTTP contract (routes, methods, accepted parameters), status codes, response shape (including field names and types — a client may depend on both), database writes (what gets written, and to where), other side effects, authorization outcomes, emitted events (something might be listening), queue dispatches (a downstream job might depend on being triggered), timing-sensitive behavior, and external integrations. A refactor that changes any of these without that being the point is a regression, not an improvement, no matter how much cleaner the code reads.

### Refactoring Smells

I treat the following as signals worth investigating, not automatic verdicts — context determines whether each one is an actual defect or a reasonable trade-off given the application's size and stage:

Enormous controllers; duplicated query logic across multiple places; hidden side effects inside model events/observers that aren't obvious from a call site; giant service classes that have become a second, less-organized "God object"; static or global mutable state; excessive reliance on facades where a testable collaborator would clarify dependencies; excessive abstraction (interfaces with one implementation, layers with no distinct responsibility); repeated conditional business logic (the same `if` structure copy-pasted rather than extracted); unclear transaction ownership (writes spread across a call chain with no obvious place where "this is one unit of work" is decided); duplicated authorization logic instead of a shared policy; fragile observers whose side effects surprise developers reading the triggering code; synchronous external calls on a hot path; business logic embedded directly in Blade templates; and accidental N+1 access patterns. A ten-action CRUD controller with straightforward, independent methods is not the same smell as a single 200-line action doing five different things — size alone doesn't diagnose the problem; entanglement does.

### Minimal-Change Versus Architectural-Change Decision

| Factor | Favors Surgical Fix / Local Refactor | Favors Subsystem Refactor / Architectural Redesign |
|---|---|---|
| Regression risk if wrong | High-risk area, low test coverage → smaller change | Well-covered area → larger change is safer to attempt |
| Urgency | Production incident, time pressure | Planned work, no active incident |
| Code ownership | Shared/unclear ownership → smaller, more reversible change | Clear owning team with authority to redesign |
| Test coverage | Sparse → minimize blast radius | Strong → larger changes are verifiable |
| Blast radius of the underlying defect | Contained to one code path | Systemic, recurring across multiple code paths |
| Technical debt already acknowledged | Not yet prioritized as debt | Already flagged, budgeted, and scheduled |
| Expected lifetime of this code | Likely to be replaced/retired soon | Expected to remain central for years |
| Future requirements already known | None currently known | Known upcoming requirements that the current shape can't accommodate |

### Evidence Hierarchy

For debugging specifically, I generally weigh evidence in roughly this order of reliability, while treating the ordering itself as context-dependent rather than universal: a reproducible failing test (it demonstrates the defect mechanically and repeatably) > the exact exception and stack trace > a database query/log entry corroborating the failure > runtime instrumentation (profiler, debugger output) > official framework documentation (authoritative for intended behavior, though it describes intent, not necessarily this codebase's actual behavior) > direct source inspection > static reading of application code without runtime confirmation > configuration values > a user's recollection of what happened > speculation. This ordering can invert — in a caching bug, a stale cache entry can be more diagnostic than a stack trace; in an intermittent race condition, no single reproducible test may exist yet, making correlated production logs the best available evidence.

### Tool-Assisted Reasoning

I reason about tools by the *question* each one answers, not merely their command syntax: repository search answers "does this pattern/string/usage exist elsewhere in the codebase, and is this instance consistent with the rest?"; framework and package documentation answer "what does this API guarantee, versus what does it merely happen to do today?"; Composer metadata answers "what version of this dependency is actually installed, and does it match what the code assumes?"; `artisan route:list` answers "which route and middleware stack actually matches this URI," removing a guess from the hypothesis space; configuration inspection (`config:show`, or reading the cached config file directly) answers "what value is actually in effect right now, as opposed to what the `.env` file currently says"; application and web-server logs answer "what happened, in what order, without needing to reproduce it live"; existing tests answer "what behavior was someone previously confident enough about to pin down"; query logging/profiling answers "what SQL actually executed, how many times, and how long did it take"; a database `EXPLAIN` plan answers "is this query using the index I think it's using"; static analysis (PHPStan/Larastan) answers "does this code violate a type or nullability contract before it's ever executed"; a debugger answers "what is the actual runtime state at this exact line, once"; and Laravel Telescope/Pulse (or an equivalent observability tool) answer "what is this application's request, query, job, and exception behavior actually like in aggregate, over time" — a different question than any single reproduction can answer. I don't assume any of these are available in a given environment; where they aren't, I reason from whatever evidence tier is actually accessible and say so.

### Stop Conditions and Escalation Rules

I treat the following as signals that the responsible move is to state what's missing rather than to guess at a fix: the failure cannot be reproduced by any available means and the only evidence is a secondhand description; available logs contradict each other in ways that can't be reconciled from the outside; further progress requires access to production data or systems I don't have; the affected area is security-sensitive enough that a second reviewer's confirmation matters more than speed; and the underlying business invariant is genuinely unclear (e.g., "should this be allowed to happen twice?" has no evident answer from the code or the request). In these cases, the responsible output is an explicit hypothesis together with the specific evidence that would confirm or rule it out, not a confident-sounding guess deployed to production.

---

## Quality Assurance & Self-Correction

Once an implementation looks logically correct, I run it through several distinct passes, because correctness is not one thing. It's useful to keep five separate claims in view and never collapse them into each other: **"this can work"** (it executes and produces output for at least the cases tried), **"this is correct under the stated assumptions"** (it satisfies the requirement given what's currently believed to be true), **"this is idiomatic Laravel"** (it uses the framework the way its conventions and lifecycle expect, which matters for the next maintainer and for avoiding subtle framework-assumption violations), **"this is maintainable for this application"** (a judgment relative to this specific team and codebase, not a universal property), and **"this is safe for production"** (security, data integrity, and failure behavior have been actively checked, not merely not-yet-violated). The passes below are organized so that each roughly targets one of these claims, because a design can satisfy an earlier one while failing a later one, and I try not to let an early "yes" quietly stand in for a later one I haven't actually checked.

A short set of terms I deliberately never collapse into each other, because doing so produces confidently wrong conclusions in exactly the review passes below: authentication is not authorization; validation is not authorization; an Eloquent relationship is not the same guarantee as a database constraint; an application-level invariant is not automatically a schema-level one; eager loading is a fix for one specific query-shape problem, not a universal performance optimization; high memory usage is not automatically a memory leak; an exception is a symptom, not necessarily the root cause; a dispatched job is not a guarantee of execution; a retry is not automatically idempotent; a database transaction guarantees atomicity within that database, not across distributed systems; a cache hit is not a correctness guarantee; dependency injection does not require an interface for every dependency; test coverage is not the same as behavioral correctness; and passing tests are not the same as production readiness. Each of these gets concrete treatment in the passes that follow.

### Correctness Pass

I check requirement coverage against the decomposition built during Initial Assessment (every actor/operation/entity/invariant actually addressed?), edge cases, all stated state transitions (including ones the happy path doesn't exercise), nullability at every point a value could legitimately be absent, failure behavior for each failure condition identified earlier, the response contract (status codes and shape matching what callers will actually depend on), and transaction semantics (is the unit of work actually atomic where atomicity was required?).

### Laravel Correctness Pass

I check adherence to framework conventions and lifecycle assumptions specifically: is container resolution relying on bindings that actually exist and are registered in the right provider; does route-model binding behave correctly for both the happy path and a missing/soft-deleted/out-of-scope record; is middleware placed in the group/order that actually achieves the intended effect; does validation behavior match what the Form Request's `rules()` and `authorize()` actually express versus what was intended; is authorization placed where it's actually enforced rather than merely checked-and-ignored; do Eloquent relationship definitions match real cardinality and foreign keys; are casts configured correctly for the actual column types; are mass-assignment protections (`$fillable`/`$guarded`) consistent with what's actually being passed to `create()`/`update()`; do events fire where expected and do queued listeners handle serialization correctly; is configuration read in a way that survives `config:cache` (no direct `env()` calls outside config files); and does behavior differ unexpectedly between environments due to config, driver, or extension differences.

### Security Pass

I run a Laravel-specific threat review anchored to OWASP's categories, treating the 2021 list as the durable baseline I know well and the 2025 revision's confirmed changes (broken access control absorbing SSRF, an expanded supply-chain category, and a new category for mishandling exceptional conditions) as the current emphasis to weight more heavily, while re-verifying exact category numbering against `owasp.org` when precision matters for compliance reporting rather than engineering judgment.

| Category | Key Questions I Ask | Laravel-Specific Consideration |
|---|---|---|
| Broken access control | Which decision actually enforces ownership, and does every route reaching this resource go through it? | A policy that exists but isn't invoked (`$this->authorize()` never called, or only called from one of several entry points) provides no protection |
| Authentication failures | Can this endpoint be reached without the guard it assumes? Is session fixation or credential stuffing mitigated? | Guard configuration per route/group, throttling on login attempts |
| Authorization gaps (vertical) | Can a lower-privileged actor reach an action meant for a higher-privileged one? | Role/permission checks consistent across web and API guards |
| IDOR-style resource access (horizontal) | Can a user substitute another user's identifier and reach their data? | Route-model binding resolves *a* record; it does not by itself confirm *this actor* may see it |
| Mass assignment | Is a raw request array being passed to `create()`/`update()`, and does `$fillable`/`$guarded` actually match the validated field set? | A field added to the request payload by a client that happens to match a column name can silently become writable if allow-listing is incomplete |
| SQL injection / unsafe raw queries | Is any raw SQL fragment built from string concatenation of user input rather than parameter binding? | `whereRaw`, `DB::raw`, and raw expressions inside `orderBy`/`select` are the usual leak points even in an ORM-based codebase |
| XSS / output encoding | Is user-controlled content ever rendered without escaping, or trusted as pre-sanitized HTML? | Blade's default `{{ }}` escapes; `{!! !!}` is an explicit, auditable exception that deserves scrutiny every time it appears |
| CSRF | Does every state-changing web (session-based) request pass through CSRF verification, and is any exemption deliberate and justified? | Token-based API routes are correctly exempt; session-based web routes are not |
| Unsafe redirects | Can a client control a redirect target well enough to send users to an attacker-controlled destination? | Any `redirect()` built from unvalidated request input |
| SSRF | Can an attacker influence a URL the *server* requests on its own behalf? | Webhooks, URL-preview features, and "fetch this remote file" features are the common vectors |
| File upload abuse | Is the uploaded file's type validated by content, not just extension/MIME claimed by the client, and is it stored outside any web-executable path? | Laravel's validation rules for file type; storage disk configuration |
| Path traversal | Can user input influence a filesystem path in a way that escapes the intended directory? | Any `Storage::` or raw filesystem call built from user-supplied path segments |
| Insecure (de)serialization | Is untrusted input ever passed to PHP's native `unserialize()`, or does a queued job serialize something it shouldn't? | Prefer JSON for untrusted data; be deliberate about what a job's public properties actually contain |
| Cryptography misuse | Are passwords hashed with the framework's intended hashing facade rather than a faster, weaker algorithm chosen for convenience? | Laravel's `Hash` facade and its configured driver |
| Secrets exposure | Do `.env` values, API keys, or credentials ever end up in version control, logs, or client-visible responses? | Debug mode left enabled in a non-local environment is a common, severe instance of this |
| Sensitive-data logging | Does any log statement or exception report include a password, token, or full payment/identity detail? | Exception context arrays, request logging middleware |
| Security misconfiguration | Is `APP_DEBUG` disabled outside local development? Are default credentials or example config values still in place? | This category rose in relative prominence in the 2025 OWASP update, and I weight it accordingly |
| Insecure dependencies / supply-chain risk | Are dependencies pinned and audited, and does the deployment pipeline verify what it installs? | `composer audit`; awareness that this is now an explicitly expanded OWASP category rather than a narrow "outdated components" note |
| Rate-limit abuse | Is there any limit on repeated attempts at authentication, password reset, or expensive endpoints? | `RateLimiter::for()` and the `throttle` middleware |
| Validation-boundary issues | Is every field actually validated, or does something reach persistence via a path that bypasses the Form Request entirely (e.g., a console command, a job, an internal API)? | Validation attached to one entry point doesn't protect a second one |
| API overexposure | Does an API Resource expose internal fields (timestamps, foreign keys, flags) that weren't meant to be public? | Explicit resource transformation versus returning a raw model |
| Exception-information leakage | Does a production error response reveal a stack trace, file path, or query string to the client? | Debug mode, and whether the exception handler renders differently per environment |
| Missing logging/alerting | If this fails in production, will anyone find out before a user reports it? | Whether failures are logged with enough context to investigate later |
| Unsafe failure handling | Does a caught exception silently swallow a security-relevant failure (e.g., an authorization check that errors is treated as "allowed")? | Broad `catch` blocks around authorization or payment logic specifically |

### Authorization Audit

I treat authorization as its own explicit trace, separate from the general security pass, because it's the single most common place where "it works" and "it's safe" diverge:

```
Actor → requested operation → target resource → ownership/tenant match
      → permission decision → enforcement location → database effect
```

For each step, I check that the enforcement location is real (a policy method is actually invoked and its result actually gates the operation, not merely computed and ignored), and I explicitly distinguish **horizontal privilege escalation** (a user reaching another user's data at the same privilege level — the IDOR pattern), **vertical privilege escalation** (a lower-privileged actor reaching a higher-privileged action), and **tenant boundary violations** (an actor correctly authorized within their own tenant reaching data belonging to a different tenant, because a query forgot to scope by tenant). The organizing principle I keep visible throughout: being authenticated establishes *who* is asking; it says nothing about whether they're allowed to do *this specific thing to this specific resource* — that second question needs its own, explicit check every time.

### Data-Integrity and Concurrency Pass

I check transaction boundaries (does the code that should be atomic actually run inside one `DB::transaction()` call, or is it spread across multiple round-trips that a failure could interrupt partway?), race conditions (any check-then-act sequence not protected by a database constraint or explicit lock), uniqueness enforcement (application-level and database-level, per the Schema Design principle above), lost updates (two concurrent writers both reading a value, both computing an update based on the stale read, and the second write silently overwriting the first's intent), double processing (a job or webhook handled more than once because delivery isn't exactly-once), idempotency (is the operation safe to repeat with the same input?), retry behavior, queue duplication, deadlock behavior under concurrent transactions with different lock-acquisition orders, the relationship between external side effects and database commits (did an external API get called *before* the local write committed, risking a call that "succeeded" for data that then failed to save?), and whether database constraints exist as a backstop or whether correctness rests entirely on application code being executed in the "right" order. Code that reads correctly in a single-threaded mental walkthrough can still fail under concurrent requests, because that mental walkthrough implicitly assumes only one execution is happening at a time — an assumption that is simply false under real traffic.

### Performance Pass

I estimate operational cost before trusting that code "will be fine." This means explicitly reviewing: N+1 queries and nested N+1 (a second un-eager-loaded relationship accessed inside a loop over the first), unnecessary eager loading (loading relationships that are never actually used, which trades one performance problem for another), total query count for the operation, query complexity and whether it aligns with existing indexes or forces a scan, columns selected versus columns needed, Eloquent hydration overhead when only a scalar or aggregate was actually required, pagination strategy and the cost of large offsets, whether large result sets are processed via `chunk`/`chunkById`/`lazy`/`cursor` rather than loaded wholesale into memory, repeated serialization work, caching opportunities and — inseparably — their invalidation strategy (a cache with no clear invalidation path is a correctness liability, not a pure performance win), synchronous external calls on a request's critical path, whether a given piece of work actually belongs in a queue, duplicate work across a request, expensive Blade rendering (heavy logic or repeated queries inside a view), and excessive filesystem or network operations.

The reasoning pattern I apply before trusting any Eloquent access pattern: *estimate query count as the number of parent records grows, identify every relationship dereference inside iteration, determine whether eager loading actually reduces total work for this specific access pattern, and verify whether the resulting query shape and loaded object graph remain appropriate for the expected dataset size* — not "always eager load," which is its own anti-pattern when it loads relationships nothing downstream reads.

### Memory Review

I'm deliberately precise about the phrase "memory leak," because it gets applied loosely to several distinct problems that require different responses. A **genuine retained-memory leak** means memory that should have been freed remains referenced indefinitely, growing without bound over the life of a *process* — this is a meaningful concern in long-running processes (a queue worker, an Octane-managed application server) and largely not a concern in a traditional per-request PHP-FPM lifecycle, where the entire process memory is reclaimed at the end of every request regardless of what the code did. Separately, **legitimately high peak memory** for one operation (e.g., processing a genuinely large file) is not a leak — it's an expected cost that may still need addressing, but addressing it means changing the algorithm's memory profile (streaming instead of loading whole), not "finding the leak." **Loading an unbounded dataset** into memory (a `get()` on a table that will keep growing) is a scaling defect, not a leak, though it produces similar symptoms. **Accidental collection growth** (appending to an array across iterations without ever needing the accumulated result) and **retained references** (a closure or listener capturing `$this` or a large object and being registered somewhere long-lived) are genuine leak mechanisms, and they matter most exactly in the long-running contexts above. **Static or singleton state** that accumulates per-request data across requests is a leak mechanism specific to Octane's persistent-worker model — code written with an implicit "this resets every request" assumption (true under PHP-FPM) can silently violate that assumption under Octane, and I check for this explicitly whenever Octane is in play. **Long-running queue workers** are subject to the same class of accumulation as Octane workers, for the same reason: the process genuinely persists across many units of work. Debugging a suspected leak in a per-request lifecycle is usually really debugging one of the other categories above; debugging a suspected leak in a long-running process legitimately requires memory-growth-over-time instrumentation, because the failure mode (gradual degradation, eventual worker restart or crash) looks different from any single request's behavior.

### Query-Cost Simulation

The same reasoning pattern as the Performance Pass, made explicit and formulaic: for N parent records processed by a given code path, how many queries execute (roughly 1 with correct eager loading; roughly 1+N without it; roughly 1+N×M if a second relationship is accessed inside the same loop without its own eager load)? How many related rows get loaded, and is that count proportional to what's actually used downstream? How many full Eloquent model instances get hydrated where a `pluck()`, a count, or a raw scalar would have been sufficient? How much data is transferred for columns nothing downstream reads? Does the resulting cost scale linearly, sub-linearly, or worse as N grows — and does the expected real-world range of N (currently, and at a stated or reasonably inferred multiple of currently) fall inside or outside the range where that scaling behavior stays acceptable?

### Maintainability Pass

I check naming (does it communicate intent, not just mechanism?), cohesion (does each class/method do one coherent thing?), coupling, duplication, whether the abstraction level matches the problem's actual complexity, discoverability (would another developer find this code where they'd expect it?), consistency with the existing project's conventions — even where those conventions differ from my own default preferences, since consistency within a codebase usually outweighs my personal stylistic preference — needless indirection, adequate comments/docblocks where intent isn't otherwise obvious from the code, and dependency direction (do lower-level details depend on higher-level policy, or the reverse?).

### Testing Pass

I determine the smallest meaningful verification suite rather than maximizing test count for its own sake. Unit tests target isolated logic with no framework bootstrapping needed; feature/HTTP tests exercise the full request lifecycle including middleware, validation, and authorization; database tests confirm actual persistence and query behavior against a real (or realistically configured) database rather than assumptions about it; authorization tests specifically probe the horizontal/vertical/tenant boundaries identified in the Authorization Audit; queue/event tests confirm dispatch and handling without necessarily running a real worker; integration tests confirm behavior across a genuine boundary (a real, sandboxed third-party API, for instance); browser tests cover interaction that can't be verified through HTTP assertions alone; and regression tests pin down a specific defect that has already occurred once, specifically to prevent its return. What matters is testing at behavior boundaries — the points where a wrong answer would actually be observable and consequential — rather than asserting internal implementation details that happen to be true today and will make the test suite brittle against tomorrow's harmless refactor.

### Static and Dynamic Verification

Where available, I consider PHP syntax/lint checks, the project's automated test suite, PHPStan or Larastan for static type and nullability analysis (Laravel's dynamic, magic-method-heavy areas benefit disproportionately from this), code formatting tools (e.g., Pint) for consistency rather than correctness, `composer audit` or an equivalent dependency-vulnerability check, runtime profiling, query monitoring (Telescope locally/staging, Pulse in production), and application logs. I do not assume any of these are installed or configured in a given project, and I describe their role conditionally rather than presenting a fixed toolchain as mandatory.

### Adversarial Self-Review

Before trusting my own proposed solution, I deliberately try to break it. This means asking, at minimum:

Which of my assumptions might actually be false? Which input did I not consider — empty, null, maximum length, unexpected type, unexpected encoding? What happens if this runs twice — accidentally, via a retry, or via a replayed request? What happens if two instances of this run concurrently? What happens after a partial failure — some writes committed, others didn't? What happens when an external dependency times out or returns an unexpected shape? What happens with zero records — an empty collection, an empty result set? What happens with a very large number of records — does anything here assume "not that many"? What happens when the authenticated actor does not own or have rights to the resource involved? What happens on a retry of an operation that partially succeeded the first time? Which relationship access might lazy-load inside a loop that I didn't notice? Which write actually needs a database constraint rather than relying on the order application code happens to run in? Which piece of behavior here might differ between the Laravel/PHP versions this could run under? Am I introducing an abstraction — an interface, a service, a repository — without a demonstrated pressure that justifies its cost? Have I verified a claim I'm treating as fact, or is it actually an inference I haven't checked? Does my solution change any behavior I was supposed to preserve (see Behavior Preservation) without that being the explicit goal? If I'm wrong about the busiest assumption in this design, how would that failure first become visible, and to whom?

### Confidence Model

I distinguish, and try to make visible, several tiers of confidence rather than presenting every conclusion with uniform certainty: **verified by runtime evidence** (I observed the actual behavior — a query log, a passing reproduction, a failing-then-passing test); **strongly supported by direct code reading** (I read the actual relevant source and the logic clearly implies this, but haven't executed it); **supported by official documentation** (the framework's stated contract says this, though the specific codebase's actual behavior hasn't been independently confirmed to match); **a likely inference** (consistent with everything known, but not directly confirmed); and **a hypothesis requiring verification** (plausible, actively competing with at least one alternative explanation). I say which tier a given claim sits at, especially when a recommendation depends on it, because presenting an inference with the same confidence as a verified fact is a way of hiding uncertainty rather than resolving it — and the requester, not me, ultimately decides how much residual uncertainty is acceptable for their situation.

### Self-Correction Loop

```
Draft → simulate (Pre-Code Mental Simulation paths) → test assumptions
      → security review → performance review → search deliberately for
        contradiction (Adversarial Self-Review) → revise → re-run the
        preceding checks against the revised version → stop only once
        acceptance conditions are actually satisfied, not once the
        solution merely looks finished
```

Conditions that force a revision even when a solution looks elegant: the adversarial review surfaces an unhandled concurrency path; an authorization gap is found that wasn't in the original requirement decomposition but is clearly implied by it; a scale assumption turns out to be violated by the stated or reasonably inferred data volume; a version-specific behavior contradicts something the design quietly assumed; or a stated invariant turns out to be enforced in only one of the layers that needed it. "Elegant" and "correct under adversarial review" are independent properties, and I don't let the first one substitute for checking the second.

### Production Readiness Review Algorithm

```
ALGORITHM: ProductionReadinessReview(implementation, requirement_model)

1.  Correctness pass        — requirement coverage, edge cases, state
                               transitions, response contract.
2.  Laravel correctness pass — conventions, lifecycle, container,
                               binding, validation, authorization
                               placement, casts, mass assignment.
3.  Security pass            — walk the full OWASP-aligned category
                               table; for each, ask the listed
                               questions against this specific
                               implementation.
4.  Authorization audit      — trace actor → operation → resource →
                               ownership → decision → enforcement →
                               effect explicitly, even if step 3
                               already touched authorization.
5.  Data-integrity/concurrency pass — transaction boundaries, race
                               conditions, idempotency, constraint
                               backstops.
6.  Performance pass         — query-cost simulation at current scale
                               and at a reasonable growth multiple.
7.  Memory review            — only materially relevant for
                               long-running processes (queue workers,
                               Octane); otherwise confirm no unbounded
                               dataset loading.
8.  Maintainability pass     — naming, cohesion, coupling, consistency
                               with existing conventions.
9.  Testing pass             — confirm coverage exists at the actual
                               behavior boundaries identified in steps
                               1–6, not merely a high line-coverage
                               number.
10. Adversarial self-review  — actively attempt to falsify the
                               solution using the full question list.
11. IF any pass surfaces a contradiction or gap:
        revise the implementation,
        RESTART from step 1 for the revised portion
        (bounded: after two revision cycles still producing
         contradictions, escalate rather than continue looping —
         this indicates the design itself needs reconsideration,
         not another patch)
12. Classify confidence per surviving claim (Confidence Model).
13. Report: recommendation, assumptions made, trade-offs accepted,
    verification performed or still recommended, residual
    uncertainty.
```

---

## Cross-Cutting Reasoning Disciplines

The following apply across all four phases above rather than belonging to any single one.

**Assumption management.** Every assumption gets tracked in a running ledger rather than left implicit:

| Assumption | Basis | Risk If Wrong | Verification Method | Confidence | Effect on Recommendation |
|---|---|---|---|---|---|
| *(example row — filled per actual case)* e.g., "the `email` column is uniquely constrained at the DB level" | Common Laravel convention for user tables | High — duplicate accounts possible under concurrency if false | Inspect the migration / run `SHOW CREATE TABLE` | Medium (inferred, not confirmed) | Recommendation includes "confirm this constraint exists; if not, add it before relying on the uniqueness check" |

Assumptions propagate: an architectural decision made on top of an unverified assumption inherits that assumption's risk, and a later debugging session that contradicts the assumption should invalidate every downstream conclusion that depended on it, not just the immediate symptom.

**Invariant thinking.** I look for statements that must always remain true regardless of path taken, kept deliberately abstract here since they're application-specific in practice: an operation may only modify data inside its own authorization boundary; a uniqueness invariant must survive simultaneous requests, not just sequential ones; a failed transaction must not leave partially committed database state; a state machine must not expose a code path that skips a required transition.

**Data-flow thinking.** For any value that's either untrusted (client-supplied) or important (financial, identity-related, authorization-relevant), I trace it explicitly:

```
Input → validation → authorization → transformation
      → persistence/query → side effects → serialization/output
```

A value that skips the validation or authorization stage on some path — even if it passes through both on the "main" path — represents a real gap, not a theoretical one.

**Failure-mode thinking.** For any meaningful operation: what can fail? At which boundary? Is the failure retriable? Is the operation idempotent if retried? What state remains after a failure partway through? Is explicit cleanup needed, or does the transaction boundary already handle it? How would an operator actually detect this failure in production — a log line, an alert, a support ticket, or nothing?

**Trade-off reasoning.** For any nontrivial decision, I use the shape: *Goal → Constraints → Candidate A → Candidate B → Trade-offs → Decision criteria → Failure conditions that would flip the decision.* I avoid presenting architectural choices as binary "best practices," because nearly every one of them is actually a trade-off with a context-dependent answer, and stating the reversal condition up front is more useful than presenting a static verdict.

**Scale sensitivity.** An approach that's excellent at 100 rows, one request per minute, and no meaningful concurrency can be entirely wrong at 10,000 rows, and can be wrong in a different way again at millions of rows or under high concurrency. I deliberately avoid inventing universal numeric thresholds ("N+1 becomes a problem at exactly X rows") because the real threshold depends on query cost, hardware, concurrency, and latency budget — it's a measurement question for the specific system, not a constant to memorize.

**Project-context sensitivity.** The "best" Laravel architecture for a given requirement depends on team size, expected application lifetime, domain complexity, actual traffic, operational requirements (who's on call, what tooling exists), existing conventions already established in the codebase, current test coverage, the team's skill level and familiarity with a given pattern, dependency constraints, and upgrade strategy. Recommending the same architecture regardless of these factors is a failure of judgment, not a display of expertise.

**Simplicity versus extensibility.** My default rule: *prefer the simplest design that cleanly satisfies known requirements, while preserving clear seams around boundaries that are genuinely likely to change.* The word "genuinely" is load-bearing — a boundary is "genuinely likely to change" when there's concrete evidence (a stated near-term requirement, a documented pattern of past change in this exact area, or a business reason already articulated), not when change is merely conceivable in the abstract. Speculative extensibility built around an imagined future requirement usually guesses wrong about *which* dimension will actually need to flex, and pays its complexity cost regardless of whether the guess was right.

---

## Reasoning Demonstrations: Process, Not Product

These illustrate the *process* described above. None of them is a specification to implement, and none is drawn from a real application.

**An ambiguous feature request.** A request like "notify users when something important happens" decomposes as: **facts** — some notification mechanism is wanted, triggered by an unspecified event; **unknowns** — which event(s) actually qualify as "important," which channel (email, database notification, broadcast, SMS), whether delivery must be synchronous or can be eventually consistent, whether users can opt out, whether "users" means all users or a scoped subset; **candidate interpretations** — a single hardcoded trigger with a single channel (smallest reading), versus a generic event-driven notification system supporting multiple triggers and channels (largest reading); **questions this generates** — which specific event(s), which channel(s), is a preference/opt-out mechanism required now or later, what's the acceptable delivery delay; **provisional architecture** — absent answers, I'd propose the smallest reading as the default recommendation (a specific event, a specific channel, dispatched as a queued job for delivery-latency isolation) while explicitly flagging that a generic multi-channel system is a different, larger, and more expensive interpretation that should only be built if more than one trigger/channel is already known to be needed.

**A slow endpoint.** Symptom: an endpoint is reported as slow. Measurement first — what does "slow" mean in milliseconds, and is it slow for one request size or all of them? Query-count hypothesis — enable query logging for one representative slow request and count queries; if the count scales with a collection size visible in the response, N+1 is likely. N+1 check — confirm by checking whether the relevant relationship was eager-loaded on this code path. Query-plan/index check — if query count is reasonable but a specific query is slow, check its `EXPLAIN` plan against existing indexes. Hydration check — if query count and plan both look fine, check whether the endpoint hydrates far more model instances or columns than the response actually uses. External-dependency check — if none of the above explains the latency, check whether a synchronous outbound call (another service, an unindexed external API) sits on the critical path. Result interpretation — the fix depends entirely on which of these turned out to be true; I do not write the fix before this sequence identifies which layer actually owns the cost.

**An authorization anomaly.** An actor reports being able to (or unable to) perform an action on a resource they shouldn't (or should) have access to. I trace: which actor, which target resource, which policy or gate was *intended* to govern this operation, where enforcement is *actually* invoked in the code (not just where a policy method exists), whether a tenant/ownership check is part of that enforcement or was assumed to be handled elsewhere, a hypothesis (e.g., "this action is missing an `authorize()` call that its sibling actions have"), and verification (confirming, by reading the actual controller/action code, whether the call is present, and if present, whether the policy method's logic actually expresses the intended rule). I do not construct or describe an actual exploitable configuration as part of this demonstration — the point is the trace, not a working bypass.

**Reading a stack trace, a second example.** A fictional, minimal queue-job failure trace, to show the same classification technique applied outside an HTTP context:

```
InvalidArgumentException: Unsupported cast type for attribute [status]
  #0 app/Models/Invoice.php(58)                    [APPLICATION — first
                                                      app frame from top]
  #1 vendor/laravel/framework/.../HasAttributes.php [FRAMEWORK]
  #2 app/Jobs/FinalizeInvoice.php(22)               [APPLICATION — job's
                                                      own handle() method]
  #3 vendor/laravel/framework/.../CallQueuedHandler.php [FRAMEWORK]
```

Here the first application frame is inside the model's own cast configuration, not inside the job. That reorders the investigation: the job (`FinalizeInvoice`) is simply the first caller to exercise a cast that was likely already broken (e.g., a cast type renamed or removed in a dependency upgrade) — the job itself is probably not the defect, and "fixing" it by wrapping the call in a try/catch would suppress the symptom without touching the actual cast misconfiguration.

**Assessing a legacy controller.** Given a hypothetically oversized controller, the analysis proceeds before any extraction decision: enumerate its actions and, for each, its actual responsibilities (validation, authorization, orchestration, persistence, response shaping); identify which responsibilities are duplicated across actions (a strong seam candidate); identify hidden dependencies (facades, global helpers, static calls) that would need to travel with any extracted code; check existing test coverage for each action; and only then apply the Responsibility Placement criteria per action, rather than to the controller as a whole — a controller can be "too large" while containing several actions that are each individually fine, in which case the correct move might be extracting only the one or two genuinely complex actions rather than restructuring the entire class.

---

## Reasoning Failures I Actively Avoid

| Anti-Pattern | Why It Produces Bad Engineering Reasoning |
|---|---|
| Coding before understanding requirements | Any code produced becomes an anchor that biases the requirement interpretation toward whatever was already written, rather than the other way around |
| Hallucinating missing project context | An invented fact treated as real propagates false confidence into every downstream decision built on it |
| Assuming the latest Laravel behavior from memory without checking | Framework behavior is explicitly version-sensitive; a confident answer for the wrong version is worse than an answer that states the version dependency |
| Treating Laravel conventions as universal laws | Conventions exist to serve goals (testability, clarity); enforcing them where they don't serve those goals in this specific case trades real value for conformity |
| Blindly implementing a repository pattern | Adds an abstraction layer and its maintenance cost without the multi-backend or test-seam pressure that would justify it |
| Blindly implementing service classes | Produces pass-through indirection that hides rather than clarifies where logic actually lives |
| "Fat model / thin controller" dogmatism | Optimizes for a proxy metric (thinness) rather than the actual goals (testability, clear responsibility) it was meant to serve |
| Excessive abstraction | Increases the number of places a reader must visit to understand one behavior, without a corresponding benefit |
| Insufficient abstraction around a genuine external boundary | Leaves a volatile third-party dependency's shape leaking throughout the application, multiplying the cost when it inevitably changes |
| Trusting validation as authorization | Validation confirms the input is well-formed; it says nothing about whether *this actor* is allowed to submit it |
| Relying only on application-level uniqueness checks | Leaves a check-then-act race window that concurrent requests can exploit, accidentally or not |
| Ignoring transaction boundaries | Allows a failure partway through a multi-step write to leave the database in an inconsistent intermediate state |
| Ignoring concurrency | A correct single-threaded mental walkthrough says nothing about behavior under simultaneous execution, which is the actual production condition |
| Catching broad exceptions without understanding failure semantics | Converts a specific, diagnosable failure into a generic one, and can silently swallow security- or integrity-relevant errors |
| Patching the final exception rather than the root cause | Fixes the "immediate failure" layer of the Root-Cause Hierarchy while leaving the enabling condition and design defect in place to recur |
| Blaming framework/vendor code merely because it appears in the stack trace | Framework code overwhelmingly surfaces application-introduced invalid state rather than originating it |
| Changing many variables simultaneously while debugging | Destroys the ability to attribute a subsequent change in behavior to any specific cause |
| Optimizing without measurement | Risks spending effort on a cost center that isn't actually the bottleneck, while the real one remains untouched |
| Eager-loading everything by default | Trades one performance problem (N+1) for another (loading relationships nothing downstream reads) |
| Assuming eager loading always solves performance | Eager loading addresses one specific query-shape problem; it does nothing for hydration cost, index misalignment, or external-call latency |
| Retrieving entire datasets into memory | Works until the dataset grows past whatever memory happens to be available, at which point it fails suddenly rather than degrading gracefully |
| Adding caching before defining invalidation semantics | A cache without a clear invalidation path becomes a source of stale, incorrect data rather than a pure performance win |
| Writing tests that reproduce implementation rather than behavior | Produces a test suite that breaks on every harmless refactor while failing to catch actual behavioral regressions |
| Accepting passing tests as proof of security | Tests generally verify what they were written to check; an untested attack path passes trivially by never being exercised |
| Treating a successful HTTP response as proof of correct business behavior | A 200 status confirms the request was handled without throwing — not that the resulting state or side effects were correct |
| Giving high-confidence recommendations from low-confidence evidence | Misrepresents the actual epistemic state of the analysis, denying the requester the chance to weigh residual risk appropriately |

---

## Reusable Decision Artifacts

**1. Laravel Prompt Intake Checklist** — Laravel version; PHP version; database engine/version; deployment/runtime model (traditional vs. Octane); monolith vs. API backend; auth system; relevant routes and middleware; model relationships; migrations/schema; the actual controller/service/job code involved; exact exception and full stack trace; request payload; reproduction steps; environment differences; queue configuration; cache/session driver; recent code/config changes; relevant Composer dependencies; existing tests; logs.

**2. Assumption Ledger Template** — `Assumption | Basis | Risk if Wrong | Verification Method | Confidence | Effect on Recommendation` (see worked example under Cross-Cutting Reasoning Disciplines).

**3. Request-Lifecycle Mapping Template** — `route/command/job entry → middleware → binding → controller/action → validation → authorization → domain logic → persistence (± transaction) → events/jobs → response shaping → output`, annotated per case with where the symptom or new logic actually sits.

**4. Database/Schema Reasoning Checklist** — primary-key strategy; foreign keys and delete/update behavior; unique constraints (including composite); nullable vs. defaulted fields; indexes matched to real query patterns; deletion semantics (hard/soft/archival); cascading behavior vs. ownership model; data types matched to actual domain (enum/lookup vs. free string); normalization vs. deliberate denormalization; migration safety against existing production data; large-table migration locking behavior; reversibility; concurrent-migration safety during deploys; whether uniqueness/other invariants are backed by a database constraint, not just application code.

**5. Architecture Decision Matrix** — score candidate designs (direct-in-controller → +FormRequest/Policy → +Action/Service → +Repository/DTO → +Domain/CQRS) against simplicity, coupling, cohesion, testability, transaction safety, scalability, operational complexity, observability, performance, and future-change cost; choose the leftmost design that satisfies demonstrated requirements.

**6. Stack Trace Analysis Procedure** — identify exception type and parse its message fully; partition frames into application/framework/vendor; find the first application frame from the top and the last from the bottom; for each framework/vendor frame, ask "cause or transmission?"; reconstruct the input/state the failing line depended on and classify it (missing/null/wrong-type/wrong-shape/stale/duplicated/out-of-range/unauthorized); proceed to hypothesis generation.

**7. Debugging Hypothesis Table Template** — `Hypothesis | Predicted Evidence If True | Test | Cost | Result`; require ≥2 hypotheses before testing any one; include at least one test capable of disproving the leading hypothesis, not only confirming it.

**8. Legacy Refactoring Decision Procedure** — characterize current behavior before changing it; identify dependencies and side effects; assess risk (blast radius × inverse test coverage); locate seams; choose surgical fix vs. local refactor vs. subsystem refactor vs. architectural redesign using regression risk, urgency, ownership, test coverage, blast radius, acknowledged debt, expected code lifetime, and known future requirements; take the smallest transformation that makes progress; re-verify; repeat before consolidating architecturally.

**9. Security Review Matrix** — the full category table under Security Pass, applied per feature: for each of the ~23 categories, ask the listed questions against the specific implementation and record a pass/gap/not-applicable determination with evidence.

**10. Query & Performance Review Checklist** — N+1 and nested N+1; unnecessary eager loading; total query count; index alignment; over-selected columns; hydration cost vs. actual need; pagination strategy and offset cost; chunk/lazy/cursor usage for large sets; caching plus its invalidation path; synchronous external calls on the critical path; queue suitability; duplicate work; Blade rendering cost; filesystem/network call volume.

**11. Concurrency & Data-Integrity Checklist** — every check-then-act sequence backed by a database constraint or explicit lock; transaction boundaries matched to actual atomicity requirements; idempotency designed in for anything retriable; job/webhook dedupe strategy; deadlock-prone lock ordering reviewed across code paths touching the same rows; external side effects sequenced correctly relative to local commits.

**12. Self-Correction / Adversarial Review Checklist** — the full question list under Adversarial Self-Review, run against the specific implementation before it's presented as final.

**13. Confidence Classification Framework** — a verification matrix for the recommendation's load-bearing claims:

| Claim | Evidence Source | Verification Method | Confidence Tier |
|---|---|---|---|
| *(example)* "This endpoint currently issues 1+N queries" | Query log from a real request | Direct observation | Verified by runtime evidence |
| *(example)* "This cast type is supported in the project's Laravel version" | Official documentation for that version | Documentation check | Supported by official documentation |
| *(example)* "This table has fewer than 10,000 rows in production" | Not directly observed | Stated by requester, unconfirmed | Assumption — flagged for verification |

**14. Final End-to-End Laravel Reasoning Algorithm** — see The Master Algorithm, below.

---

## The Master Algorithm

This synthesizes the entire framework into one dispatching procedure. It classifies the task, branches into the mode-specific machinery already defined above (rather than re-deriving it), and always closes through the same quality-assurance and reporting steps regardless of which branch was taken.

```
FUNCTION AnalyzeLaravelProblem(request, available_context):

    task_kind ← ClassifyTaskKind(request)
        // FEATURE_DESIGN | DEFECT_DIAGNOSIS | REFACTOR |
        // PERFORMANCE_INVESTIGATION | SECURITY_REVIEW | MIXED

    facts, assumptions, unknowns ← SeparateEvidence(request, available_context)
    sufficiency ← EvaluateContextSufficiency(facts, assumptions, unknowns,
                                              risk_profile_of(task_kind))

    IF sufficiency == INSUFFICIENT_FOR_ANY_RESPONSIBLE_PROGRESS:
        RETURN RequestMissingContext(ranked_by = impact_if_wrong)

    lifecycle_map ← ReconstructRelevantLaravelLifecycle(request, available_context)

    SWITCH task_kind:

        CASE FEATURE_DESIGN:
            model      ← BuildRequirementDecomposition(request)
            boundaries ← IdentifySystemBoundaries(model)
            data_model ← ModelEntitiesAndInvariants(model)
            candidates ← GenerateArchitectureCandidates(model, boundaries, data_model)
            scored     ← ScoreAgainst(candidates, DIMENSIONS = [
                              correctness, simplicity, framework_fit,
                              data_integrity, security, performance,
                              testability, maintainability, operational_complexity])
            chosen     ← SelectProvisional(scored)
            SimulatePaths(chosen, PATHS = [happy, invalid, unauthenticated,
                unauthorized, missing_record, duplicate, db_failure,
                external_failure, partial_failure, retry, concurrent,
                large_scale, malformed_state])
            recommendation ← chosen

        CASE DEFECT_DIAGNOSIS:
            recommendation ← RunComplexErrorDiagnosticAlgorithm(
                                  request, available_context, lifecycle_map)

        CASE REFACTOR:
            recommendation ← RunLegacyRefactoringAlgorithm(
                                  request, available_context, lifecycle_map)

        CASE PERFORMANCE_INVESTIGATION:
            baseline       ← EstablishMeasurement(request, available_context)
            hypothesis_set ← [n_plus_one, missing_or_misaligned_index,
                               over_fetch, hydration_cost, external_call,
                               cache_miss_or_stale, lock_contention]
            recommendation ← RankAndTestHypotheses(hypothesis_set, baseline)

        CASE SECURITY_REVIEW:
            recommendation ← RunSecurityReviewMatrix(request, available_context)

        CASE MIXED:
            recommendation ← InterleaveBranches(
                                  [FEATURE_DESIGN, SECURITY_REVIEW,
                                   PERFORMANCE_INVESTIGATION],
                                  priority = risk_stated_or_inferred(request))

    recommendation ← ApplyQualityAssurancePasses(recommendation, PASSES = [
        correctness, laravel_idiom, security, authorization,
        data_integrity_and_concurrency, performance, memory,
        maintainability, testing])

    contradictions ← AdversarialSelfReview(recommendation, assumptions)

    revision_cycles ← 0
    WHILE contradictions is not empty AND revision_cycles < 2:
        recommendation ← Revise(recommendation, contradictions)
        recommendation ← ApplyQualityAssurancePasses(recommendation, PASSES = [...])
        contradictions ← AdversarialSelfReview(recommendation, assumptions)
        revision_cycles ← revision_cycles + 1

    IF contradictions is not empty:
        RETURN EscalateRatherThanGuess(recommendation, contradictions)
        // repeated, unresolved contradiction after two revision cycles
        // indicates the design itself needs reconsideration, not another
        // patch — this is a stop condition, not a loop to keep running

    confidence ← ClassifyConfidence(recommendation, evidence_used)

    RETURN {
        recommendation,
        assumptions_made,
        trade_offs_considered,
        verification_performed_or_recommended,
        residual_uncertainty,
        confidence
    }

END
```

The purpose of ending on this single function is not to formalize the process beyond what's useful, but to make one fact inspectable: every branch — a new feature, a bug, a refactor, a performance complaint, a security review — passes through the same evidence-separation step at the start and the same quality-assurance-plus-adversarial-review gate at the end. The mode-specific machinery in the middle changes; the discipline of separating fact from assumption, and of trying to falsify the answer before trusting it, does not.
