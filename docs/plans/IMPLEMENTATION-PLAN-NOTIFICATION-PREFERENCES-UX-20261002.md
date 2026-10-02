# ADASI PORTAL — IMPLEMENTATION PLAN
# Notification Preferences — UX Refresh, Silent Delivery, and Local-Core Copy Alignment

**Repository:** `https://github.com/poyipoy/supplierportal`
**Base Branch:** `customization-update`
**Planning Baseline:** `customization-update` at commit `d94932f` (2026-10-02, `feat(notifications): add in-app preferences and manual assistance`)
**Suggested Target Branches:** one per part (see §11)
**Suggested Repo Path When Committed:** `docs/plans/IMPLEMENTATION-PLAN-NOTIFICATION-PREFERENCES-UX-20261002.md` (`CLAUDE.md` says new docs must not be added to the repository root)
**Implementation Mode:** Plan-first, then execution after plan acceptance
**Primary Objective:** Make `/profile/notifications` faster to scan and safer to edit (Part A), let users reduce noise without losing history through a **Silent** delivery level (Part B2), and make Local-core notification copy consistent with the Local-core language policy (Part B9).

---

# 0. Executive Decision

The plan has three parts with different risk levels. They ship as **separate pull requests** in this order.

```text
Part A   UX refresh (view layer only).            Phase 5 contract fully preserved.
Part B2  Silent delivery level.                   Deliberately EXTENDS Phase 5 (see §1.3).
Part B9  Local-core notification copy alignment.  Independent of A and B2.
Part C   Deferred roadmap (not scheduled).        Needs further product decisions.
```

Decisions confirmed by the requester during planning:

| ID | Decision |
|---|---|
| D1 | A8 is **in scope**: `priority` key, "Action needed" badge, presets. No locking of any event. |
| D2 | A5 category open/closed rule: recommended rule in A5 (server-decided, errors forced open, Expand/Collapse all). |
| D3 | Part B items pulled into scope: **B2 (Silent)** and **B9 (language consistency)**. All other Part B ideas stay deferred (Part C). |

**Important correction to the earlier B9 idea.** The repository has a written UI Language Policy (`CLAUDE.md`, `AGENTS.md`): the Import Core is English-first, the Local Core is **Indonesian-first**, and "mixed Indonesian/English text is not a defect by itself". Therefore the Indonesian `local_invoice_*` preference labels are **correct and are not translated to English**. B9 is re-scoped to the real mismatch found in the code (see Part B9).

---

# 1. Evidence Baseline (verified against branch source)

Everything below was read from a checkout of `customization-update`. **Nothing was executed**: the application, the test suites, and the front-end build were not run during planning.

## 1.1 Current implementation

| Area | File | Verified fact |
|---|---|---|
| Registry | `config/notification_preferences.php` | 40 events. Keys: `class`, `source_event`, `label`, `description`, `category`, `roles`, `supplier_scopes`, `default`, optional `eligibility`. |
| Storage | `user_preferences.notification_preferences` (JSON, nullable) | Stores **only** `false` overrides, e.g. `{"quotation_rejected": false}`. `true` removes the key. Empty state is SQL `NULL`. |
| Service | `app/Services/NotificationPreferenceService.php` | `eventsFor()`, `effectivePreferences()`, `enabled()`, `mergeOverrides()`, `normalize()`, `normalizeStored()`. Lookup failure falls back to "deliver". Per-user overrides cached in `$overrides`. |
| Delivery | `app/Listeners/ApplyNotificationPreferences.php` | On `NotificationSending`, channels `database` and `broadcast` are both blocked when the event is OFF. Other channels and unregistered events are never touched. |
| Notification | `app/Notifications/SystemNotification.php` | `via()` returns `['database', 'broadcast']`; `toDatabase()` merges title/message/url/icon with caller `data`. |
| Sender | `app/Services/NotificationService.php` | Builds `SystemNotification` with deterministic UUIDv5 id; skips already-delivered ids. |
| Counts | `app/Services/NotificationSummaryService.php` | Loads latest N + **all** unread rows, filters by domain **in PHP**, then computes per-category counts. |
| Client | `resources/views/layouts/app.blade.php` | Echo `userChannel.notification(...)` inserts the item, calls `updateBadges()` (fetches `notifications.unread-count`) and shows a transient toast. A 30-second polling fallback also calls `updateBadges()`. |
| Controller | `app/Http/Controllers/UserNotificationPreferenceController.php` | `index` passes `events` (sorted by a hard-coded 11-category order) and `effectivePreferences`. `update` = PATCH, `reset` = DELETE. |
| Request | `app/Http/Requests/UpdateNotificationPreferenceRequest.php` | Rejects unknown event keys and any root field other than `_token`, `_method`, `notification_preferences`. |
| Persistence | `app/Services/UserPreferenceService.php` | `saveNotificationPreferences($user, array $preferences)` and `resetNotificationPreferences($user)`; row-lock + transaction + `revision` increment. |
| View | `resources/views/profile/notifications.blade.php` | One card per category; per event a `fieldset/legend` + description + bordered label wrapping a checkbox (label text duplicated); hidden `0` + checkbox `1`; single `Save Changes` at the bottom; separate DELETE form for reset. |

