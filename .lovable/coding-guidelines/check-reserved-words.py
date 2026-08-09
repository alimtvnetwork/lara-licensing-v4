#!/usr/bin/env python3
"""check-reserved-words.py

Meta-linter for spec/24-app-ui-design-system/ route blueprints (33..42).

Per 56-copy-dictionary.md §10 reserved-word table, blueprint prose MUST use
the canonical verb for each concept. This linter greps blueprint bodies for
non-canonical synonyms and reports drift.

Baseline: findings F11..F15 in 59-cross-blueprint-audit.md §5.2 are known
drift queued for CR-02 (step 07-03 / plan-08 step 3). While that patch is
pending, matching file+word pairs are listed in the WAIVERS map so this
linter exits 0 on the current tree. As CR-02 lands, waiver entries MUST be
removed from the map (never widened).

Exits 0 on success, 1 when a NEW drift lands outside the waivers map.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

SPEC_DIR = Path(__file__).resolve().parents[2] / "spec" / "24-app-ui-design-system"

# Non-canonical synonym -> canonical replacement per 56- §10.
BANNED_WORDS: dict[str, str] = {
    "Retire": "Retract",
    "Deactivate": "Disable",
    "Kill switch": "Revoke",
}

# Blueprints where the corresponding word is currently waived pending CR-02.
# Empty after CR-02 landed at v0.170.0 (blueprint 36 rewritten Deactivate -> Disable).
WAIVERS: dict[str, set[str]] = {}


BLUEPRINT_GLOBS = ("3[3-9]-route-blueprint-*.md", "4[0-2]-route-blueprint-*.md")


def collect_blueprints() -> list[Path]:
    """Return the list of route blueprint files 33..42."""
    files: list[Path] = []
    for pattern in BLUEPRINT_GLOBS:
        files.extend(SPEC_DIR.glob(pattern))
    return sorted(files)


def scan_word(text: str, word: str) -> list[int]:
    """Return 1-indexed line numbers where `word` appears as a whole word."""
    pattern = re.compile(rf"\b{re.escape(word)}\b")
    return [i + 1 for i, line in enumerate(text.splitlines()) if pattern.search(line)]


def audit_file(path: Path) -> list[str]:
    """Return per-file drift findings; empty list means clean."""
    text = path.read_text(encoding="utf-8")
    waived = WAIVERS.get(path.name, set())
    findings: list[str] = []
    for banned, canonical in BANNED_WORDS.items():
        if banned in waived:
            continue
        for lineno in scan_word(text, banned):
            findings.append(
                f"{path.name}:{lineno}: reserved-word drift `{banned}` (use `{canonical}` per 56- §10)"
            )
    return findings


def main() -> int:
    blueprints = collect_blueprints()
    if not blueprints:
        print("check-reserved-words: no route blueprints found", file=sys.stderr)
        return 1
    findings: list[str] = []
    for bp in blueprints:
        findings.extend(audit_file(bp))
    for finding in findings:
        print(finding)
    print(
        f"check-reserved-words: {len(blueprints)} blueprint(s) scanned, "
        f"{len(findings)} finding(s), {sum(len(v) for v in WAIVERS.values())} waiver(s) pending CR-02"
    )
    return 0 if not findings else 1


if __name__ == "__main__":
    sys.exit(main())
