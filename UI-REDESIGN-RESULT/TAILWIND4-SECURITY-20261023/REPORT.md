# Tailwind 4 security remediation — 23 October 2026

## A. Executive summary

**Ready for PR review.** Tailwind CSS and its Vite integration now use 4.3.3. The vulnerable `braces` dependency is absent, npm reports zero vulnerabilities, and the security workflow remains enabled.

The migration preserves Bootstrap ownership of resets, the ADASI design tokens, class-based forms, explicit source discovery, and existing utility behavior where Tailwind 4 defaults would change the interface. No procurement/payment calculations, authorization, supplier isolation, database schema, Pusher implementation, or transaction logic changed.

Local verification passed: 547 JavaScript tests; the full Laravel suite with 3,060 passed and two optional sample tests skipped; clean-checkout installation/build/JavaScript tests; and local browser checks. This is **not** a staging, GitHub Actions, MariaDB, or production readiness claim.

## B. Git baseline

| Item | Evidence |
|---|---|
| Prerequisite | [Architecture Remediation PR #9](https://github.com/poyipoy/supplierportal/pull/9), merged 2026-10-23 04:14:34 UTC |
| Starting master | `df39a2dc0f68ae5315881da8acdac5c8dd26d9bd`; all nine remediation commits verified as ancestors |
| Migration branch | `fix/tailwind-v4-security-remediation`, created from freshly fetched `origin/master` |
| Local implementation commit | `c58b5e50012f9508ab00b697f6646097ce7d8660` |
| Evidence commit | The separate commit containing this report; its final hash is included in the delivery response |
| Existing user files | `.rnd` and the architecture handoff remained untracked and untouched |
| Remote actions | No push, merge, deployment, or CI dispatch |

The implementation changed 301 files, principally utility literals in templates and their tests. The complete implementation manifest is [changed-files.txt](changed-files.txt). The evidence commit adds this report, structured results, and deliberately captured synthetic screenshots.

## C. ECC skill execution

The following SKILL.md files were read and applied from the installed ECC 2.2.3 package at `C:/Users/BAHRIALGI/.claude/plugins/cache/ecc/ecc/2.2.3/skills/`:

| Skill | Contribution |
|---|---|
| `change-impact` | Mapped Blade, JS/Alpine, PHP-rendered HTML, CSS, token configuration, tests and CI before implementation; reconciled the final diff |
| `security-review` | Traced every `braces` introduction, checked registry/advisory evidence, removed the dependency path and verified audits without exclusions |
| `browser-qa` | Captured baseline/post-migration screenshots, responsive/light/dark comparisons, keyboard and modal checks, local authenticated smoke checks, Lighthouse and console/network evidence |
| `test-collaboration` | Identified missing compiled-CSS coverage; added six pipeline tests and one copy-inventory test |
| `module-regression` | Ran existing downstream suites across Import, Local Supplier, Finance, GA, auth, isolation and shared UI |
| `verification-loop` | Reproducible installation/build, JS/PHP checks, audits, clean checkout and final diff review |

These were instruction-guided workflows executed through Codex tools; no unavailable slash-command execution is claimed. No TESTS.md or REGRESSION.md was created. No sub-agent review is claimed.

## D. Dependency security evidence

Before:

```text
tailwindcss@3.4.19
├─ chokidar@3.6.0 → braces@3.0.3
├─ micromatch@4.0.8 → braces@3.0.3
└─ fast-glob@3.3.3 → micromatch@4.0.8 → braces@3.0.3
```

`npm audit --json` exited **1**, reporting five high-severity affected package entries, all tracing to **GHSA-vfj7-8cjw-p6xm / CVE-2026-93687**. Registry inspection found Tailwind 3.4.19 and `braces` 3.0.3 were still their latest releases in those lines. The [reviewed advisory](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) lists no patched version, so a non-major update could not close this path.

After:

```text
tailwindcss@4.3.3
@tailwindcss/vite@4.3.3 → @tailwindcss/node / oxide → tailwindcss@4.3.3
@tailwindcss/forms@0.5.11 → tailwindcss@4.3.3 (peer)
braces: absent
postcss-selector-parser: absent
```

| Command | Before | After |
|---|---|---|
| `npm audit --json` | Exit 1; five high entries | Exit 0; zero vulnerabilities |
| `npm audit --audit-level=high` | — | Exit 0 |
| `npm ls braces` | Exit 0; 3.0.3 and all paths identified | Empty; exit 1 is npm's expected result for an absent package |
| `composer audit --locked --no-interaction` | No Composer changes | Exit 0; no advisories |

Raw npm evidence: [before](npm-audit-before.json), [after](npm-audit-after.json). The old parser override was removed because its dependency path disappeared, not replaced with another override. No audit gate, advisory, package source or vulnerability was hidden.

## E. Migration changes and compatibility decisions

The migration follows the official [upgrade guide](https://tailwindcss.com/docs/upgrade-guide), [Vite integration](https://tailwindcss.com/docs/installation/using-vite), [Preflight guidance](https://tailwindcss.com/docs/preflight), [source discovery guidance](https://tailwindcss.com/docs/detecting-classes-in-source-files), and [forms plugin documentation](https://github.com/tailwindlabs/tailwindcss-forms).

- **Prefix and utility semantics:** Validated 934 existing class candidates against the v3 rule generator, then converted utility/variant structure. Examples: `lg:tw-col-span-8` → `tw:lg:col-span-8`, `hover:tw-bg-primary` → `tw:hover:bg-primary`, and `-tw-translate-x-full` → `tw:-translate-x-full`. Renamed radius/shadow/outline utilities preserve their old meaning. Ordinary identifiers and previously unsupported no-op names were not blindly rewritten.
- **Selectors:** CSS selectors were parsed; DOM queries for prefixed classes use attribute selectors such as `[class~="tw:rounded-full"]`. Dashboard class generators, Alpine expressions, PHP-generated markup, public JS and matching assertions were migrated together.
- **Tokens/config:** `tailwind.config.js` remains an explicitly loaded compatibility configuration, retaining the existing CSS variable design system. V3 palette shades already used by screens, the exact font fallback stack and full radius are preserved.
- **No Preflight:** `app.css` imports theme/utilities separately and omits Preflight. Existing unlayered cascade order is preserved because Bootstrap and custom components depend on it. Forms remains `strategy: 'class'`.
- **Source discovery:** Explicit sources cover views, resources JS, `app/` and public JS. Compiled views and historical reports do not generate CSS. Full literal dynamic maps remain available; PHP-generated DataTables classes are covered. Ordinary `ui-data-action` classes remain normal CSS.
- **Observed regressions repaired:** A small PostCSS compatibility transform preserves v3 border-width behavior without forced solid borders, hidden-aware sibling spacing/dividers, and static `hidden` priority before responsive display variants. The old hover behavior is explicit. Compiled-output tests protect these decisions.
- **Dormant CSS:** Thirteen component selectors that v3 never emitted were removed during layer unwrapping, preserving the emitted baseline instead of unexpectedly activating dormant role/toast styles.
- **Build:** The existing Laravel entry points remain. Tailwind uses its supported Vite plugin; the obsolete PostCSS config and autoprefixer were removed. PostCSS remains directly used by the compatibility transform and build tests.
- **Copy audit:** The existing ledger was refreshed for literal/byte-offset changes. Its 24,068 records and disposition counts remained unchanged; assertions were retained.

Four PHP controllers contain only utility-string substitutions. Models, services, middleware, routes, schema/migrations, financial calculations and architecture-remediation logic have no implementation diff.

Rollback requires restoring the complete pre-migration source **and** package lock, running `npm ci`, rebuilding assets and refreshing Blade caches together. Mixing v3 templates with v4 CSS would break utility lookup. No database rollback is needed. A rollback would reintroduce the documented advisory.

## F. Visual and interaction evidence

[visual-comparison.json](visual-comparison.json) records **138 cases across 23 real Laravel-rendered synthetic pages**, each at 1440, 768 and 375 CSS pixels in light/dark themes. A further 69 cases used system dark mode. The measured comparison covers geometry, visibility, colors, font/fallback, spacing, borders, outlines and shadows. Geometry tolerance was 0.5 px. Equivalent color representations, invisible shadow layers and invisible outlines were normalized; themes were allowed to settle before measurement. No measured differences remained after the documented stabilization reruns. A forced-colors Finance comparison also matched.

Covered areas: login/registration, Purchasing dashboard and PR register, quotations/comparison, PO and shipments, QC dashboard/inspections, Import Supplier dashboard/quotations, Local Supplier dashboard/PO/invoice form, Finance dashboard/master/invoices/DRP, GA claims/form, administration/users and customization.

The [screenshots directory](screenshots/) contains 18 before/after pairs for login, Purchasing dashboard and local invoice form across all three sizes and both themes, plus two populated Finance verification screenshots. Only synthetic data is shown.

The screenshots are WebP evidence, **not a claim of universal pixel identity**: 11 pairs were pixel-identical; the remaining pairs had 0.102–0.385% pixels differing by more than 5/255 in at least one RGB channel. The [pixel comparison](screenshot-pixel-comparison.json) retains these small raster differences rather than concealing them. Representative mobile images were inspected visually.

[Live browser checks](live-browser-checks.json) used the real HTTP kernel, file sessions and the verified test DB:

- Login, 19 pages and logout across Purchasing, Import Supplier, Local Supplier, QC, GA and Admin.
- Finance login/dashboard, populated invoice Section A/B, revision dialog opening/closing with Escape and focus return, populated PO/GR DataTables feed **HTTP 200**, dropdown and mobile sidebar.
- 375 px Finance view had no document overflow; dark mode remained usable.
- Keyboard tab focus and Enter password-toggle interaction passed. No invoice approval, settlement or procurement mutation was submitted through the browser.
- Login Lighthouse accessibility/best-practices scores were **100/100 before and after**; SEO was 83 in both due to the existing absent meta description. This does not establish complete WCAG conformance or replace screen-reader review.
- The inspected operational pages had no captured JS/network errors. A native `ViewTransition opt-in disabled` rejection on logout was reproduced with **both baseline df39a2dc templates/assets and migrated assets**; logout completed successfully in both.

Snapshot comparisons mostly use empty operational lists. Populated document details, every modal/state, Safari, Firefox and manual screen-reader review are not fully covered.

## G. Test evidence and CI compatibility

Full command-level data and exit codes are in [verification.json](verification.json).

| Executed check | Result | Exit |
|---|---|---|
| Baseline build | Passed | 0 |
| Baseline isolated JS | 541 passed | 0 |
| Baseline PHP component/asset tests | 32 tests, 653 assertions | 0 |
| Testing database safety guard | 2 passed; live DB confirmed | 0 |
| Final `npm ci` / build | Passed | 0 / 0 |
| Final JS, concurrency 1 with process isolation | 547 passed, including six new compiled-pipeline checks | 0 |
| Full PHP Parallel | 2,925 total; 2,923 passed, two optional real-Infor sample skips; 236,724 assertions | 0 |
| Full PHP Serial | 137 passed; 4,585 assertions | 0 |
| Final focused PHP/UI/ledger/safety checks | 63 passed; 93,394 assertions | 0 |
| Scoped Pint / PHP syntax | 14 non-Blade PHP files passed | 0 |
| Blade `view:cache --env=testing` | Passed | 0 |
| Tracked-artifact guard / diff whitespace check | Passed | 0 |
| npm audit / high gate / Composer audit | Clean | 0 |

The full suites ran sequentially against `adasi_portal_test` and Laravel-managed parallel test copies after the safety guard. No development or production database was migrated, seeded or modified. The Serial run included the existing 50,000-row export benchmark, which took approximately 711 seconds.

A detached clean checkout of `c58b5e50` independently passed Composer installation, generated testing keys, `npm ci`, build and **all 547 JS tests**, with no tracked working-tree changes. PHP dependencies were installed after preparing the testing environment, matching the project bootstrap rule.

Windows PHP 8.2.30, Node 24.14.0 and npm 11.9.0 were used. CI retains Node 22, which meets the installed Vite engine requirement when setup-node resolves its current supported patch. Workflow/security files were unchanged. **No GitHub Actions run for this branch is claimed.**

Failed/intermediate checks were investigated, not hidden: an inappropriate shared-process JS run was replaced by the existing process-isolated runner; the official upgrade tool could not completely migrate the functional token config; the first ledger check detected stale offsets; and Composer audit initially timed out before a successful retry.

## H. Remaining risks and limitations

- Tailwind 4 requires modern browsers; the official minimums are Safari 16.4+, Chrome 111+ and Firefox 128+. Actual enterprise browser inventory and non-Chromium execution need release verification.
- Two Lightning CSS warnings identify existing calendar `::part(...):not([attribute])` selectors. Their original declarations remain unchanged; warnings were not suppressed.
- The native logout ViewTransition rejection exists on the baseline too and remains separate follow-up work.
- Six PHPUnit deprecations and the two intentional optional sample skips remain. No tests were removed or weakened.
- Browser coverage is representative, not every populated business state. Staging, printed/PDF review, performance under production load, MariaDB execution and deployment are unverified in this task.
- The compatibility transform intentionally preserves old selector behavior. Future Tailwind updates must pass the compiled-CSS and browser checks before that transform changes.
- Reverting the migration restores the vulnerable Tailwind 3 dependency path.

## I. Final verdict

**Ready for PR review.** The targeted vulnerable graph is removed, clean installation and builds reproduce, automated regressions pass, and the captured browser evidence supports preservation of the exercised UI. Review and staging/CI verification remain required before release. Nothing was pushed, merged or deployed.
