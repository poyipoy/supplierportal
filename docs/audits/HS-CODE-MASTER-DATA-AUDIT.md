# HS Code and Master Material Data Audit

Audit date: 2026-08-12  
Runtime source scope: approved selected sources only

## Source integrity

| Source | Selected scope | SHA-256 |
|---|---|---|
| `docs/naufal raw material.xlsx` | Sheet `master material` only | `F1417557C3783D4EE7DF6942207580A9324486AFFF63F1DEB15C25C2B404EC6A` |
| `docs/PENENTUAN HS CODE.pdf` | All 30 numbered HS rule entries and the material-category section | `34471EC02EB1EED58E24584E319CC0D894EF090360B0F365C8A3EB0D8DF8336C` |

The workbook sheets `Backup Kriteria`, `Sheet2`, `Penentuan HS Code`, and
`backup master material` are deliberately excluded. They are neither fallback
sources nor inputs to runtime resolution.

The PDF text and table sequence were extracted successfully and matched against
the workbook mapping sequence. Visual page sign-off remains unavailable because
Poppler page rendering was not available during the baseline audit and the
Chrome canvas fallback rendered blank pages. This limitation does not affect the
extracted row counts or hashes above.

## Controlled fixtures

| Fixture | SHA-256 | Records |
|---|---|---:|
| `database/data/material_masters.json` | `7E09192412C53ABED31551CF1FDE5D9BAF7A3A48E08067E141E803CCAB510AC7` | 84 |
| `database/data/hs_code_rules.json` | `9671D089FAE6AD3EBD7235297B966DD63169B9BDA77CF046767E3EBE462B0F9D` | 21 |

Each material record retains its source file, sheet, and row. Each HS rule
retains its source file, PDF page, and numbered source entries in `source_refs`.

Material codes and aliases are normalized with these operations only:

1. Trim leading and trailing whitespace.
2. Collapse internal whitespace to one space.
3. Convert letters to uppercase.
4. Preserve punctuation, including dots and hyphens.

## Master material results

| Measure | Count |
|---|---:|
| Valid material rows | 84 |
| Explicit HS-category mappings | 64 |
| Unmapped materials | 20 |
| Daido materials | 39 |
| Non-Daido materials | 45 |
| Aluminium density materials | 4 |

Explicit mapped-category coverage is:

| Canonical category | Count |
|---|---:|
| `alloy_steel` | 52 |
| `high_speed_steel` | 4 |
| `carbon_steel` | 3 |
| `honed_tube_steel` | 1 |
| `other` | 4 |

The 20 deliberately unmapped materials are:

`DEX20`, `DEX40`, `SK5`, `F3RV`, `BECU`, `SUS 304`, `Kuningan`,
`Tembaga C1100`, `SS400`, `SUJ2`, `Mn13`, `Tembaga`, `ST60`, `MAS1C`,
`DHW`, `PX5W`, `DM`, `NAKW`, `HARDOX 500`, and `PAK90`.

`raw_category` is retained as provenance and never promoted into
`hs_category`. In particular:

- `ST52` uses the explicit `honed_tube_steel` mapping.
- `YXM1` uses the explicit `alloy_steel` mapping.
- `SK5` remains unmapped even though its raw category is `Other`; it is not
  inferred as Strip Steel.
- The four Aluminium materials use the Aluminium weight factor and explicit
  category `other`, but no definitive HS rule exists for that category.

## HS rule consolidation

The 30 PDF rows reduce to 19 unique condition groups. The runtime fixture keeps
21 auditable records: 19 active decisions and two inactive conflict
alternatives.

Nine same-condition/same-code source pairs are consolidated through
`source_refs`: `2+3`, `6+7`, `8+9`, `10+11`, `15+16`, `17+18`, `19+20`,
`27+28`, and `29+30`.

