#!/usr/bin/env python3
"""
Concurrency-Conflict UI Linter
==============================
Enforces AC-CONFLICT-001..005 from spec/21-app/49-concurrency-conflict-ux.md
against every React component under src/components/**.tsx that imports any
ETag-guarded mutation helper. Every such component MUST contain:

  1. A branch on `ApiErrorCodeType.PreconditionFailed` (closed-set enum;
     never httpStatus === 412 or message substrings).
  2. A `router.invalidate()` call reachable from the conflict branch.
  3. The verbatim copy anchor "changed since you loaded it" so that
     acceptance tests and localization audits share a single string.

A component may opt out with the waiver comment
`// lint:allow-no-conflict-ui` when the surface is intentionally read-only
or when the mutation is fire-and-forget (no ETag captured).

Usage:   python3 linter-scripts/check-concurrency-conflict-ui.py
Exit 0 on success, 1 on any violation.
"""

from __future__ import annotations

import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SCAN_DIR = os.path.join(ROOT, "src", "components")
WAIVER = "lint:allow-no-conflict-ui"

# Closed set of ETag-guarded client helpers per
# spec/21-app/11-api-contracts/09-concurrency-control.md §Scope. Any
# component that imports one of these is a mutating surface that MUST
# implement the recovery pattern.
GUARDED_HELPERS = (
    "updateLicense",
    "deleteLicense",
    "putLicenseFeature",
    "deleteLicenseFeature",
)

COPY_ANCHOR = "changed since you loaded it"
CONFLICT_ENUM = "ApiErrorCodeType.PreconditionFailed"
INVALIDATE_CALL = re.compile(r"router\s*\.\s*invalidate\s*\(")

# Forbidden anti-patterns per AC-CONFLICT-005.
FORBIDDEN_PATTERNS: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"httpStatus\s*===\s*412"), "branches on httpStatus === 412; use ApiErrorCodeType.PreconditionFailed"),
    (re.compile(r"\.message\s*\.\s*includes\(\s*['\"]Precondition"), "branches on message substring; use ApiErrorCodeType.PreconditionFailed"),
    (re.compile(r"window\.location\.reload\s*\("), "uses window.location.reload; must call router.invalidate() to preserve edits"),
]


def iter_component_files() -> list[str]:
    hits: list[str] = []
    for dirpath, _dirs, files in os.walk(SCAN_DIR):
        for name in files:
            if name.endswith(".tsx"):
                hits.append(os.path.join(dirpath, name))
    return sorted(hits)


def check_file(path: str) -> list[str]:
    with open(path, "r", encoding="utf-8") as fh:
        text = fh.read()

    if WAIVER in text:
        return []
    if not any(h in text for h in GUARDED_HELPERS):
        return []

    errors: list[str] = []
    if CONFLICT_ENUM not in text:
        errors.append(f"missing branch on {CONFLICT_ENUM}")
    if not INVALIDATE_CALL.search(text):
        errors.append("missing router.invalidate() call")
    if COPY_ANCHOR not in text:
        errors.append(f'missing copy anchor "{COPY_ANCHOR}"')
    for pattern, why in FORBIDDEN_PATTERNS:
        if pattern.search(text):
            errors.append(f"forbidden pattern: {why}")
    return errors


def main() -> int:
    failures: list[str] = []
    scanned = 0
    for path in iter_component_files():
        with open(path, "r", encoding="utf-8") as fh:
            head = fh.read(4096)
        if not any(h in head for h in GUARDED_HELPERS) and not any(
            h in open(path, "r", encoding="utf-8").read() for h in GUARDED_HELPERS
        ):
            continue
        scanned += 1
        errs = check_file(path)
        rel = os.path.relpath(path, ROOT)
        for e in errs:
            failures.append(f"{rel}: {e}")

    if failures:
        print("check-concurrency-conflict-ui: violations found")
        for line in failures:
            print(f"  - {line}")
        print(f"Scanned {scanned} mutating component(s).")
        print("Ref: spec/21-app/49-concurrency-conflict-ux.md AC-CONFLICT-001..005.")
        return 1

    print(f"check-concurrency-conflict-ui: OK ({scanned} mutating component(s) scanned)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
