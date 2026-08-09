# Print / Export Stylesheet

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1. Single normative source for print styles, PDF certificate generation, and CSV / JSON export formats.
**Owner:** Print + export governance. Every printable route and every export endpoint MUST cite this document.
**Related:** [`10-token-registry.md`](./10-token-registry.md), [`13-typography.md`](./13-typography.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`34-route-blueprint-admin-licenses.md`](./34-route-blueprint-admin-licenses.md), [`35-route-blueprint-admin-serials.md`](./35-route-blueprint-admin-serials.md), [`37-route-blueprint-admin-quota-approvals.md`](./37-route-blueprint-admin-quota-approvals.md), [`50-swagger-contract.md`](./50-swagger-contract.md), [`52-icon-illustration-registry.md`](./52-icon-illustration-registry.md), [`../21-app/13-audit-logging.md`](../21-app/13-audit-logging.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md).

---

## 1. Purpose and scope

Defines (a) the print stylesheet applied to the SAME HTML the app already renders for a small set of printable routes, (b) the server-side PDF certificate generation for License and Serial, (c) the CSV and JSON export formats for lists (Licenses, Serials, Users, Audit).

Out of scope: Invoice PDFs (invoicing is not in v1 scope; the app records licenses but not billing artefacts). Word / Excel exports (v2).

## 2. Printable routes (closed set)

Only these routes are print-designed. Every other route prints with `body { display: none; @media print }` per §3 rule 8 so the reader gets a clean redirect message rather than a broken table.

| Route | Purpose | Format |
|---|---|---|
| `/admin/licenses/:LicenseId/certificate` | License certificate | 1 page A4 |
| `/admin/serials/:SerialId/certificate` | Serial certificate | 1 page A4 |
| `/admin/quotas/:QuotaRequestId/decision` | Quota decision record for reseller filing | 1 page A4 |
| `/reseller/licenses/:LicenseId/certificate` | Reseller-scope certificate (same content, reseller header) | 1 page A4 |
| `/me/products/:LicenseId/certificate` | End-user certificate | 1 page A4 |

- Five printable routes. Adding a printable route means adding a row here.
- Each printable route renders the certificate CONTENT as regular HTML (with §3 print stylesheet) AND exposes a `Download PDF` Button that hits the server PDF endpoint per §5. Both paths MUST render identical content; drift between print CSS and server PDF is a lint failure per §12.

## 3. Print stylesheet rules

Applied under `@media print` in `src/styles.css` after the runtime tokens. Rules:

1. `@page { size: A4; margin: 20mm 18mm 20mm 18mm; }` fixed A4 with symmetric margins.
2. Colour: force black text on white background. Semantic colour tokens re-mapped in a `@media print` block: `--color-foreground: #000000`, `--color-background: #ffffff`, `--color-border: #000000`. Status colours are DROPPED (Active / Revoked etc. render as text plus a border-boxed label).
3. Typography: switch to a print-friendly stack `Georgia, "Times New Roman", serif` at 11pt body, 18pt title, 13pt section headings. Line-height 1.35. No web fonts (they may not embed in the print PDF).
4. Hide Sidebar, App bar, footer, and all interactive controls via a shared `.print-hide` utility applied to the shell in `__root.tsx`; Buttons / Links inside a certificate are unconditionally hidden with `button, a[role="button"] { display: none; } @media print`.
5. Preserve link URLs by appending `[url]` after every `<a href>` via `a[href]::after { content: " [" attr(href) "]"; }` limited to `@media print`. Excludes anchors and mailto (`a[href^="#"], a[href^="mailto:"] { content: none; }`).
6. Page breaks: `.certificate-section { break-inside: avoid; page-break-inside: avoid; }` on every major certificate block so tables do not split awkwardly.
7. Table borders: force 1px solid black on every certificate table; runtime rounded borders BANNED in print (rounded borders reproduce poorly under toner).
8. Non-printable routes render a `Print not supported for this page. Return to a certificate URL or use CSV export from the list header.` message via a fallback body under `@media print` on every route that is not in the §2 list.
9. Backgrounds: `print-color-adjust: exact` on any element that MUST preserve a background (rare; e.g. the top-of-page brand strip). Otherwise all backgrounds drop to white.
10. Watermarks (`DRAFT`, `REVOKED`) rendered via a fixed-position `<div>` at 45deg rotation, `opacity: 0.15`, `color: #000000`, `font-size: 96pt`. Applied conditionally based on License / Serial status.