## 1.2 Existing UI building blocks (`resources/views/components/ui/`)

Available: `tabs`, `action-bar` (sticky by default), `status-chip`, `toolbar` (slots `search`, `filters`), `dialog`, `empty-state`, `toast` / `toast-container`, `section`, `select`, `icon` (Lucide), `button`, `page-header`.

**Not available:** a switch/toggle component.

Interaction conventions found in the repo:
- Alpine is used inline (`x-data="{ ... }"`); reusable components are registered in `resources/js/app.js` via `Alpine.data(...)`.
- Dialogs open via a window event: `open-ui-dialog` with `detail` = dialog name; `close-ui-dialog` closes it.
- Toasts: `window.AdasiToast.success|error|warning|info(message)`.
- `resources/css/app.css` already has a `.ui-preference-option` rule (focus ring).

## 1.3 Phase 5 decisions and how this plan treats them

Source: `UI-REDESIGN-RESULT/PHASE-5-GATE2.md` (§4, §7, §9, §10, §12) and the pinned tests.

| # | Phase 5 decision | Part A | Part B2 | Part B9 |
|---|---|---|---|---|
| 1 | In-app event checkboxes only; no Email / Required / Optional / Always On / database / broadcast controls | Kept | Kept (Silent is a delivery *style*, not a channel control, and not a lock) | Kept |
| 2 | `database` + `broadcast` are one logical in-app preference | Kept | **Extended**: broadcast and badge are split from inbox storage for the Silent state only | Kept |
| 3 | Storage holds only `false` overrides; stale/nested legacy data is ignored | Kept | **Extended**: one additional recognised value, the string `"silent"`; everything else is still ignored | Kept |
| 4 | Native hidden-0 / checkbox-1 PATCH form + `Save Changes`; separate DELETE reset | Kept | Kept (a native `<select>` is added per event; the checkbox contract is unchanged) | Kept |
| 5 | Empty categories omitted; 11-category order enforced | Kept | Kept | Kept |
| 6 | Preference is account-wide for dual-scope suppliers | Kept | Kept | Kept |
| 7 | Email deliberately removed | Kept | Kept | Kept |

## 1.4 Test contract that Part A must keep green

From `tests/Feature/UserNotificationPreferencesTest.php` (read, not run):

| Assertion | Consequence for the redesign |
|---|---|
| Registry has exactly **40** entries, every `default === true`, no `channels` key | Do not add/remove registry entries. Extra keys (e.g. `priority`) are not forbidden, but `default` must stay `true` and `channels` must not appear. |
| Page text contains `Choose which in-app notifications you want to receive.`, `Reset to defaults`, `Notifications`; must **not** contain `Required notifications` | Keep these strings. Never introduce "Required" wording. |
| Exactly one `//h1` equal to `Notifications` | Keep a single H1 (from `x-ui.page-header`). |
| `//fieldset/legend` equals the event label (e.g. `Invoice diajukan`) | Keep `fieldset` + `legend` per event. |
| Checkbox: `name="notification_preferences[KEY]"`, `value="1"`, `checked` when enabled, non-empty `aria-describedby`, **exactly one** `label[@for=<checkbox id>]`, **exactly one** hidden input with the same name and value `0` | The new switch must remain a native `input[type=checkbox]` with these attributes. |
| Form `action` = `route('profile.notifications.update')` with `_method=PATCH` and `_token`; exactly **one** form has `_method=PATCH` | Do not add another PATCH form. |
| A local supplier sees exactly **13** `input[type=checkbox]` | **No additional checkboxes** anywhere. Bulk controls are `<button type="button">`; the Silent control is a `<select>`. |
| `events` view variable keeps the 11-category order; `effectivePreferences` view variable exists and holds booleans | Do not change these keys, values, or ordering. New view data uses a **new** variable name. |
| Forged nested values (`[KEY => ['mail' => false]]`, `['database' => false]`, etc.) return 422; unsupported root fields (`theme`, `role`, `user_id`, …) return 422 | Keep rejection behavior. Any new root field must be explicitly allow-listed and strictly validated. |
| Dual-scope supplier sees **both** import and local events regardless of `supplier_context` | Scope tabs may only be a client-side view filter; all inputs stay in the DOM and in the single form. |
| `ga` role has exactly one event (`new_device_login`) | The page must remain sensible with 1 event. |
| Page render performs exactly **1** `SELECT` on `user_preferences` | No added queries or per-event lookups. |
| `GET` performs no preference write; PATCH/DELETE require valid CSRF | Unchanged. |

