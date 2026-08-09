#!/usr/bin/env python3
"""
check-hardcoded-colors.py

Guard against reintroduction of hardcoded color values in `src/**/*.tsx` and
`src/**/*.ts`. Spec: spec/24-app-ui-design-system/07-css-technique-budget.md,
spec/24-app-ui-design-system/08-token-registry.md.

Rules enforced:

  1. No Tailwind color literals that bypass the token system:
     text-white | text-black | bg-white | bg-black
     (plus `border-white|black`, `ring-white|black`, `fill-white|black`,
      `stroke-white|black`).

  2. No hex color literals in JSX/TS source, i.e. patterns matching
     `#[0-9A-Fa-f]{3,8}` when they appear inside a string or JSX class.

  3. No `rgb(`, `rgba(`, `hsl(`, `hsla(` calls in JSX/TS source (design tokens
     are OKLCH per AC-ADS-021 in `08-token-registry.md`).

Allowlist:

  - Lines annotated with `// allow-hardcoded-color: <reason>` are skipped.
  - Files under `src/components/ui/` (shadcn primitives) are checked but any
    exemption there must be justified with the annotation above.
  - Auto-generated files (`routeTree.gen.ts`) are skipped.

Exit codes: 0 clean, 1 findings.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "src"

TW_LITERALS = re.compile(
    r"(?<![\w-])(?:text|bg|border|ring|fill|stroke)-(?:white|black)(?![\w-])"
)
HEX_LITERAL = re.compile(r"#[0-9A-Fa-f]{3,8}\b")
FUNC_LITERAL = re.compile(r"\b(?:rgb|rgba|hsl|hsla)\s*\(")
ALLOW = re.compile(r"//\s*allow-hardcoded-color:")
ALLOW_FILE = re.compile(r"//\s*allow-hardcoded-color-file:")

SKIP_FILES = {"routeTree.gen.ts"}
INCLUDED_SUFFIXES = {".tsx", ".ts"}


def scan_file(path: Path) -> list[tuple[int, str, str]]:
    findings: list[tuple[int, str, str]] = []
    text = path.read_text(encoding="utf-8", errors="replace")
    if ALLOW_FILE.search(text):
        return findings
    lines = text.splitlines()
    for idx, line in enumerate(lines):
        lineno = idx + 1
        prev = lines[idx - 1] if idx > 0 else ""
        if ALLOW.search(line) or ALLOW.search(prev):
            continue
        stripped = line.strip()
        if stripped.startswith("//") or stripped.startswith("*"):
            continue
        if TW_LITERALS.search(line):
            findings.append((lineno, "tailwind-literal", line.rstrip()))
        if HEX_LITERAL.search(line):
            findings.append((lineno, "hex-literal", line.rstrip()))
        if FUNC_LITERAL.search(line):
            findings.append((lineno, "css-color-func", line.rstrip()))
    return findings


def iter_targets() -> list[Path]:
    targets: list[Path] = []
    for path in SRC.rglob("*"):
        if not path.is_file() or path.suffix not in INCLUDED_SUFFIXES:
            continue
        if path.name in SKIP_FILES:
            continue
        targets.append(path)
    return targets


def main() -> int:
    if not SRC.is_dir():
        print(f"error: {SRC} not found", file=sys.stderr)
        return 2
    exit_code = 0
    for path in sorted(iter_targets()):
        findings = scan_file(path)
        if not findings:
            continue
        exit_code = 1
        rel = path.relative_to(ROOT)
        for lineno, kind, line in findings:
            print(f"{rel}:{lineno}: [{kind}] {line}")
    if exit_code == 0:
        print("check-hardcoded-colors: clean")
    else:
        print(
            "\ncheck-hardcoded-colors: findings above must be replaced with "
            "design tokens from spec/24-app-ui-design-system/08-token-registry.md, "
            "or annotated with `// allow-hardcoded-color: <reason>`.",
            file=sys.stderr,
        )
    return exit_code


if __name__ == "__main__":
    sys.exit(main())
