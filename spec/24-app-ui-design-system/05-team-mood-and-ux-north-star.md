# Team Mood and UX North Star

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`mood` · `ux-north-star` · `personas` · `tone` · `non-goals`

## 1. Team Mood

LaraLicensingV1 is an operations console. The mood is calm, precise, and operator-grade. It is the visual equivalent of a well-maintained control room: quiet surfaces, unambiguous status, keyboard-first, no decorative motion, no marketing gloss inside the authenticated shell. Public surfaces (`/`, `/auth/*`, `/verify`) may carry one restrained hero band but must not shift the interior tone.

Adjectives (permitted): quiet, precise, dense-when-needed, legible, honest, keyboard-first, auditable.

Adjectives (forbidden): playful, whimsical, gamified, glassy, neon, glossy, hero-heavy, ornamental.

## 2. UX North Star (5 Verbs)

Every route, every command, every empty state must serve one of these five verbs. If a proposed surface does not serve one, it does not ship.

| Verb | Actor primarily served | Concrete example |
|------|------------------------|------------------|
| Verify | EndUser, AppBuilder | `/verify`, `POST /Verify/Final` handshake, serial + hash confirmation. |
| Issue | Admin, Reseller | Issue license, mint serial, allocate quota, approve `QuotaRequest`. |
| Renew | Admin, Reseller, EndUser | Extend `ExpiresAtUtc`, upgrade tier, roll environment. |
| Investigate | Admin | Audit search, abuse triage, quota ledger review, request-id lookup. |
| Recover | Admin, Reseller, EndUser | Revoke, rebind device, rotate builder key, reset password, unblock. |

Every blueprint under `29-per-surface-blueprints/` MUST declare which verb(s) it serves in its opening sentence.

## 3. Personas (mapped to `spec/21-app/16-ui-surfaces.md`)

| Persona | Role | Primary verbs | Key constraints |
|---------|------|---------------|-----------------|
| Ops Admin | `Admin` | Issue, Investigate, Recover | Keyboard-first, high-density tables, deep audit trail. |
| Reseller Operator | `Reseller` | Issue, Renew | Quota visibility, single-tenant row-scope, self-service `QuotaRequests`. |
| Integrator | `AppBuilder` | Verify | Swagger console, key rotation, log tail. |
| Licensed Human | `EndUser` | Verify, Renew, Recover | Simple single-column layout, plain language, no admin jargon. |

## 4. Non-Goals

- No dashboard "delight" (confetti, celebratory toasts, sparkles).
- No purple/indigo gradients on white; no generic AI-tool aesthetic.
- No page-specific one-off colors, fonts, or shadow scales.
- No hero animation inside the authenticated shell.
- No decorative illustrations. Icons + text only.
- No marketing copy inside `/admin`, `/reseller`, `/builder`, `/me`.
- No dark-mode-only or light-mode-only surfaces; both themes are peers.

## 5. Operating Principles

1. Status meaning never depends on color alone; every status carries an icon or a label from `spec/21-app/15-license-lifecycle.md`.
2. Every mutation is idempotent-key protected and reports `RequestId` in an Advanced disclosure.
3. Every error surface names the canonical `ErrorCode` from `spec/21-app/12-error-taxonomy.md`.
4. Keyboard operability is the acceptance floor, not an enhancement.
5. Reduced motion is respected without loss of state feedback.
6. Silent failure is a bug: if a surface has no logged outcome, it is not shipped.

## 6. Verification

- AC-ADS-018 (parent plan): every new UI file this folder ships references either a persona or a north-star verb in its opening section.
- AC-ADS-029: every per-surface blueprint links the checklist in `30-checklist-for-new-surface.md` (to be authored in Plan 06 Step 35).

```bash
python3 linter-scripts/check-spec-cross-links.py
python3 linter-scripts/check-forbidden-strings.py
```

## Cross-References

- [Visual Foundations](./01-visual-foundations.md)
- [Shell and Navigation](./02-shell-and-navigation.md)
- [UI Surfaces](../21-app/16-ui-surfaces.md)
- [License Lifecycle](../21-app/15-license-lifecycle.md)
- [Error Taxonomy](../21-app/12-error-taxonomy.md)
