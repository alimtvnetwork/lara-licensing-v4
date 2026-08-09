# SS-02: Self-Update Endpoint Contract

Parent: 01-blind-ai-audit-folder-21
Slug: self-update-endpoint
Status: pending
Created: 2026-07-16

## Endpoints to add in `spec/21-app/17-self-update-endpoint.md`

### `GET /App/UpdateManifest`

Query: `?platform={windows-x64|linux-x64|darwin-arm64}&channel={stable|beta}&currentVersion=<semver>`

Response (200, PascalCase JSON keys):

```json
{
  "LatestVersion": "1.4.2",
  "MinimumRequiredVersion": "1.2.0",
  "ReleasedAt": "2026-07-16T09:00:00Z",
  "AssetUrl": "https://api.lara.example/App/UpdateAsset/1.4.2/windows-x64",
  "Sha256": "<hex>",
  "SizeBytes": 12345678,
  "ReleaseNotesUrl": "https://…/RELEASE-NOTES.md#1-4-2"
}
```

Cache: 300s edge cache. Rate limit bucket: `update-manifest` (see 14-rate-limiting.md).

### `GET /App/UpdateAsset/{version}/{platform}`

Returns the binary blob with `Content-Type: application/octet-stream`, `X-Sha256: <hex>`, `Content-Length: <bytes>`. Range requests supported.

## Version-probe regex

Mirrors `spec/14-update/09-version-verification.md`:

```
^v?(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)(?:-(?<pre>[0-9A-Za-z.-]+))?$
```

## Error codes

- `UPDATE_MANIFEST_UNAVAILABLE` (503)
- `UPDATE_ASSET_NOT_FOUND` (404)
- `UPDATE_CHECKSUM_MISMATCH` (client-side; audit-logged)
- `UPDATE_VERSION_DOWNGRADE_BLOCKED` (409)

## Handoff flow

Follows `spec/14-update/05-handoff-mechanism.md`: probe → download → verify sha256 → rename-active-to-.bak → rename .new-to-active → spawn handoff copy.
