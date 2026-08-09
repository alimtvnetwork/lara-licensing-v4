#!/usr/bin/env python3
"""Feature registry parity linter.

Enforces `spec/21-app/45-license-features.md` §2 as the sole source of
`FeatureKey` values. Every backtick-quoted, dot-segmented PascalCase
identifier that matches the `FeatureKey` regex and appears anywhere under
`spec/21-app/` MUST be either:

  a) listed in the canonical registry table of `45-license-features.md`, or
  b) listed on the "Forbidden synonyms" line of that same file (which is
     the closed set of strings that MUST return `FeatureUnknown`).

Any other match is spec drift: a stray key that no admin write path or
verify response wire row will accept. Exits non-zero on drift.

Usage:
    python3 linter-scripts/check-feature-registry-parity.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
REGISTRY_PATH = REPO / "spec" / "21-app" / "45-license-features.md"
SEARCH_ROOT = REPO / "spec" / "21-app"

# Backtick-quoted, at least one dot, PascalCase segments per §2.
KEY_RE = re.compile(r"`([A-Z][A-Za-z0-9]+(?:\.[A-Z][A-Za-z0-9]+)+)`")
# Forbidden synonyms are quoted lowercase/snake/camel strings on one line.
FORBIDDEN_RE = re.compile(r"`([^`]+)`")


def load_registry() -> tuple[set[str], set[str]]:
    text = REGISTRY_PATH.read_text(encoding="utf-8")
    # Registry table: rows in §2 whose first cell is a backtick-quoted key.
    registry: set[str] = set()
    for line in text.splitlines():
        if line.startswith("| `") and "|" in line[3:]:
            m = re.match(r"\|\s*`([^`]+)`", line)
            if m:
                registry.add(m.group(1))
    # Forbidden synonyms line.
    forbidden: set[str] = set()
    for line in text.splitlines():
        if line.startswith("Forbidden synonyms"):
            forbidden = set(FORBIDDEN_RE.findall(line))
            break
    return registry, forbidden


def scan_uses() -> dict[str, set[str]]:
    """Return {ac_key: {relative_paths}} for every FeatureKey-shaped match."""
    uses: dict[str, set[str]] = {}
    for path in SEARCH_ROOT.rglob("*.md"):
        if path.resolve() == REGISTRY_PATH.resolve():
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for key in KEY_RE.findall(text):
            uses.setdefault(key, set()).add(
                path.relative_to(REPO).as_posix()
            )
    return uses


def main() -> int:
    registry, forbidden = load_registry()
    if not registry:
        print("ERROR: registry table empty in 45-license-features.md §2", file=sys.stderr)
        return 2
    # Only enforce parity inside the feature namespaces owned by the registry
    # (first segment of every registered key). Other dotted identifiers such
    # as permission keys (`Licenses.Create`), JSON attribute paths
    # (`Attributes.Error`), and column paths (`AuditLogs.Action`) are outside
    # this file's ownership and MUST NOT be flagged here.
    owned_namespaces = {key.split(".", 1)[0] for key in registry}
    uses = scan_uses()
    drift: list[tuple[str, set[str]]] = []
    for key, sources in sorted(uses.items()):
        if key.split(".", 1)[0] not in owned_namespaces:
            continue
        if key in registry:
            continue
        if key in forbidden:
            continue
        drift.append((key, sources))
    if drift:
        print("Feature registry drift detected:", file=sys.stderr)
        for key, sources in drift:
            print(f"  {key}  <-  {', '.join(sorted(sources))}", file=sys.stderr)
        print(
            "Add the key to 45-license-features.md §2, retire it as a "
            "forbidden synonym, or remove the stray reference.",
            file=sys.stderr,
        )
        return 1
    print(
        f"OK: {len(uses)} FeatureKey references, "
        f"{len(registry)} registry entries, {len(forbidden)} forbidden synonyms."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