Delivery-side tests that matter for B2 (`tests/Feature/NotificationPreferenceDeliveryTest.php`, read, not run): disabled events create no database row and push no broadcast; same-class events are independent; lookup failure fails open; repeated callbacks perform one preference read per recipient; queued delivery reads fresh preferences.

## 1.5 Language policy evidence (for B9)

`CLAUDE.md` / `AGENTS.md`, "UI Language Policy": Import Core is English-first; Local Core is Indonesian-first; Local Core may keep English only for established business/domain/technical terms; *"Do not treat mixed Indonesian/English text as a defect by itself"*; *"Do not translate domain terminology merely for stylistic consistency."* Repo-wide, `CLAUDE.md` also says: match the surrounding file rather than normalizing it.

---

# 2. Scope and Non-Goals

## 2.1 In scope

**Part A (view layer):**
- New `x-ui.switch` component; restructured event row (no duplicated label).
- Sticky action bar with unsaved-changes state and `beforeunload` guard.
- Confirmation dialog for **Reset to defaults**.
- Collapsible category sections, live "n of m on" counts, bulk on/off buttons, Expand/Collapse all.
- Summary line, client-side search, All / Enabled / Muted filter, empty state.
- Scope tabs (Import / Local) for dual-scope suppliers as a client-side filter.
- `priority` registry key, "Action needed" badge, presets (A8).

**Part B2:** Silent delivery level (storage value, delivery logic, badge exclusion, request/controller/service changes, UI select, tests, result doc).

**Part B9:** Indonesian-first titles/messages for Local-core invoice notifications generated by `InvoiceNotificationService`.

## 2.2 Explicitly out of scope

- Locking or forcing any event ON; any "Required / Optional / Always On" wording (B1).
- Quiet hours, email, digest, per-object mute, admin insight, default-OFF events, taxonomy unification (all Part C).
- Renaming registry keys or events; translating existing Indonesian preference labels to English.
- Changing the page chrome or the `Local invoices` category heading unless decisions D6/D7 say so (§6).
- Backfilling or rewriting already-created notification rows.

---

# 3. Invariants — Preserve These

**Part A**
1. The form remains one native PATCH form posting `notification_preferences[KEY]` with hidden `0` + checkbox `1`. The page must **work without JavaScript** (Save button present and enabled in the server-rendered HTML; category `open` state decided in Blade).
2. Every event's input stays inside the single form even when its row is hidden by search, filter, tab, or a collapsed section. Hide with the `hidden` attribute / `<details>` — **never** `disabled`, and never remove from the DOM.
3. Account-wide semantics: scope tabs never change what is saved.
4. `events` / `effectivePreferences` view data and ordering are unchanged.
5. No new query on `user_preferences` or other tables during `GET`.
6. Bulk controls are `<button type="button">`, not checkboxes.

**Part B2 (additional)**
7. **Off wins.** If an event is Off, the Silent value is ignored and nothing is delivered.
8. Silent applies **only to registered events** (same boundary as Off). Unregistered events are always delivered normally.
9. Any lookup failure fails open to **Normal** delivery (never silently suppress).
10. **No migration.** The JSON column keeps its shape; the only new stored value is the exact string `"silent"`.
11. `effectivePreferences` stays boolean (On/Off). Silent is exposed through a new view variable.
12. One preference lookup per request and one per recipient per delivery (existing tests pin this).
13. Existing already-created notification rows are never modified.

**Part B9 (additional)**
14. Notification identity (`event_key`, UUIDv5) does not depend on the title; changing copy must not change identity or de-duplication.
15. Registered preference labels (Indonesian for `local_invoice_*`) are not changed.

---

# 4. Part A — UX Refresh (view layer only)

Each step is independently shippable and leaves the test suite green.

## A1. `x-ui.switch` component

**New file:** `resources/views/components/ui/switch.blade.php`

