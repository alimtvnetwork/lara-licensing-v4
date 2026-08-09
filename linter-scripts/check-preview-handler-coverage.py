#!/usr/bin/env python3
"""
Plan 17 Step 14: shell-linter mirror of the vitest preview coverage gate
(`tests/preview-coverage-gate.test.ts`, Plan 16 Step 51, INV-RM-04).

Rule: every operationId declared in `src/generated/api/operations.ts` MUST
have at least one `registerPreviewHandler("<id>", ...)` call under
`src/lib/preview-fixtures/`, unless it appears in the allowlist below.

Rationale: the vitest gate only fires from `bunx vitest run`. This mirror
runs from `linter-scripts/run.sh` so `.lovable/prompts/lint` catches drift
without a full test invocation. Both gates share the same allowlist shape
(currently empty per Plan 16 Step 52).
"""
from __future__ import annotations
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
OPS_FILE = ROOT / "src" / "generated" / "api" / "operations.ts"
FIX_DIR = ROOT / "src" / "lib" / "preview-fixtures"

# Keep in sync with PREVIEW_COVERAGE_ALLOWLIST in
# tests/preview-coverage-gate.test.ts. Empty as of Plan 16 Step 52.
ALLOWLIST: set[str] = set()

# Plan 18 Step 180: future required ops that might not be in operations.ts yet
FUTURE_REQUIRED: set[str] = {
    "auth.session.refresh",
    "admin.licenses.index",
    "admin.serials.show",
    "admin.users.destroy",
    "admin.features.index",
}

OP_RE = re.compile(r'"([a-zA-Z0-9._-]+)"\s*:\s*op<')
REG_RE = re.compile(r'registerPreviewHandler\(\s*"([a-zA-Z0-9._-]+)"')


def collect(path: pathlib.Path, pattern: re.Pattern[str]) -> set[str]:
    return set(pattern.findall(path.read_text(encoding="utf-8")))


def main() -> int:
    if not OPS_FILE.is_file():
        print(f"FAIL: operations file missing: {OPS_FILE}")
        return 1
    universe = collect(OPS_FILE, OP_RE)
    if not universe:
        print("FAIL: operations universe is empty")
        return 1
    registered: set[str] = set()
    for fx in sorted(FIX_DIR.glob("*.ts")):
        registered |= collect(fx, REG_RE)

    missing = ((universe | FUTURE_REQUIRED) - registered) - ALLOWLIST
    stale_allow = ALLOWLIST & registered
    orphan_allow = ALLOWLIST - universe

    ok = True
    if missing:
        ok = False
        print("FAIL: operationIds without a preview handler:")
        for op in sorted(missing):
            print(f"  - {op}")
    if stale_allow:
        ok = False
        print("FAIL: allowlist entries that are now implemented (remove them):")
        for op in sorted(stale_allow):
            print(f"  - {op}")
    if orphan_allow:
        ok = False
        print("FAIL: allowlist entries not present in operations.ts:")
        for op in sorted(orphan_allow):
            print(f"  - {op}")

    if ok:
        print(f"OK: {len(universe)} ops, all registered (allowlist size {len(ALLOWLIST)})")
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())
