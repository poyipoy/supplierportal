# Notification Conversation Mute Result (WP-2 / B5)

## 1. Overview and Executive Summary
Work Package 2 (WP-2) implements feature **B5: Per-Conversation Notification Mute** in `poyipoy/supplierportal` on branch `feature/notification-conversation-mute`.

B5 provides conversation participants (purchasing and supplier) with the ability to mute noisy conversation threads without turning off message notifications account-wide. When a conversation is muted for a user, message notifications for that conversation behave as **Silent** (persisted to the inbox with `data.silent === true`, no realtime broadcast, and excluded from global/category badge counts), while preserving the chat's own unread count and message delivery.

---

## 2. Fixed Product Decisions (F4 – F12)

| Decision | Specification & Implementation Details |
|---|---|
| **F4: Conversation Threads Scope** | v1 scope is strictly conversation threads (representing PR or PO threads via morph relation `conversable`). Separate PO-level mute is out of scope. |
| **F5: Allow-list Registry Mechanism** | Muting applies ONLY to events explicitly tagged with `mutable_subject => 'conversation'` in `config/notification_preferences.php`. In v1, this is strictly `conversation_message_created`. Other conversation-linked events (e.g. negotiation/action events) are never muted. |
| **F6: Delivery Semantics & Precedence** | Off wins everywhere: if a user sets `conversation_message_created` to Off (`0`), no notification is stored. If Normal (`1` / default) and the conversation is muted by recipient, delivery resolves to `'silent'`. Realtime broadcast is suppressed. Mutes are strictly per-user; conversation partners are unaffected. |
| **F7: Indefinite Lifetime in v1** | Mutes remain active until explicitly unmuted by the user. No automatic expiration or cleanup daemon in v1. |
| **F8: Chat Unread Distinction** | Chat's own badge (`conversations.unread-count`) and message rendering in the chat drawer/page are completely unaffected. Only notification popups/broadcasts and system notification badge counters are silenced. Explanatory tooltip/help text clarifies this behavior. |
| **F9: Preference Reset Isolation** | "Reset to defaults" on `/profile/notifications` resets only event preference overrides in `user_preferences`. It does NOT touch `notification_mutes` records. The preferences page UI (13 checkboxes for local supplier) remains identical with 0 DOM regressions. |
| **F10: UI Entry Points** | Mute/Unmute toggle is provided in the conversation header of both the AJAX chat drawer (`resources/views/partials/chat-drawer.blade.php`) and the full-page conversation view (`resources/views/conversations/show.blade.php`) for authorized participants only. |
| **F11: Table Schema & Hashids** | Stored in `notification_mutes` table (`id`, `user_id` FK -> `users` on delete cascade, `subject_type` varchar(32), `subject_id` unsigned bigint, timestamps, UNIQUE(`user_id`, `subject_type`, `subject_id`), INDEX(`subject_type`, `subject_id`)). Numeric IDs stored internally; URL routes use hashids matching existing conversation routes. |
| **F12: Language Policy** | Conversations belong to Import Core (English-first). All UI buttons, labels, accessible aria labels, and messages are English. |

---

## 3. Database Schema & Migration SQL (`--pretend`)

Migration: `database/migrations/2026_10_03_144103_create_notification_mutes_table.php`

```sql
create table `notification_mutes` (
    `id` bigint unsigned not null auto_increment primary key,
    `user_id` bigint unsigned not null,
    `subject_type` varchar(32) not null,
    `subject_id` bigint unsigned not null,
    `created_at` timestamp null,
    `updated_at` timestamp null,
    unique `notification_mutes_user_id_subject_type_subject_id_unique`(`user_id`, `subject_type`, `subject_id`),
    index `notification_mutes_subject_type_subject_id_index`(`subject_type`, `subject_id`)
) default character set utf8mb4 collate 'utf8mb4_unicode_ci';

alter table `notification_mutes`
    add constraint `notification_mutes_user_id_foreign`
    foreign key (`user_id`) references `users` (`id`) on delete cascade;
```

