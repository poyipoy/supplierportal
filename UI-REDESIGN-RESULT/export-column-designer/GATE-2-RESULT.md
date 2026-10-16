# Gate 2 — Excel-Style Column Designer

Verifikasi lokal 15–16 Oktober 2026. [Spesifikasi Gate 1](../../docs/export/GATE-1-ADVANCED-EXPORT-EXCEL-COLUMN-DESIGNER-PLAN.md). Baseline branch/HEAD: feature/material-procurement-revision-gate2 / 78e8bf4. Seluruh pekerjaan tetap uncommitted.

**GATE 2 IMPLEMENTATION COMPLETE — VERIFICATION STATUS REPORTED SEPARATELY.**

Implementasi tersedia; Gate A–D lulus pada cakupan bukti berikut. **Gate E acceptance tetap terbuka:** final paired diagnostic lulus 15/15, tetapi fixed threshold paint katalog 24 masih **FAIL: 9,570 ms > 7,915 ms**. Tidak ada klaim seluruh objective acceptance atau production readiness tercapai. [Review lanjutan](continuation-20261016/REVIEW.md) memuat defect/fix, review keamanan dan ukuran, perubahan pengukuran, serta batas bukti.

## Implementasi dan inventory

Alternative B: [export-column-designer.js](../../resources/js/export-column-designer.js) memiliki pure frozen order + visible state dan view/interaksi. [advanced-export.js](../../resources/js/advanced-export.js) tetap memiliki transport, filters, format, preset, Quick Export dan SDK. DOM hanya projection; Export dan Save memakai serializer yang sama. Required tetap visible dan movable. Hide mempertahankan slot total order; Unhide memakai slot itu. Reset memakai default server (PO 10 dari 12). Preset tetap menyimpan visible ordered keys; hidden-position history tidak dipersistensikan.

Modal Bootstrap hampir fullscreen menyediakan horizontal headers/letters, tiga decorative empty rows, ribbon, picker Unhide, context menu, native desktop drag/midpoint/auto-scroll, keyboard/typeahead dan tombol alternatif touch. Tidak ada records, editable cells, formulas, resize, data-preview request atau spreadsheet library.

| Area | File |
|---|---|
| Runtime baru | resources/js/export-column-designer.js |
| Runtime diubah | resources/js/advanced-export.js; resources/views/components/export/advanced-modal.blade.php; resources/css/app.css; lang/en/exports.php; lang/id/exports.php |
| Tes JS | tests/js/export-column-state.test.mjs; tests/js/advanced-export-columns.test.mjs; tests/js/advanced-export-format.test.mjs |
| Tes PHP | tests/Feature/AdvancedExportUiTest.php; tests/Feature/AdvancedPurchaseOrderExportTest.php |
| Helper file proof | tests/Support/export-column-output-proof.php |
| Helper fixture/aplikasi terisolasi | tests/Support/export-column-designer-fixtures.php; tests/Support/export-column-designer-app.php |
| Helper browser | tests/Support/export-column-designer-browser-check.js; export-column-designer-browser-measure.js; export-column-designer-browser-drag.js; export-column-designer-browser-resources.js; export-column-designer-browser-cleanup.js, seluruhnya di tests/Support/ |

Total **19 source/test/helper files**. [Hash/status](changed-files.json), [scoped textual diff](scope.diff), [generated assets](final-assets.json). Diff dinormalisasi LF untuk review; snapshot dan SHA-256 mempertahankan identitas byte asli. Helper CDP/trace/parser tambahan berada di folder hasil, tidak di runtime aplikasi.

Tidak ada app.js entry, package, migration file, route, controller PHP, authorization, preset schema, job atau writer algorithm yang diubah oleh task. Auth splash tidak disentuh.

**Preservasi:** [baseline awal](continuation-20261016/preservation-initial.json): 1.875/1.909 file identik, 8 perubahan mission pada file existing, 26 perbedaan luar mission yang diamati berlangsung konkuren. [Baseline lanjutan](continuation-20261016/preservation-continuation.json): 1.915/1.920 identik, 5 file mission berubah, **0 file luar mission berubah**, 0 missing. Seluruh auth-splash yang dipantau identik. Task tidak mengembalikan/menimpa perubahan sesi lain. Original bytes AdvancedPurchaseOrderExportTest, termasuk dirty count 12 milik pengguna, tersimpan pada scoped snapshot.

## Gate dan tes yang dieksekusi