- Renders a native `<input type="checkbox" role="switch">` visually styled as a track + thumb (CSS only), preserving `name`, `value`, `id`, `checked`, `aria-describedby`, `aria-invalid`; forwards attributes via `$attributes`.
- Minimum target height 44px (matches the current `tw-min-h-11`).
- Focus ring reuses the existing `--ui-focus-ring-*` tokens; colors use existing M3 tokens. Verify light, dark, and `[data-density="compact"]`.
- Off/On state must not rely on color alone (visible "On"/"Off" text).

**CSS:** add switch rules next to `.ui-preference-option` in `resources/css/app.css`. New Tailwind classes require `npm run build`.

## A2. Event row restructure

**File:** `resources/views/profile/notifications.blade.php`

```text
fieldset
  legend            -> event label (visible title; keeps the xpath assertion)
  p#notification-KEY-help -> description
  input hidden 0
  x-ui.switch       -> checkbox, id=notification-KEY, aria-describedby=notification-KEY-help
  label[for=id]     -> exactly ONE; contains sr-only event label; visible On/Off text is aria-hidden
```

- Exactly one `label[@for=id]` per checkbox (test-pinned).
- Layout: title + description on the left, switch on the right; wrap (no truncation) on narrow screens.
- Keep `@error` output, `aria-invalid`, and `old($field, $effectivePreferences[$key])`.

## A3. Sticky action bar, dirty state, `beforeunload`

- Wrap the form in an inline Alpine scope. `dirty` = any checkbox where `checked !== defaultChecked` (B2.5 extends this to the Silent selects).
- Use `x-ui.action-bar` (sticky). Left: `● N unsaved changes` when dirty, otherwise muted "No unsaved changes". Right: **Discard** (restores defaults) and **Save Changes** (`type="submit"`, label unchanged).
- Progressive enhancement: the server-rendered Save button is enabled; Alpine may disable it while pristine.
- `beforeunload` warning only while dirty and not submitting.
- After failed validation (server re-render with `old()`), `defaultChecked` reflects the re-rendered state; note this in the verification report.
- Keep the existing flash `success` handling.

## A4. Reset-to-defaults confirmation

- The visible **Reset to defaults** button dispatches `open-ui-dialog` for an `x-ui.dialog` (e.g. `reset-notification-preferences`).
- The dialog contains the existing DELETE form (`@csrf`, `@method('DELETE')`, action `route('profile.notifications.reset')`) with Cancel and a confirm button.
- Copy: all notification settings return to default and unsaved changes on the page are discarded.
- DELETE route, route name, and success flash text (`Notification preferences reset to defaults.`) are unchanged; the page still contains the exact text `Reset to defaults`.

## A5. Category sections, counts, bulk buttons, initial open state

- Render each category as a `<details>` with a header showing the category name (existing text, e.g. `Local invoices`), a live count `n of m on`, and two `<button type="button">` controls: **Turn all on** / **Turn all off**.
- Add an **Expand all / Collapse all** button next to the search field.
- **Initial open state is decided server-side in Blade** (works without JS):
  - 12 events or fewer, or only 1–2 categories → every category open.
  - More than 12 events → open only (a) the first category, (b) any category containing a currently muted event, and (c) any category containing a **validation error**; the rest are closed.
- Controls inside a closed `<details>` are still submitted by the browser; verify manually.
- Category order is unchanged (controller `uasort`).

## A6. Summary, toolbar, filters, empty state

- Summary: `31 of 40 enabled` using `x-ui.status-chip` (tone `success` normally; `warning` when something is muted — informational, not a lock). B2.5 extends it to `31 of 40 enabled · 3 silent · 2 off`.
- `x-ui.toolbar`: `search` slot (client-side match on label + description) and `filters` slot (All / Enabled / Muted).
- Render the toolbar, summary, and bulk buttons **only when the page has more than 8 events**, so a one-event page (`ga`) stays simple.
- No matches: `x-ui.empty-state` with a "Clear filters" action.
- Filtering uses the `hidden` attribute; inputs remain in the form.

## A7. Scope tabs for dual-scope suppliers

- Add `data-scope="import|local|general"` to each row, derived from the event's existing `supplier_scopes` (`['import']`, `['local']`, `[]`). No extra query.
- Render `x-ui.tabs` (Import / Local / General) **only** when the user is a supplier and `$events` contain both `import` and `local` scoped rows.
- Default tab: the active portal context (`PortalContext::isLocal($user)` is already used elsewhere; confirm its signature before using it in a view). Falls back to Import (decision D5).
- Tabs are a **view filter only**; all rows remain in the DOM.
- Show a one-line hint: preferences are account-wide and apply to both portals.

