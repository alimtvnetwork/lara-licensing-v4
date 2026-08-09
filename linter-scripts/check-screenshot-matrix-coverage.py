#!/usr/bin/env python3
"""
Preview screenshot matrix coverage linter (Plan 16 Step 87).

Contract source of truth:
    spec/28-runtime-modes/10-screenshot-matrix.md
    spec/28-runtime-modes/08-preview-scenarios.md (v2)

Root cause this file addresses (one sentence): Step 86 emits
`tests/e2e/screenshots/preview-matrix/index.json` but nothing enforces that
every (route x scenario x seed) cell is present, `ok: true`, and backed by a
PNG on disk, so a silent regression that drops or hides a cell still turns
CI green (INV-SM-01, INV-ERR-04 parity).

Behavior:
    - Loads the manifest and verifies the full cross product of the pinned
      route / scenario / seed closed sets is present.
    - Verifies each `ok: true` cell has its PNG on disk at the declared path.
    - Allows explicit waivers via `waivers.json` (route + scenario + seed +
      reason). Any cell not on the waiver list must be `ok: true`.
    - Missing manifest: soft-pass locally (print notice, exit 0) so
      `lint:api-surface` stays runnable without Playwright. In CI, set
      `SCREENSHOT_MATRIX_STRICT=1` to hard-fail on a missing manifest; the
      preview-screenshot-matrix workflow sets this after rendering.

Exit codes:
    0  OK (or soft-pass when manifest is absent and strict mode is off).
    1  Coverage / freshness / waiver violations.
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ARTIFACT_ROOT = REPO_ROOT / "tests" / "e2e" / "screenshots" / "preview-matrix"
MANIFEST_PATH = ARTIFACT_ROOT / "index.json"
WAIVERS_PATH = ARTIFACT_ROOT / "waivers.json"

# Closed sets - must match tests/e2e/specs/preview-screenshot-matrix.spec.ts
# and spec/28-runtime-modes/08-preview-scenarios.md v2. Any change here MUST
# come with a spec bump in 10-screenshot-matrix.md.
EXPECTED_ROUTES: tuple[str, ...] = (
    "/",
    "/admin/login",
    "/register",
    "/forgot-password",
    "/e2e/error-harness",
)
EXPECTED_SCENARIOS: tuple[str, ...] = ("null", "offline", "slow", "rate-limited")
EXPECTED_SEEDS: tuple[str, ...] = ("default", "empty", "error")


def slug_for_route(route: str) -> str:
    if route == "/":
        return "root"
    return route.lstrip("/").replace("/", "-")


def load_json(path: Path) -> object | None:
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"error: cannot parse {path.relative_to(REPO_ROOT)}: {exc}", file=sys.stderr)
        sys.exit(1)


def load_waivers() -> set[tuple[str, str, str]]:
    data = load_json(WAIVERS_PATH)
    if data is None:
        return set()
    if not isinstance(data, list):
        print(f"error: {WAIVERS_PATH.relative_to(REPO_ROOT)} must be a JSON array", file=sys.stderr)
        sys.exit(1)
    waived: set[tuple[str, str, str]] = set()
    for entry in data:
        if not isinstance(entry, dict) or not entry.get("reason"):
            print(f"error: waiver entry missing 'reason': {entry!r}", file=sys.stderr)
            sys.exit(1)
        waived.add((entry.get("route", ""), entry.get("scenario", ""), entry.get("seed", "")))
    return waived


def index_cells(manifest: dict) -> dict[tuple[str, str, str], dict]:
    cells = manifest.get("cells")
    if not isinstance(cells, list):
        print("error: manifest missing 'cells' array", file=sys.stderr)
        sys.exit(1)
    indexed: dict[tuple[str, str, str], dict] = {}
    for cell in cells:
        key = (cell.get("route"), cell.get("scenario"), cell.get("seed"))
        indexed[key] = cell
    return indexed


def collect_violations(indexed: dict, waived: set[tuple[str, str, str]]) -> list[str]:
    violations: list[str] = []
    for route in EXPECTED_ROUTES:
        for scenario in EXPECTED_SCENARIOS:
            for seed in EXPECTED_SEEDS:
                key = (route, scenario, seed)
                cell = indexed.get(key)
                if cell is None:
                    violations.append(f"missing cell in manifest: {route} scenario={scenario} seed={seed}")
                    continue
                if not cell.get("ok"):
                    if key in waived:
                        continue
                    reason = cell.get("reason") or "(no reason)"
                    violations.append(f"cell failed and not waived: {route} scenario={scenario} seed={seed} reason={reason}")
                    continue
                png_path = ARTIFACT_ROOT / slug_for_route(route) / f"{scenario}.{seed}.png"
                if not png_path.exists():
                    violations.append(f"cell ok=true but PNG missing on disk: {png_path.relative_to(REPO_ROOT)}")
    unexpected = set(indexed.keys()) - {
        (r, s, d) for r in EXPECTED_ROUTES for s in EXPECTED_SCENARIOS for d in EXPECTED_SEEDS
    }
    for key in sorted(unexpected, key=lambda k: tuple(str(x) for x in k)):
        violations.append(f"unexpected cell in manifest (closed set drift): route={key[0]} scenario={key[1]} seed={key[2]}")
    return violations


def main() -> int:
    strict = os.environ.get("SCREENSHOT_MATRIX_STRICT") == "1"
    manifest = load_json(MANIFEST_PATH)
    if manifest is None:
        msg = f"manifest not found at {MANIFEST_PATH.relative_to(REPO_ROOT)}"
        if strict:
            print(f"error: {msg}", file=sys.stderr)
            print("Run `bunx playwright test tests/e2e/specs/preview-screenshot-matrix.spec.ts --project=chromium` first.", file=sys.stderr)
            return 1
        print(f"check-screenshot-matrix-coverage: SKIP ({msg}; set SCREENSHOT_MATRIX_STRICT=1 to fail)")
        return 0
    if not isinstance(manifest, dict):
        print("error: manifest root must be an object", file=sys.stderr)
        return 1
    indexed = index_cells(manifest)
    waived = load_waivers()
    violations = collect_violations(indexed, waived)
    if violations:
        print("Screenshot matrix coverage violations (Plan 16 Step 87):", file=sys.stderr)
        for v in violations:
            print(f"  {v}", file=sys.stderr)
        print(
            "\nFix: re-run the matrix driver, add a waiver in "
            "tests/e2e/screenshots/preview-matrix/waivers.json with a reason, "
            "or update spec/28-runtime-modes/10-screenshot-matrix.md when the "
            "closed sets legitimately change.",
            file=sys.stderr,
        )
        return 1
    total = len(EXPECTED_ROUTES) * len(EXPECTED_SCENARIOS) * len(EXPECTED_SEEDS)
    print(f"check-screenshot-matrix-coverage: OK ({total} cells, {len(waived)} waived)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
