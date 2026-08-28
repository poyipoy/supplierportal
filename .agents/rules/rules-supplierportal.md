---
trigger: always_on
---

You are working inside an existing software project. Before planning, analyzing, modifying, generating, or reviewing any project code, you MUST establish project context by reading the project's instruction and reasoning documents.

## Mandatory Context Initialization

At the beginning of every new chat session or task, before making implementation decisions:

1. Locate and read `AGENTS.md` completely.
2. Locate and read `CLAUDE.md` completely.
3. Locate and read `claudes-cognitive-framework-for-laravel-development.md` completely.

Do not begin implementation before these files have been inspected when they exist in the repository.

If one of these files does not exist, continue with the available files and explicitly note the missing file rather than inventing its contents.

## Purpose of Each File

Treat the files as having different responsibilities.

### `AGENTS.md`

This is the primary project-specific engineering instruction source.

Use it to understand:

* repository architecture;
* project-specific conventions;
* business rules;
* database rules and invariants;
* naming conventions;
* implementation constraints;
* testing requirements;
* migration requirements;
* commands and workflows;
* prohibited approaches;
* known architectural decisions;
* directory-specific instructions;
* operational requirements.

Project-specific facts in `AGENTS.md` must take priority over generic Laravel assumptions.

If nested or directory-specific `AGENTS.md` files exist, inspect and respect the most specific applicable instructions for the files being modified.

### `CLAUDE.md`

Treat `CLAUDE.md` as additional project intelligence and development guidance.

Use it to understand:

* project context;
* architecture;
* historical decisions;
* implementation guidance;
* domain terminology;
* important warnings;
* established development practices;
* tool usage guidance;
* previous conclusions that remain applicable.

Do not ignore `CLAUDE.md` merely because you are Gemini rather than Claude.

Its contents are project documentation and should be interpreted based on their substance, not the name of the file.

### `claudes-cognitive-framework-for-laravel-development.md`

Treat this document as the project's Laravel engineering reasoning framework.

Do NOT treat it as project-specific factual truth when it conflicts with the actual repository.

Instead, use it as the methodology for how you analyze Laravel work.

Apply its reasoning disciplines where relevant, including:

* requirement decomposition;
* explicit separation of facts, observations, assumptions, inferences, and unknowns;
* context sufficiency assessment;
* Laravel request lifecycle reconstruction;
* data-first architecture reasoning;
* invariant placement;
* schema and constraint reasoning;
* query-shape analysis;
* architectural trade-off analysis;
* responsibility placement;
* avoiding unnecessary abstraction;
* concurrency and transaction analysis;
* hypothesis-driven debugging;
* stack-trace analysis;
* root-cause analysis;
* legacy-code characterization;
* smallest-safe-change refactoring;
* security review;
* authorization review;
* OWASP-oriented analysis;
* N+1 and query-performance review;
* memory/resource review;
* testing strategy;
* adversarial self-review;
* confidence classification;
* self-correction before presenting work as complete.

Use the framework as a decision process, not as a requirement to produce verbose reasoning in the final response.

Do not expose hidden chain-of-thought or private reasoning. Perform the methodology internally and report concise conclusions, evidence, assumptions, trade-offs, and verification results when useful.

## Instruction Precedence

When information conflicts, use this precedence:

1. System/platform/tool safety requirements.
2. Explicit instructions from the user for the current task.
3. Applicable `AGENTS.md`, with the closest directory-scoped `AGENTS.md` taking precedence over broader ones.
4. Project-specific factual and architectural guidance in `CLAUDE.md`.
5. `claudes-cognitive-framework-for-laravel-development.md`.
6. Generic Laravel conventions and your prior knowledge.

A generic recommendation from the cognitive framework must never override verified project behavior or an explicit project rule.

When `AGENTS.md` and `CLAUDE.md` contain apparently conflicting project facts, inspect the repository, migrations, schema, configuration, tests, and implementation to determine which reflects the current codebase.

Do not silently choose one.

Prefer verified repository evidence over stale documentation and report the discrepancy when it materially affects the task.

## Repository Evidence Rule

Never rely solely on documentation when the requested change depends on current implementation details.

Before modifying relevant behavior, inspect the actual source of truth, which may include:

* routes;
* controllers;
* Form Requests;
* policies and gates;
* middleware;
* models and relationships;
* migrations;
* database constraints;
* services/actions;
* jobs;
* events/listeners;
* configuration;
* Blade/templates;
* JavaScript;
* tests;
* Composer dependencies;
* existing helper classes;
* relevant Git history when necessary.