## 4. Certificate content contract

Every certificate (License, Serial, Quota decision) MUST include:

- Header: brand mark (SVG, monochrome; NEVER a raster asset in print because it aliases at high DPI) plus product name plus certificate title.
- Metadata block: certificate ID, generated-at ISO 8601 timestamp, generated-by role (Admin / Reseller / End-user), source scope. NEVER the caller's raw email or UserId; a fingerprint per `35-security-events.md` §6 is acceptable.
- Body: license / serial / decision core fields, PascalCase labels sourced from `../21-app/26-route-dto-index.md`.
- Footer: verification instructions (`Verify this certificate by visiting <verify URL> and entering the certificate ID.`), page number `Page 1 of 1`, and a footer band with the app version pin (from `package.json` at build time).
- QR code: 128x128 px encoding the verify URL. Generated server-side; the QR content is the SAME verify URL a human would type. Third-party QR services BANNED (privacy leak + build determinism); use `qrcode` npm package at PDF generation time.

Every certificate MUST NOT include: raw device fingerprints, secrets or install commands, session cookies, or bearer tokens.

## 5. Server PDF generation

- Endpoint: `GET /Licenses/{LicenseId}/Certificate.pdf`, `GET /Serials/{SerialId}/Certificate.pdf`, `GET /QuotaRequests/{QuotaRequestId}/Decision.pdf`.
- Runtime: the Worker generates the PDF via a pure-JS library that ships as WASM or plain JS (do NOT introduce Node-only `puppeteer` or `sharp` per the server runtime constraints). Candidate library `pdf-lib` (pure JS, no native deps) is the default; final selection recorded in `.lovable/decisions/pdf-library.md` when the runtime task lands.
- Content: identical to the printable HTML route (§3, §4). The server PDF renderer consumes the same template data via a shared React component under `src/components/certificates/*` rendered via `renderToStaticMarkup` and re-mapped into PDF primitives OR (preferred) a shared data-only DTO consumed by both React and the PDF renderer. The chosen path is decided when the runtime task lands; the SPEC requires only that the outputs be identical.
- Response headers: `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="License-{LicenseId}-Certificate.pdf"`, `Cache-Control: private, no-store` (certificates are per-user), `X-Content-Type-Options: nosniff`.
- Errors: `LicenseNotFound`, `SerialNotFound`, `QuotaRequestNotFound`, `Forbidden` per `../21-app/12-error-taxonomy.md`. NEVER stream a partial PDF on error; return `ErrorEnvelope` at `application/json` with the appropriate status per `50-swagger-contract.md` §8.
- Audit: every successful PDF fetch logs `CertificateDownloaded` per `../21-app/28-audit-action-enum.md` with the certificate ID, RequestId, and caller Role; the audit row is REQUIRED for legal-defensibility of the certificate as evidence.

## 6. CSV export format

Applies to Admin list surfaces: `/admin/licenses`, `/admin/serials`, `/admin/users`, `/admin/quotas`, `/admin/features`, `/admin/audit` (audit log page, if surfaced in v1).

