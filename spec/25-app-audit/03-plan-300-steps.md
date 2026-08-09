# 03. 300-Step Plan to Reach 100 / 100 Honest Confidence

Version: 2.0.0
Updated: 2026-07-19
Owning plan mirror: `.lovable/plans/pending/11-audit-v2-remediation-300-steps.md` (to be created next session).

Every step is a single deliverable a blind AI can execute. Order matters: steps unblock later steps. Version bump per landing group (roughly every 5-10 steps).

Legend for target file: `BE` = `backend/`, `FE` = `src/`, `SPEC` = `spec/`, `CI` = `.github/workflows/` or `linter-scripts/`, `DOCS` = `docs/`.

## Group 1 - Spec fidelity (steps 1-10)

1. SPEC: diff `spec/21-app/17-self-update-endpoint.md` vs `spec/14-update/01-self-update-overview.md`; capture delta in `spec/25-app-audit/A1-diff.md`.
2. SPEC: raise `17-self-update-endpoint.md` to v1.1.0 with the 3-step publish state machine (Draft, Staged, Published).
3. SPEC: add sequence diagram (mermaid) for probe / download / verify / rename / handoff.
4. SPEC: cross-link 17 into `spec/21-app/00-overview.md` under "Endpoints".
5. SPEC: add ACs `AC-SU-1..8` to `spec/25-app-audit/97-acceptance-criteria.md` (recreate section).
6. SPEC: bump `99-consistency-report.md` (recreate) after re-scan.
7. SPEC: run `linter-scripts/check-endpoint-permission-parity.py`; capture the drift file.
8. SPEC: rename any PascalCase field in `spec/23-app-db/*` that disagrees with `spec/21-app/11-api-contracts/*`.
9. SPEC: re-run `linter-scripts/ac-index-parity.py` and pin to green.
10. SPEC: add `spec/25-app-audit/A2-field-name-audit.md` with a table of every DB column vs its JSON key.

## Group 2 - Backend self-update + portal + hardening (steps 11-75)

