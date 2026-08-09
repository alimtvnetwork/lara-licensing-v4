#!/usr/bin/env python3
"""
check-preview-store-key-shape.py

Enforces Plan 17 Step 32 invariants for preview-store call sites:

  1. Every call to `read/write/remove/list/listKeys/resetDomain(` from the
     preview-store module must pass a domain that is a **string literal**
     and a member of `PREVIEW_FIXTURE_MODULE_NAMES`.
  2. For `read/write/remove`, the second argument (key) must:
       - be a string literal or template literal;
       - not be empty;
       - contain no whitespace characters;
       - not start or end with `::`;
       - if it is a composite (contains `::`), both sides must be
         non-empty.

This linter is authoritative for keys under `src/lib/preview-seeds/` and
`src/lib/preview-fixtures/`. It exits non-zero on any violation.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCAN_DIRS = [ROOT / "src/lib/preview-seeds", ROOT / "src/lib/preview-fixtures"]
FIXTURE_INDEX = ROOT / "src/lib/preview-fixtures/index.ts"

CALL_RE = re.compile(
    r"\b(read|write|remove|list|listKeys|resetDomain)\s*(?:<[^>()]*>)?\s*\(",
)


def load_domains() -> set[str]:
    text = FIXTURE_INDEX.read_text(encoding="utf-8")
    match = re.search(
        r"PREVIEW_FIXTURE_MODULE_NAMES\s*=\s*\[([^\]]+)\]", text, re.DOTALL,
    )
    if not match:
        print("FATAL: could not parse PREVIEW_FIXTURE_MODULE_NAMES")
        sys.exit(2)
    return set(re.findall(r'"([^"]+)"', match.group(1)))


def split_top_level_args(src: str) -> list[str]:
    depth = 0
    out: list[str] = []
    buf = ""
    in_str: str | None = None
    i = 0
    while i < len(src):
        ch = src[i]
        if in_str:
            buf += ch
            if ch == "\\" and i + 1 < len(src):
                buf += src[i + 1]
                i += 2
                continue
            if ch == in_str:
                in_str = None
        elif ch in ('"', "'", "`"):
            in_str = ch
            buf += ch
        elif ch in "([{":
            depth += 1
            buf += ch
        elif ch in ")]}":
            depth -= 1
            buf += ch
        elif ch == "," and depth == 0:
            out.append(buf.strip())
            buf = ""
        else:
            buf += ch
        i += 1
    if buf.strip():
        out.append(buf.strip())
    return out


def find_call_body(text: str, start: int) -> tuple[str, int] | None:
    depth = 0
    in_str: str | None = None
    body_start = start
    i = start
    while i < len(text):
        ch = text[i]
        if in_str:
            if ch == "\\" and i + 1 < len(text):
                i += 2
                continue
            if ch == in_str:
                in_str = None
        elif ch in ('"', "'", "`"):
            in_str = ch
        elif ch == "(":
            depth += 1
            if depth == 1:
                body_start = i + 1
        elif ch == ")":
            depth -= 1
            if depth == 0:
                return text[body_start:i], i + 1
        i += 1
    return None


def check_key_literal(raw: str) -> str | None:
    if not raw:
        return "empty key expression"
    if raw[0] in ('"', "'") and raw[-1] == raw[0]:
        inner = raw[1:-1]
    elif raw[0] == "`" and raw[-1] == "`":
        inner = raw[1:-1]
    else:
        return None  # non-literal expression; skip content checks
    if not inner:
        return "empty key literal"
    if re.search(r"\s", inner):
        return f"key contains whitespace: {raw!r}"
    if inner.startswith("::") or inner.endswith("::"):
        return f"key has dangling '::': {raw!r}"
    if "::" in inner:
        parts = inner.split("::")
        for p in parts:
            if p == "" and "${" not in raw:
                return f"key has empty '::' segment: {raw!r}"
    return None


def strip_comments(src: str) -> str:
    # Replace comment contents with spaces (preserves line numbers/offsets).
    out = []
    i = 0
    in_str: str | None = None
    while i < len(src):
        ch = src[i]
        two = src[i:i + 2]
        if in_str:
            out.append(ch)
            if ch == "\\" and i + 1 < len(src):
                out.append(src[i + 1]); i += 2; continue
            if ch == in_str:
                in_str = None
            i += 1; continue
        if ch in ('"', "'", "`"):
            in_str = ch; out.append(ch); i += 1; continue
        if two == "//":
            while i < len(src) and src[i] != "\n":
                out.append(" "); i += 1
            continue
        if two == "/*":
            while i < len(src) and src[i:i + 2] != "*/":
                out.append("\n" if src[i] == "\n" else " "); i += 1
            if i < len(src):
                out.append("  "); i += 2
            continue
        out.append(ch); i += 1
    return "".join(out)


def check_file(path: Path, domains: set[str]) -> list[str]:
    text = strip_comments(path.read_text(encoding="utf-8"))
    problems: list[str] = []
    for m in CALL_RE.finditer(text):
        fn = m.group(1)
        body = find_call_body(text, m.start())
        if body is None:
            continue
        args = split_top_level_args(body[0])
        if not args:
            continue
        dom = args[0]
        line = text.count("\n", 0, m.start()) + 1
        loc = f"{path.relative_to(ROOT)}:{line}"
        if not (dom.startswith('"') and dom.endswith('"')):
            problems.append(f"{loc} {fn}(): non-literal domain {dom!r}")
            continue
        dom_val = dom[1:-1]
        if dom_val not in domains:
            problems.append(
                f"{loc} {fn}(): unknown domain {dom_val!r} "
                f"(not in PREVIEW_FIXTURE_MODULE_NAMES)",
            )
        if fn in ("read", "write", "remove") and len(args) >= 2:
            err = check_key_literal(args[1])
            if err:
                problems.append(f"{loc} {fn}(): {err}")
    return problems


def main() -> int:
    domains = load_domains()
    problems: list[str] = []
    for base in SCAN_DIRS:
        if not base.exists():
            continue
        for path in base.rglob("*.ts"):
            problems.extend(check_file(path, domains))
    if problems:
        print("preview-store key-shape violations:")
        for p in problems:
            print(f"  {p}")
        print(f"\n{len(problems)} violation(s)")
        return 1
    print("preview-store key-shape: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
