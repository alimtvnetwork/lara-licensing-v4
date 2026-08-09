#!/usr/bin/env python3
"""
check-schema-symbol-drift.py - Plan 16 Step 92

Guards `src/generated/api/schema.d.ts` against silent drift from the operations
registry. Every `S.<TypeName>` symbol referenced in `operations.ts` MUST resolve
to an exported `type` or `interface` in `schema.d.ts`, and the referenced set
must match the locked baseline in `schema.symbols.lock.json`.

Failure modes surfaced (never swallowed):
  - Referenced symbol not exported from schema.d.ts (dangling reference).
  - Referenced set differs from lockfile (silent contract change).
  - Zero references parsed (regex broke - hard fail rather than false pass).

Usage:
  python3 linter-scripts/check-schema-symbol-drift.py           # verify
  python3 linter-scripts/check-schema-symbol-drift.py --write   # regen lock
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OPS = ROOT / "src/generated/api/operations.ts"
SCHEMA = ROOT / "src/generated/api/schema.d.ts"
LOCK = ROOT / "src/generated/api/schema.symbols.lock.json"

REF_RE = re.compile(r"\bS\.([A-Z][A-Za-z0-9_]+)")
EXPORT_RE = re.compile(r"^export\s+(?:type|interface)\s+([A-Z][A-Za-z0-9_]+)", re.M)


def read(path: Path) -> str:
    if not path.exists():
        print(f"FAIL: missing file {path.relative_to(ROOT)}", file=sys.stderr)
        sys.exit(1)
    return path.read_text(encoding="utf-8")


def parse_refs(text: str) -> set[str]:
    return set(REF_RE.findall(text))


def parse_exports(text: str) -> set[str]:
    return set(EXPORT_RE.findall(text))


def main() -> int:
    write_mode = "--write" in sys.argv

    refs = parse_refs(read(OPS))
    exports = parse_exports(read(SCHEMA))

    if not refs:
        print("FAIL: parsed zero S.<Type> references from operations.ts (regex broken?)", file=sys.stderr)
        return 1

    dangling = sorted(refs - exports)
    if dangling:
        print("FAIL: schema symbol drift (referenced but not exported):", file=sys.stderr)
        for name in dangling:
            print(f"  MISSING S.{name}", file=sys.stderr)
        return 1

    locked_sorted = sorted(refs)
    if write_mode:
        LOCK.write_text(
            json.dumps({"symbols": locked_sorted}, indent=2) + "\n",
            encoding="utf-8",
        )
        print(f"OK: wrote {LOCK.relative_to(ROOT)} ({len(locked_sorted)} symbols)")
        return 0

    if not LOCK.exists():
        print(f"FAIL: missing lockfile {LOCK.relative_to(ROOT)}. Run with --write.", file=sys.stderr)
        return 1

    try:
        locked = set(json.loads(LOCK.read_text(encoding="utf-8"))["symbols"])
    except (json.JSONDecodeError, KeyError) as exc:
        print(f"FAIL: cannot parse lockfile: {exc}", file=sys.stderr)
        return 1

    added = sorted(refs - locked)
    removed = sorted(locked - refs)
    if added or removed:
        print("FAIL: schema symbol set drifted from lockfile:", file=sys.stderr)
        for name in added:
            print(f"  ADDED   S.{name}", file=sys.stderr)
        for name in removed:
            print(f"  REMOVED S.{name}", file=sys.stderr)
        print("Run: python3 linter-scripts/check-schema-symbol-drift.py --write", file=sys.stderr)
        return 1

    print(f"OK: schema symbol drift check clean ({len(refs)} symbols)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
