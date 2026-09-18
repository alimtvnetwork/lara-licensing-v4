# SS-03: PowerShell Publish

Parent: 01-blind-ai-audit-folder-21
Slug: powershell-publish
Status: pending
Created: 2026-07-16

## Script

`scripts/publish-lara.ps1`

## Invocation

```pwsh
pwsh scripts/publish-lara.ps1 `
  -Version 1.4.2 `
  -Channel stable `
  -Platforms windows-x64,linux-x64,darwin-arm64 `
  -DryRun:$false
```

## Steps

1. Verify semver.
2. Build per-platform binaries (delegates to existing build task).
3. Compute sha256 per asset; write `checksums.txt`.
4. Upload assets to GitHub Releases via `gh release create v$Version --notes-file RELEASE-NOTES.md`.
5. `POST /App/UpdateManifest` (admin-authenticated) to publish manifest to the Lara host.
6. Emit audit-log `UpdatePublished` on success.

## Exit codes

- `0` success
- `10` semver invalid
- `20` build failed
- `30` checksum failed
- `40` GitHub upload failed
- `50` manifest publish failed

## Dry-run

`-DryRun:$true` performs steps 1-3 only, prints the manifest JSON, does not upload or POST.
