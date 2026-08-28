---
trigger: always_on
---

# Supplier Portal — Laravel Cognitive Framework

Before performing any substantial development task in this repository, you MUST read and understand:

```text
Claude's Cognitive Framework for Laravel Development
```

Treat this document as the project's **engineering reasoning framework**.

Do not merely acknowledge that you read it. Apply its reasoning principles to your work.

## Required Order

Before implementation:

1. Read `CLAUDE.md`.
2. Read `Claude's Cognitive Framework for Laravel Development`.
3. Read any other repository instruction files required by `CLAUDE.md`.
4. Inspect the relevant existing code, schema, configuration, and tests.
5. Understand the request, constraints, affected workflow, and acceptance criteria.
6. Determine the appropriate implementation approach.
7. Only then modify files or execute implementation commands.

## Apply the Framework

For non-trivial work, follow the framework's reasoning sequence:

```text
Request
→ Intent & Constraints
→ Context / Uncertainty
→ System & Data Model
→ Architecture
→ Implementation Strategy
→ Verification
→ Security & Performance Review
→ Contradiction / Failure Detection
→ Revision
→ Final Recommendation
```

The framework specifically requires separating verified facts, reasonable inference, assumptions, and unresolved uncertainty. Do not silently turn assumptions into facts.

## Important Engineering Behavior

Before changing code:

* Understand the existing implementation.
* Inspect the relevant request/job/command lifecycle.
* Inspect the actual database schema and relationships.
* Identify business invariants.
* Consider authorization separately from validation.
* Consider database constraints separately from application checks.
* Consider transaction boundaries and concurrency.
* Consider query shape and potential N+1 behavior.
* Consider side effects, queues, events, and external services.
* Consider failure and retry behavior.
* Prefer the simplest architecture that satisfies the demonstrated requirements.

Do NOT introduce patterns such as repositories, services, DTOs, strategies, CQRS, or additional abstraction layers merely because they are considered "clean architecture".

Use additional architecture only when the actual project has a concrete pressure that justifies it.

## Debugging

When diagnosing a defect, do not guess the fix immediately.

Use:

```text
Observation
→ Hypotheses
→ Predicted Evidence
→ Discriminating Test
→ Evidence
→ Hypothesis Update
→ Root Cause
→ Correct-Layer Fix
→ Regression Verification
```

Generate multiple plausible hypotheses before committing to one.

Distinguish:

* symptom;
* enabling condition;
* underlying design defect;
* missing safeguard.

Fix the appropriate layer rather than only suppressing the immediate symptom.

## Legacy / Existing Code

Treat existing behavior as potentially part of the application's contract.

Before significant refactoring:

* discover current behavior;
* identify dependencies and side effects;
* understand data flow;
* determine blast radius;
* preserve behavior unless changing it is the explicit goal;
* make the smallest safe transformation first.

Do not rewrite working code simply because a different structure appears cleaner.

## Quality Assurance

Do not treat these as equivalent:

* "it runs";
* "it is logically correct";
* "it is idiomatic Laravel";
* "it is maintainable";
* "it is production-safe".

Perform separate checks for:

* correctness;
* Laravel lifecycle/framework behavior;
* security;
* performance;
* data integrity;
* failure handling;
* regression risk.

The framework explicitly separates these quality claims and requires independent review passes.

## Security

For security-sensitive work, perform an explicit security review.

Consider at minimum:

* authentication;
* authorization;
* ownership / IDOR;
* mass assignment;
* SQL injection;
* XSS;
* CSRF;
* unsafe redirects;
* file upload abuse;
* path traversal;
* serialization;
* secrets exposure;
* sensitive-data logging;
* security misconfiguration;
* dependency/supply-chain risk;
* rate limiting;
* validation boundaries;
* API overexposure;
* exception leakage;
* unsafe failure handling.

Do not assume that validation alone makes an operation secure. Authorization and database-level integrity must be considered separately.

## Final Requirement

Before declaring a non-trivial task complete, explicitly verify:

1. The requested behavior is implemented.
2. Existing business behavior was preserved where required.
3. Relevant edge cases were considered.
4. Security implications were reviewed.
5. Performance/query implications were reviewed.
6. Tests or other appropriate verification were executed.
7. Remaining uncertainty or unresolved issues are clearly reported.

Do not claim verification that was not actually performed.

## Core Rule

```text
Read CLAUDE.md first.
Read the Cognitive Framework.
Inspect the repository.
Reason before coding.
Verify before concluding.
```