| Rule key | PDF entries | HS Code | Status | Priority |
|---|---:|---|---|---:|
| `pdf-001-high-speed-round-d-ge-10` | 1 | `7228.10.10` | active | 100 |
| `pdf-002-alloy-round-d-10-165` | 2, 3 | `7228.30.10` | active | 100 |
| `pdf-004-alloy-flat-t-10-135-w-400-600` | 4 | `7226.91.90` | active | 100 |
| `pdf-005-alloy-flat-t-3-135-w-400-600` | 5 | `7226.91.90` | active | 100 |
| `pdf-006-alloy-flat-t-gt-135` | 6, 7 | `7228.40.90` | active | 100 |
| `pdf-008-alloy-round-d-gt-165` | 8, 9 | `7228.40.10` | active | 100 |
| `pdf-010-alloy-flat-t-3-135-w-ge-600` | 10, 11 | `7225.40.90` | active | 100 |
| `pdf-012-alloy-flat-t-10-135-w-le-150` | 12 | `7228.30.90` | active | 100 |
| `pdf-013-alloy-flat-t-3-135-w-150-400` | 13 | `7226.91.10` | active | 100 |
| `pdf-014-high-speed-flat-t-ge-10-w-150-400` | 14 | `7226.20.10` | active | 100 |
| `pdf-015-carbon-round-d-16-250-l-gt-2000` | 15, 16 | `7214.99.92` | active | 100 |
| `pdf-017-carbon-flat-t-gt-10-w-gt-600` | 17, 18 | `7208.51.00` | active | 20 |
| `pdf-019-carbon-round-d-ge-250-l-gt-2000` | 19, 20 | `7214.10.11` | active | 100 |
| `pdf-021-carbon-flat-t-ge-250-w-gt-600` | 21 | `7214.10.19` | active | 10 |
| `pdf-022-honed-hollow-od-ge-140-id-gt-50-selected` | 22 | `7304.31.90` | active | 100 |
| `pdf-023-honed-hollow-od-ge-140-id-gt-50-rejected` | 23 | `7304.31.40` | inactive | 100 |
| `pdf-024-strip-flat-w-le-400` | 24 | `7211.29.20` | active | 100 |
| `pdf-025-alloy-hollow-od-gt-165-id-gt-135-rejected` | 25 | `7304.51.90` | inactive | 100 |
| `pdf-026-alloy-hollow-od-gt-165-id-gt-135-selected` | 26 | `7304.59.90` | active | 100 |
| `pdf-027-carbon-flat-t-4-75-10-w-gt-600` | 27, 28 | `7208.52.00` | active | 100 |
| `pdf-029-carbon-flat-t-3-4-75-w-gt-600` | 29, 30 | `7208.53.00` | active | 100 |

## Conflict decisions and overlap behavior

- Honed Tube entries 22 and 23 have identical conditions. `7304.31.90` is the
  active decision; `7304.31.40` remains inactive for audit.
- Alloy Hollow entries 25 and 26 have identical conditions. `7304.59.90` is the
  active decision; `7304.51.90` remains inactive for audit.
- No Cold Drawn or Hot Drawn process field is introduced because the selected
  sources do not provide that metadata.
- Alloy Flat entries 4 and 5 overlap and resolve to the same HS Code. Both are
  retained for provenance; runtime treats same-priority/same-code matches as one
  result while Data Quality reports the redundant overlap.
- Carbon Flat entry 21 is a specific subset of entries 17 and 18. Priority 10
  makes it win over the broader priority-20 rule.
- Admin activation is rejected only when an active overlapping rule at the same
  priority produces a different HS Code. Same-code overlap and different-priority
  overlap remain visible in Data Quality.

## Gaps and unreachable coverage

- `strip_steel` has an active PDF rule but no material in the selected master
  maps to that category, so the rule is currently unreachable.
- `other` has mapped Aluminium materials but no active rule, so Purchasing must
  supply a manual HS Code at submit time after server resolution returns
  `no_rule`.
- The approved cross-source audit identified the reference-only labels `S35C`,
  `Q345D`, `Q235`, `A3`, and `WEARPLATE` as absent from sheet `master material`.
  They are shown in Data Quality but are intentionally not imported.
- Unmapped catalog materials remain selectable. They resolve to
  `unmapped_material` and require a canonical manual HS Code before submit.

## Seeder and historical-data guarantees

`MaterialHsCodeMasterSeeder` is deliberately absent from `DatabaseSeeder`. It
must be invoked explicitly. It inserts missing records by stable normalized code
or rule key, does not update existing master/rule rows, and can be run repeatedly
without duplicating the approved fixture.

The additive `pr_items` migration defaults existing rows to `legacy`. It does not
rewrite historical material names, HS Codes, weights, quotation references, QC
references, purchase orders, or reports.
