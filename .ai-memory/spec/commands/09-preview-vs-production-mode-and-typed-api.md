# Command 09: Preview vs Production runtime mode, typed API layer, version.json

Scope: entire frontend (`src/`), root repo, `backend/` OpenAPI export.

Command (verbatim intent, paraphrased without em dashes):

1. The frontend must run in one of three modes at any time: `preview` (Lovable in-browser, no backend), `dev` (local backend), `production` (deployed backend). Selection MUST come from a single source of truth at repo root: `version.json`.
2. `version.json` at repo root drives environment. Fields include at minimum: `version`, `mode` (`preview` | `dev` | `production`), `apiBaseUrl`, `previewSeed` (name of seed set to load), `updatedAt`. Admins can flip `mode` and `apiBaseUrl` at runtime from an in-app admin screen; the change persists (writes back to `version.json` via a backend endpoint when a backend is present, or to `localStorage` override in preview mode).
3. In `preview` mode, every screen must work end-to-end against seedable in-memory dummy data. Saves, edits, deletes must appear to persist for the session (in-memory or IndexedDB). No network calls to the real backend.
4. In `dev` / `production` mode, all API calls go through the typed API client and hit the real backend using `apiBaseUrl` from `version.json`.
5. No frontend module may talk to the backend without a typed request and typed response. `unknown`/`any` at API boundaries is banned. Existing `src/lib/lara-*.ts` files must be audited and any drift from the backend contract fixed.
6. Tooling: a generator script must produce TypeScript types from the backend OpenAPI/Swagger spec into `src/generated/api/` (git-tracked but marked auto-generated). Until backend exports a live spec, hand-write the shapes in `src/generated/api/` following the same folder convention so the switchover is a one-line change.
7. Admin UI surface: `Settings -> Runtime` page must expose current mode, `apiBaseUrl`, seed name; allow changing them; show a banner across the app while in preview or dev mode.
8. All mode-branching must go through one helper (`getRuntimeMode()` / `useRuntimeMode()`); no scattered `import.meta.env.MODE` checks.

Rationale: user cannot test UI inside Lovable today because the Laravel backend is not reachable from the preview iframe. A first-class preview mode with seeded fixtures unblocks visual/functional testing without a live backend, and a typed generated API layer prevents contract drift between FE and BE.

Applies to: every new frontend feature going forward, and a one-time retrofit pass on existing screens.
