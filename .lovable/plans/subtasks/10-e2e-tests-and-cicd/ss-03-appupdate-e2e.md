# SS-03: AppUpdate self-update e2e

Parent: 10-e2e-tests-and-cicd
Slug: appupdate-e2e
Status: pending
Created: 2026-07-19

## Goal

End-to-end proof that the two-phase self-update contract (spec/21-app/17) works: reserve upload ticket, PUT the asset with `X-Sha256`, materialize manifest row, verify via `GET /App/UpdateManifest`, and yank.

## Flow (single Pest Feature test `Admin/AppUpdateFlowTest.php`)

1. Seed admin via `E2EFixturesSeeder`; login; capture bearer.
2. `POST /Admin/AppUpdates/UploadTicket` with `{Platform: WindowsAmd64, Version: 9.9.9, Channel: Stable}`. Assert response `{UploadToken, UploadUrl, ExpiresAt}`.
3. `PUT {UploadUrl}` with a fixture binary (`tests/fixtures/self-update/dummy-windows-amd64.bin`), header `X-Sha256: <sha256>`. Assert 204.
4. `POST /Admin/AppUpdates` with `{UploadToken, Product, Version, Channel, MinRequiredVersion, ReleaseNotesUrl, Sha256, Size}`. Assert 201, manifest row inserted with correct fields.
5. `GET /App/UpdateManifest?Product=LicensingPortalClient&Platform=WindowsAmd64&Channel=Stable&CurrentVersion=0.0.0`. Assert manifest returns 9.9.9 with the sha and size that were stored (not the client-provided values, to catch tamper attempts).
6. `POST /Admin/AppUpdates/{id}/Yank`. Assert row flagged, subsequent manifest call skips this version and returns the prior stable one.

## Negative paths (same test class, separate `it()` blocks)

- Bad sha: `POST /Admin/AppUpdates` with `Sha256` that does not match the stored blob -> 422 `AppUpdateShaMismatch`.
- Bad size: same -> 422 `AppUpdateSizeMismatch`.
- Expired ticket: wait past `ExpiresAt` (freeze time) -> 410 `AppUpdateTicketExpired`.
- Non-admin caller: 403.

## Verification

- `vendor/bin/pest tests/Feature/Admin/AppUpdateFlowTest.php` green.
- Fixture binary committed under `backend/tests/fixtures/self-update/` (small, deterministic).
