# Implementation Result — PR 2: Silent Delivery Level (Part B2)

**Branch:** `feature/notification-silent-delivery`  
**Date:** 2026-10-02  
**Status:** Completed & Verified  

---

## 1. Executive Summary

PR 2 (Part B2) introduces a **Silent Delivery Level** into the ADASI Notification System. This feature allows users to configure individual notifications to arrive silently (inbox only) without triggering real-time toast popups / Pusher Echo broadcasts, while continuing to preserve the database payload for auditability and inbox visibility.

The implementation strictly honors all architectural invariants:
- **Off Wins Everywhere:** When a user turns an event switch Off, the notification is completely disabled and stored as `false` in the database regardless of the delivery select mode.
- **Fail-Open Behavior:** Missing, malformed, or failed preference lookups fall back to Normal delivery (`'normal'`), ensuring operational awareness is never missed.
- **Zero Extra DB Queries:** Database queries for notification summaries and preferences remain uncompromised; silent notifications are excluded from unread counters in memory within `NotificationSummaryService`.
- **DOM & Accessibility Contract:** Delivery mode `<select>` elements sit outside `<legend>` within each event fieldset, contain no `<label>` tags, use descriptive `aria-label`, and keep the exact count of 13 checkboxes for Local Suppliers.
- **Full Backward Compatibility:** Requests omitting `notification_delivery` continue to update preferences smoothly without error.

---

## 2. Changes Made by Layer

### 2.1 Storage & Service Layer (B2.1)
- **`App\Services\NotificationPreferenceService`:**
  - Added `deliveryFor(User $user, string $key): string`: returns `'normal'`, `'silent'`, or `'off'`.
  - Added public `normalizeStored(mixed $stored): bool|string`: validates stored override values, accepting only `false` and `'silent'`.
  - Updated `mergeOverrides(User $user, array $current, array $input, array $delivery = []): array`: enforces "Off Wins Everywhere" (`$input[$key] === false` stores `false`; enabled + `'silent'` stores `'silent'`; enabled + `'normal'` removes the override when default is true).

### 2.2 Delivery Interception Layer (B2.2)
- **`App\Listeners\ApplyNotificationPreferences`:**
  - Intercepts broadcast channel delivery: checks `enabled()` first (preserving Mockery test expectations in `NotificationPreferenceDeliveryTest`), and suppresses `broadcast` delivery when `deliveryFor() === 'silent'`.
- **`App\Notifications\SystemNotification`:**
  - In `toDatabase(object $notifiable): array`: wraps preference lookup in `try/catch (\Throwable)` to fail open cleanly. If `deliveryFor() === 'silent'`, injects `'silent' => true` into the database data payload; otherwise, ensures any caller-spoofed `'silent'` key is stripped.

### 2.3 Unread Badge & Category Summary (B2.3)
- **`App\Services\NotificationSummaryService`:**
  - Added `excludeSilent(Collection $notifications): Collection`: filters out unread notifications having `($notification->data['silent'] ?? false) === true`.
  - Applied to `forUser()` and `countsForUser()` unread collections so that global badge and category badge counts exclude silent notifications, while inbox list collections retain all notifications.

### 2.4 Request, Controller & Persistence (B2.4)
- **`App\Http\Requests\UpdateNotificationPreferenceRequest`:**
  - Added validation for `notification_delivery`: allowed in `$allowedRootFields`, validated with `array:<eligible_event_keys>`, and values restricted to `in:normal,silent`.
- **`App\Http\Controllers\UserNotificationPreferenceController`:**
  - In `index()`: passes `deliveryPreferences` array to the view, mapping each event key to `'normal'` or `'silent'`.
  - In `update()`: forwards `$request->validated('notification_delivery', [])` into `saveNotificationPreferences`.
- **`App\Services\UserPreferenceService`:**
  - Updated `saveNotificationPreferences(User $user, array $preferences, array $delivery = [])` to forward `$delivery` into `NotificationPreferenceService::mergeOverrides()`.

### 2.5 UI & Presentation (B2.5)
- **`resources/views/profile/notifications.blade.php`:**
  - **Delivery Select:** Added `<select name="notification_delivery[{{ $key }}]">` outside `<legend>`, hidden when switch is Off (`x-show="switches['{{ $key }}']"`).
  - **Toolbar Filter:** Added "Silent" filter button (`:aria-pressed="activeFilter === 'silent' ? 'true' : 'false'"`).
  - **Toolbar Presets:** Added "Quiet mode" preset (action-required notifications stay Normal; other notifications become Silent).
  - **Summary Chip:** Updated format to `N of M enabled · S silent · O off`.
  - **Alpine Reactive Tracking:** Bound `delivery` and `switches` state with full dirty detection and discard support.

---

## 3. Verification Matrix

| Suite / Check | Command | Assertions / Result | Status |
|---|---|---|---|
| Silent Delivery Feature Tests | `php artisan test --filter=NotificationSilentDeliveryTest` | 13 passed (55 assertions) | **PASS** |
| User Notification Preferences | `php artisan test --filter=UserNotificationPreferencesTest` | 17 passed (407 assertions) | **PASS** |
| Notification Preferences UI | `php artisan test --filter=NotificationPreferencesUiTest` | 14 passed (352 assertions) | **PASS** |
| Delivery Pinned Regression | `php artisan test --filter=NotificationPreferenceDeliveryTest` | 39 passed (164 assertions) | **PASS** |
| Delivery Pipeline Integration | `php artisan test --filter=NotificationDeliveryTest` | 10 passed (58 assertions) | **PASS** |
| User Customization Suite | `php artisan test --filter=UserCustomizationTest` | 14 passed (130 assertions) | **PASS** |
| Local Invoice Full Domain | `php artisan test tests/Feature/LocalInvoice/` | 148 passed (728 assertions) | **PASS** |
| PHP Code Style & Formatting | `vendor\bin\pint --test` | 0 style issues | **PASS** |
| Frontend Asset Build | `npm.cmd run build` | Built in 2.67s | **PASS** |

---

## 4. Live Browser Verification Summary

Verified via Chrome DevTools MCP on `http://127.0.0.1:8000/profile/notifications`:
1. **Initial Render:** Summary chip displays `10 of 11 enabled · 0 silent · 1 off`.
2. **Toolbar Elements:** "Silent" filter button and "Quiet mode" preset rendered with correct semantics.
3. **Select Accessibility:** Select element has `aria-label`, sits outside `<legend>`, has no `<label>` element, and options are `normal` and `silent`.
4. **Switch Reactivity:** Toggling switch Off hides the select container (`display: none`).
5. **Quiet Mode Preset:** Sets action-required events to `normal` and non-action-required events to `silent`, updating dirty count to 7 and enabled count to 11.
6. **Silent Filter:** Filters visible fieldsets to only the 6 silent items.
7. **Discard Button:** Resets all delivery selects and switches back to their initial snapshot, resetting dirty count to 0.
8. **Real Form Submission & Persistence:** Selecting `silent` on an enabled event (`quotation_rejected`) and submitting persisted `'silent'` to the database and reloaded with `10 of 11 enabled · 1 silent · 1 off`. Reverting to `normal` and submitting persisted `'normal'` and reloaded cleanly.
9. **Off Wins Everywhere Live Verification:** On an event that is switch Off (`quotation_accepted`), setting delivery select to `silent` and submitting persisted `false` (Off), proving that Off switch strictly overrides delivery select mode.
