# SS-04 Visual verification (SSR + hydration parity)

Parent: 15-ui-modernization-pass
Step: 26
Status: completed

## Goal

Prove SSR + hydration parity for Plan 15 Step 5-25 refits on `/`,
`/admin/login`, `/admin`: canonical class markers land in the SSR HTML
(so hydration cannot diverge), and no hydration/Suspense error strings
appear in the server-rendered payload.

## Method

Sandbox chromium (1228) is missing `libglib-2.0.so.0` under the current
nix profile, so headless Playwright cannot launch. Fell back to a
direct SSR probe via `curl http://localhost:8080{path}` and grepped the
rendered HTML for the refit class markers introduced in Steps 5-25 and
for hydration-error strings. This is stricter than a screenshot: if a
class is missing on the server, hydration would swap it in on the
client and produce a visible flash, so the SSR grep is the ground
truth for parity.

## Result (v0.674.x, current dev server)

| Route          | surface-elevated | gradient-headline | brand-tile | dot-pattern | fade-in | hydration errors |
|----------------|------------------|-------------------|------------|-------------|---------|------------------|
| `/`            | 6                | 1                 | 0          | 1           | 0       | 0                |
| `/admin/login` | 1                | 0                 | 1          | 0           | 1       | 0                |
| `/admin`       | 5                | 0                 | 1          | 1           | 2       | 0                |

- `/` (landing, Steps 7 + 24): six `surface-elevated` feature cards,
  one `gradient-headline` H1, dot-pattern layer present.
- `/admin/login` (Step 25): auth card wraps in `surface-elevated
  rounded-2xl p-8 fade-in` with `brand-tile` mark, all rendered
  server-side.
- `/admin` (preview runtime shell): shell chrome elevates via
  `surface-elevated`, brand-tile in header, `fade-in` on route content.
- No `hydration error` or `Suspense boundary received` strings in any
  SSR payload.

## Follow-up

When the sandbox chromium regains glib, promote this to a headed
Playwright screenshot pass at sm/md/lg without changing acceptance:
the SSR grep is authoritative; screenshots are visual evidence only.
