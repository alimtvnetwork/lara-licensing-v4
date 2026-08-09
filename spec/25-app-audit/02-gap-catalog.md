# 02. Gap Catalog

Version: 2.0.0
Updated: 2026-07-19

Every gap that prevents a 10/10. Each row maps to a step range in `03-plan-300-steps.md`.

Severity: 🔴 blocker, 🟠 high, 🟡 medium, 🟢 low.

## Axis A - Spec fidelity (2 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| A-1 | 🟡 | `spec/21-app/17-self-update-endpoint.md` still at v1.0 shape; v1.1.0 3-step publish state machine diff open | 1-6 |
| A-2 | 🟡 | Field-name drift between `spec/21-app/11-api-contracts/*` and `spec/23-app-db/*` (a handful of PascalCase vs snake_case slips) | 7-10 |

## Axis B - Backend implementation (8 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| B-1 | 🟠 | Self-update publish state machine (probe/download/verify + rename + handoff) not wired end-to-end in `SelfUpdateController` | 11-25 |
| B-2 | 🟠 | Portal endpoints for update manifest, download token, and receipt not fully implemented | 26-40 |
| B-3 | 🟡 | Rate-limit middleware only on auth routes, not on portal serial-verify | 41-45 |
| B-4 | 🟡 | Idempotency-key TTL sweep command not scheduled | 46-50 |
| B-5 | 🟠 | Some Admin write paths still use inline validation instead of FormRequest | 51-60 |
| B-6 | 🟡 | `Admin\LicenseController` conflict-context header only on update; not on issue/revoke | 61-65 |
| B-7 | 🟡 | Reseller quota economy debit/credit not transactional across Root+Shard in one path | 66-72 |
| B-8 | 🟡 | `composer.json` version not synced to `package.json` (Plan 10 deferred step 5) | 73-75 |

## Axis C - Frontend implementation (6 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| C-1 | 🟠 | No Admin UI for environment class assignment on License create/edit | 76-85 |
| C-2 | 🟠 | No Admin UI for feature-flag catalog management (view/create/deprecate features) | 86-95 |
| C-3 | 🟡 | No Reseller UI for tier-aware quota view | 96-102 |
| C-4 | 🟡 | Landing page copy is placeholder; no real value prop, no pricing, no FAQ | 103-112 |
| C-5 | 🟡 | Error boundary does not report to a backend telemetry endpoint | 113-118 |
| C-6 | 🟡 | `VITE_API_BASE_URL` not per-env; no `.env.production` template | 119-122 |

## Axis D - Database + shards (5 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| D-1 | 🟠 | `MigrationsAreIdempotentTest` not written (Plan 10 deferred step 6) | 123-130 |
| D-2 | 🟠 | No verified DR drill: dump, restore, verify integrity, replay audit log | 131-142 |
| D-3 | 🟠 | Shard-add runbook missing (how to bring shard N+1 online, backfill routing) | 143-152 |
| D-4 | 🟡 | Grants not asserted by a linter; a new table can ship without a GRANT | 153-158 |
| D-5 | 🟡 | No integrity job that verifies Root user_roles vs Shard membership | 159-164 |

## Axis E - RBAC / Quota / Tier / Env / Features (7 gaps, Plan 05 Layers A-G)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| E-1 | 🟠 | Fine-grained permissions (Spatie-style) not enforced by middleware on every write | 165-175 |
| E-2 | 🟠 | Tier gating (Bronze/Silver/Gold) not checked before license issue | 176-185 |
| E-3 | 🟠 | Environment class gating (Dev/Stage/Prod) not enforced on serial verify | 186-195 |
| E-4 | 🟠 | Feature flags evaluated client-side only; backend authoritative check missing | 196-205 |
| E-5 | 🟡 | Cross-cutting policy consolidation (Plan 05 Layer G) not done | 206-215 |
| E-6 | 🟡 | No "who can approve" matrix documented in `spec/21-app/19-user-management.md` | 216-220 |
| E-7 | 🟡 | Last-admin guard exists in UI, not asserted in backend policy | 221-225 |

## Axis F - Observability + audit (3 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| F-1 | 🟡 | Audit retention policy documented, not enforced by a scheduled sweeper | 226-232 |
| F-2 | 🟡 | No structured-log aggregator config example (Loki/CloudWatch/Papertrail) | 233-238 |
| F-3 | 🟢 | No `/api/public/health/deep` that probes DB, shards, mail, storage | 239-244 |

## Axis G - Test coverage (5 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| G-1 | 🟠 | No load test (k6/Artillery) against license-issue and serial-verify hot paths | 245-252 |
| G-2 | 🟡 | No contract test between FE `lara-*` transport and BE JsonResource shape | 253-260 |
| G-3 | 🟡 | Mutation testing (Infection for PHP, Stryker for TS) not wired | 261-266 |
| G-4 | 🟡 | Playwright storage-state not refreshed per project matrix (Firefox/WebKit) | 267-270 |
| G-5 | 🟢 | No accessibility axe run on portal and reseller routes (only admin covered) | 271-274 |

## Axis H - CI/CD (2 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| H-1 | 🟡 | Branch protection documented, not proven enforced by a policy-as-code check | 275-278 |
| H-2 | 🟡 | `release.yml` does not fail closed on smoke-suite failure against a staging URL | 279-282 |

## Axis I - Deploy + release (5 gaps, this is the big one)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| I-1 | 🔴 | Publish scripts have never been rehearsed against a real cPanel host in-repo | 283-292 |
| I-2 | 🔴 | `VITE_API_BASE_URL` not wired per environment; frontend cannot find backend on published URL | 293-296 |
| I-3 | 🟠 | Self-update binary channel not populated; a deployed client trying to update gets 404 | 297-300 |
| I-4 | 🟠 | No blue/green or canary; a bad migration takes the whole tenant down | (see I-1 range) |
| I-5 | 🟡 | No signed release artifacts; no SBOM | (folded into I-3 range) |

## Axis J - Runbook + ops (5 gaps)

| ID | Sev | Gap | Steps |
|---|---|---|---|
| J-1 | 🟠 | No SuperAdmin bootstrap procedure documented (must be first `/register` before it locks) | (steps 283-292 cover it) |
| J-2 | 🟠 | SMTP setup missing; password reset email not delivered in prod | (steps 283-292 cover it) |
| J-3 | 🟠 | Secret rotation runbook missing (Sanctum, HMAC captcha, DB, SMTP) | (steps 283-292 cover it) |
| J-4 | 🟡 | Uptime monitor wiring missing (e.g. hitting `/api/public/health` from Pingdom) | (steps 283-292 cover it) |
| J-5 | 🟡 | On-call runbook and incident template missing | (steps 283-292 cover it) |

Totals: 48 numbered gaps. Blockers: 3. High: 15. Medium: 26. Low: 4.
