# Exchange Rates: server processing without document reload

Implemented on 3 November 2026, following the horizontal annual-rate column editor. Scope is every interaction within the Admin Exchange Rates menu: Effective and Annual tabs, filters, pagination, Add/Edit/Cancel, and Save. Other portal menus were not converted by this task.

## Behavior and contracts

- The existing server-side queries retain bounded pagination: five stored annual years and thirty effective-rate records per page. Filters, current revisions, validation, authorization, and rendering remain on the server.
- The existing `AdasiServerTabs` SDK replaces only the Exchange Rates workspace content and tab links. Fragment requests use the dedicated `X-Adasi-Server-Tabs: 1` header and receive `html`, `nav`, `tab`, and `url`. Financial fragments use `private, no-store`, zero client cache TTL, and no speculative prefetch.
- Both POST forms use CSRF-protected AJAX. Confirmed success returns `{redirect, message}`, refreshes the appropriate server-rendered matrix/list, and displays a toast. Ordinary HTML requests retain their redirect/flash fallback. The effective-rate modal remains outside the replaceable fragment and its calendar display resets with its submitted value.
- A pending save blocks duplicate submissions and workspace navigation. A confirmed save whose list refresh fails disables the stale form's Save action and offers Refresh. Network uncertainty retains the form and offers explicit review without retrying the write automatically.
- Validation errors preserve all values and the original revision. Inline errors are associated with their input; error summaries omit irrelevant upload guidance. Stale revisions offer explicit review and never silently obtain a new token.
- Existing dirty-form confirmation protects workspace navigation, sidebar navigation, reload shortcuts, and browser Back/Forward. Managed history tracks physical entries separately from the displayed view, so Stay restores the correct entry even across older dirty guards. Accepted GET replacement makes the old content inert to prevent keyboard edits during loading. Shared behavior is opt-in; other pages retain their existing defaults.
- Annual matrix semantics, sticky currency labels, three inputs in one editable year, comparison columns, append-only history, atomic revisions, unique constraints, admin access, and document snapshots are preserved. No package, route, schema, or resolver changes were added in this stage.

## Verification

- Focused PHP suite passed: **66 tests, 43,627 assertions**, including twelve new server-processing cases, annual management/concurrency, effective-rate behavior, historical conversion, snapshot preservation, and translation parity.
- Entire JavaScript suite passed: **642 tests**, including ten Exchange Rates adapter cases, shared fragment SDK, actual unsaved-guard VM behavior, async field errors, and existing portal regressions. Failed managed Back restores the source history entry before dirty tracking resumes; this ordering has dedicated regression coverage.
- Scoped Pint, frontend build, and Blade cache passed. The build retains two existing calendar `::part` optimization warnings; calendar source was not changed.
- PHP syntax checks and `git diff --check` passed.

## Browser evidence

A temporary loopback-only harness used a synthetic admin and the isolated `adasi_annual_rates_test` database. It exercised real Blade rendering, CSRF, controller validation, and persistence while bypassing real login/MFA.

- Both tabs, filtering, clearing filters, and pagination updated through fetch. The original document marker and `performance.timeOrigin` remained intact; the network log contained one initial document request.
- Edit kept comparison years visible. Invalid USD retained the entered valid JPY value and the original revision; the inline error appeared in the input's accessible description.
- Annual Save returned to the five-year matrix with the new value. A second tab holding revision 2 was rejected after revision 3 was saved; its attempted values and token remained intact.
- Add displayed a blank 2027 draft with revision 0, then saved it without reload. Attempting to add 2027 again showed a review/edit link.
- Dirty Back with Stay preserved both the invalid form and exact history entry. Accepting Leave moved to the prior matrix with one confirmation and no document reload.
- During an artificially delayed fragment GET, the old editor was inert and could not receive keyboard focus; interactivity returned after replacement.
- At a 375 px viewport, document scroll width equaled client width (360 px with the desktop scrollbar). The three inputs retained explicit labels; keyboard focus scrolled Save into view and Enter saved successfully.
- Effective Save appended a record, closed its modal, removed the backdrop, and refreshed the list without reload. Rapid submit during the opening animation was reproduced and corrected. A selected 4 November date saved correctly; resetting the form restored both the raw date and displayed label to 3 November. The settled form was clean and Save was usable again.

The owned browser tabs were closed, loopback server stopped, and synthetic user/rate fixtures removed. Application schema and application data were not changed. Real login/MFA, full screen-reader testing, MariaDB, staging, and production were not verified. No commit or push was performed.