- Endpoint: `GET /Licenses.csv`, `GET /Serials.csv`, `GET /Users.csv`, `GET /QuotaRequests.csv`, `GET /Features.csv`, `GET /Audit.csv`.
- Query params: SAME filters + search + sort as the list page (`PageIndex` + `PageSize` are IGNORED; the CSV always exports the full filtered set up to a hard cap per §7).
- Encoding: UTF-8 with BOM (`0xEF 0xBB 0xBF`) so Excel opens it correctly; ASCII-only alternative BANNED because it corrupts non-ASCII user data.
- Line ending: CRLF per RFC 4180.
- Delimiter: comma.
- Quoting: RFC 4180 minimal quoting. Every field containing comma / CRLF / double-quote is wrapped in double-quotes with embedded double-quotes doubled.
- Header row: MANDATORY. Column names are PascalCase matching the DTO's JSON keys per `../21-app/26-route-dto-index.md` (e.g. `LicenseId,Status,IssuedAtUtc,ExpiresAtUtc,ResellerId,Tier`). Column order documented in the CSV manifest §9 and stable across releases (adding a column at the END is allowed; reordering is a breaking change).
- Timestamps: ISO 8601 UTC with trailing `Z` (e.g. `2026-07-22T14:03:00Z`), NEVER local time, NEVER Unix epoch.
- Booleans: `true` / `false` lowercase, NEVER `1` / `0` or `TRUE` / `FALSE`.
- Nullable fields: EMPTY cell, NEVER `null` / `NULL` / `-` / `N/A`.
- Enums: rendered as the exact enum VALUE from the closed set (per `50-` §9); localised labels BANNED.
- IDs: rendered as UUID strings unquoted UNLESS the ID contains a hyphen (it always does), then unquoted is still safe because hyphen is not a delimiter. Rule: no special quoting for IDs.
- Response headers: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="{Resource}-{IsoTimestamp}.csv"`, `Cache-Control: private, no-store`.

## 7. Export caps and streaming

- Hard cap: 100000 rows per CSV. Beyond that, the endpoint returns `413 PayloadTooLarge` per `../21-app/12-error-taxonomy.md` with a `RowCount` field in `Error.Details` and a message asking to narrow the filter. Client-side pagination-of-exports BANNED (the resulting file split confuses spreadsheets).
- Streaming: the response is streamed row-by-row (Web Streams `TransformStream`) so the client sees the download start immediately and the Worker's memory footprint is bounded. Buffering the entire CSV in memory BANNED because it violates the Worker memory budget on datasets above ~10 MB.
- Warm-up: the response header block is sent BEFORE the first row is queried so the browser knows to download rather than render. Any error after headers are sent MUST truncate the stream and log `CsvExportTruncated` with the RequestId + rows-emitted count; the client is expected to detect the truncation via the trailing checksum row in §8.
- Concurrency: at most one export per caller at a time. A second concurrent export returns `429 RateLimited` with `Retry-After` per `../21-app/14-rate-limiting.md` §5.

## 8. Checksum trailer

Every CSV MUST end with a checksum row:

```
Checksum,SHA-256,{hex-digest of all preceding rows}
```

- The digest covers the header row and all data rows, in the exact bytes as sent, EXCLUDING the checksum row itself and the trailing CRLF.
- Purpose: the client (or a spreadsheet user) can verify the file arrived complete. This is the ONLY normative form of file-integrity check in v1; ad-hoc content-length checks are insufficient because streaming responses may not set `Content-Length`.
- Row detection: consumers strip the last row if its first column is exactly `Checksum` before importing into a spreadsheet. The checksum row is documented in the CSV manifest so third parties know to expect it.

## 9. CSV column manifest

Per-resource column order documented in the OpenAPI extension `x-csv-columns` on each export operation:

- `Licenses.csv`: `LicenseId, Status, Tier, ResellerId, IssuedAtUtc, ExpiresAtUtc, IssuedByUserFingerprint, RevokedAtUtc, RevokedReason, LastActivityUtc, MachineBindingCount, FeatureCount`.
- `Serials.csv`: `SerialId, LicenseId, Status, IssuedAtUtc, VerifiedAtUtc, DeviceFingerprintHash, RevokedAtUtc, RevokedReason`.
- `Users.csv`: `UserId, EmailFingerprint, Roles, LastSignInUtc, CreatedAtUtc, DisabledAtUtc`.
- `QuotaRequests.csv`: `QuotaRequestId, ResellerId, Tier, RequestedDelta, DecisionStatus, DecidedByUserFingerprint, DecidedAtUtc, DecisionEtag`.
- `Features.csv`: `FeatureKey, Label, DeprecatedAtUtc, LicenseOverrideCount, TierDefaultsHash`.
- `Audit.csv`: `AuditId, ActionKey, ActorRole, ActorFingerprint, TargetKind, TargetId, OccurredAtUtc, RequestId`.

- Raw emails, raw UserId values (except in the primary `UserId` column of `Users.csv`), device fingerprints in plaintext, and secrets NEVER appear in a CSV column.
- Adding a column: MUST be additive AND at the end.
- Removing a column: SemVer major on the export contract; requires 90-day deprecation period per `50-` §10 rules.

## 10. JSON export format

- Endpoint: `GET /{Resource}.json` mirroring the CSV endpoints; response body is the SAME resource collection as the paginated list endpoint but WITHOUT the `PageMeta` (all rows in a single `Data[]`).
- Content-Type: `application/json; charset=utf-8`. UTF-8 no BOM.
- Same hard cap (§7) and same audit rules (§5 audit).
- Same field values as CSV (§6 rules for timestamps, booleans, enums, IDs).
- Envelope: `Response<T[]>` per `50-` §7 with `Meta.RequestId` and `Meta.ServerTimeUtc`. Streaming JSON is DEFERRED (v2); v1 buffers up to the hard cap because JSON is not stream-friendly without JSON Lines and JSON Lines is not what most third parties expect.

## 11. Localisation

- Print + export are ENGLISH-ONLY in v1 (single-locale app). Timestamps are UTC ISO 8601. Number formatting uses US-style `1,234.56` because that is unambiguous when parsed by most spreadsheet defaults; locale-aware `1.234,56` BANNED for exports (import ambiguity).
- Certificates render dates in `2026-07-22 (Jul 22, 2026 UTC)` dual-form so a human reader immediately sees the date without parsing.

## 12. Linter (`check-print-export.py`)

New linter `linter-scripts/check-print-export.py`:

- Verifies every `.csv` / `.json` / `.pdf` export endpoint in `spec/api/paths/*.yaml` carries the `x-csv-columns` / `x-json-envelope` / `x-pdf-template` extension per §9.
- Verifies the OpenAPI response headers include `Content-Disposition` + `Cache-Control: private, no-store` on every export.
- Verifies every certificate route in §2 has a matching PDF endpoint in §5 and both cite the same template component.
- Fails on drift between §9 columns and the DTO field list in `../21-app/26-route-dto-index.md`.
- Fails on any export endpoint missing the checksum trailer contract or the row-cap error response.
- Runs in CI and via `./linter-scripts/run.sh check-print-export`.

## 13. Anti-patterns (BANNED)

1. Print CSS that leaves Sidebar / App bar / Buttons visible.
2. Rounded borders in print.
3. Web fonts in print (must fall back to a serif stack).
4. Raster brand mark in print (must be SVG).
5. Third-party QR services (build determinism + privacy).
6. Certificates containing secrets, install commands, session tokens, or raw device fingerprints.
7. CSV without UTF-8 BOM.
8. CSV using LF line endings (must be CRLF).
9. CSV timestamps in local time or Unix epoch.
10. CSV enums as localised labels.
11. CSV null / N/A placeholders.
12. Raw emails in CSV export columns.
13. Buffering entire CSV in memory (must stream).
14. Client-side pagination of exports.
15. Concurrent exports per caller (must return 429).
16. CSV without checksum trailer.
17. Certificate download WITHOUT audit log entry.
18. Localised number formatting in CSV.
19. Streaming JSON (deferred to v2; v1 buffers).
20. Removing or reordering CSV columns without SemVer major.

## 14. Acceptance criteria

- AC-EXPORT-001: Every printable route in §2 renders identically to its PDF endpoint in §5.
- AC-EXPORT-002: PDFs generated on the Worker use pure-JS or WASM libraries; no Node-only `puppeteer` / `sharp`.
- AC-EXPORT-003: Every CSV export follows the encoding, delimiter, quoting, timestamp, boolean, enum, null, and header rules in §6.
- AC-EXPORT-004: CSV responses are streamed row-by-row and end with the §8 checksum trailer.
- AC-EXPORT-005: Row cap is 100000 with `413 PayloadTooLarge` + `RowCount` in `Error.Details` on overflow.
- AC-EXPORT-006: Every certificate download logs `CertificateDownloaded` per `../21-app/28-audit-action-enum.md`.
- AC-EXPORT-007: Every export endpoint sets `Cache-Control: private, no-store` and `Content-Disposition: attachment; filename=...`.
- AC-EXPORT-008: `check-print-export.py` passes.

## 15. Open items

- Excel `.xlsx` export deferred to v2.
- Streaming JSON (JSON Lines) deferred to v2.
- Invoice PDFs deferred (out of scope; app does not bill).
- Multi-locale certificate rendering deferred (single-locale v1).
- Word `.docx` export deferred (no clear demand; `.pdf` covers legal filing).