| Gate | Status | Evidence dan batas |
|---|---|---|
| A — baseline/safety | PASS | [Initial baseline](baseline-files.json), snapshot, [latest live DB safety](continuation-20261016/database-safety.json); namespace milik task dan 0 koneksi lain sebelum suite. |
| B — state | PASS | [51 JS tests](continuation-20261016/after-js.tap): termasuk 27 state tests, 6.174 exhaustive prefixes dan 4.800 seeded transitions; invariants/immutability pada setiap prefix. |
| C — UI/lifecycle | PASS | [26 full-app cases](continuation-20261016/full-app-browser-flow.json), [6 drag cases](continuation-20261016/drag-edges.json), native input dan cleanup di bawah. |
| D — request/file | PASS | [70 PHP / 608 assertions](continuation-20261016/final-php-regressions.log), [4 parsed HTTP files](continuation-20261016/http-file-comparison.json), [108 writer cases](backend-output-final/report.json). |
| E — browser/performance acceptance | FAIL / OPEN | Browser/zoom/retention tersedia; [final timing](continuation-20261016/counterbalanced-trace-comparison.json) melanggar satu fixed threshold. |

- **PASS — 51 JS:** 27 state, 13 controller/race, 3 format-only, 5 SDK, 3 CSV timeout tests. [Before refinement](continuation-20261016/before-js.tap) juga lulus. Format harness meng-import ESM asli, tidak menghapus import agar VM lolos.
- **PASS — 70 PHP / 608 assertions**, termasuk dua locale POST→dispatcher→worker→finalizer→writer cases. Tiga PHPUnit deprecation dilaporkan; bukan full suite.
- **PASS — TranslationParity 3 tests / 40.020 assertions:** [log](continuation-20261016/final-translation.log).
- **PASS — existing read-only DB safety 2 tests / 4 assertions:** [log](continuation-20261016/db-shared-safety.log), lalu probe effective config/live SELECT DATABASE namespace milik task.
- **PASS — build, scoped Pint, Blade cache, PHP/JS syntax, scoped diff whitespace:** [build](continuation-20261016/final-build.log), [Pint](continuation-20261016/final-pint.log), [Blade](continuation-20261016/final-view-cache.log). CRLF advisory format test bukan whitespace error.

TDD awal dipertahankan: [missing-model RED](gate-b-red-state.tap), [GREEN](gate-b-green-state.tap), [sparse-array RED](gate-b-red-sparse-state.tap), dan gate-c-red-* untuk lifecycle/races. Seeds 24301, 12648430, 11386901 masing-masing diuji pada 24/64 keys. Review lanjutan mereproduksi menu-local overflow scroll FAIL lalu [regression GREEN](continuation-20261016/browser-regression-green.json) setelah fix scroll/focus. No-op Reset membatalkan drag stale; outside drop mencegah native text insertion; malformed metadata fail-closed.

Tidak dijalankan: full PHP/JS suite, staging/production, dan wrapper AdvancedExportConcurrency/Baseline/QueuedCsvBaseline/Performance yang memiliki guard/subprocess shared DB adasi_portal_test. Namespace bersama tidak di-reset untuk wrapper. Coverage serial tidak disebut real concurrency.

## State → request → actual file

| Boundary | Status | Bukti |
|---|---|---|
| Ordered visible state | PASS | Literal Hide→Move→Unhide, Preset→Reorder→Reset, All Optional→Restore, save/reload, invalid/no-op dan setiap generated prefix pada state suite. |
| Frontend request | PASS | Browser Save/Export/Quick memakai subset [remark, po_number, currency], csv, tanpa hidden/order/letters/audience. Preset/SDK memory adapters eksplisit, bukan real POST proof. |
| Server/authorization | PASS | PHP HTTP suites memakai route/FormRequest/resolver existing; role/audience/required/owner/hashid/filter/preset dan worker authorization diuji. [18 metadata GET](continuation-20261016/full-app-metadata-matrix.json): 9 keys × en/id; supplier→purchasing 404. |
| Query/queue/file | PASS | Empat real POST→dispatcher→worker→finalizer files diparse PHP assertions dan [parser stdlib independen](continuation-20261016/compare-http-files.py): exact headings/values/order, Unicode, formula-like text, CSV BOM, dua row, tanpa formula/visual rows. |
| Seluruh katalog | PASS | 108 native writer files: 9 Tier-1 keys × 2 locales × XLSX/CSV × default/reverse-all/subset. Real models/catalog/map/headings dan installed Maatwebsite writer; SQL/PDO denied, 0 di luar denial canary. Bukan tambahan HTTP/query/queue coverage. |

