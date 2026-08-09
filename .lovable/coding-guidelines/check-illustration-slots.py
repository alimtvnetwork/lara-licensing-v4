#!/usr/bin/env python3
"""check-illustration-slots.py

Enforces `spec/24-app-ui-design-system/52-icon-illustration-registry.md` §10:
illustrations appear ONLY in empty-state and terminal-state surfaces, and
every mention MUST cite `52-icon-illustration-registry.md`.

Rules:
  1. Blueprints 33-, 34-, 36-, 37-, 38-, 39-, 40- are dashboard/table/form
     surfaces per `52-` §10; the word "illustration" (case-insensitive)
     is BANNED there.
  2. Blueprints 35-, 41-, 42- MAY mention illustrations but every mention
     MUST have a `52-icon-illustration-registry.md` citation on the same
     line or within the two preceding lines.
  3. Lines that quote the ban itself (contain BANNED / FORBIDDEN / NEVER
     tokens) are treated as rule references and skipped in rule (1).
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BLUEPRINTS = ROOT / "spec" / "24-app-ui-design-system"

DASHBOARD_ONLY = {"33", "34", "36", "37", "38", "39", "40"}
ALLOWED = {"35", "41", "42"}
CITATION = "52-icon-illustration-registry.md"
RULE_REF = re.compile(r"\b(BANNED|FORBIDDEN|NEVER|banned|forbidden)\b")
ILLUSTRATION = re.compile(r"\billustrations?\b", re.IGNORECASE)


def scan(path: Path) -> list[str]:
    prefix = path.name.split("-", 1)[0]
    findings: list[str] = []
    if prefix not in DASHBOARD_ONLY and prefix not in ALLOWED:
        return findings
    lines = path.read_text().splitlines()
    for i, line in enumerate(lines):
        if not ILLUSTRATION.search(line):
            continue
        if RULE_REF.search(line):
            continue
        if prefix in DASHBOARD_ONLY:
            findings.append(
                f"{path.name}:{i+1} illustration mention in dashboard/table/form "
                f"surface (banned by 52- §10): {line.strip()[:100]}"
            )
            continue
        window = "\n".join(lines[max(0, i - 2): i + 1])
        if CITATION not in window:
            findings.append(
                f"{path.name}:{i+1} illustration mention lacks 52- citation "
                f"within two-line window: {line.strip()[:100]}"
            )
    return findings


def main() -> int:
    files = sorted(BLUEPRINTS.glob("[3-4][0-9]-route-blueprint-*.md"))
    all_findings: list[str] = []
    for f in files:
        all_findings.extend(scan(f))
    print(
        f"check-illustration-slots: {len(files)} blueprint(s) scanned, "
        f"{len(all_findings)} finding(s)"
    )
    for f in all_findings:
        print(f"  {f}")
    return 1 if all_findings else 0


if __name__ == "__main__":
    sys.exit(main())