## A8. Presets and "Action needed" hint (approved, D1)

**Registry additions** (additive; the pinned test only forbids `channels` and requires `default === true`):

- `priority`: `action_required` for the events below; omitted (treated as `info`) for all others.
- `priority_roles` (optional list): when present, the priority applies **only** to those roles. Needed because two events share one key across roles that behave differently.

| Group | Events tagged `action_required` |
|---|---|
| Supplier-facing | `quotation_revision_requested`, `quotation_negotiation_message`, `po_issued`, `claim_created`, `local_invoice_revision_requested`, `local_invoice_partial_payment`, `local_invoice_physical_delivery_reminder` |
| Internal (purchasing / QC / finance / admin) | `quotation_submitted`, `quotation_revised`, `shipment_submitted`, `po_material_arrived`, `supplier_registration_submitted`, `supplier_registration_resubmitted` |
| Shared key, internal roles only | `local_invoice_submitted`, `local_invoice_resubmitted` with `priority_roles => ['finance', 'accounting']` (for the supplier role these are acknowledgements, not tasks) |
| Security (all roles) | `new_device_login`, `repeated_lockouts_detected` |

**UI behavior** (no locking, no "Required" wording):
- "Action needed" badge (informational) next to the label, shown only when the viewing user's role matches.
- Presets (client-side only; still require Save): **Everything** (all On) and **Action needed only** (every event without a matching `priority` is switched Off; tagged events — including security — are left On).
- If a user turns off an `action_required` event, show a non-blocking inline note under the row. Never block or confirm.
- After Part B2 ships, add a third preset **Quiet mode** (see B2.5).

---

# 5. Part B2 — Silent Delivery Level

## B2.0 Behavior definition

| State | Inbox row stored | Realtime insert / toast | Bell + category badge count |
|---|---|---|---|
| Normal (default) | yes | yes | counted |
| **Silent** | yes (with `silent: true` in its data) | **no** | **not counted** |
| Off (existing) | no | no | n/a |

Silent rows still appear in the notification list; "mark all read" and "mark read" behave as today.

## B2.1 Storage (no migration)

- `notification_preferences` JSON: `false` = Off (unchanged), `"silent"` = Silent, key absent = Normal.
- `normalizeStored()` accepts, for **registered keys only**, the value `false` and the exact string `"silent"`. Everything else (nested arrays, `"mail"`, truthy values, unknown keys) is ignored as today, so the pinned stale-data compatibility test keeps its meaning.
- New `NotificationPreferenceService::deliveryFor(User $user, string $eventKey): string` returning `'normal' | 'silent' | 'off'`. `enabled()` keeps its current boolean meaning (Silent counts as enabled). Lookup failure → `'normal'` (invariant 9). Reuses the existing per-user cache (invariant 12).

## B2.2 Delivery

- `ApplyNotificationPreferences`: for channel `broadcast`, return `false` when state is `silent` or `off`; for channel `database`, return `false` only for `off`. Unregistered events and non-`User` notifiables stay untouched (invariant 8).
- `SystemNotification::toDatabase()` sets `silent => true` when the service reports `silent`, computed **inside `toDatabase()`** (not by mutating the notification from the listener) so the result does not depend on channel ordering. The service-computed value always overrides any caller-supplied `silent` key in `$data`; non-silent notifications do not carry the key.
- No change to `NotificationService::send()` identity/de-duplication.

## B2.3 Badge counts

- `NotificationSummaryService::forUser()` and `countsForUser()`: exclude rows whose `data['silent'] === true` from the **unread** collection used for badge numbers (global and per-category). The latest-N list used by the inbox still includes them. Filtering is done in PHP on the already-loaded collection, matching the existing domain filter (no JSON SQL query).
- The 30-second polling fallback and the Echo path both call the same endpoint, so counts stay consistent.
- Verify that the separate `conversations.unread-count` badge is unaffected.

## B2.4 Request, controller, services

- **Request** (`UpdateNotificationPreferenceRequest`): allow a new root field `notification_delivery` (array). Keys limited to the user's eligible events; values limited to `normal` | `silent`; anything else → 422. Add it to the root-field allow-list in `withValidator`; every other unsupported root field is still rejected.
- **Controller**: `update` passes the validated delivery array through; `index` adds a **new** view variable `deliveryPreferences` (event key → `normal|silent`) alongside the unchanged `effectivePreferences`.
- **Services**: `UserPreferenceService::saveNotificationPreferences($user, $normalized, array $delivery = [])` (new optional third parameter; existing two-argument callers unchanged) and `NotificationPreferenceService::mergeOverrides(...)` extended with these rules:

