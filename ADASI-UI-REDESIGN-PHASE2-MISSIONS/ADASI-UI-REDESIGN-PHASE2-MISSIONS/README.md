# ADASI UI Redesign Phase 2 — Mission Pack

This pack is designed for sequential execution by Gemini 3.7 in the **same working tree**.

## Always attach two files per run

1. `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`
2. the mission currently being executed

## Recommended execution order

1. `MISSION-01-SHELL-CORE-VISUAL-SYSTEM.md`
2. `MISSION-02-PURCHASING-DASHBOARD-LISTS.md`
3. `MISSION-03-PURCHASING-FORMS-DETAILS.md`
4. `MISSION-04-SUPPLIER-EXPERIENCE.md`
5. `MISSION-05-QC-EXPERIENCE.md`
6. `MISSION-06-ADMIN-EXPERIENCE.md`
7. `MISSION-07-SHARED-AUTH-CROSS-APP.md`
8. `MISSION-08-FINAL-CONSISTENCY-AUDIT.md`

## Important execution rules

- Do not reset the working tree between missions.
- Output from the previous mission becomes the foundation for the next mission.
- Do not automatically commit/push/merge/rebase.
- No browser automation.
- Keep Bootstrap as compatibility layer.
- Preserve business behavior and backend contracts.
- Reuse the Phase 1 Lucide/AdasiToast/token foundation.
- Preserve the Global Contract anti-AI-slop rules in every mission.
- Use `MANUAL_VISUAL_QA_REQUIRED` for rendered behavior not manually checked by the user.

## Why the work is split this way

Mission 01 establishes the visual architecture. Missions 02–03 make Purchasing the primary design benchmark. Missions 04–07 propagate the benchmark by role without inventing new visual languages. Mission 08 repairs drift and verifies regressions.
