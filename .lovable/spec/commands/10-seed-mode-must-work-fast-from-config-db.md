# Command 10: Seed mode must work end-to-end, fast, sourced from spec/06 seedable config

Verbatim (paraphrased for capture): "If seeding data is enabled, why is it breaking? Fix it. When we are in seeding, everything should work very fast and the seeding data can come from the seedable config DB — it's in spec folder 06."

## Scope

- Every admin, portal, and reseller route must render successfully in `Mode=preview` with any of `default`, `empty`, `error` seeds; no route may drop to a generic StateCard failure because a preview handler / seed row is missing.
- Preview seed data for closed sets, feature catalog, roles, and other config-tier tables must be derived from `spec/06-seedable-config-architecture/` (fundamentals + features) so backend and preview stay in lockstep.
- Preview boot (runtime resolve → seed dispatch → handler registration) must complete before the first `useApi` query fires; no race, no silent fallback to "backend unreachable" while in seed mode.
- Seed mode SLA: cold reload → first admin list rendered in < 400 ms on a typical laptop; subsequent navigations < 100 ms (IndexedDB warm).

## When it applies

Every task that touches: preview fixtures, preview seeds, runtime-mode boot, admin/reseller/portal data loading, or the seedable-config spec.