| Submitted switch | Submitted delivery | Stored result |
|---|---|---|
| On (`1`) | `silent` | `"silent"` |
| On (`1`) | `normal` or absent | key removed (Normal) |
| Off (`0`) | any | `false` (delivery ignored) |
| key not submitted | any | stored state retained (partial updates, as today) |

- **Reset** is unchanged (`NULL` → everything Normal).

## B2.5 UI (extends Part A rows)

- Under each event row, a native `<select name="notification_delivery[KEY]">`: **Normal — popup + inbox** / **Silent — inbox only**. Use `x-ui.select` if it renders a native select with name/value pass-through; otherwise a native select styled with existing tokens (verify before choosing).
- The select is visible only while the switch is On (toggle with `hidden`; it stays in the DOM and is simply ignored by the server when the switch is Off).
- A select is neither a checkbox nor a radio, so the pinned checkbox count (13 for a local supplier) and hidden-`0` contract stay valid.
- Extend A3 dirty tracking to include selects; extend A6 summary and filters with **Silent**.
- New preset **Quiet mode**: `action_required` events stay Normal; every other event becomes Silent (not Off). Requires A8.
- Help text under the select explains that Silent events remain in the inbox but do not pop up or add to the unread count.

## B2.6 Tests

**Expected to need no edits** (to be confirmed by running them; if any fails, stop and report before changing it): `UserNotificationPreferencesTest`, `NotificationPreferenceDeliveryTest`, `NotificationDeliveryTest`.

**New:** `tests/Feature/NotificationSilentDeliveryTest.php` (+ UI assertions in the Part A UI test file):

| Test | Asserts |
|---|---|
| Silent delivery | Database row exists with `data.silent === true`; **no** `BroadcastEvent` pushed (`Queue::fake`, same pattern as the pinned Off test). |
| Off unchanged | No row, no broadcast. |
| Normal | Row and broadcast; `silent` key absent. |
| Spoofed key | A caller-supplied `data['silent']` is overridden by the service value. |
| Unregistered event | Delivered normally even if the user stored `"silent"` for a similarly named key. |
| Fail-open | Preference lookup failure → Normal delivery. |
| Badge counts | `unread-count` / `summary` exclude silent rows from numbers; the list includes them; `mark-all-read` marks them read. |
| Request validation | Invalid delivery values (`'mute'`, arrays, `null`), unknown keys, ineligible events → 422; delivery for an Off key is ignored; partial update retains other keys. |
| Stored shape | `{"KEY":"silent"}` after On+Silent; switching back to Normal removes the key; Off after Silent stores `false`; reset → `NULL`. |
| Dual-scope | Silent is account-wide regardless of `supplier_context`. |
| Query budget | Page `GET` still performs exactly one `user_preferences` SELECT; repeated callbacks still perform one read per recipient. |

**Manual (browser):** no toast and no realtime insert for a Silent event; badge unchanged; item visible in the inbox after reload; polling fallback consistent; select shown/hidden with the switch.

## B2.7 Documentation

Add `docs/results/NOTIFICATION-SILENT-DELIVERY-RESULT.md` recording that Phase 5 statements §4 and §9 ("database/broadcast are one logical in-app preference", "OFF prevents both") are **extended** for the Silent state only. Do **not** edit the historical `UI-REDESIGN-RESULT/PHASE-5-GATE2.md`.

---

# 6. Part B9 — Local-Core Notification Copy Alignment

## B9.0 Evidence and re-scope

- `config/notification_preferences.php`: all `local_invoice_*` labels and descriptions are Indonesian — consistent with the Local Core policy. **No change.**
- `app/Services/LocalInvoice/InvoiceNotificationService.php` (read):
  - `send()` builds the title as `'Local invoice: '.ucwords(str_replace('_', ' ', $history->event))` (English, generic) and uses the English fallback message `Waiting for physical documents.`.
  - `sendPhysicalDeliveryReminder()` already uses an Indonesian title (`Pengingat pengiriman berkas fisik`) and message.
  - Result today: a Local supplier sees a preference named e.g. "Invoice dibayar" but receives a notification titled "Local invoice: Paid".
