#!/usr/bin/env python3
"""
Plan 09 Step 7: heading font-family linter.

Root cause guarded: the `@theme` closed-set test in
`tests/font-tokens-closed-set.test.ts` locks down which `--font-*` tokens
exist, but nothing prevents a component from hard-coding a family via
`font-family: "Inter"`, an inline `style={{ fontFamily: "..." }}`, or a
Tailwind `font-serif` / `font-mono-*` utility. This linter walks the
frontend source tree and fails when any font-family value is not
`var(--font-display)`, `var(--font-sans)`, `var(--font-mono)`, or an
explicit `inherit`. It also bans the Tailwind `font-serif` utility class
outright (no third family is registered under `@theme`).

The linter reads `src/styles.css` @theme once to whitelist declarations
made inside that block itself, so the mandate lives in exactly one
place. Any drift in `styles.css` immediately trips the closed-set test;
any drift in a component immediately trips this script.

Exit code: 0 on clean scan, 1 on any violation. Prints file:line for
each finding so CI logs point directly at the offending source.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "src"
STYLES = SRC / "styles.css"

ALLOWED_FAMILY_VALUES = {
    "var(--font-display)",
    "var(--font-sans)",
    "var(--font-mono)",
    "inherit",
    "initial",
    "unset",
    "revert",
}

FORBIDDEN_CLASSES = {"font-serif"}

FONT_FAMILY_CSS = re.compile(r"font-family\s*:\s*([^;]+);")
FONT_FAMILY_JSX = re.compile(r"fontFamily\s*:\s*([\"'`])(.*?)\1")
CLASSNAME_TOKENS = re.compile(r"\bfont-[a-z0-9-]+\b")


def scan_css(path: Path, allow_theme_declarations: bool) -> list[str]:
    text = path.read_text(encoding="utf-8")
    # Strip comments so `/* mentions font-family */` is not flagged.
    clean = re.sub(r"/\*[\s\S]*?\*/", "", text)
    findings: list[str] = []
    for match in FONT_FAMILY_CSS.finditer(clean):
        value = " ".join(match.group(1).split()).rstrip(";").strip()
        if allow_theme_declarations and _inside_theme_block(clean, match.start()):
            continue
        if value in ALLOWED_FAMILY_VALUES:
            continue
        line = clean[: match.start()].count("\n") + 1
        findings.append(f"{path}:{line}: font-family: {value}")
    return findings


def _inside_theme_block(clean: str, offset: int) -> bool:
    theme_pos = clean.rfind("@theme", 0, offset)
    if theme_pos < 0:
        return False
    open_brace = clean.find("{", theme_pos)
    if open_brace < 0 or open_brace > offset:
        return False
    depth = 0
    for i in range(open_brace, len(clean)):
        ch = clean[i]
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return i > offset
    return False


def scan_tsx(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    findings: list[str] = []
    for match in FONT_FAMILY_JSX.finditer(text):
        raw = match.group(2).strip()
        if raw in ALLOWED_FAMILY_VALUES:
            continue
        line = text[: match.start()].count("\n") + 1
        findings.append(f"{path}:{line}: inline fontFamily: {raw!r}")
    for match in CLASSNAME_TOKENS.finditer(text):
        token = match.group(0)
        if token in FORBIDDEN_CLASSES:
            line = text[: match.start()].count("\n") + 1
            findings.append(f"{path}:{line}: forbidden class {token}")
    return findings


def main() -> int:
    if not STYLES.exists():
        print(f"missing {STYLES}", file=sys.stderr)
        return 1
    findings: list[str] = []
    findings.extend(scan_css(STYLES, allow_theme_declarations=True))
    for path in SRC.rglob("*"):
        if not path.is_file():
            continue
        if path.suffix in {".ts", ".tsx"}:
            findings.extend(scan_tsx(path))
        elif path.suffix == ".css" and path != STYLES:
            findings.extend(scan_css(path, allow_theme_declarations=False))
    if findings:
        print("Heading font linter (Plan 09 Step 7) findings:", file=sys.stderr)
        for f in findings:
            print(f"  {f}", file=sys.stderr)
        print(
            "Allowed font-family values: var(--font-display), var(--font-sans),"
            " var(--font-mono). Ban font-serif utility.",
            file=sys.stderr,
        )
        return 1
    print("check-heading-fonts: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
