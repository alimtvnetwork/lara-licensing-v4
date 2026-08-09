#!/usr/bin/env python3
"""check-copy-dictionary.py

Meta-linter for `spec/24-app-ui-design-system/56-copy-dictionary.md` §12
and §14. Enforces two rules against the shipped UI tree:

1. Every user-visible string in `<Button>`, `<Toast>`, and error-message JSX
   must resolve through `src/lib/copy.ts` (imported as `copy.*` or via a
   named import from `@/lib/copy`); inline literals are BANNED.
2. Prohibited copy tokens (`Sorry`, `Oops`, `Uh oh`, `Just `, `Simply `,
   `Click here`) MUST NOT appear anywhere under `src/`.

Runs a lightweight regex sweep, not an AST pass, so false positives are
possible; genuine exceptions live in the `WAIVERS` set with a citation.

Exits 0 clean, 1 on drift.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

SRC_DIR = Path(__file__).resolve().parents[2] / "src"

PROHIBITED = ("Sorry", "Oops", "Uh oh", "Just ", "Simply ", "Click here")

# Inline literal inside a <Button>...</Button> body (single-line only; the
# canonical component API renders the label as a prop or a `copy.*` reference).
INLINE_BUTTON = re.compile(r"<Button[^>]*>\s*([A-Z][A-Za-z ]{1,40})\s*</Button>")

WAIVERS: set[str] = set()  # e.g. {"src/components/foo.tsx:42"}


def collect_tsx() -> list[Path]:
    return sorted(SRC_DIR.rglob("*.tsx"))


def scan_prohibited(path: Path, text: str) -> list[str]:
    findings: list[str] = []
    for token in PROHIBITED:
        for i, line in enumerate(text.splitlines(), 1):
            if token in line and f"{path}:{i}" not in WAIVERS:
                findings.append(f"{path.name}:{i}: prohibited copy `{token}` (56- §11)")
    return findings


def scan_inline_button(path: Path, text: str) -> list[str]:
    findings: list[str] = []
    for i, line in enumerate(text.splitlines(), 1):
        for match in INLINE_BUTTON.finditer(line):
            label = match.group(1).strip()
            if label and not label.startswith("{"):
                findings.append(
                    f"{path.name}:{i}: inline <Button> literal `{label}` (route via copy.ts per 56- §12)"
                )
    return findings


def audit_file(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    return scan_prohibited(path, text) + scan_inline_button(path, text)


def main() -> int:
    files = collect_tsx()
    if not files:
        print("check-copy-dictionary: no tsx files found", file=sys.stderr)
        return 1
    findings: list[str] = []
    for f in files:
        findings.extend(audit_file(f))
    for finding in findings:
        print(finding)
    print(
        f"check-copy-dictionary: {len(files)} file(s) scanned, "
        f"{len(findings)} finding(s), {len(WAIVERS)} waiver(s)"
    )
    return 0 if not findings else 1


if __name__ == "__main__":
    sys.exit(main())
