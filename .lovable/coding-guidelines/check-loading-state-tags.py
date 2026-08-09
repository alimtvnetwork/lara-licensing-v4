#!/usr/bin/env python3
"""check-loading-state-tags: every skeleton/loading mention in blueprints
33..42 must cite `54-loading-state-catalog.md` AND name at least one of the
closed-set modes (Mode A / Mode B / Mode C / Mode D) per `54-` §2.

Root-cause rationale: F29, F30 in `59-` §5.8 showed blueprints mentioned
"skeleton" without naming the mode, so runtime could ship the wrong timing or
mix modes on the same surface. This linter forces a mode tag near every
skeleton mention.

Scope: only lines that explicitly say "skeleton" (or "skeletons"). Loading
prose that does not mention skeleton (e.g. `RetryAfterBanner`) is out of
scope; those cases are governed by `16-route-shell-states.md`.

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

MENTION_RE = re.compile(r"\bskeletons?\b", re.IGNORECASE)
CATALOG_RE = re.compile(r"54-loading-state-catalog")
MODE_RE = re.compile(r"Mode [ABCD]\b")
IGNORE_CONTEXT_RE = re.compile(r"^#|^\*\*Related")
# Table headers and generic anti-pattern lists that reference skeletons
# without needing a mode tag are still expected to cite the catalog; the
# variant tag becomes mandatory only when the line documents a specific
# runtime behaviour (not a pure list of banned patterns).


def is_bare_banned_pattern(line: str) -> bool:
    """A line like `- Skeleton BANNED during background refetch` does not need
    a mode tag; it references the catalog rule itself. Treat any line that
    contains BANNED or FORBIDDEN plus `skeleton` as a rule reference, not a
    runtime tag."""
    return bool(re.search(r"BANNED|FORBIDDEN", line))


def scan(path: Path) -> list[tuple[int, str, str]]:
    lines = path.read_text(encoding="utf-8").splitlines()
    findings: list[tuple[int, str, str]] = []
    for i, line in enumerate(lines):
        if IGNORE_CONTEXT_RE.match(line) or re.match(r"^\s*[-*]\s*AC-", line):
            continue
        if not MENTION_RE.search(line):
            continue
        if is_bare_banned_pattern(line):
            continue
        window = "\n".join(lines[max(0, i - 1) : i + 2])
        has_catalog = bool(CATALOG_RE.search(window))
        has_mode = bool(MODE_RE.search(window))
        if has_catalog and has_mode:
            continue
        missing = []
        if not has_catalog:
            missing.append("54- citation")
        if not has_mode:
            missing.append("mode tag (Mode A/B/C/D)")
        findings.append((i + 1, line.strip()[:140], ", ".join(missing)))
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
        print(f"check-loading-state-tags: {total} blueprint(s) scanned, 0 finding(s)")
        return 0
    for f, lineno, snippet, missing in all_findings:
        rel = f.relative_to(ROOT)
        print(f"{rel}:{lineno}: skeleton mention missing {missing} - `{snippet}`")
    print(f"check-loading-state-tags: {total} blueprint(s) scanned, {len(all_findings)} finding(s)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
