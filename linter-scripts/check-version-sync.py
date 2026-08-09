#!/usr/bin/env python3
"""
Plan 09 Step 85: version-sync linter.

Root cause guarded: the release ritual bumps four sources by hand
(`package.json`, `README.md`, `CHANGELOG.md`, `RELEASE-NOTES.md`) and any
missed edit shipped a version that disagreed with itself. This linter
extracts the version string from each source and fails if they drift.

Sources of truth (all must agree):
  - package.json "version" field
  - backend/composer.json "version" field
  - README.md   first line matching `**Version:** X.Y.Z`
  - CHANGELOG.md      first line matching `## vX.Y.Z`
  - RELEASE-NOTES.md  first line matching `## vX.Y.Z`

Plan 10 Step 5 pinned `backend/composer.json` into the sync set (v0.426.0
onward) so a PHP release that forgets to bump the composer manifest
fails CI at the linter instead of shipping a backend that disagrees with
the SPA about its own version. Wired into `.github/workflows/release.yml`.

Exit code: 0 clean, 1 on any drift. Prints source:line and the value
each source reported so CI logs point directly at the mismatch.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SEMVER = re.compile(r"\d+\.\d+\.\d+")


def read_package_json() -> tuple[str, int]:
    path = ROOT / "package.json"
    data = json.loads(path.read_text(encoding="utf-8"))
    version = str(data.get("version", "")).strip()
    return version, 1


def read_composer_json() -> tuple[str, int]:
    path = ROOT / "backend" / "composer.json"
    text = path.read_text(encoding="utf-8")
    data = json.loads(text)
    version = str(data.get("version", "")).strip()
    line = 0
    for index, raw in enumerate(text.splitlines(), start=1):
        if '"version"' in raw:
            line = index
            break
    return version, line


def read_markdown_version(rel_path: str, pattern: re.Pattern[str]) -> tuple[str, int]:
    path = ROOT / rel_path
    for index, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        match = pattern.search(raw)
        if match:
            return match.group(1), index
    return "", 0


def collect() -> list[tuple[str, str, int]]:
    pkg_version, pkg_line = read_package_json()
    composer_version, composer_line = read_composer_json()
    readme_version, readme_line = read_markdown_version(
        "README.md", re.compile(r"\*\*Version:\*\*\s+(" + SEMVER.pattern + r")")
    )
    changelog_version, changelog_line = read_markdown_version(
        "CHANGELOG.md", re.compile(r"^##\s+v(" + SEMVER.pattern + r")")
    )
    notes_version, notes_line = read_markdown_version(
        "RELEASE-NOTES.md", re.compile(r"^##\s+v(" + SEMVER.pattern + r")")
    )
    return [
        ("package.json", pkg_version, pkg_line),
        ("backend/composer.json", composer_version, composer_line),
        ("README.md", readme_version, readme_line),
        ("CHANGELOG.md", changelog_version, changelog_line),
        ("RELEASE-NOTES.md", notes_version, notes_line),
    ]


def main() -> int:
    rows = collect()
    empty = [name for name, value, _ in rows if value == ""]
    if empty:
        for name, value, line in rows:
            print(f"  {name}:{line} -> {value or '<not found>'}")
        print(f"check-version-sync: missing version in: {', '.join(empty)}", file=sys.stderr)
        return 1
    values = {value for _, value, _ in rows}
    if len(values) != 1:
        print("check-version-sync: DRIFT detected across version sources:", file=sys.stderr)
        for name, value, line in rows:
            print(f"  {name}:{line} -> {value}", file=sys.stderr)
        return 1
    version = next(iter(values))
    print(f"check-version-sync: OK ({version})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
