# Cross-Blueprint Audit Remediation

**Version:** 1.0.0
**Updated:** 2026-07-22
**Status:** Active. Records CR-01..CR-10 disposition against `59-cross-blueprint-audit.md`.
**Category:** UI / Frontend / Governance
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`cross-blueprint` · `remediation` · `AC-CROSS-001` · `back-links` · `plan-07`

## 1. Purpose

Track the ten cross-blueprint change requests (CR-01..CR-10) queued by
`59-cross-blueprint-audit.md` §7 and pin each `AC-CROSS-001..006` row as
`SATISFIED` or `OPEN`. `59-` is the audit; this file is the remediation
ledger. When every row below reaches `SATISFIED`, Plan 07 graduates.

## 2. Root cause captured in one sentence

Blueprints 33..42 and their supporting catalogs (`50-` through `58-`) were
authored across separate single-pass steps, so cross-references (back-links,
verbs, tokens, shortcuts, events, error codes, empty-state variants, loading
modes) drifted from the catalogs by the time Plan 06 graduated; CR-01..CR-10
are the minimum set of patches that closes that drift without introducing
new rules.

## 3. Remediation ledger

| CR | Audit finding(s) | Owning file(s) | Status | Landed in | Verification signal |
|----|------------------|----------------|--------|-----------|---------------------|
| CR-01 | F1..F10 (`59-` §5.1 missing back-links) | 33-42 route blueprints | SATISFIED | v0.168.0 | `check-blueprint-crossrefs.py` 30 findings -> 0 |
| CR-02 | F11..F15 (`59-` §5.2 reserved-word drift) | 33-, 36-, 39-, 40-, 27- | SATISFIED | v0.170.0 | `check-reserved-words.py` waiver map empty |
| CR-03 | F16..F18 (`59-` §5.3 token drift) | 24-, 54-, 55-, 58- | SATISFIED | v0.171.0 | `54-` §5 timing exception + `58-` §6 bucket note landed |
| CR-04 | F19..F21 (`59-` §5.4 shortcut drift) | 24-, 32-, 41- | SATISFIED | v0.172.0 | F19 verified absent in `24-` §5 (no `PageUp`/`PageDown` refs), F20 verified absent in `32-` §3 (no `Mod+K` refs), F21 aligned in `41-` §5; `check-shortcut-registry.py` 11 blueprints 0 findings |

| CR-05 | F22..F24 (`59-` §5.5 analytics drift) | 32-, 40-, 58- | SATISFIED | v0.173.0 | `check-command-telemetry.py` 4 -> 0 after `32-` §7 rows (Users.Disable/Reactivate, Features.Edit/Deprecate/Reactivate, Clients.RotateSecret), `32-` §8.1 58- fallback normative note, `40-` §7 verb rename, `58-` §4.4 `Product.InstallReveal` + `Product.Reverify` families |
| CR-06 | F25..F26 (`59-` §5.6 error-code drift) | 41-, 42- | SATISFIED | v0.173.0 | F25 verified absent in `41-` §5 (no bare `NotFound` refs), F26 aligned in `42-` (`AuthFailed` cited with `12-error-taxonomy.md` per §6/§8/§12); linter ErrorCode-context skip added |

| CR-07 | F27..F30 (`59-` §5.7 / §5.8 empty + loading tags) | 33-, 34-, 35-, 36-, 37-, 38-, 39-, 40-, 41-, 42- | SATISFIED | v0.174.0 | `check-empty-state-tags.py` 8 -> 0 and `check-loading-state-tags.py` 5 -> 0 after tagging every empty-state mention with a `53-` §3 variant (First-run / Filter-reset / Permission-scope) and every skeleton mention with a `54-` §2 mode (A/B/C/D) |
| CR-08 | F31 (`59-` §5.9 illustration spot-check) | 33-, 39- | SATISFIED | v0.175.0 | `rg -i 'illustration'` returns 0 hits across `33-` and `39-`; `check-illustration-slots.py` 0 findings across blueprints 33-42 (bans mentions in dashboard/table/form surfaces per `52-` §10, requires `52-` citation elsewhere) |
| CR-09 | F32 (`59-` §5.10 print route gap) | 35-, 55- | SATISFIED | v0.176.0 | `55-` §2 already lists `/admin/serials/:SerialId/certificate` (row 2) and `55-` §5 lists `GET /Serials/{SerialId}/Certificate.pdf`; only real gap was `35-` Related block missing a `55-` back-link, patched in `35-route-blueprint-admin-serials.md:6` |
| CR-10 | F33 (`59-` §5.11 inline literal `<Button>` gap) | 33-42 example snippets | SATISFIED | v0.176.0 | `rg '<Button[^>]*>[A-Z]'` across blueprints 33-42 returns 0 hits; new `check-blueprint-inline-literals.py` linter enforces the ban permanently, 0 findings |

## 4. Acceptance criteria pinning

| AC ID | Statement (from `97-acceptance-criteria.md`) | Status | Evidence |
|-------|-----------------------------------------------|--------|----------|
| AC-CROSS-001 | Every route blueprint (33..42) cites the four required catalogs (`28-`, `51-`, `54-`, `56-`) in `Related:` | SATISFIED | v0.168.0 `check-blueprint-crossrefs.py` 0 findings |
| AC-CROSS-002 | Reserved-word table in `56-` §10 has zero drift across blueprints | SATISFIED | v0.170.0 CR-02 |
| AC-CROSS-003 | Token and shortcut catalogs (`51-`, `54-`, `55-`, `57-`, `58-`) have zero drift across blueprints | SATISFIED | v0.172.0 CR-03..CR-04 |

| AC-CROSS-004 | Every mutation event name matches `58-` §4 canonical registry | SATISFIED | v0.173.0 CR-05 |
| AC-CROSS-005 | Every error UI cites a code from `../21-app/12-error-taxonomy.md` | SATISFIED | v0.173.0 CR-06 |

| AC-CROSS-006 | Every empty / loading state tags a variant from `53-` / `54-` and every illustration cites `52-`; every print / export path cites `55-`; every example snippet resolves labels through `56-` | OPEN | pending CR-07..CR-10 |

## 5. Verification

- `python3 .lovable/coding-guidelines/check-blueprint-crossrefs.py` exits 0.
- `python3 .lovable/coding-guidelines/check-reserved-words.py` exits 0 (one waiver
  entry remaining for `36-route-blueprint-admin-users.md :: Deactivate`, to be
  removed by CR-02).
- No em dashes in this file, verified by `rg -n em-dash-char spec/24-app-ui-design-system/61-*.md`.

## 6. Consumed by

- Plan 08 (`.lovable/plans/done/08-execute-plan-07-steps-01-18.md`) closed all
  ten CR patches (CR-01..CR-10) and shipped ten meta-linters wired into
  `linter-scripts/run.sh`. SATISFIED at v0.177.0. No pending waivers.
- `97-acceptance-criteria.md` mirrors the AC-CROSS-00N rows; keep both in sync.


## Cross-References

- [Cross-Blueprint Audit](./59-cross-blueprint-audit.md)
- [Copy Dictionary](./56-copy-dictionary.md)
- [Keyboard Shortcut Registry](./57-keyboard-shortcut-registry.md)
- [Analytics Event Catalog](./58-analytics-event-catalog.md)
- [Acceptance Criteria](./97-acceptance-criteria.md)
