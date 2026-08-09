#!/usr/bin/env python3
"""
Plan 09 Step 92: copy-coverage linter.

Root cause guarded: every error code the backend can emit (rows in
`backend/config/lara.php` under `error_http_status`) must have both a
matching enum member in `src/lib/lara-api-error.ts` (`ApiErrorCodeType`)
and a user-facing string in `src/lib/error-copy.ts` (`errorsByCode`).
Without a linter, a backend code added on the PHP side ships to
production and the SPA silently falls back to the raw enum name (or,
worse, `undefined`). The existing Vitest `error-copy-coverage.test.ts`
only checks enum <-> copy parity, not backend <-> enum.

This script parses all three sources with narrow regexes (no
PHP/TS runtime), diffs the sets, and prints exactly which codes are
missing where. Exit 1 on any drift.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def backend_codes() -> set[str]:
    text = read("backend/config/lara.php")
    match = re.search(r"'error_http_status'\s*=>\s*\[(.*?)^\s*\],", text, re.DOTALL | re.MULTILINE)
    if not match:
        raise SystemExit("check-copy-coverage: could not locate 'error_http_status' block")
    return set(re.findall(r"'([A-Z][A-Za-z0-9]+)'\s*=>\s*\[", match.group(1)))


def enum_codes() -> set[str]:
    text = read("src/lib/lara-api-error.ts")
    match = re.search(r"export enum ApiErrorCodeType \{(.*?)^\}", text, re.DOTALL | re.MULTILINE)
    if not match:
        raise SystemExit("check-copy-coverage: could not locate ApiErrorCodeType enum")
    return set(re.findall(r"^\s*([A-Z][A-Za-z0-9]+)\s*=\s*\"", match.group(1), re.MULTILINE))


def copy_codes() -> set[str]:
    text = read("src/lib/error-copy.ts")
    match = re.search(r"errorsByCode[^=]*=\s*\{(.*?)^\}", text, re.DOTALL | re.MULTILINE)
    if not match:
        raise SystemExit("check-copy-coverage: could not locate errorsByCode map")
    return set(re.findall(r"^\s*([A-Z][A-Za-z0-9]+)\s*:\s*\"", match.group(1), re.MULTILINE))


def report(label: str, missing: set[str]) -> None:
    for code in sorted(missing):
        print(f"  {label}: missing '{code}'", file=sys.stderr)


def main() -> int:
    backend = backend_codes()
    enum = enum_codes()
    copy = copy_codes()
    missing_from_enum = backend - enum
    missing_from_copy = backend - copy
    extra_in_enum = enum - backend
    if missing_from_enum or missing_from_copy or extra_in_enum:
        print("check-copy-coverage: DRIFT between backend / enum / copy:", file=sys.stderr)
        report("src/lib/lara-api-error.ts (ApiErrorCodeType)", missing_from_enum)
        report("src/lib/error-copy.ts (errorsByCode)", missing_from_copy)
        report("backend/config/lara.php error_http_status", extra_in_enum)
        return 1
    print(f"check-copy-coverage: OK ({len(backend)} codes, backend == enum == copy)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