11. BE: scaffold `App\Http\Controllers\SelfUpdate\ProbeController` returning current channel manifest.
12. BE: `DownloadController` streaming from Root storage, gated by device token.
13. BE: `VerifyController` returning SHA-256 + signature envelope.
14. BE: `HandoffController` acknowledging install success + writing `UpdateReceipts`.
15. BE: `App\Services\SelfUpdate\ChannelResolver` mapping tenant to channel (stable/beta).
16. BE: migration `create_update_channels_table` (Root).
17. BE: migration `create_update_manifests_table` (Root).
18. BE: migration `create_update_receipts_table` (Shard).
19. BE: seeder `UpdateChannelsSeeder` with `stable` and `beta`.
20. BE: FormRequest `ProbeUpdateRequest`.
21. BE: Policy `UpdatePolicy@download` scoping by tenant + channel.
22. BE: JsonResource `UpdateManifestResource`.
23. BE: PSR-14 event `UpdatePublished` + listener writing audit + broadcasting.
24. BE: Pest feature `SelfUpdateProbeTest`, `SelfUpdateDownloadTest`, `SelfUpdateVerifyTest`, `SelfUpdateHandoffTest`.
25. BE: append `AC-SU-*` rows to `linter-scripts/ac-test-coverage.py` allowlist.
26. BE: `App\Http\Controllers\Portal\SerialVerifyController` returning verify state + next-step hint.
27. BE: `Portal\UpdateManifestController` (public + signed).
28. BE: `Portal\DownloadTokenController` issuing short-TTL signed URL.
29. BE: `Portal\ReceiptController` accepting install ack.
30. BE: middleware `PortalRateLimit` (bucket per SerialId + IP).
31. BE: FormRequest `SerialVerifyRequest`.
32. BE: JsonResource `SerialVerifyResource`.
33. BE: JsonResource `UpdateManifestResource` (portal variant, minimal fields).
34. BE: policy scoping portal endpoints to Portal keys only (no user tokens).
35. BE: `SignedRequestMiddleware` verifying HMAC and setting `lara.signature.key_id`.
36. BE: Pest `PortalSerialVerifyTest`, `PortalUpdateManifestTest`, `PortalDownloadTokenTest`, `PortalReceiptTest`.
37. BE: audit rows for portal actions with `PortalKeyId` propagation (already in RequestContext).
38. BE: e2e `tests/e2e/specs/portal-serial-lookup.spec.ts` extended to assert new fields.
39. BE: e2e `portal-update-download.spec.ts` extended for receipt round-trip.
40. BE: docs at `docs/backend/portal-endpoints.md`.
41. BE: apply `PortalRateLimit` to `SerialVerifyController`; add Pest for 429.
42. BE: extend `RateLimitAuthMiddleware` to admin login (already in place); assert with Pest.
43. BE: add `Retry-After` header on every 429; add Vitest for `use-retry-after-countdown.ts` parity.
44. BE: add throttle to password-reset request endpoint.
45. BE: Pest for throttle on password-reset request.
46. BE: artisan `idempotency:sweep --older-than=24h` command.
47. BE: register schedule in `routes/console.php` every hour.
48. BE: Pest `IdempotencySweepTest`.
49. BE: doc `docs/backend/idempotency-lifecycle.md`.
50. BE: expose sweep metrics on `/api/public/health/deep` (added later, step 239).
51. BE: audit every `Admin/*Controller` for inline validation; list in `docs/backend/inline-validation-audit.md`.
52. BE: convert `Admin\UserController@update` to `UpdateAdminUserRequest`.
53. BE: convert `Admin\ResellerController@destroy` to `DeleteResellerRequest`.
54. BE: convert `Admin\SessionController@revoke` to `RevokeSessionRequest`.
55. BE: convert `Admin\BindingController@rotate` to `RotateBindingRequest`.
56. BE: convert `Admin\FeatureController@*` (once created in Group 5) to FormRequests.
57. BE: convert `Reseller\LicenseController@issue` to `IssueLicenseRequest`.
58. BE: convert `Reseller\QuotaController@request` to `RequestQuotaRequest`.
59. BE: enable `linter-scripts/check-magic-literals.py` on `backend/app/Http/Controllers`.
60. BE: Pest for each converted controller asserting 422 with typed errors.
61. BE: extend `LicenseController@issue` to set `X-Lara-Conflict-Context` on Precondition failures.
62. BE: same for `@revoke`.
63. BE: same for `@restore`.
64. BE: same for `@transfer`.
65. BE: Pest asserting the header value on each.
66. BE: refactor `QuotaService::debit` and `::credit` into a single `withQuota()` closure that opens a Root + Shard transaction.
67. BE: use `DB::transaction()` with retry on serialisation failure.
68. BE: Pest `QuotaDebitCreditTest` with a deliberate rollback.
69. BE: audit log rows on both sides with a shared `RequestId`.
70. BE: doc `docs/backend/quota-transaction.md`.
71. BE: property test (faker) for 500 debit/credit interleavings.
72. BE: alert on quota drift via a nightly `quota:reconcile` command.
73. BE: pin `backend/composer.json` version to project version.
74. BE: extend `linter-scripts/check-version-sync.py` to three-way (`package.json` + `composer.json` + `README.md`).
75. BE: CI gate: `check-version-sync` in `backend-static-analysis.yml`.

## Group 3 - Frontend surfaces (steps 76-122)