File contoh: [en XLSX](http-output-en.xlsx), [en CSV](http-output-en.csv), [id XLSX](http-output-id.xlsx), [id CSV](http-output-id.csv). Urutan Remark/Catatan → PO Number/Nomor PO → Currency/Mata Uang; required PO Number di tengah. Runtime backend boundary files yang direview tetap sesuai baseline; [review keamanan sumber](continuation-20261016/REVIEW.md) terpisah dari runtime security approval.

## Browser dan accessibility

Chrome 155/Windows. Aplikasi penuh GET-only harness 8102 memakai test-only actor/session injection di database task; bukan verifikasi login/MFA produksi. Fixture metadata-only 8101 dan frozen replay 8103 tidak mengakses data bisnis.

| Kriteria | Status | Bukti dan batas |
|---|---|---|
| Worksheet/ribbon/Unhide/menu/preset/Quick | PASS | [26 actual-app cases](continuation-20261016/full-app-browser-flow.json); browser-memory transport seam terpisah dari server/file proof. |
| Native mouse drag | PASS | [Start](native-drag-start.json), [scrolled](native-drag-scrolled.json), [outside cancel](native-drag-outside-cancel.json), [final](native-drag-final.json); native drops mencatat isTrusted=true. |
| Auto-scroll/first/last/Escape/reset/outside drop | PASS | [6 final renderer cases](continuation-20261016/drag-edges.json); synthetic events, bukan physical touch/native input. |
| Native keyboard/Tab/menu/Escape/focus | PASS | [Keyboard](native-keyboard-final.json), [Tab](native-keyboard-tab.json), [menu](native-keyboard-menu.json); final overflow/resize/focus regressions juga lulus. |
| Dua roots/30–60 cycles/opening destroy | PASS | [Dual root](browser-dual-root.json), [counters](browser-cleanup-final.json), [opening destroy](browser-opening-destroy.json). Fade dinonaktifkan pada counter loop, normal transitions diuji terpisah. |
| Retained roots setelah GC | PASS | [Heap + WeakRefs](continuation-20261016/cdp-retained-roots.json): 60 normal-fade cycles; calibrated 3 live wrappers → 0 sesudah destroy/remove/clear handle dan dua GC. Finite probe, bukan universal no-leak claim. |
| ID/dark/mobile, 24 columns | PASS | [Final app 390×844](continuation-20261016/full-app-mobile-dark-id.json): enam ribbon targets 44px, Move/Hide/Restore, menu/footer reachable, containment aktif. |
| Actual browser zoom 200/400% | PASS | [Final ID zoom](continuation-20261016/final-cdp-browser-zoom.json), [400% image](continuation-20261016/final-cdp-zoom-400.png): outer tetap, inner width/DPR membuktikan actual layout zoom, visualViewport.scale=1, fresh task-owned profile. |
| Automated a11y scoped designer | PASS | [Lighthouse](a11y-app-final.json), [accessible-name proof](accessible-name-check.json). Empat Move label/name findings diperbaiki; skor 100 bukan full WCAG conformance. Navbar findings existing di luar scope. |
| Calendar pointer compatibility | PASS | Next/Today memperbarui input dan restore trigger pada [current](continuation-20261016/calendar-current.json). |
| Calendar keyboard focus existing | FAIL, PRE-EXISTING | [Legacy reproduction](continuation-20261016/calendar-legacy.json) dan current: panel body di luar Bootstrap trap. Tidak disamarkan sebagai PASS atau diubah lewat refactor global. |
| Screen reader/physical devices/browser lain/studi operator | NOT TESTED | Tidak ada connected screen reader/partisipan/perangkat pada tools. Keyboard/AX/synthetic bukan human usability/WCAG proof. |

## Performa dan ukuran

Seri gagal/terkontaminasi tetap disimpan. Harness awal memasukkan backdrop transition sekitar 150ms dan memakai two-rAF sebagai surrogate Paint. [Review](continuation-20261016/REVIEW.md) dan [reproduksi](continuation-20261016/measurement-backdrop-red.json) menjelaskan koreksi. Actual trace mengukur mutation readiness dan main-thread Paint end, menjaga viewport/catalog/build, memisahkan selection/frame phase, dan menolak missing Paint/data loss. Physical presentation tidak diklaim.

