# Command 05: Fluid UI, Ubuntu/Poppins typography, cPanel release automation

Scope: Frontend (`src/`), Laravel backend publish pipeline, CI/CD release workflow.
Captured: 2026-07-19
Verbatim (paraphrased for brevity, intent preserved):
- Build the UI in Lovable so it renders in the preview and connects to the Laravel backend endpoints.
- Provide a PowerShell `run.ps1` that deploys both frontend and backend.
- Provide CI/CD that on every release produces two zip artifacts (backend + frontend) uploadable to cPanel.
- UI must be modern, fluid, professional, not generic. Headers use **Ubuntu**. Body/other text uses **Poppins**.
- Frontend must remain viewable inside Lovable preview.

Applies when:
- Adding or refitting UI surfaces under `src/` for Lara Licensing V1.
- Producing release artifacts for cPanel hosting.
- Wiring CI/CD release workflows.

Rules:
- Load Ubuntu (headings) and Poppins (body) via `<link>` tags in the root route head (`src/routes/__root.tsx`), never via `@import` in `src/styles.css` (Tailwind v4 Lightning CSS constraint).
- Register both families in `@theme` as `--font-display` (Ubuntu) and `--font-sans` (Poppins). All `h1..h6` and `PageHeader` title slots use `font-display`; everything else defaults to `font-sans`.
- Do NOT introduce Next.js. Stack stays TanStack Start + React 19 + Tailwind v4 per `.lovable/overview.md`. "Next.js-like" means modern fluid patterns (App-Router-style layouts, RSC-style data flow via loaders + server functions), not the Next.js framework itself.
- Frontend build output for cPanel = static SPA bundle (`dist/`) zipped as `frontend-vX.Y.Z.zip` with an `.htaccess` for SPA fallback.
- Backend publish already handled by `scripts/publish-laravel.ps1` (see command 04); extend it to also emit `backend-vX.Y.Z.zip`.
- Root `run.ps1` orchestrates both builds and emits both zips into `release/`.
- GitHub Actions release workflow triggers on `v*` tags, runs the same PowerShell script under `pwsh`, and uploads both zips as Release assets.
