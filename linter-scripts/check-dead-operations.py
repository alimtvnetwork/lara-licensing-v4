#!/usr/bin/env python3
"""
Plan 16 Step 96: dead-operation linter (final wire-up).

Root cause this guards (one sentence): `src/generated/api/operations.ts`
locks the typed contract and `operations.lock.json` guards drift, but
nothing failed CI when an operation was defined and typed yet had zero
`registerPreviewHandler("<op.id>", ...)` binding in
`src/lib/preview-fixtures/**`, so a preview-mode call for that op would
fail at runtime with `HandlerNotFound` while CI stayed green.

What this linter does:
  1. Parse `src/generated/api/operations.ts` for every OperationId key.
  2. Scan `src/lib/preview-fixtures/**/*.ts` for
     `registerPreviewHandler("<op.id>", ...)` occurrences.
  3. Fail if any Operation has zero preview registrations
     (dead-in-preview) or more than one (double-registration).

Silent-failure rule: any parse error is a hard fail, never a fall-back
to "no operations found" or "no handlers found". A zero result on either
side of the diff aborts with a non-zero exit and a named list.

Wired into `package.json > lint:api-surface` and
`.github/workflows/error-contract.yml`.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OPS_FILE = ROOT / "src" / "generated" / "api" / "operations.ts"
FIXTURES_DIR = ROOT / "src" / "lib" / "preview-fixtures"

OP_KEY_RE = re.compile(r'^\s*"([a-z][a-z0-9\-]*(?:\.[a-z0-9\-]+)+)"\s*:\s*op<', re.MULTILINE)
HANDLER_RE = re.compile(r'registerPreviewHandler\(\s*"([a-z][a-z0-9\-]*(?:\.[a-z0-9\-]+)+)"')


def parse_operations() -> list[str]:
    if not OPS_FILE.is_file():
        print(f"FAIL: operations file not found: {OPS_FILE}", file=sys.stderr)
        sys.exit(2)
    text = OPS_FILE.read_text(encoding="utf-8")
    ops = OP_KEY_RE.findall(text)
    if not ops:
        print("FAIL: parsed zero operations from operations.ts (regex drift?)", file=sys.stderr)
        sys.exit(2)
    return ops


def parse_handlers() -> dict[str, list[Path]]:
    if not FIXTURES_DIR.is_dir():
        print(f"FAIL: preview fixtures dir not found: {FIXTURES_DIR}", file=sys.stderr)
        sys.exit(2)
    hits: dict[str, list[Path]] = {}
    files = sorted(FIXTURES_DIR.rglob("*.ts"))
    if not files:
        print("FAIL: no preview fixture files scanned", file=sys.stderr)
        sys.exit(2)
    for f in files:
        for op_id in HANDLER_RE.findall(f.read_text(encoding="utf-8")):
            hits.setdefault(op_id, []).append(f.relative_to(ROOT))
    return hits


def main() -> int:
    ops = parse_operations()
    handlers = parse_handlers()

    missing = [op for op in ops if op not in handlers]
    duplicated = {op: paths for op, paths in handlers.items() if len(paths) > 1}
    unknown = [op for op in handlers.keys() if op not in ops]

    problems = 0
    if missing:
        problems += 1
        print("FAIL: operations without a preview handler (dead-in-preview):", file=sys.stderr)
        for op in missing:
            print(f"  - {op}", file=sys.stderr)
    if duplicated:
        problems += 1
        print("FAIL: operations with more than one registerPreviewHandler():", file=sys.stderr)
        for op, paths in duplicated.items():
            print(f"  - {op}", file=sys.stderr)
            for p in paths:
                print(f"      {p}", file=sys.stderr)
    if unknown:
        problems += 1
        print("FAIL: preview handlers reference unknown operation ids:", file=sys.stderr)
        for op in unknown:
            print(f"  - {op}", file=sys.stderr)

    if problems:
        return 1
    print(f"OK: {len(ops)} operations, each with exactly one preview handler.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
