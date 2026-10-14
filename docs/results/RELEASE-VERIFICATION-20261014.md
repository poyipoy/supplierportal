# Release verification — 14 October 2026

Scope: all authorized working-tree revisions on `remediation/security-review-20261010`, including security remediation, registration, financial workflows, import recovery/progress, PR references/manual selection and UI refinements.

## Executed

- Initial full PHP suite ran serially on the configured MySQL test database: **2,391 passed, 8 failed; 221,049 assertions** (1,895.40 seconds).
- Three transfer-workbook failures exposed drift between the runtime template and the supplied root workbook. The runtime template was restored byte-for-byte from `TARIKAN TRANSFER.xlsx`; both SHA-256 hashes are `cccc86f9d293ab48f04a82baa17e074af3c26ad7e98431c46350e69a06dc49cc`.
- Five ZIP failures exposed test-order dependence: `DatabaseTruncation` removed the migration-owned `local_po_document_capacity` singleton. The ZIP suites passed in isolation (18 tests, 92 assertions). Truncation tests now explicitly preserve that singleton; no runtime admission guard was weakened.
- Regression after fixes: transfer export + manual concurrency + ZIP storage/messages + PO document tests: **64 passed, 2,172 assertions**.
- All affected truncation/concurrency suites: **33 passed, 374 assertions**, including supplier registration races, invoice/GR transitions, imports and preferences.
- Staged diff check and tracked artifact check passed. Three existing ZIP archives were removed from Git tracking while their local copies were retained.
- Earlier feature verification remains documented in the module reports: PR/import HTTP and concurrency, JavaScript, rendered-browser fixtures, localization, build, Blade cache and read-only financial reconcile.

## Publication boundaries

- The untracked `docs/results/USER-ACCOUNTS-FINANCE-GA-LOCAL-20261012.sql` account provisioning script remains local because it includes initial password material; it is excluded from publication.
- `public/build/` is ignored by the repository. Deployments must build assets or upload the locally generated build through the existing deployment procedure; a Git push alone does not deploy generated assets or apply migrations.
- No application database migration, production deployment, force push, reset or clean was performed as release verification.

## Not verified

The entire full suite was not repeated after the two fixes; the initial full result and focused post-fix results are reported separately above. Production/staging operation, CI results and bank acceptance are not inferred from local tests.
