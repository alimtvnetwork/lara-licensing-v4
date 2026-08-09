#!/usr/bin/env python3
"""AC index parity linter.

Enforces the discovery rule declared in
`spec/21-app/97-acceptance-criteria.md`: every `AC-*` id that appears in any
source file under `spec/21-app/` or `spec/23-app-db/` (excluding the index
itself) MUST be listed in the index, and vice versa. Emits a non-zero exit
code on any drift and prints the offending ids so CI can surface them.

Usage:
    python3 linter-scripts/ac-index-parity.py
    python3 linter-scripts/ac-index-parity.py --emit   # print regenerated block

The `--emit` mode prints a grouped, sorted list suitable for pasting into the
"## Index" section of the acceptance criteria file. It does NOT modify files.
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import defaultdict
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
INDEX_PATH = REPO / "spec" / "21-app" / "97-acceptance-criteria.md"
SEARCH_ROOTS = [REPO / "spec" / "21-app", REPO / "spec" / "23-app-db"]
AC_RE = re.compile(r"AC-[A-Z]+-[0-9]+")


def collect_source_ids() -> dict[str, set[str]]:
    """Return {relative_source_path: {ac_ids}} excluding the index file."""
    by_file: dict[str, set[str]] = defaultdict(set)
    for root in SEARCH_ROOTS:
        for path in root.rglob("*.md"):
            if path.resolve() == INDEX_PATH.resolve():
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            ids = set(AC_RE.findall(text))
            if ids:
                rel = path.relative_to(REPO).as_posix()
                by_file[rel].update(ids)
    return by_file


def collect_index_ids() -> set[str]:
    text = INDEX_PATH.read_text(encoding="utf-8", errors="replace")
    return set(AC_RE.findall(text))


def emit_block(by_file: dict[str, set[str]]) -> str:
    """Format the canonical grouped block for pasting into the spec."""
    lines: list[str] = []
    for rel in sorted(by_file):
        lines.append(f"\n### {rel}")
        for ac in sorted(by_file[rel]):
            lines.append(f"- {ac} (source: {rel})")
    return "\n".join(lines).lstrip("\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--emit", action="store_true", help="print regenerated grouped block and exit 0")
    args = parser.parse_args()

    by_file = collect_source_ids()
    source_ids: set[str] = set().union(*by_file.values()) if by_file else set()
    index_ids = collect_index_ids()

    if args.emit:
        print(emit_block(by_file))
        return 0

    missing_from_index = sorted(source_ids - index_ids)
    orphan_in_index = sorted(index_ids - source_ids)

    if not missing_from_index and not orphan_in_index:
        print(f"OK ac-index-parity: {len(source_ids)} AC ids reconciled across {len(by_file)} source files")
        return 0

    print("FAIL ac-index-parity: drift detected between source specs and the index")
    print(f"  source files scanned: {len(by_file)}")
    print(f"  source ids: {len(source_ids)}  index ids: {len(index_ids)}")
    if missing_from_index:
        print(f"  in-source but NOT in index ({len(missing_from_index)}):")
        for ac in missing_from_index:
            owners = [rel for rel, ids in by_file.items() if ac in ids]
            print(f"    {ac}  <- {', '.join(owners)}")
    if orphan_in_index:
        print(f"  in-index but NOT in any source ({len(orphan_in_index)}):")
        for ac in orphan_in_index:
            print(f"    {ac}")
    print("\nRun `python3 linter-scripts/ac-index-parity.py --emit` to regenerate the Index block.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