- All payment-related senders found (`LocalInvoicePaymentService`, `PaymentExecutionService`, `SupplierOverpaymentService`) call `InvoiceNotificationService::send()`, so one place covers them.
- History events seen in services: `submitted`, `resubmitted`, `cancelled`, `physical_received`, `approved`, `revision_requested`, `rejected`, `partial_payment`, `paid`, `overpaid`, `refund_settled`, plus `delivery_missed`, `expired`, `rescheduled`. The last three have **no** registry key (so they are not user-configurable today); `review_started` is skipped by the sender.
- Tests: a search found no assertions on the English title string `Local invoice: …`. Test fixtures that inject notifications use their own literal titles. Confirm by running the suites in §8.

## B9.1 Steps

1. In `InvoiceNotificationService::send()`, resolve the title in this order: (a) the registry `label` for `local_invoice_<event>` when one exists (single source of truth, so the notification title equals the preference label); (b) a small private Indonesian map for events without a registry key (`delivery_missed`, `expired`, `rescheduled`); (c) the current English generic title as a last-resort fallback for any unknown event.
2. Replace the English fallback message `Waiting for physical documents.` with an Indonesian equivalent (proposed: `Menunggu berkas fisik.`). `$history->notes` (user-entered) and the invoice/submission numbers are untouched.
3. Do not change `event_key`, UUIDv5 identity, `category`, `domain`, `url`, or `icon` (invariant 14).
4. Audit step: search other Local-core senders (GA claims, disbursement) for English titles/messages generated the same way. **Report findings; change only if the same pattern exists.**
5. No backfill: existing notification rows keep their stored titles (invariant 13).

## B9.2 Tests

- New test: for every registered `local_invoice_*` history event, the generated notification title equals the registry label; for the three unregistered events the Indonesian map is used; an unknown event falls back to the generic English title.
- Run the existing Local-invoice, payment, and notification suites to confirm nothing depended on the old English title.

## B9.3 Decision-gated extras (default: do not change)

| ID | Question | Why gated |
|---|---|---|
| D6 | Rename the `Local invoices` category heading to Indonesian? | It is a registry string used by the controller's category order and pinned in tests (`assertSeeText('Local invoices')`, category order list). |
| D7 | Localize the page chrome (title, description, Save/Reset) for Local-only users? | The page is shared across all roles; pinned tests assert the English chrome for every role. Needs a product call under the language policy. |

---

# 7. Open Decisions

| ID | Decision | Status / default |
|---|---|---|
| D1 | A8 priority + badge + presets | **Decided: yes** |
| D2 | Category open/closed rule | **Decided:** rule in A5 |
| D3 | Part B items to pull in | **Decided: B2 and B9 only** |
| D4 | Translate Indonesian `local_invoice_*` labels to English? | **No** (language policy; B9 corrected) |
| D5 | Default tab for dual-scope suppliers | Active portal context |
| D6 | Rename `Local invoices` category heading | Default: leave as is |
| D7 | Localize page chrome for Local-only users | Default: leave as is |
| D8 | Show a small "Silent" tag on inbox items | Default: no tag |

---

# 8. Part C — Deferred Roadmap (NOT scheduled)

| ID | Idea | Why deferred | Notes after B2 |
|---|---|---|---|
| B1 | Lock/force critical events | Phase 5 forbids Required / Always On controls; pinned tests | The non-blocking inline note in A8 is the compatible alternative. |
| B3 | Quiet hours / Do Not Disturb | New preference columns; must keep the single-lookup behavior | Cheaper after B2 because it only needs to hold back the broadcast path. |
| B4 | Email channel and digests | Phase 5 deliberately removed email; registry test forbids `channels` | Needs a production mail decision first. |
| B5 | Mute per object (conversation / PO) | New concept outside the registry model | Needs a new table and retention rules. |
| B6 | Admin insight on muted/noisy events | Aggregate queries over `user_preferences` | After B2, "silent" counts can be included. |
| B7 | Event default-OFF support | Registry test asserts every `default === true` | Storage now has non-boolean values, so tri-state handling must be designed deliberately. |
| B8 | Unify category taxonomies; move category order to config | Order is pinned in controller and tests | Low user value. |

---

# 9. Files Expected to Change

