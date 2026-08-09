#!/usr/bin/env python3
"""
Plan 16 Step 98: coverage report matrix.

Emits docs/testing/coverage-matrix.json and docs/testing/coverage-matrix.md
joining Vitest units, Playwright E2E inventory, and the eight API-surface
linter axes into one auditable artifact.

Exit codes:
  0  every mandatory axis passed and artifacts written.
  1  any mandatory axis failed or artifacts could not be written.
"""
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path
from datetime import datetime, timezone

REPO_ROOT = Path(__file__).resolve().parent.parent
VITEST_GLOBS = (
    "src/**/*.test.ts",
    "src/**/*.test.tsx",
    "tests/**/*.test.ts",
    "tests/**/*.test.tsx",
)
E2E_SPECS_DIR = REPO_ROOT / "tests" / "e2e" / "specs"
OUT_JSON = REPO_ROOT / "docs" / "testing" / "coverage-matrix.json"
OUT_MD = REPO_ROOT / "docs" / "testing" / "coverage-matrix.md"

LINTER_AXES = (
    ("UntypedFetch", "linter-scripts/check-untyped-fetch.py"),
    ("AnyInApi", "linter-scripts/check-any-in-api.py"),
    ("MagicEndpointStrings", "linter-scripts/check-magic-endpoint-strings.py"),
    ("PreviewInProdBundle", "linter-scripts/check-preview-in-prod-bundle.py"),
    ("ScreenshotMatrixCoverage", "linter-scripts/check-screenshot-matrix-coverage.py"),
    ("OpenApiDrift", "linter-scripts/check-openapi-drift.py"),
    ("SchemaSymbolDrift", "linter-scripts/check-schema-symbol-drift.py"),
    ("DeadOperations", "linter-scripts/check-dead-operations.py"),
)


def count_vitest_files() -> int:
    total = 0
    for pattern in VITEST_GLOBS:
        total += len(list(REPO_ROOT.glob(pattern)))
    return total


def count_e2e_specs() -> list[str]:
    if not E2E_SPECS_DIR.is_dir():
        return []
    return sorted(p.name for p in E2E_SPECS_DIR.glob("*.spec.ts"))


def run_linter(script_rel: str) -> tuple[bool, str]:
    proc = subprocess.run(
        [sys.executable, str(REPO_ROOT / script_rel)],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        timeout=90,
    )
    tail = (proc.stdout or proc.stderr or "").strip().splitlines()
    summary = tail[-1] if tail else ""
    return proc.returncode == 0, summary


def collect_linter_results() -> list[dict]:
    results = []
    for axis, script in LINTER_AXES:
        ok, summary = run_linter(script)
        results.append({"Axis": axis, "Script": script, "Ok": ok, "Summary": summary})
    return results


def build_payload() -> dict:
    linters = collect_linter_results()
    specs = count_e2e_specs()
    return {
        "GeneratedAt": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "VitestFileCount": count_vitest_files(),
        "E2eSpecCount": len(specs),
        "E2eSpecs": specs,
        "Linters": linters,
        "AllLintersOk": all(item["Ok"] for item in linters),
    }


def render_markdown(payload: dict) -> str:
    lines = ["# Coverage Matrix", "", f"Generated: {payload['GeneratedAt']}", ""]
    lines.append(f"- Vitest files: {payload['VitestFileCount']}")
    lines.append(f"- Playwright specs: {payload['E2eSpecCount']}")
    lines.append(f"- All linters green: {payload['AllLintersOk']}")
    lines.append("")
    lines.append("## Linter axes")
    lines.append("")
    lines.append("| Axis | Ok | Summary |")
    lines.append("| --- | --- | --- |")
    for item in payload["Linters"]:
        ok = "yes" if item["Ok"] else "NO"
        lines.append(f"| {item['Axis']} | {ok} | {item['Summary']} |")
    lines.append("")
    lines.append("## Playwright specs")
    lines.append("")
    for name in payload["E2eSpecs"]:
        lines.append(f"- {name}")
    lines.append("")
    return "\n".join(lines)


def write_outputs(payload: dict) -> None:
    OUT_JSON.parent.mkdir(parents=True, exist_ok=True)
    OUT_JSON.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    OUT_MD.write_text(render_markdown(payload), encoding="utf-8")


def main() -> int:
    payload = build_payload()
    write_outputs(payload)
    if not payload["AllLintersOk"]:
        failed = [item["Axis"] for item in payload["Linters"] if not item["Ok"]]
        print(f"FAIL: linter axes failing: {failed}", file=sys.stderr)
        return 1
    print(
        f"OK: coverage-matrix written "
        f"(Vitest={payload['VitestFileCount']}, "
        f"E2E={payload['E2eSpecCount']}, "
        f"Linters={len(payload['Linters'])} all green)"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
