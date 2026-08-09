#!/usr/bin/env python3
"""check-shortcut-registry.py

Meta-linter for `spec/24-app-ui-design-system/57-keyboard-shortcut-registry.md`.

`57-` §5 pins the global shortcut closed set (`Mod+K`, `?`, `Escape`,
`Mod+/`, `Alt+Shift+D`, `Alt+Shift+S`). Any blueprint under `24-` that
mentions a global shortcut MUST cite `57-` §5 (or §6.<row>) in the same
paragraph so the reader can trace intent. This linter greps blueprint
bodies for shortcut tokens and reports drift when a mention is missing
its `57-` citation.

Exits 0 clean, 1 on drift. Waivers by `path:line` in `WAIVERS`.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

SPEC_DIR = Path(__file__).resolve().parents[2] / "spec" / "24-app-ui-design-system"

# Shortcut tokens that MUST cite 57- when mentioned in blueprints.
SHORTCUT_TOKENS = (
    "Mod+K",
    "Alt+Shift+D",
    "Alt+Shift+S",
    "Mod+/",
)

CITATION = re.compile(r"`57-[^`]*`|57-keyboard-shortcut-registry")

BLUEPRINT_GLOBS = ("3[2-9]-*.md", "4[0-2]-*.md")

WAIVERS: set[str] = set()


def collect_blueprints() -> list[Path]:
    files: list[Path] = []
    for pattern in BLUEPRINT_GLOBS:
        files.extend(SPEC_DIR.glob(pattern))
    return sorted(files)


def audit_file(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    if path.name.startswith("57-"):
        return []
    findings: list[str] = []
    for i, line in enumerate(text.splitlines(), 1):
        if any(tok in line for tok in SHORTCUT_TOKENS) and not CITATION.search(line):
            key = f"{path.name}:{i}"
            if key in WAIVERS:
                continue
            findings.append(f"{key}: shortcut mentioned without `57-` citation")
    return findings


def main() -> int:
    files = collect_blueprints()
    if not files:
        print("check-shortcut-registry: no blueprints found", file=sys.stderr)
        return 1
    findings: list[str] = []
    for f in files:
        findings.extend(audit_file(f))
    for finding in findings:
        print(finding)
    print(
        f"check-shortcut-registry: {len(files)} blueprint(s) scanned, "
        f"{len(findings)} finding(s), {len(WAIVERS)} waiver(s)"
    )
    return 0 if not findings else 1


if __name__ == "__main__":
    sys.exit(main())
