# Annual rates: horizontal year-column editing

Implemented the approved simplification on 3 November 2026. The original annual-rate implementation and unrelated dirty work were preserved. No application database migration, financial-data write, commit, or push was performed.

## Behavior

- Effective and Annual rates use separate server-rendered tabs; the bare index URL retains Effective as its default.
- Annual rates retain horizontal years, five stored years per page in chronological order. Edit opens exactly three inputs inside one year column while comparison years remain visible. IDR is fixed explanatory text.
- One POST form wraps the matrix. The GET year filter stays outside it; Add, filtering, pagination, and other Edit actions are disabled during editing. Save/Cancel remain in the active column.
- Add Year creates a blank display-only draft; a duplicate existing year offers Edit. Successful saving returns to the page containing the saved year without an exact-year filter.
- Annual-only error restoration preserves submitted values and their stale revision token. Restored unsaved input remains protected by the existing navigation reminder; no shared JavaScript was changed.
- Dashboard matrices remain read-only and link stored years to Edit and missing years to Add.
- Native table semantics, explicit currency/year labels, field errors, and a named scroll region are retained. The currency column is sticky. Edit links focus the selected USD input, including after horizontal scrolling on narrow screens.
- Scoped Bootstrap color variables preserve readable table text and active-column shading in dark mode. A positioned scroll region contains visually hidden labels so they do not widen the document.

## Concurrency finding and handling

A controlled probe against the pre-task controller reproduced exhausted initial-save deadlocks (`40001`/`1213`); the same contention affected the current controller. The existing three transaction retries, row lock, unique constraint, and atomic three-currency append remain intact. A narrowly filtered handler now returns a recoverable annual error after those retries roll back. It retains submitted input and does not claim a duplicate or automatically refresh the revision. Other database failures still propagate. Focused coverage also verifies rollback on the final currency and error propagation.

## Verification

- 54 focused PHP tests passed, 43,409 assertions: management, revisions, real two-process races, exhausted deadlocks, rollback, malformed input, authorization, historical conversion, snapshot preservation, effective rates, and translation parity.
- The separate database safety suite passed: 2 tests, 4 assertions, against the configured dedicated test database.
- Existing unsaved-changes JavaScript regression tests passed: 2 tests.
- Scoped Pint, PHP syntax, frontend build, Blade compilation, and diff checks passed. Route inspection confirmed the three existing Exchange Rates routes retain web/auth/admin middleware and the configured operational URL prefix.
- The frontend build reports two calendar `::part` optimization warnings. Calendar code was not changed by this task.

The focused PHP suite used the existing isolated MySQL schema `adasi_annual_rates_test`. A guarded temporary bootstrap verified that database and its annual migration before reusing the schema. Test fixture writes, cleanup, and concurrency truncation were confined to that test database. The application schema and production data were not modified.

## Browser QA

A temporary loopback-only harness used a synthetic Admin in the isolated test database. It exercised real page rendering, form submission, CSRF, and annual save routes while bypassing real login/MFA.

- Edit kept surrounding years visible; keyboard Tab moved USD to JPY. Leaving after an input change displayed the existing unsaved-change dialog.
- Invalid USD retained all three entered values and the original token. Its field and summary errors were associated with the input.
- A valid save created a new revision and returned to the full matrix. A second stale tab was refused, retaining its values/token; explicit reload then showed the winning revision.
- Add Year displayed an unsaved 2027 column before any rate write, then saving returned to the five-year page containing 2027.
- The 375 CSS-pixel layout kept document scroll width equal to its client width; horizontal movement stayed inside the matrix, the currency label remained sticky, and Save/Cancel were reachable.
- Desktop dark-mode rendering was visually inspected. Measured active-cell text contrast was 10.48:1 and input text contrast was 17.85:1 in the inspected state.

Full screen-reader testing, real login/MFA browser coverage, MariaDB execution, and staging/production verification were not performed.

The loopback harness was stopped and its synthetic rate/user fixture removed before the final 54-test rerun, which also passed with 43,409 assertions.