76. FE: `src/lib/lara-environment.ts` add `EnvironmentClass = "Dev" | "Stage" | "Prod"` and CRUD calls.
77. FE: route `src/routes/_authenticated/admin.environments.tsx` listing env classes.
78. FE: dialog `AdminEnvironmentCreate.tsx`.
79. FE: dialog `AdminEnvironmentEdit.tsx`.
80. FE: on `admin.licenses.$id.tsx`, add `<EnvironmentClassPicker />`.
81. FE: License create form: env class required; closed-set validation.
82. FE: Vitest for env-class picker (already `lara-environment.test.ts`; extend).
83. FE: Playwright `admin-environment-crud.spec.ts`.
84. FE: copy dictionary entries for env class.
85. FE: `check-copy-coverage.py` green.
86. FE: `src/lib/lara-features.ts` add feature-catalog CRUD.
87. FE: route `admin.features.tsx` listing features from catalog.
88. FE: dialog `AdminFeatureCreate.tsx`.
89. FE: dialog `AdminFeatureDeprecate.tsx`.
90. FE: on license edit, `<FeatureAssignment />` component with search + toggle.
91. FE: `check-feature-registry-parity.py` extended to require FE + BE + spec parity.
92. FE: Playwright `admin-feature-catalog.spec.ts`.
93. FE: Playwright `admin-license-feature-assignment.spec.ts`.
94. FE: Vitest for `lara-feature-precedence.ts` extended.
95. FE: copy + a11y checks.
96. FE: `src/routes/_authenticated/reseller.quota.tsx` showing tier + used/available.
97. FE: `TierBadge.tsx` (closed set).
98. FE: quota-request wizard: tier-aware max ask.
99. FE: Vitest for tier gate on the wizard.
100. FE: Playwright `reseller-tier-quota.spec.ts`.
101. FE: copy entries for tiers.
102. FE: docs `docs/ui-baselines/tier-badge.png` snapshot.
103. FE: rewrite landing hero copy with real value prop.
104. FE: pricing section (three tiers, no fake numbers, use "starting from" + contact).
105. FE: FAQ section with 6 real questions.
106. FE: testimonial slot (empty state OK) with schema-org placeholder.
107. FE: mobile design pass at 375px and 414px.
108. FE: replace all placeholder images with generated assets.
109. FE: Playwright `landing-copy.spec.ts` (no "Lorem", no "TODO", no "Placeholder").
110. FE: run `check-forbidden-strings.py` with new rules for landing copy.
111. FE: title + description finalized in `src/routes/index.tsx` head().
112. FE: `og:image` generated as `src/assets/og-cover.jpg` and wired.
113. FE: `src/lib/lovable-error-reporting.ts` extended to POST to `/api/public/telemetry`.
114. BE: create `/api/public/telemetry` route with HMAC + rate limit.
115. BE: `telemetry_reports` table (Root) + migration + grants.
116. BE: Pest for signature verification.
117. FE: error boundary posts on `componentDidCatch` (non-blocking, fire-and-forget).
118. FE: Vitest for error-boundary reporting.
119. FE: introduce `.env.production.example`, `.env.staging.example`, `.env.development.example` with `VITE_API_BASE_URL`.
120. FE: `src/lib/lara-api-client.ts` read `import.meta.env.VITE_API_BASE_URL`, fail fast if missing.
121. FE: doc `docs/frontend/environments.md`.
122. CI: add `check-env-examples-in-sync.py` (all `.env.*.example` share the same keys).

## Group 4 - Database + shards (steps 123-164)

123. BE: `tests/Feature/MigrationsAreIdempotentTest.php` running migrations twice and asserting schema hash stable.
124. BE: schema-hash helper via `pg_dump --schema-only` or `sqlite_master`.
125. BE: fixture DB per driver (sqlite for CI, pgsql for nightly).
126. BE: CI matrix in `backend-e2e.yml` add pgsql leg.
127. BE: fix any migration that is not idempotent (guard with `Schema::hasTable`).
128. BE: rerun; commit green.
129. BE: doc `docs/backend/idempotent-migrations.md`.
130. BE: pin to CI required check.
131. DOCS: `docs/ops/dr-drill.md` with a step-by-step: dump Root, dump each shard, wipe, restore.
132. BE: artisan `db:dump --root --all-shards --to=/backups/{ts}`.
133. BE: artisan `db:restore --from=/backups/{ts}`.
134. BE: artisan `db:verify-integrity` checking FK, orphan rows, audit hash chain.
135. BE: Pest `DrDrillTest` running dump/restore/verify on sqlite fixtures.
136. CI: nightly `dr-drill.yml` job.
137. DOCS: `docs/ops/backup-schedule.md`.
138. DOCS: `docs/ops/rto-rpo.md` with numbers (RTO 4h, RPO 24h target).
139. BE: audit hash chain: each row includes `Hash = sha256(prev.Hash || payload)`.
140. BE: migration adding `Hash` column + backfill.
141. BE: Pest `AuditHashChainTest`.
142. DOCS: rehearse DR drill against a staging cPanel; record video-length README note.
143. DOCS: `docs/ops/shard-add.md` with the full runbook (provision DB, register in Root, backfill routing, smoke).
144. BE: artisan `shard:add --dsn=... --slug=...`.
145. BE: artisan `shard:rebalance --dry-run`.
146. BE: artisan `shard:activate --slug=...`.
147. BE: Pest `ShardAddTest` (sqlite in-memory shards).
148. BE: middleware `ShardBindingMiddleware` re-checked to cover the new shard.
149. BE: emit `ShardAdded` audit + metric.
150. FE: Admin surface `admin.shards.tsx` showing shard health.
151. FE: Playwright `admin-shards.spec.ts`.
152. DOCS: `docs/ops/shard-decommission.md`.
153. CI: `linter-scripts/check-grants-per-table.py` scanning migrations for `CREATE TABLE public.*` without a matching `GRANT`.
154. CI: wire into `backend-static-analysis.yml` required.
155. BE: backfill any missing grants.
156. BE: same linter for shard migrations.
157. DOCS: `docs/backend/grants-rules.md`.
158. Pest: assert `pg_has_role` on each table for the intended role (nightly job).
159. BE: artisan `integrity:roles-vs-membership` cross-checking `user_roles` (Root) vs Shard binding.
160. BE: Pest `RolesIntegrityTest`.
161. BE: schedule nightly.
162. BE: alert (Slack/Discord webhook secret) on drift.
163. DOCS: `docs/ops/roles-integrity.md`.
164. BE: dashboard tile in admin overview for last integrity result.

