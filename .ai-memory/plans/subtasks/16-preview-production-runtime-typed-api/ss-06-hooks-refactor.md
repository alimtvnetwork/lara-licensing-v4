---
Slug: hooks-refactor
Status: pending
Created: 2026-07-20
Parent: 16-preview-production-runtime-typed-api
---

# SS-06: Data hooks refactor to `useApi` / `useApiMutation`

## Target hooks

Enumerate every `src/hooks/use-*.ts` that currently calls `laraFetch` directly and rewrite each to go through `useApi`/`useApiMutation`. Do NOT change public hook signatures during this refactor; the change is internal transport only. If a hook currently returns `unknown` or `any`, tighten the return type at the same time (this refactor is the moment where drift is fixed).

## Rules

- One hook per commit; each commit must keep vitest green.
- Query keys derive from `["op", operationId, params]` so preview and live use the same cache.
- Retry-after propagation stays wired via `useApiMutation`.
- `LaraApiError` remains the surfaced error type; consumers keep working.
- Any hook currently swallowing errors gets a `throw` and lets the global error boundary surface it.

## Verification

- After each hook, run `bunx vitest run src/hooks/<hook>.test.tsx` (add tests where missing).
- End of pass: `bunx tsgo` clean; grep for `laraFetch(` outside `src/lib/api-client.ts` and `src/lib/lara-fetch.ts` returns zero.