Distinguish:

* documented behavior;
* observed implementation;
* inferred behavior;
* assumptions.

If documentation disagrees with the implementation, do not silently rewrite the system according to the documentation.

Determine which behavior is authoritative for the current task.

## Laravel Task Classification

Before implementation, classify the task internally as one or more of:

* feature development;
* bug diagnosis;
* refactoring;
* performance investigation;
* security review;
* database/schema change;
* UI/UX change;
* integration change;
* maintenance/documentation.

Then apply the relevant reasoning procedure from the Laravel cognitive framework.

## Before Coding

Before making changes:

1. Understand the requested outcome.
2. Inspect applicable project instructions.
3. Inspect the existing implementation.
4. Identify affected components and dependencies.
5. Identify relevant business invariants.
6. Identify authorization implications.
7. Identify database and transaction implications.
8. Identify potential backward-compatibility effects.
9. Identify relevant tests.
10. Prefer the smallest correct change that satisfies the requirement.

Do not redesign unrelated parts of the application unless explicitly required.

Do not introduce architectural patterns merely for stylistic purity.

Prefer consistency with the existing project unless there is a concrete reason to change the architecture.

## Database and Concurrency Discipline

For database-related work, always consider:

* foreign keys;
* unique constraints;
* nullable semantics;
* indexes;
* transactional boundaries;
* race conditions;
* check-then-act hazards;
* locking requirements;
* idempotency;
* migration safety;
* rollback implications;
* existing production data.

Do not treat application validation as a replacement for database integrity constraints where concurrency matters.

## Debugging Discipline

For bugs and exceptions:

1. Start from evidence.
2. Read the complete error and stack trace.
3. Identify relevant application frames.
4. Reconstruct the execution path.
5. Generate multiple plausible hypotheses when the cause is not already proven.
6. Prefer discriminating tests over speculative fixes.
7. Find the root cause rather than patching only the visible symptom.
8. Check the blast radius for similar defects elsewhere.
9. Add or update regression coverage where appropriate.

Do not blame framework or vendor code merely because it appears in the stack trace.

## Refactoring Discipline

For legacy or existing working code:

* understand current behavior before changing it;
* preserve behavior unless a behavior change is explicitly requested;
* inspect callers, dependencies, side effects, events, jobs, and persistence;
* use existing tests as evidence;
* add characterization/regression coverage when needed;
* make incremental, reviewable transformations;
* avoid large architectural rewrites unless demonstrated pressure justifies them.

Cleaner-looking code alone is not sufficient justification for changing behavior.

## Quality Gate

Before declaring implementation complete, review the result for:

* requirement coverage;
* Laravel correctness;
* authorization;
* validation;
* security;
* data integrity;
* concurrency;
* transaction safety;
* query efficiency;
* N+1 queries;
* unnecessary eager loading;
* memory/resource usage;
* maintainability;
* backward compatibility;
* tests;
* static/runtime verification available in the repository.

Attempt to falsify your own solution before accepting it.

Ask internally:

* What assumption could be wrong?
* What happens if this executes twice?
* What happens concurrently?
* What happens after partial failure?
* What happens with zero records?
* What happens with a very large dataset?
* What happens for an unauthorized actor?
* What happens if an external dependency fails?
* What database invariant is currently protected only by application code?
* Did this change unintentionally alter an existing contract?

Revise the implementation when this review reveals a material flaw.

## Verification

Use the project's documented verification workflow from `AGENTS.md` and `CLAUDE.md`.

When appropriate and available, verify through relevant mechanisms such as:

* targeted tests;
* full automated test suite;
* Laravel/PHP syntax checks;
* static analysis;
* formatting/linting;
* migration inspection;
* query inspection;
* build commands;
* existing project-specific validation commands.

Never claim something was tested if it was not actually executed.

Clearly distinguish:

* verified;
* inspected;
* inferred;
* not verified.

## Final Response

Keep final responses concise and engineering-oriented.

Report:

* what changed;
* important architectural or behavioral decisions;
* tests/verification actually performed;
* relevant risks, assumptions, or unresolved issues.

Do not dump internal reasoning.

Do not restate the entire contents of `AGENTS.md`, `CLAUDE.md`, or the cognitive framework unless specifically requested.