## Group 5 - RBAC / Quota / Tier / Env / Features (steps 165-225)

165. BE: enum `App\Enums\Permission` with every action code (LicenseIssue, LicenseRevoke, ...).
166. BE: table `role_permissions` (Root) + migration + grants.
167. BE: seeder `RolePermissionsSeeder` mapping SuperAdmin/Admin/Reseller/User/Portal to permissions.
168. BE: helper `Gate::before` using `has_permission(uid, code)` SECURITY DEFINER function.
169. BE: middleware `RequirePermission:LicenseIssue` alias.
170. BE: apply to every write route.
171. BE: Pest per route asserting 403 for insufficient role.
172. BE: FE `<RequirePermission>` guard component reading `/me` capabilities.
173. FE: `/me` returns `Permissions[]`.
174. FE: Vitest for the guard.
175. DOCS: `docs/backend/permissions.md` + a matrix generator command.
176. BE: enum `LicenseTier` (Bronze, Silver, Gold).
177. BE: `tiers` table (Root) with quota multipliers + feature includes.
178. BE: middleware `RequireTier` and service `TierService::check`.
179. BE: enforce on `Reseller\LicenseController@issue` (cannot issue Gold if reseller tier is Bronze).
180. BE: Pest across the matrix.
181. FE: tier picker on license form (disabled if not allowed).
182. FE: Vitest for the picker's disabled state.
183. FE: Playwright `reseller-tier-gate.spec.ts`.
184. DOCS: `docs/backend/tiers.md`.
185. FE: copy for tier errors ("Your plan does not include Gold licenses.").
186. BE: enum `EnvironmentClass` (Dev, Stage, Prod).
187. BE: License gets `EnvironmentClassId`.
188. BE: `Portal\SerialVerifyController` refuses if envClass mismatches request hint.
189. BE: Pest `EnvironmentClassGateTest`.
190. FE: verify-final-environment-guard already exists in Vitest; wire runtime.
191. BE: audit event `LicenseEnvironmentDenied`.
192. BE: metrics counter for denials.
193. FE: end-user portal shows env class + last-verified badge.
194. FE: Playwright `portal-env-class.spec.ts`.
195. DOCS: `docs/backend/environment-class.md`.
196. BE: `FeatureService::licenseHas($licenseId, $featureCode)` backed by `LicenseFeatures` table.
197. BE: middleware `RequireFeature:AutoUpdate` alias.
198. BE: apply to self-update endpoints.
199. BE: Pest for each feature-gated endpoint.
200. FE: `useFeature('AutoUpdate')` hook resolving via `/me/features?license=`.
201. FE: gate UI affordances on `useFeature`.
202. FE: Vitest for feature precedence (Reseller override > License > Tier > Default).
203. BE: Pest same precedence server-side.
204. DOCS: `docs/backend/features.md` with precedence diagram.
205. FE: Playwright `admin-feature-precedence.spec.ts`.
206. BE: consolidate all gates into `App\Http\Middleware\PolicyGate` reading a route metadata array.
207. BE: routes declare gates via `->middleware(PolicyGate::for('LicenseIssue', 'Tier:Silver+', 'Feature:MultiSeat'))`.
208. BE: single fail path with typed error `PolicyGateException` mapped by exception handler.
209. BE: Pest exhaustive on the gate combinatorics.
210. FE: `<PolicyGate>` mirror component consuming `/me/gates?route=`.
211. FE: Vitest for the mirror.
212. DOCS: `docs/backend/policy-gate.md`.
213. BE: linter `check-endpoint-permission-parity.py` extended to require gate declaration per route.
214. CI: gate wired required.
215. BE: audit trail: every gate denial written as `PolicyDenied` with the failing predicate.
216. DOCS: extend `spec/21-app/19-user-management.md` with an "approval matrix" (who can approve what).
217. DOCS: matrix generator command `artisan matrix:approvals` outputting markdown.
218. CI: check the generated matrix matches the committed one.
219. DOCS: examples per approval type (quota, tier upgrade, license restore).
220. FE: approval UI shows the matrix inline via help drawer.
221. BE: policy `Admin\UserPolicy@delete` refuses if it would leave zero admins.
222. BE: Pest `LastAdminGuardServerTest`.
223. FE: already has `last-admin-guard.test.tsx`; mirror the copy.
224. BE: same guard on role-downgrade path.
225. DOCS: `docs/backend/last-admin-guard.md`.

