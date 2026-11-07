#!/usr/bin/env python3
"""53-lint-design-tokens.py - Design-token linter (402 item 010).

Flags raw hex color literals in Go source and embedded CSS that are not
routed through the canonical Tokyo Night tokens in cli/termout/themes.go.
A hex value that matches a token value is allowed (sanctioned literal
fallbacks such as SafeAdaptiveStyle arguments); anything else must be
migrated to a termout token (TokyoPrimary, TokyoOK, TokyoWarn, ...) or
added to the token set first.

Scope:
  - cli/ Go files (excluding cli/termout/ itself and *_test.go files)
  - embedded CSS inside cli/cmddb/*.go and cli/promptdb/*.go

Usage:
  gitmap py 03-ai-scripts/53-lint-design-tokens.py [--allowlist FILE] [--json]
  gitmap py 03-ai-scripts/53-lint-design-tokens.py --allowlist .ai-memory/design-token-allowlist.txt

  --allowlist  One hex value per line (case-insensitive, leading # optional).
  --json       Emit violations as a JSON array instead of human-readable lines.

Exit code: 0 when no violations, 1 when violations are found.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
TERMOUT_DIR = REPO_ROOT / "cli" / "termout"
CLI_DIR = REPO_ROOT / "cli"
EMBEDDED_CSS_DIRS = [CLI_DIR / "cmddb", CLI_DIR / "promptdb"]

HEX_RE = re.compile(r"#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b")
TOKEN_DEF_RE = re.compile(r'^\s*Tokyo\w+\s*=\s*"(#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}))"')


def load_token_hexes() -> set[str]:
    """Parse canonical token values from cli/termout/themes.go."""
    tokens: set[str] = set()
    themes = TERMOUT_DIR / "themes.go"
    if not themes.exists():
        return tokens
    for line in themes.read_text(encoding="utf-8", errors="ignore").splitlines():
        match = TOKEN_DEF_RE.match(line)
        if match:
            tokens.add(match.group(1).lower())
    return tokens


def load_allowlist(path: str | None) -> set[str]:
    allowed: set[str] = set()
    if not path:
        return allowed
    for line in Path(path).read_text(encoding="utf-8", errors="ignore").splitlines():
        value = line.strip()
        if not value or value.startswith("//"):
            continue
        if not value.startswith("#"):
            value = "#" + value
        allowed.add(value.lower())
    return allowed


def scan_file(path: Path, allowed: set[str], kind: str) -> list[dict]:
    violations: list[dict] = []
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return violations
    for lineno, line in enumerate(text.splitlines(), start=1):
        for match in HEX_RE.finditer(line):
            value = match.group(0).lower()
            if value in allowed:
                continue
            violations.append({
                "file": str(path.relative_to(REPO_ROOT)),
                "line": lineno,
                "hex": match.group(0),
                "kind": kind,
                "context": line.strip()[:120],
            })
    return violations


def collect_go_files() -> list[Path]:
    files: list[Path] = []
    for path in CLI_DIR.rglob("*.go"):
        if path.is_relative_to(TERMOUT_DIR):
            continue
        if path.name.endswith("_test.go"):
            continue
        files.append(path)
    return sorted(files)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--allowlist", default=None,
                        help="file with one allowed hex value per line")
    parser.add_argument("--json", action="store_true",
                        help="emit violations as JSON")
    args = parser.parse_args(argv)

    allowed = load_token_hexes() | load_allowlist(args.allowlist)

    violations: list[dict] = []
    for path in collect_go_files():
        violations.extend(scan_file(path, allowed, "go"))
    for directory in EMBEDDED_CSS_DIRS:
        if not directory.exists():
            continue
        for path in sorted(directory.glob("*.go")):
            violations.extend(scan_file(path, allowed, "css"))

    violations.sort(key=lambda v: (v["file"], v["line"], v["hex"]))

    if args.json:
        print(json.dumps(violations, indent=2))
    else:
        for violation in violations:
            print(f"{violation['file']}:{violation['line']}: "
                  f"{violation['hex']} [{violation['kind']}] "
                  f"{violation['context']}")
        print(f"\n{len(violations)} untracked hex literal(s) "
              f"({sum(1 for v in violations if v['kind'] == 'go')} go, "
              f"{sum(1 for v in violations if v['kind'] == 'css')} css)")

    return 1 if violations else 0


if __name__ == "__main__":
    sys.exit(main())