---

## 4. Architecture & Query Budget

1. **Zero DB Queries for Non-Mutable Events:**
   `NotificationPreferenceService::deliveryFor()` checks the registry entry for `mutable_subject`. If absent (39 of 40 events), no query to `notification_mutes` is executed.
2. **At Most One Mute Query Per Recipient Per Request/Job:**
   Muted subject IDs for a user are fetched once via `mutedSubjectIds(User $user, string $subjectType)` and cached in memory in `$this->mutes[$user->getKey()][$subjectType]`.
3. **Fail-Open Safety:**
   Any database or unexpected lookup error in the mute resolution path catches `Throwable` and fails open to the user's stored delivery preference (`'normal'`).
4. **Broadcast & Channel Consistency:**
   Realtime broadcast channel determination in `ApplyNotificationPreferences` reads `deliveryFor()`. Because it returns `'silent'`, the broadcast channel array is emptied (`[]`), suppressing the websocket event.

---

## 5. Requirement-to-Test Traceability

All 16 test cases in `tests/Feature/NotificationConversationMuteTest.php` pass:

| Requirement / Invariant | Test Method | Result |
|---|---|---|
| Schema, columns & unique constraint | `test_table_shape_and_unique_constraint` | PASS |
| User cascade deletion | `test_cascade_delete_on_user` | PASS |
| Idempotency of mute/unmute service | `test_mute_and_unmute_service_idempotency` | PASS |
| Authorization: participant allowed; non-participant/guest forbidden | `test_authorization_for_mute_and_unmute_endpoints` | PASS |
| Delivery: Muted + Normal -> Silent row stored + zero broadcast | `test_delivery_muted_and_normal_delivers_silent_row_with_no_broadcast` | PASS |
| Delivery: Muted + Off -> Off wins (nothing stored) | `test_delivery_muted_and_off_suppresses_everything_off_wins` | PASS |
| Delivery: Unmuted -> Normal delivery + broadcast pushed | `test_delivery_unmuted_delivers_normal_with_broadcast` | PASS |
| Participant isolation: Partner's delivery unaffected | `test_partner_is_unaffected_when_one_participant_mutes` | PASS |
| Allow-list guard: Non-allow-listed event with conversation_id stays Normal | `test_conversation_linked_non_allow_listed_event_stays_normal_even_when_muted` | PASS |
| Unregistered events: untouched and 0 mute queries | `test_unregistered_event_untouched_and_zero_mute_queries` | PASS |
| Query budget: 0 queries for non-mutable, at most 1 query for mutable | `test_query_budget_at_most_one_mute_query_per_recipient_and_zero_for_non_mutable` | PASS |
| Fail-open resilience on DB error | `test_fail_open_on_lookup_failure` | PASS |
| Summary & Badge isolation: Badge excludes silent, inbox panel includes | `test_badge_counts_exclude_muted_notification_while_inbox_list_includes_it` | PASS |
| Preferences page reset does not wipe mutes | `test_reset_to_defaults_does_not_clear_mutes` | PASS |
| Chat drawer and conversation JSON exposes `muted` | `test_drawer_and_conversation_json_contains_muted` | PASS |
| UI contract: toggle renders for participants only with correct aria/DOM | `test_ui_contract_toggle_renders_for_participants_only_with_correct_aria_state` | PASS |

---

## 6. Pinned Phase 5 Contract Preservation
- Pinned tests (`UserNotificationPreferencesTest`, `NotificationPreferencesUiTest`, `NotificationSilentDeliveryTest`, `NotificationPreferenceDeliveryTest`, `NotificationDeliveryTest`) remain 100% green without modification.
- Notification preferences registry in `config/notification_preferences.php` retains exactly 40 entries, with no changes to labels, categories, descriptions, or defaults.
- The `/profile/notifications` page renders identically with unchanged row counts and structure.
