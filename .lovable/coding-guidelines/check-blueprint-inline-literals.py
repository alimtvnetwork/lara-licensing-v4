#!/usr/bin/env python3
"""check-blueprint-inline-literals.py

Enforces `56-copy-dictionary.md` §12 across route blueprints 33-42:
example snippets MUST NOT ship inline literal strings inside a `<Button>`
element. Contributors copy from examples, so drift in examples becomes
drift in shipped code (which `check-copy-dictionary.py` catches in
`src/**/*.tsx` but not in the specs).

Rules:
  1. Any occurrence of `<Button ...>Word</Button>` (or the same pattern
     inside backticks in prose) is a finding.
  2. Lines containing `BANNED`, `FORBIDDEN`, or `NEVER` are treated as
     rule references and skipped.
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BLUEPRINTS = ROOT / "spec" / "24-app-ui-design-system"

# Match <Button ...>Text</Button> where Text is a bare string (no JSX braces).
INLINE = re.compile(r"<Button\b[^>]*>\s*(?!\{)([A-Z][A-Za-z0-9 .,'!?-]{0,80})</Button>")
RULE_REF = re.compile(r"\b(BANNED|FORBIDDEN|NEVER|banned|forbidden)\b")


def scan(path: Path) -> list[str]:
    findings: list[str] = []
    for i, line in enumerate(path.read_text().splitlines()):
        if RULE_REF.search(line):
            continue
        m = INLINE.search(line)
        if m:
            findings.append(
                f"{path.name}:{i+1} inline literal Button child "
                f"({m.group(1)!r}) violates 56- §12; use copy.buttons.*: "
                f"{line.strip()[:120]}"
            )
    return findings


def main() -> int:
    files = sorted(BLUEPRINTS.glob("[3-4][0-9]-route-blueprint-*.md"))
    all_findings: list[str] = []
    for f in files:
        all_findings.extend(scan(f))
    print(
        f"check-blueprint-inline-literals: {len(files)} blueprint(s) scanned, "
        f"{len(all_findings)} finding(s)"
    )
    for f in all_findings:
        print(f"  {f}")
    return 1 if all_findings else 0


if __name__ == "__main__":
    sys.exit(main())
