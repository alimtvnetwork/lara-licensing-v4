#!/usr/bin/env python3
"""
Plan 16 Step 74: ban `: any` and `as any` in the typed API surface.

Root cause guarded: a single `any` in `src/lib/lara-*.ts`, `src/lib/api-client.ts`,
or `src/generated/api/**` silently drops the entire typed contract enforced by
`spec/28-runtime-modes/04-generated-types-contract.md` and the closed-set error
codes in `spec/03-error-manage/`. Callers then read fields that do not exist at
runtime, and TypeScript can no longer diff FE shapes against the generated
OpenAPI schema. Guard the axis before drift takes hold.

Scope (audited files only, NOT all of src/):
  - src/lib/lara-*.ts        (30 files)
  - src/lib/api-client.ts    (dispatcher)
  - src/generated/api/**     (auto-generated, must be pristine)

Match rules (comments/strings/whitespace-safe):
  - `: any` where `any` is a bare identifier (not `anyThing`, not `Company`).
    Covers `x: any`, `x: any[]`, `x: any | null`, `x?: any`, generics like
    `<T = any>` and `Foo<any>`.
  - `as any` unary cast (`x as any`, `(x as any).y`).
  - Skips single-line `//` comments, block-comment `*` continuation lines,
    and lines whose only occurrence is inside a string literal.

Exit 0 clean, 1 on any violation. There is NO allowlist by design; if a real
external boundary needs `unknown`, use `unknown` and narrow it.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = REPO_ROOT / "src"

# `: any` where `any` is a whole word (word-boundary before and after).
# Handles `: any`, `: any[]`, `: any|null`, `?: any`, `<T = any>`.
COLON_ANY = re.compile(r":\s*any\b")
# `as any` cast. Word boundary each side so `as anything` etc do not match.
AS_ANY = re.compile(r"\bas\s+any\b")
# `<any>` or `<any,` or `,any>` inside generic argument lists.
GENERIC_ANY = re.compile(r"[<,]\s*any\s*[,>]")

SCAN_EXT = {".ts", ".tsx", ".d.ts"}


def audited_paths() -> list[Path]:
    """Return the exact file set guarded by this linter."""
    paths: list[Path] = []
    lib = SRC_DIR / "lib"
    if lib.is_dir():
        paths.extend(sorted(lib.glob("lara-*.ts")))
        api_client = lib / "api-client.ts"
        if api_client.is_file():
            paths.append(api_client)
    generated = SRC_DIR / "generated" / "api"
    if generated.is_dir():
        for path in sorted(generated.rglob("*")):
            if path.is_file() and (
                path.suffix in SCAN_EXT or path.name.endswith(".d.ts")
            ):
                paths.append(path)
    return paths


def strip_strings(line: str) -> str:
    """Blank out string literal contents so `"as any"` inside a string is ignored."""
    out: list[str] = []
    i = 0
    n = len(line)
    while i < n:
        ch = line[i]
        if ch in ("'", '"', "`"):
            quote = ch
            out.append(quote)
            i += 1
            while i < n and line[i] != quote:
                if line[i] == "\\" and i + 1 < n:
                    i += 2
                    continue
                i += 1
            if i < n:
                out.append(quote)
                i += 1
        else:
            out.append(ch)
            i += 1
    return "".join(out)


def line_is_comment(stripped: str) -> bool:
    return stripped.startswith("//") or stripped.startswith("*") or stripped.startswith("/*")


def find_violations(text: str) -> list[tuple[int, str, str]]:
    findings: list[tuple[int, str, str]] = []
    in_block = False
    for lineno, raw in enumerate(text.splitlines(), start=1):
        stripped_lead = raw.lstrip()
        # Track block comments crudely; safe for our audited files.
        if in_block:
            if "*/" in raw:
                in_block = False
            continue
        if stripped_lead.startswith("/*") and "*/" not in raw:
            in_block = True
            continue
        if line_is_comment(stripped_lead):
            continue
        scan = strip_strings(raw)
        # Drop trailing line comment.
        cut = scan.find("//")
        if cut >= 0:
            scan = scan[:cut]
        if COLON_ANY.search(scan):
            findings.append((lineno, "`: any`", raw.rstrip()))
            continue
        if AS_ANY.search(scan):
            findings.append((lineno, "`as any`", raw.rstrip()))
            continue
        if GENERIC_ANY.search(scan):
            findings.append((lineno, "generic `<any>`", raw.rstrip()))
    return findings


def main() -> int:
    if not SRC_DIR.is_dir():
        print(f"[check-any-in-api] src/ not found at {SRC_DIR}", file=sys.stderr)
        return 1
    total = 0
    for path in audited_paths():
        rel = path.relative_to(REPO_ROOT).as_posix()
        try:
            text = path.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError) as err:
            print(f"[check-any-in-api] read failed: {rel}: {err}", file=sys.stderr)
            return 1
        for lineno, kind, snippet in find_violations(text):
            print(f"{rel}:{lineno}: banned {kind} in typed API surface")
            print(f"    {snippet}")
            total += 1
    if total > 0:
        print(
            f"[check-any-in-api] FAIL: {total} violation(s). "
            "Use `unknown` and narrow, or generate a concrete type from the OpenAPI spec.",
            file=sys.stderr,
        )
        return 1
    print("check-any-in-api: OK (no `: any` / `as any` in lara-*.ts, api-client.ts, generated/api/**)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
