# PERF-02 — Global Request Cost

## Status

`COMPLETE — VERIFIED LOCAL IMPROVEMENT`

## Evidence and Classification

- `VERIFIED BOTTLENECK`: the navbar view composer ran two notification queries and hydrated notification detail on every authenticated full-page response.
- `VERIFIED BOTTLENECK`: the layout hydrated every visible conversation plus a correlated message count and summed the collection in PHP before rendering, although polling immediately repeated the count.
- `VERIFIED BOTTLENECK`: the layout read and base64-encoded a 31,568-byte logo on every render, adding about 42 KB of uncacheable HTML before compression.
- `LIKELY BOTTLENECK — MEASUREMENT REQUIRED`: the drawer formatted up to 50 conversations by executing `unreadCountFor()` once per row.

## Changes

- Removed the global notification view composer.
- Added an authenticated, role-protected notification-summary endpoint and lazy-loaded the dropdown only when opened.
- Kept unread/category count polling and category semantics intact, while selecting only notification columns required for counts and rendering.
- Replaced the initial chat collection hydration with the existing asynchronous count path.
- Replaced global chat unread hydration/PHP summation with one `messages` aggregate constrained through conversation membership.
- Added a projected unread count to the drawer query, eliminating per-conversation count queries.
- Replaced inline base64 logo generation with the existing same-origin static asset URL.

## Warm Before vs After

| Path | Before queries | After queries | Query change | Before ms | After ms | Response-byte change |
|---|---:|---:|---:|---:|---:|---:|
| Purchasing dashboard | 9 | 6 | -33.3% | 184.90 | 78.67 | 460,828 → 184,209 (-60.0%) |
| Supplier dashboard | 12 | 9 | -25.0% | 121.39 | 54.51 | 454,733 → 166,054 (-63.5%) |
| Inter-supplier full view | 43 | 40 | -7.0% | 128.38 | 57.73 | 429,804 → 154,188 (-64.1%) |
| Historical comparison view | 14 | 11 | -21.4% | 156.15 | 72.34 | 513,860 → 238,244 (-53.6%) |
| Purchasing quotation list | 10 | 7 | -30.0% | 156.34 | 70.11 | 509,157 → 233,541 (-54.1%) |

The three-query reduction on full authenticated views is the removed notification-detail pair plus initial chat query. The response-size drop also includes removal of the duplicated notification panel and base64 logo. Timings are warm local CLI controller/view observations, not production P95.

The lazy detail request remains two bounded queries and measured about 11.31 ms response time locally. Notification count polling remains two queries because its response preserves legacy category classification and category totals; caching was intentionally avoided to prevent stale operational counts.

## Safety and Verification

- Notification detail and mark/read operations remain scoped through the authenticated user's notification relationship.
- Chat counts remain constrained to conversations where the authenticated user is Purchasing or Supplier member.
- The lazy panel continues to escape notification data in Blade; client insertion parses only the authenticated same-origin HTML response.
- `php -l` passed for all modified PHP files.
- `php artisan test tests/Feature/NotificationControllerTest.php tests/Feature/ConversationUnreadCountTest.php tests/Feature/CustomAdasiToastTest.php tests/Feature/Auth/SecurityHeadersTest.php` — 16 tests, 138 assertions, passed.
- `php artisan view:cache` — passed.
- `php artisan performance:profile-critical` — passed after the change.
- Browser interaction and visual behavior: `MANUAL_VISUAL_QA_REQUIRED`; browser automation was not run per task constraint.