## Group 6 - Observability + audit (steps 226-244)

226. BE: artisan `audit:sweep --older-than=365d --archive-to=s3://...`.
227. BE: schedule daily.
228. BE: archived rows verified by hash chain before deletion.
229. BE: Pest `AuditSweepTest`.
230. DOCS: `docs/backend/audit-retention.md`.
231. BE: metric `audit.rows_archived_total`.
232. BE: alert on chain break.
233. DOCS: `docs/ops/log-aggregation.md` with Loki + CloudWatch + Papertrail examples.
234. BE: Monolog channel `structured` writing JSON lines.
235. BE: sample log-shipper config `docs/ops/logshipper.example.yaml`.
236. DOCS: dashboard JSON for Grafana (Loki data source).
237. DOCS: sample alert rules (5xx > 1%, auth 401 spike).
238. DOCS: `docs/ops/on-call-triage.md` referencing the dashboard.
239. BE: `Api\Public\HealthDeepController` probing DB, each shard, mail (SMTP HELO), storage (write+read), Redis if used.
240. BE: JSON envelope with per-check `Ok`, `LatencyMs`, `Error`.
241. BE: rate-limit and cache (10s) to avoid ddos of self.
242. BE: Pest `HealthDeepTest`.
243. FE: admin overview card "System health".
244. DOCS: `docs/ops/health-endpoints.md`.

## Group 7 - Test coverage (steps 245-274)

245. TEST: `tests/load/k6-license-issue.js` targeting 100 RPS.
246. TEST: `tests/load/k6-serial-verify.js` targeting 500 RPS.
247. TEST: `tests/load/README.md` how to run locally.
248. CI: `nightly-load.yml` running k6 against staging URL.
249. TEST: SLO doc `docs/ops/slo.md` (p95 < 300ms issue, < 100ms verify).
250. BE: perf indexes verified via `EXPLAIN` snapshots in `docs/backend/query-plans.md`.
251. BE: N+1 detector (`beyondcode/laravel-query-detector`) in dev.
252. BE: Pest asserting no N+1 on hot endpoints.
253. TEST: `tests/contract/lara-license.contract.test.ts` importing FE zod schema and comparing to BE OpenAPI dump.
254. BE: artisan `openapi:dump --to=docs/openapi.json`.
255. TEST: contract test consumes `docs/openapi.json`.
256. CI: fail build on drift.
257. TEST: same for reseller, user, portal, self-update, features.
258. TEST: `tests/contract/README.md`.
259. TEST: extend to error taxonomy (already `error-taxonomy-parity.test.ts`; unify).
260. CI: contract gate required.
261. TEST: install `infection/infection` for PHP.
262. TEST: install `@stryker-mutator/core` for TS.
263. TEST: baseline MSI thresholds in `infection.json` and `stryker.conf.json`.
264. CI: `mutation.yml` nightly, informational at first.
265. CI: promote to required once MSI >= 60%.
266. DOCS: `docs/testing/mutation.md`.
267. TEST: Playwright `storage-state.ts` regenerated per project (chromium/firefox/webkit) via a `test:e2e:auth:refresh` script.
268. CI: nightly regen with a rotating fixture user.
269. TEST: assert storage-state signature (token expiry) on load.
270. DOCS: update `tests/e2e/README.md`.
271. TEST: `tests/e2e/specs/a11y-portal.spec.ts` axe scan.
272. TEST: `tests/e2e/specs/a11y-reseller.spec.ts` axe scan.
273. CI: fail on any `serious` or `critical` axe violation.
274. DOCS: `docs/testing/a11y.md`.

