#!/usr/bin/env python3
"""check-empty-state-tags: every empty-state mention in blueprints 33..42
must cite `53-empty-state-catalog.md` AND name at least one of the closed-set
variants (First-run / Filter-reset / Permission-scope) per `53-` §3.

Root-cause rationale: F27, F28 in `59-` §5.7 showed blueprints mentioned
"empty state" or "Empty catalog card" without naming the variant, so runtime
could ship the wrong copy or CTA. This linter forces a variant tag on every
occurrence.

Exit codes: 0 = clean, 1 = findings, 2 = missing inputs.
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC_DIR = ROOT / "spec" / "24-app-ui-design-system"
BLUEPRINTS = sorted(SPEC_DIR.glob("3[3-9]-route-blueprint-*.md")) + sorted(
    SPEC_DIR.glob("4[0-2]-route-blueprint-*.md")
)

# A line "mentions" empty-state when it contains any of these tokens.
MENTION_RE = re.compile(r"\bempty[ -]state|\bEmpty[ -]state|Empty catalog card", re.IGNORECASE)
# Compliance if the line (or the line immediately following in a two-line list
# item) cites 53- AND names a variant.
CATALOG_RE = re.compile(r"53-empty-state-catalog")
VARIANT_RE = re.compile(r"First-run|Filter-reset|Permission-scope")
# Ignore lines that are prose about the catalog itself (headings, related links).
IGNORE_CONTEXT_RE = re.compile(r"^#|^\*\*Related")


def scan(path: Path) -> list[tuple[int, str, str]]:
    lines = path.read_text(encoding="utf-8").splitlines()
    findings: list[tuple[int, str, str]] = []
    for i, line in enumerate(lines):
        if IGNORE_CONTEXT_RE.match(line) or re.match(r"^\s*[-*]\s*AC-", line):
            continue
        if not MENTION_RE.search(line):
            continue
        window = "\n".join(lines[i : i + 2])
        has_catalog = bool(CATALOG_RE.search(window))
        has_variant = bool(VARIANT_RE.search(window))
        if has_catalog and has_variant:
            continue
        missing = []
        if not has_catalog:
            missing.append("53- citation")
        if not has_variant:
            missing.append("variant tag (First-run/Filter-reset/Permission-scope)")
        findings.append((i + 1, line.strip()[:120], ", ".join(missing)))
    return findings


def main() -> int:
    if not BLUEPRINTS:
        print("ERROR: no blueprint files matched", file=sys.stderr)
        return 2
    total = 0
    all_findings: list[tuple[Path, int, str, str]] = []
    for f in BLUEPRINTS:
        for lineno, snippet, missing in scan(f):
            all_findings.append((f, lineno, snippet, missing))
        total += 1
    if not all_findings:
        print(f"check-empty-state-tags: {total} blueprint(s) scanned, 0 finding(s)")
        return 0
    for f, lineno, snippet, missing in all_findings:
        rel = f.relative_to(ROOT)
        print(f"{rel}:{lineno}: empty-state mention missing {missing} - `{snippet}`")
    print(f"check-empty-state-tags: {total} blueprint(s) scanned, {len(all_findings)} finding(s)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
