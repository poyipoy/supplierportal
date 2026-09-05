# Verification & Falsification Protocol

This protocol enforces empirical validation and prevents premature or unfounded claims of correctness.

---

## 1. The Verification Hierarchy

Always apply verification methods in order of rigor:

```text
Static Syntax Checks → Unit/Feature Tests → Regression Tests → Manual Scenario Simulation
```

### Tier 1: Static Syntax & Linting
- **PHP Linting**: Always verify PHP syntax on every modified or created PHP file:
  ```bash
  php -l path/to/file.php
  ```
- **Code Style & Static Analysis**: Run Laravel Pint or PHPStan/Pest when available:
  ```bash
  ./vendor/bin/pint --test
  ```

### Tier 2: Targeted Automated Testing
- Never run tests blindly without knowing what you are testing.
- First run existing tests covering the modified subsystem to establish a baseline.
- Run the targeted test suite using Pest or PHPUnit:
  ```bash
  php artisan test tests/Feature/SpecificSubsystemTest.php
  ```
- For security-sensitive changes (e.g. data isolation, permissions, hashids):
  ```bash
  php artisan test tests/Feature/SupplierDataIsolationTest.php
  php artisan test tests/Feature/HashidUrlSecurityTest.php
  ```

### Tier 3: Regression Verification
- Verify that adjacent features or parent modules were not broken by the change.
- Run tests that interact with shared models, global middleware, or event listeners.

---

## 2. Adversarial Self-Critique (Falsification Questions)

Before presenting code to the user, mentally attack your own solution using these questions:

1. **"What assumption am I making that could be false?"**
   - Did I assume a relationship is always eager-loaded?
   - Did I assume the user record always has an associated profile model?
2. **"How does this fail under concurrent requests?"**
   - Could two requests read the same initial state and write conflicting updates?
3. **"What happens when the dataset scales?"**
   - Will this query perform an in-memory loop on 10,000 records instead of chunking or database aggregation?
4. **"Does this leak sensitive data across authorization boundaries?"**
   - Are Eloquent models returning hidden fields in JSON responses?
   - Can an unauthenticated or unauthorized role view this model?
5. **"Did I alter existing contracts without backward compatibility?"**
   - Did I change a method signature, route parameter, or return type that existing callers rely on?

---

## 3. Reporting Integrity Standards

Never misrepresent the state of verification in your final response. Adhere strictly to these definitions:

- **[VERIFIED]**: You executed a command (e.g., test suite passed, `php -l` succeeded, DB migration ran clean) and observed the affirmative result.
- **[INSPECTED]**: You read the actual source code, route table, migration, or configuration file, and confirmed the logic directly.
- **[INFERRED]**: You reached a conclusion based on strong circumstantial evidence from inspected code, but did not execute a direct runtime check.
- **[NOT VERIFIED]**: A relevant condition could not be tested due to environmental limitations (e.g., missing external SMTP server, unconfigured Pusher keys, staging database unavailability). **Explicitly declare this limitation.**

---

## 4. The 6-Point Completion Gate Checklist

Do NOT submit your final response until every item in this checklist is satisfied:

- [ ] **1. Requirement Full Coverage**: Every single aspect of the user's prompt is addressed.
- [ ] **2. Empirical Evidence**: The solution is rooted in the actual codebase structure, not generic boilerplate.
- [ ] **3. Minimal Change Applied**: No unnecessary refactoring, no unrelated cleanups, no premature abstractions.
- [ ] **4. Material Invariants Preserved**: Security boundaries, database constraints, and business invariants remain intact.
- [ ] **5. Verification Executed**: Syntax checks and relevant tests were run and passed.
- [ ] **6. Transparent Status**: Any remaining limitations, environment constraints, or unverified edge cases are honestly reported.