## Group 8 - CI/CD (steps 275-282)

275. CI: OPA/Conftest policy-as-code checking that GitHub branch protection matches `docs/ci/branch-protection.md`.
276. CI: `.github/workflows/branch-protection-audit.yml` running the check on schedule.
277. CI: PR labeler + required review from CODEOWNERS enforced.
278. DOCS: `docs/ci/policy-as-code.md`.
279. CI: `release.yml` runs `test:e2e:smoke` against the staging URL after publishing frontend and before promoting.
280. CI: gate on p95 latency probe against staging.
281. CI: on failure, auto-rollback via redeploying previous artifact.
282. DOCS: `docs/ci/release-gate.md`.

## Group 9 - Deploy + release (the big one, steps 283-300)

283. OPS: provision a real cPanel host (staging + prod). Record hostnames in `docs/ops/hosts.md` (values in a private note, not the repo).
284. OPS: run `scripts/publish-backend.ps1` against staging; capture stdout/stderr as `docs/ops/first-deploy-log.md`.
285. OPS: run migrations + `RolesSeeder` + `FeatureCatalogSeeder` + `ClosedSetsSeeder`; verify counts.
286. OPS: create first SuperAdmin via `/register` bootstrap; record the closed-registration flip.
287. OPS: run `scripts/publish-frontend.ps1` with `VITE_API_BASE_URL=https://api-staging.example`.
288. OPS: run `test:e2e:smoke` against the staging URL; capture videos.
289. OPS: rehearse rollback: publish v-1 artifact, verify.
290. OPS: SMTP: configure DKIM/SPF/DMARC; verify password reset email lands.
291. OPS: uptime monitor (Better Stack / Pingdom) hitting `/api/public/health`.
292. OPS: on-call rotation + incident template `docs/ops/incident-template.md`.
293. FE: wire per-env `VITE_API_BASE_URL` via `.env.staging.local` and `.env.production.local` (not committed).
294. FE: build-time assertion: fail build if `VITE_API_BASE_URL` is empty in production mode.
295. CI: matrix build with `NODE_ENV=production` and required env vars.
296. DOCS: `docs/ops/env-vars.md`.
297. OPS: publish self-update `stable` channel manifest with signed binary URL to a real object storage bucket.
298. OPS: rehearse a client update end-to-end: install v-1, publish v, client probes, downloads, verifies, hands off, receipt written.
299. OPS: SBOM generation via `cyclonedx-php-composer` + `cyclonedx-bom` for JS; publish alongside release.
300. OPS: sign release artifacts (cosign / minisign); publish public key at `/.well-known/lara-release-signing.pem`.

## Rollout cadence

- Groups 1-2 land as v0.399.0 to v0.410.0 (spec + BE hardening).
- Groups 3-4 land as v0.411.0 to v0.425.0 (FE surfaces + DB safety).
- Group 5 lands as v0.426.0 to v0.445.0 (RBAC/Quota/Tier/Env/Features runtime).
- Groups 6-7 land as v0.446.0 to v0.460.0 (observability + tests).
- Group 8 lands as v0.461.0 to v0.465.0 (CI hardening).
- Group 9 lands as v0.466.0 to v0.475.0 (the real deploy). This is the release-candidate window.
- v0.500.0 = 100/100 audit re-run against this plan.

## Definition of done for 10/10

- All 300 steps checked off.
- Fresh audit re-run scores every axis >= 90.
- Weighted total >= 95.
- One full DR drill rehearsed in the last 30 days.
- One full release rehearsed against staging in the last 7 days.
- Zero blocker or high gaps open.