[Protocol final](continuation-20261016/counterbalanced-trace-protocol.json) dibekukan sebelum run: tiga batch × 30 measured + tiga warmups untuk 12/24/64 keys, order counterbalanced, loader idle tanpa reset. Tersimpan 540 samples dan 18 compressed traces/summaries. [Comparison](continuation-20261016/counterbalanced-trace-comparison.json):

- **Fixed thresholds: FAIL 1/15** — paint 24 **9,570 ms**, B=6,617, J=1,298, batas **7,915 ms**.
- **Paired diagnostic: PASS 15/15**; tidak menggantikan fixed failure.
- Readiness, command computation, command-to-Paint dan open-to-Paint lulus semua ukuran pada fixed comparison.
- Seluruh trial: 66 metadata calls/33 opens, 0 leftover layer/backdrop, dialog/backdrop animation false, loader inactive/pending=0, dataLossOccurred=false.

Host/JIT drift direproduksi pada legacy replay. Tidak ada capture tambahan untuk mencari PASS. Non-regression penuh belum terbukti; perlu sesi/mesin yang dapat dijaga dari pekerjaan paralel.

| Asset | Baseline raw/gzip | Final raw/gzip |
|---|---:|---:|
| Main JS | 164.141 / 50.705 | 180.004 / 56.201 bytes |
| CSS | 146.364 / 25.501 | 150.891 / 26.390 bytes |

**Trigger ukuran terlampaui; technical size review diterima dengan atribusi terukur.** JS gzip +5.496 > 3.657; CSS gzip +889. [Review independen](continuation-20261016/REVIEW.md) mengatribusikan raw +15.863: model/validation 3.372, view/interaksi 11.634, controller net +726, bundle integration +131. Tidak ada material unused subsystem, dependency, chunk/request baru; bukan klaim under-budget atau latency PASS. Row churn 4 mutations/detached header → 0/reused header pada [before](reorder-churn-before.json)/[after](reorder-churn-after.json).

## Lingkungan dan reproduksi

Database **adasi_export_column_designer_20261015_test** dibuat khusus task setelah dipastikan belum ada. [Ownership](isolated-test-database.json), [latest live safety](continuation-20261016/database-safety.json). Existing migrations hanya pada namespace tersebut lewat test runner; tidak ada migration baru atau development/production data berubah. Shared adasi_portal_test hanya read-only safety suite. Namespace task dipertahankan; periksa ownership/koneksi sebelum RefreshDatabase mengubah isinya.

Perintah utama, serial:

    $env:APP_ENV='testing'
    $env:DB_DATABASE='adasi_export_column_designer_20261015_test'
    $env:DB_URL=''
    php UI-REDESIGN-RESULT/export-column-designer/continuation-20261016/database-probe.php
    php vendor/bin/phpunit --filter='AdvancedExport(Foundation|PhaseFiveB|PhaseSix|Rollout|Ui)Test|AdvancedPurchaseOrderExportTest|ExportPresetTest' --do-not-cache-result --colors=never
    node --test tests/js/export-column-state.test.mjs tests/js/advanced-export-columns.test.mjs tests/js/advanced-export-format.test.mjs tests/js/advanced-export-sdk.test.mjs tests/js/advanced-export-csv-timeout.test.mjs
    php tests/Support/export-column-output-proof.php final
    python UI-REDESIGN-RESULT/export-column-designer/continuation-20261016/compare-http-files.py
    npm.cmd run build

Set EXPORT_COLUMN_EVIDENCE=1 saat feature tests untuk menyimpan empat file yang diparse. Helper fixture/browser/trace memuat kontrak eksekusi; protocol final immutable, bukan loop retry. Penutupan task-owned harness/profil browser tercatat pada [cleanup](continuation-20261016/cleanup.json). Tidak ada Git stage/commit/push/reset/clean/merge/rebase atau deployment.

**FINAL STATUS: GATE 2 IMPLEMENTATION COMPLETE — VERIFICATION STATUS REPORTED SEPARATELY.**

**Acceptance objective masih terbuka pada fixed performance threshold di atas.**

Goal berstatus **blocked**, sesudah kondisi yang sama terkonfirmasi pada tiga turn berurutan. [Blocked audit](continuation-20261016/BLOCKED-AUDIT.md) merekam alasan, fresh host evidence, dan prasyarat melanjutkan. Ini bukan persetujuan release atau acceptance penuh.
