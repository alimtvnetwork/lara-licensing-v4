# App UI ,  Design System

**Version:** 0.23.0  
**Updated:** 2026-07-15  
**Status:** Active  
**AI Confidence:** High  
**Ambiguity:** Low

---

## Keywords

`app-ui` · `app-design-system` · `theming` · `components` · `layout`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present | ✅ |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Application-specific UI and design-system specifications for LaraLicensingV1. This module turns the actor and route inventory in `spec/21-app/16-ui-surfaces.md` into an implementable visual language, shell, component-state model, responsive contract, and accessibility criteria.

---

## Document Inventory

| # | File | Purpose |
|---|------|---------|
| 01 | [01-visual-foundations.md](./01-visual-foundations.md) | Typography, semantic colors, spacing, shape, icon, and dark-theme rules. |
| 02 | [02-shell-and-navigation.md](./02-shell-and-navigation.md) | Public and authenticated shells, actor navigation, page headers, and route states. |
| 03 | [03-components-and-states.md](./03-components-and-states.md) | Commands, forms, tables, status, feedback, KPI, and chart contracts. |
| 04 | [04-responsive-and-accessibility.md](./04-responsive-and-accessibility.md) | Responsive behavior, keyboard semantics, contrast, motion, and verification viewports. |
| 97 | [97-acceptance-criteria.md](./97-acceptance-criteria.md) | Testable application UI conformance criteria. |
| 99 | [99-consistency-report.md](./99-consistency-report.md) | Inventory, source alignment, and implementation gap. |

---

## Cross-References

- [Design System (Core)](../07-design-system/00-overview.md) ,  Foundational design system spec
- [App](../21-app/00-overview.md) ,  App-specific features and workflows
- [Consolidated Design System](../17-consolidated-guidelines/07-design-system.md) ,  Consolidated summary

---

*App UI ,  Design System ,  created 2026-04-10, renumbered 23→24 on 2026-04-16, slug renamed `24-app-design-system-and-ui` → `24-app-ui-design-system` on 2026-04-26*

---

## Verification

See [97-acceptance-criteria.md](./97-acceptance-criteria.md) for the full criteria index.

### AC-ADS-000: App UI / design-system conformance: Overview

**Given** the LaraLicensingV1 route inventory and application contracts,  
**When** the UI is implemented and checked at the required viewports,  
**Then** every surface uses semantic tokens, exact lifecycle states, canonical error feedback, and keyboard-operable responsive layouts.

**Verification command:**

```bash
python3 linter-scripts/check-spec-cross-links.py
python3 linter-scripts/check-forbidden-strings.py
```

**Expected:** both commands exit 0. Implementation conformance remains unclaimed until the application routes exist.

_Verification section last updated: 2026-07-15_
