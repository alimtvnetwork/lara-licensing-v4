#!/usr/bin/env python3
"""
Plan 16 Step 12: version.json schema linter.

Root cause guarded: `public/version.json` is now the canonical boot-time
source of truth for the frontend runtime mode (see
`spec/28-runtime-modes/01-version-json-schema.md` and the "Runtime Modes"
section in the root README), but nothing mechanically enforces the
invariants. A stray hand edit can silently ship a malformed artifact:
non-PascalCase keys, non-alphabetical order, a non-null `ApiBaseUrl` in
preview mode, an `http://` `ApiBaseUrl` in production, an `UpdatedAt`
with a `+00:00` offset instead of `Z`, or a `Version` that diverges from
`package.json` / `backend/composer.json`. The resolver in Plan 16 Steps
13-19 would then fail-closed at runtime with `RUNTIME_CONFIG_LOAD_FAILED`
(spec 02, no silent fallback), which is far too late.

This linter validates every rule listed under "Rejected Shapes" in
`spec/28-runtime-modes/01-version-json-schema.md` plus the field rules
in the same file. It runs in CI (wired to `frontend-static-analysis.yml`)
and locally via `python3 linter-scripts/check-version-json.py`.

Exit code: 0 on clean scan, 1 on any violation. Prints one line per
finding so CI logs point directly at the offending key.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERSION_JSON = ROOT / "public" / "version.json"
PACKAGE_JSON = ROOT / "package.json"
COMPOSER_JSON = ROOT / "backend" / "composer.json"

REQUIRED_KEYS = (
    "AllowRuntimeToggle",
    "ApiBaseUrl",
    "Mode",
    "PreviewSeed",
    "UpdatedAt",
    "Version",
)
MODE_ENUM = ("preview", "dev", "production")
PREVIEW_SEED_ENUM = ("default", "empty", "error")
SEMVER_RE = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$")
UPDATED_AT_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$")
DEV_URL_RE = re.compile(r"^http://localhost(:\d+)?/|^https://")
PROD_URL_RE = re.compile(r"^https://")


def read_json(path: Path) -> dict:
    if not path.exists():
        raise FileNotFoundError(f"missing artifact: {path.relative_to(ROOT)}")
    return json.loads(path.read_text(encoding="utf-8"))


def check_keys(doc: dict, findings: list[str]) -> None:
    keys = list(doc.keys())
    missing = [k for k in REQUIRED_KEYS if k not in keys]
    extras = [k for k in keys if k not in REQUIRED_KEYS]
    for k in missing:
        findings.append(f"version.json: missing required key '{k}'")
    for k in extras:
        findings.append(f"version.json: forbidden extra key '{k}' (additionalProperties=false)")
    if keys != sorted(keys):
        findings.append(f"version.json: keys not alphabetical, got {keys}, want {sorted(keys)}")


def check_mode_and_api(mode: object, api: object, findings: list[str]) -> None:
    if mode not in MODE_ENUM:
        findings.append(f"version.json: Mode='{mode}' not in {MODE_ENUM}")
        return
    if mode == "preview" and api is not None:
        findings.append(f"version.json: Mode='preview' requires ApiBaseUrl=null, got {api!r}")
    if mode == "dev" and (not isinstance(api, str) or not DEV_URL_RE.match(api)):
        findings.append(f"version.json: Mode='dev' requires ApiBaseUrl matching ^http://localhost(:port)?/ or ^https://, got {api!r}")
    if mode == "production" and (not isinstance(api, str) or not PROD_URL_RE.match(api)):
        findings.append(f"version.json: Mode='production' requires ApiBaseUrl starting https://, got {api!r}")


def check_scalars(doc: dict, findings: list[str]) -> None:
    if not isinstance(doc.get("Version"), str) or not SEMVER_RE.match(str(doc.get("Version", ""))):
        findings.append(f"version.json: Version={doc.get('Version')!r} must match SemVer MAJOR.MINOR.PATCH")
    if doc.get("PreviewSeed") not in PREVIEW_SEED_ENUM:
        findings.append(f"version.json: PreviewSeed='{doc.get('PreviewSeed')}' not in {PREVIEW_SEED_ENUM}")
    if not isinstance(doc.get("UpdatedAt"), str) or not UPDATED_AT_RE.match(str(doc.get("UpdatedAt", ""))):
        findings.append(f"version.json: UpdatedAt={doc.get('UpdatedAt')!r} must be RFC-3339 with Z suffix")
    if not isinstance(doc.get("AllowRuntimeToggle"), bool):
        findings.append(f"version.json: AllowRuntimeToggle={doc.get('AllowRuntimeToggle')!r} must be boolean")


def check_version_parity(doc: dict, findings: list[str]) -> None:
    v = doc.get("Version")
    pkg = read_json(PACKAGE_JSON).get("version")
    cmp = read_json(COMPOSER_JSON).get("version")
    if v != pkg:
        findings.append(f"version.json.Version='{v}' != package.json.version='{pkg}' (INV-RM-09)")
    if v != cmp:
        findings.append(f"version.json.Version='{v}' != backend/composer.json.version='{cmp}' (INV-RM-09)")


def main() -> int:
    findings: list[str] = []
    try:
        doc = read_json(VERSION_JSON)
    except (FileNotFoundError, json.JSONDecodeError) as err:
        print(f"check-version-json: {err}", file=sys.stderr)
        return 1
    check_keys(doc, findings)
    check_scalars(doc, findings)
    check_mode_and_api(doc.get("Mode"), doc.get("ApiBaseUrl"), findings)
    check_version_parity(doc, findings)
    if findings:
        print("check-version-json: FAIL", file=sys.stderr)
        for f in findings:
            print(f"  - {f}", file=sys.stderr)
        return 1
    print(f"check-version-json: OK ({VERSION_JSON.relative_to(ROOT)}, Version={doc['Version']}, Mode={doc['Mode']})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
