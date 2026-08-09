#!/usr/bin/env python3
"""check-blueprint-crossrefs.py

Meta-linter for spec/24-app-ui-design-system/ route blueprints.

Per 59-cross-blueprint-audit.md §9, this linter verifies that every route
blueprint (33-*.md through 42-*.md) declares the catalog back-links required
by 59- §4 in its `Related:` frontmatter block.

Exits 0 on success, 1 on drift. Prints one line per finding.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

SPEC_DIR = Path(__file__).resolve().parents[2] / "spec" / "24-app-ui-design-system"

# Catalogs every route blueprint (33..42) MUST cite in Related per 59- §4.
REQUIRED_CATALOGS = (
    "28-a11y-conformance.md",
    "51-motion-and-reduced-motion.md",
    "54-loading-state-catalog.md",
    "56-copy-dictionary.md",
)

BLUEPRINT_GLOB = "3[3-9]-route-blueprint-*.md"
BLUEPRINT_GLOB_ALT = "4[0-2]-route-blueprint-*.md"


def collect_blueprints() -> list[Path]:
    """Return the list of route blueprint files 33..42."""
    files = list(SPEC_DIR.glob(BLUEPRINT_GLOB)) + list(SPEC_DIR.glob(BLUEPRINT_GLOB_ALT))
    return sorted(files)


def missing_backlinks(text: str) -> list[str]:
    """Return the list of REQUIRED_CATALOGS not referenced in the file text."""
    return [c for c in REQUIRED_CATALOGS if c not in text]


def audit_file(path: Path) -> list[str]:
    """Return per-file drift findings; empty list means clean."""
    text = path.read_text(encoding="utf-8")
    misses = missing_backlinks(text)
    return [f"{path.name}: missing back-link to {c}" for c in misses]


def main() -> int:
    blueprints = collect_blueprints()
    if not blueprints:
        print("check-blueprint-crossrefs: no route blueprints found", file=sys.stderr)
        return 1
    findings: list[str] = []
    for bp in blueprints:
        findings.extend(audit_file(bp))
    for finding in findings:
        print(finding)
    print(
        f"check-blueprint-crossrefs: {len(blueprints)} blueprint(s) scanned, "
        f"{len(findings)} finding(s)"
    )
    return 0 if not findings else 1


if __name__ == "__main__":
    sys.exit(main())
