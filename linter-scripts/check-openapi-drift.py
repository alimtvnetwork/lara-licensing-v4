#!/usr/bin/env python3
"""
Plan 16 Step 91: OpenAPI drift CI.

Root cause this guards (one sentence): the typed API client
(`src/lib/api-client.ts`) and every preview handler assume the operations
map in `src/generated/api/operations.ts` is the single source of truth,
but nothing failed CI when an `operationId`, HTTP method, or URL path was
silently added, renamed, or repointed, so a contract-breaking edit could
merge without a paired review of the backend routes it must match.

What this linter does:
  1. Parse `src/generated/api/operations.ts` and extract every
     `(OperationId, Method, Path)` triple.
  2. Load the committed lockfile `src/generated/api/operations.lock.json`.
  3. Fail if the two disagree on the set, methods, or paths.

Refresh workflow when an operation legitimately changes:
  1. Edit `src/generated/api/operations.ts` in the same commit as the
     backend route change under `backend/routes/api.php`.
  2. Run `python3 linter-scripts/check-openapi-drift.py --write` to
     regenerate the lockfile.
  3. Commit both files. Reviewers see the contract diff in one place.

Wired into `package.json > lint:api-surface` and
`.github/workflows/error-contract.yml`. Silent-failure rule: any parse
error is a hard fail, never a fall-back to "no operations found".
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OPS_TS = ROOT / "src" / "generated" / "api" / "operations.ts"
OPS_LOCK = ROOT / "src" / "generated" / "api" / "operations.lock.json"

OP_PATTERN = re.compile(
    r'"([a-zA-Z0-9._-]+)"\s*:\s*op<[^()]+?>\s*\(\s*'
    r'"(GET|POST|PUT|PATCH|DELETE)"\s*,\s*"([^"]+)"\s*,?\s*\)'
)


def extract_ops(source: str) -> list[dict[str, str]]:
    flat = re.sub(r"\s+", " ", source)
    ops = [
        {"OperationId": op_id, "Method": method, "Path": path}
        for op_id, method, path in OP_PATTERN.findall(flat)
    ]
    ops.sort(key=lambda x: x["OperationId"])
    return ops


def load_lock() -> list[dict[str, str]]:
    if not OPS_LOCK.exists():
        return []
    return json.loads(OPS_LOCK.read_text())


def write_lock(ops: list[dict[str, str]]) -> None:
    OPS_LOCK.write_text(json.dumps(ops, indent=2) + "\n")


def diff(actual: list[dict], expected: list[dict]) -> list[str]:
    a_map = {o["OperationId"]: o for o in actual}
    e_map = {o["OperationId"]: o for o in expected}
    errors: list[str] = []
    for op_id in sorted(set(a_map) - set(e_map)):
        errors.append(f"ADDED operation not in lockfile: {op_id} ({a_map[op_id]['Method']} {a_map[op_id]['Path']})")
    for op_id in sorted(set(e_map) - set(a_map)):
        errors.append(f"REMOVED operation still in lockfile: {op_id} ({e_map[op_id]['Method']} {e_map[op_id]['Path']})")
    for op_id in sorted(set(a_map) & set(e_map)):
        if a_map[op_id] != e_map[op_id]:
            errors.append(
                f"CHANGED {op_id}: was {e_map[op_id]['Method']} {e_map[op_id]['Path']}, "
                f"now {a_map[op_id]['Method']} {a_map[op_id]['Path']}"
            )
    return errors


def main() -> int:
    if not OPS_TS.exists():
        print(f"FAIL: {OPS_TS} not found", file=sys.stderr)
        return 2
    actual = extract_ops(OPS_TS.read_text())
    if not actual:
        print("FAIL: parsed zero operations from operations.ts (parser broken or file empty)", file=sys.stderr)
        return 2
    if "--write" in sys.argv:
        write_lock(actual)
        print(f"OK: wrote {len(actual)} operations to {OPS_LOCK.relative_to(ROOT)}")
        return 0
    expected = load_lock()
    if not expected:
        print(f"FAIL: {OPS_LOCK} missing or empty; run --write once and commit", file=sys.stderr)
        return 2
    errors = diff(actual, expected)
    if errors:
        print(f"FAIL: OpenAPI drift ({len(errors)} issue(s))", file=sys.stderr)
        for e in errors:
            print(f"  - {e}", file=sys.stderr)
        print(
            "\nFix: after intentionally editing operations.ts alongside the "
            "backend route change, run `python3 linter-scripts/check-openapi-drift.py --write` "
            "and commit the updated lockfile in the same PR.",
            file=sys.stderr,
        )
        return 1
    print(f"OK: OpenAPI drift check clean ({len(actual)} operations)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
