# Acceptance Criteria

**Version:** 0.23.0
**Updated:** 2026-07-15
**Status:** Active
**Category:** Testing

## Foundations

- AC-ADS-001: Components use semantic tokens registered in `src/styles.css`; component files contain no raw color literals.
- AC-ADS-002: UI uses IBM Plex Sans and identifiers use JetBrains Mono, with letter spacing set to `0`.
- AC-ADS-003: Light and dark themes preserve all status meanings and WCAG AA contrast.
- AC-ADS-004: Page sections are unframed; cards occur only for repeated records, KPIs, or dialogs and are never nested.

## Shell and Navigation

- AC-ADS-005: Navigation exposes only routes allowed for the server-resolved actor in `16-ui-surfaces.md`.
- AC-ADS-006: Every route has one H1, a visible current navigation state, and an operable mobile navigation sheet.
- AC-ADS-007: Loading, error, forbidden, and empty states preserve shell dimensions and do not expose stale protected content.

## Components and Feedback

- AC-ADS-008: Every mutation prevents duplicate submission and surfaces its result without swallowing errors.
- AC-ADS-009: Failure feedback displays canonical `ErrorCode`; support disclosure displays copyable `RequestId`; rate limiting displays `Retry-After`.
- AC-ADS-010: Tables preserve filter and pagination state in the URL and remain operable at 390px width.
- AC-ADS-011: License and serial states use the exact labels from `15-license-lifecycle.md` plus a non-color cue.
- AC-ADS-012: Irreversible transitions require confirmation naming the affected entity and consequence.

## Responsive and Accessibility

- AC-ADS-013: All functionality is keyboard operable with visible focus and correct overlay focus restoration.
- AC-ADS-014: Normal text, controls, status indicators, and focus rings meet WCAG AA contrast requirements.
- AC-ADS-015: At 200% zoom and the three verification viewports, text and controls neither overlap nor become unreachable.
- AC-ADS-016: Reduced-motion mode removes non-essential animation while preserving state feedback.
- AC-ADS-017: Mobile touch targets are at least 44 by 44px and longest expected identifiers cannot shift fixed toolbars.

## Verification

Specification verification requires the cross-link and forbidden-string linters. Implementation verification additionally requires lint, build, and responsive browser screenshots for light, dark, loading, error, and populated states.

```bash
python3 linter-scripts/check-spec-cross-links.py
python3 linter-scripts/check-forbidden-strings.py
```