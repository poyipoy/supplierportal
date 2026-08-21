# MISSION-07 — Shared, Auth & Cross-Application UX
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Depends on:** Missions 01–06  
**Primary goal:** Complete cross-application experiences so Auth, Profile, Notifications, Conversations, Exports, and shared states fully align with Phase 2.

---

# 0. Mission intent

This mission closes gaps that make a redesign feel incomplete.

Do not rebuild Phase 1 systems unnecessarily.

AdasiToast, Lucide, semantic tokens, and shell foundations already exist.

Focus on material visual integration and UX consistency.

---

# 1. Re-audit before editing

Identify active views/routes for:

- Login
- Forgot Password
- Reset Password
- verification/auth-adjacent screens
- MFA/recovery/password continuation if present
- Profile
- Security
- Notification Center
- Conversations
- Chat drawer
- Export history
- shared dialogs/drawers
- shared empty/loading/error states
- PDF/print-facing shared views

Distinguish active pages from inactive Laravel starter templates.

---

# 2. Auth exception — industrial image retained

Auth is the one approved area that may use industrial imagery.

Desktop direction:

```text
industrial/manufacturing visual context
+
clean authentication form surface
```

Preferred balance:

- image/context on left
- auth form on right
- restrained dark overlay if required for text contrast

Keep:

- ADASI identity
- professional English
- clear form hierarchy
- restrained security copy

Avoid:

- marketing landing-page hero
- testimonial block
- statistics block
- glass login card
- giant headline
- excessive gradient overlay
- huge rounded auth container

---

# 3. Auth responsive behavior

On smaller screens:

- form remains primary
- image may crop/reduce/hide when appropriate
- no horizontal overflow
- forgot/reset/MFA/recovery remain readable
- password visibility control remains accessible

Do not sacrifice usability to preserve desktop image composition.

---

# 4. Profile

Redesign as a restrained account-management workspace.

Possible sections based on existing functionality:

- account/profile information
- password/security
- MFA/recovery
- other profile metadata

Use sectioned hierarchy.

Do not create a card around every field group.

---

# 5. Notification Center

Keep persistent database notification architecture.

Improve:

- category navigation
- unread hierarchy
- timestamp/meta rhythm
- action affordance
- reading density
- empty/no-result state

Do not make it look like a social-media feed.

System events use semantic/category icons.

Human chat may use avatar.

---

# 6. Conversations

Preserve backend/realtime behavior.

Improve:

- thread hierarchy
- participant context
- unread state
- timestamp rhythm
- composer
- attachment/action affordance
- responsive behavior

Avoid consumer-messenger gimmicks.

Do not add oversized bubbles, playful gradients, or reaction decoration.

---

# 7. Chat drawer

Integrate with the new shell.

Ensure:

- appropriate width
- clear thread context
- accessible close/back
- focus behavior
- mobile full-height adaptation
- unread indicators remain functional

Do not create a second visual language inside the drawer.

---

# 8. Export history

Use established list grammar.

Prioritize:

- report/file type
- request time
- state
- download/open action
- failed state
- processing state

Use AdasiToast for transient lifecycle feedback.

Do not fake progress.

---

# 9. Shared empty/loading/error states

Create one coherent cross-app pattern.

Empty state:

- concise title
- useful explanation
- one action if appropriate
- no illustration

Loading:

- skeleton or progress only where meaningful

Errors:

- field validation remains inline
- transient feedback uses AdasiToast
- blocking decision/error uses appropriate dialog/alert

Do not conflate them.

---

# 10. Shared dialogs/drawers

Audit visual consistency for:

- compact headers
- close affordance
- footer action order
- destructive action treatment
- restrained elevation
- mobile fit
- focus handling

Avoid oversized rounded modal surfaces.

---

# 11. PDF/print consistency

Where print/PDF views exist:

- preserve business data
- preserve print correctness
- improve typography/alignment only where safe
- retain legitimate print-specific CSS

Do not force the web shell/card language into print output.

---

# 12. Cross-role shared-state consistency

Ensure the same:

- status chip language
- empty states
- toast behavior
- dialog action order
- drawer styling
- input/button hierarchy

is used across Purchasing, Supplier, QC, and Admin shared workflows.

---

# 13. Anti-fake-redesign gate

Mission fails if Auth/Profile/Notifications/Conversations only receive token changes while retaining old composition.

They must visibly align with the Phase 2 system.

---

# 14. Anti-AI-slop gate

Reject:

- glass login card
- generic SaaS auth hero
- testimonials
- marketing statistics
- social-media notification feed
- consumer messenger visuals
- giant profile cards
- decorative empty-state illustration

Auth industrial image is the only explicit image exception.

---

# 15. Stitch exploration

If available, use:

- Login
- Notification Center
- Conversations or Profile

Critique aggressively against enterprise/productivity goals.

---

# 16. Verification

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run targeted auth/profile/notification/conversation/export tests.

---

# 17. Required report

Create:

```text
UI-REDESIGN-RESULT/UI-REDESIGN-PHASE2-M07-SHARED-RESULT.md
```

Include:

1. files changed
2. Auth redesign
3. responsive Auth behavior
4. Profile/Security changes
5. Notification Center changes
6. Conversations/Chat drawer changes
7. Export history
8. shared state system
9. dialog/drawer consistency
10. print/PDF considerations
11. anti-AI-slop review
12. tests/build result
13. `MANUAL_VISUAL_QA_REQUIRED`

---

# 18. Completion gate

Cross-application pages must no longer look like leftovers from an earlier design generation. They must fully belong to the Phase 2 product system.
