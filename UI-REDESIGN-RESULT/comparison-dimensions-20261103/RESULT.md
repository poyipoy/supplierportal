# Comparison table dimensions — 3 November 2026

Implemented the agreed display changes in Inter-Supplier Comparison:

- PR dimensions appear below Material; each supplier's offered dimensions replace the Qty/weight/estimated-weight note below Price/kg.
- Flat uses T/W/L, Round uses D/L, and Hollow uses OD/ID/L, with mm units. Length ranges and regional numeric separators are retained; missing offered fields remain dashes without PR substitution.
- Weight headers read `kg/unit` and `Total kg`. The existing three-column supplier groups, prices, amounts, quantities, weights, canonical specification strings, and award controls are preserved.

Verification:

- **31 PHP tests passed (42,787 assertions)**: shape-specific rendering, differing PR/offer dimensions, missing fields, ranges, both numeric preferences, regional display, existing financial/query contracts, award submission/generation, and translation parity.
- **13 JavaScript tests passed** for comparison state and Inter-Supplier charts.
- Scoped Pint, PHP syntax, build, Blade cache, and diff checks passed. The code graph includes the new formatter. Build emitted the existing calendar `::part` selector warnings; calendar CSS was not modified.
- Browser DOM inspection used synthetic data in a temporary loopback-only harness against the isolated test database. At 1440 px and 375 px, document width stayed within the viewport and the table scrolled horizontally. Material remained fixed at x=280 on desktop and x=24 at 375 px after horizontal scrolling; headers remained sticky. All nine requested/offered dimension labels matched the fixture.

Screenshot capture did not complete; an alternate browser connector exposed no enabled browsers. Browser evidence is DOM/text and geometry verification, not full visual-regression sign-off. The preview server and browser tab were closed afterward.

No application database or route changes were made. Existing unrelated dirty work was preserved; nothing was committed or pushed.
