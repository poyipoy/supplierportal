# Implementation Plan: [Task Title]

## 1. Goal & Context Decomposition
- **User Intent**: [Exact requirement and desired outcome]
- **Scope Boundary**: [What is IN scope and what is explicitly OUT of scope]
- **Operational Constraints**: [Security, performance, technology stack, backward-compatibility]

---

## 2. Evidence & Existing Invariants Inventory
- **Relevant Files Inspected**:
  - `path/to/Controller.php`: [Observed logic & flow]
  - `path/to/Model.php`: [Observed relationships & invariants]
  - `database/migrations/...`: [Observed schema & constraints]
- **Verified Facts**: [Directly inspected facts]
- **Assumptions to Verify**: [Hypotheses to test during execution]

---

## 3. Alternative Approaches & Trade-Off Analysis

### Approach A: [e.g. In-Place Extension / Direct Patch]
- **Pros**: [Fast, minimal blast radius, low complexity]
- **Cons**: [Slight technical debt, less extensible]
- **Regression Risk**: [Low / Medium / High]

### Approach B: [e.g. Dedicated Service / Structural Refactor]
- **Pros**: [Cleaner long-term separation of concerns]
- **Cons**: [Higher blast radius, more files modified, premature abstraction risk]
- **Regression Risk**: [Medium / High]

**Decision**: Selected **Approach [A/B]** because: [Clear engineering justification].

---

## 4. Step-by-Step Implementation Steps

### Step 1: [Preparation / DB Migration / Schema update]
- Target file(s): `...`
- Details: `...`

### Step 2: [Core Logic Modification]
- Target file(s): `...`
- Details: `...`

### Step 3: [UI / Presentation / API Response]
- Target file(s): `...`
- Details: `...`

---

## 5. Verification & Testing Plan

- [ ] **Syntax Verification**: `php -l [modified_files]`
- [ ] **Automated Tests**: `php artisan test [path/to/Test.php]`
- [ ] **Edge-Case Simulation**:
  - Scenario 1: [Null / empty inputs]
  - Scenario 2: [Unauthorized user / IDOR test]
  - Scenario 3: [Concurrent / double submission check]

---

## 6. Pre-Completion Audit Checklist
- [ ] Requirement 100% covered.
- [ ] Minimal necessary change rule respected (no unrelated edits).
- [ ] Invariants and backward compatibility preserved.
- [ ] All tests passed with zero errors.
- [ ] Output structured in Section 1 (Reasoning) and Section 2 (Solution).