| Part | File | Change |
|---|---|---|
| A | `resources/views/components/ui/switch.blade.php` | **New** (A1) |
| A | `resources/views/profile/notifications.blade.php` | Restructure (A2–A8) |
| A | `resources/css/app.css` | Switch + row styles (A1) |
| A | `config/notification_preferences.php` | Add `priority` / `priority_roles` (A8) |
| A | `tests/Feature/NotificationPreferencesUiTest.php` | **New** |
| B2 | `app/Services/NotificationPreferenceService.php` | `deliveryFor()`, `normalizeStored()`, `mergeOverrides()` |
| B2 | `app/Listeners/ApplyNotificationPreferences.php` | Split broadcast vs database handling |
| B2 | `app/Notifications/SystemNotification.php` | `silent` flag in `toDatabase()` |
| B2 | `app/Services/NotificationSummaryService.php` | Exclude silent from unread counts |
| B2 | `app/Http/Requests/UpdateNotificationPreferenceRequest.php` | Allow-list and validate `notification_delivery` |
| B2 | `app/Http/Controllers/UserNotificationPreferenceController.php` | Pass delivery in; add `deliveryPreferences` view data |
| B2 | `app/Services/UserPreferenceService.php` | Optional third parameter on `saveNotificationPreferences()` |
| B2 | `resources/views/profile/notifications.blade.php` | Silent select, summary/filters, Quiet mode preset |
| B2 | `tests/Feature/NotificationSilentDeliveryTest.php` | **New** |
| B2 | `docs/results/NOTIFICATION-SILENT-DELIVERY-RESULT.md` | **New** |
| B9 | `app/Services/LocalInvoice/InvoiceNotificationService.php` | Indonesian title/message resolution |
| B9 | `tests/Feature/LocalInvoice/…` (new test file) | Title-equals-label tests |

**Must not change:** `routes/web.php`, migrations, `tests/Feature/UserNotificationPreferencesTest.php`, `tests/Feature/NotificationPreferenceDeliveryTest.php`, `UI-REDESIGN-RESULT/PHASE-5-GATE2.md`, existing registry `label` / `description` / `category` values.

---

# 10. Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Pinned markup tests break (legend, label, checkbox count, hidden input) | Follow §1.4 literally; run the pinned suite after every step. |
| Hidden rows are accidentally not submitted | Use `hidden` / `<details>` only; never `disabled`; manual submit test with a filter active and a section collapsed. |
| Silent select or bulk buttons rendered as checkboxes | UI test keeps the checkbox count at 13 for a local supplier. |
| Silent rows silently hide important information | Silent is opt-in per event; rows stay in the inbox; `action_required` events are labeled and the Quiet-mode preset keeps them Normal. |
| Badge count and inbox disagree (unread rows not counted) | Documented behavior (B2.0); test and manual check; D8 allows a visible "Silent" tag later. |
| `silent` flag spoofed or leaked via caller data | Service-computed value overrides caller data; non-silent rows carry no `silent` key. |
| Extra preference reads break the one-lookup tests | Compute silent state through the existing per-user cache; query-budget tests (§5, B2.6). |
| Notification title change breaks dedupe or links | Identity uses `event_key`/UUIDv5 only (invariant 14); no URL/category/domain change. |
| Over-translating against the language policy | B9 changes only generated notification copy; registry labels and chrome untouched unless D6/D7. |
| JS-only behavior ships untested | Manual checklist and an honest "not verified" in the report. |
| New Tailwind classes missing in production | Run `npm run build`; confirm compiled CSS contains the new classes. |

---

# 11. Delivery Sequencing

| PR | Branch (suggested) | Contents | Depends on |
|---|---|---|---|
| 1 | `feature/notification-preferences-ux` | Part A (A1–A8) + UI tests | none |
| 2 | `feature/notification-silent-delivery` | Part B2 | PR 1 (rows, dirty state, presets) |
| 3 | `feature/local-invoice-notification-copy` | Part B9 | none (can run in parallel) |

Keep PRs separate so Part A (view-only, low risk) is never blocked by B2 (touches delivery and counts).

---

# 12. Verification and Reporting Contract

Run and report, per PR:

```bash
php artisan test --filter=UserNotificationPreferencesTest
php artisan test --filter=NotificationPreferenceDeliveryTest
php artisan test --filter=NotificationDeliveryTest
php artisan test --filter=NotificationPreferencesUiTest      # PR 1
php artisan test --filter=NotificationSilentDeliveryTest     # PR 2
php artisan test --filter=LocalInvoice                       # PR 3
php artisan test --filter=UserCustomizationTest
vendor/bin/pint --test
npm run build                                                # PR 1, PR 2
```

Report separately:
1. **Verified by automated tests** — exact commands and results.
2. **Verified manually in a browser** — only items actually exercised (list them).
3. **Not verified** — everything else (for example mobile layout, dark mode, density, Pusher/Echo realtime behavior, concurrency).

Rules: minimal diff; follow existing module patterns; no opportunistic refactors; never claim runtime verification that was not performed; if any pinned test must change, stop and report why before changing it.