#!/usr/bin/env python3
"""
Plan 16 Step 75: ban inline `/api/...` endpoint literals outside the typed layer.

Root cause guarded: an inline `"/api/admin/licenses"` string anywhere in
`src/` outside `src/generated/api/**` (source of truth) or
`src/lib/preview-fixtures/**` (preview handler routing table) means a caller
is bypassing `apiClient.call(Operations[...])`. That skips typed request /
response inference, the operationId contract used by dead-op detection
(Step 72), the preview transport dispatch table (`INV-RM-04`), and the
error-code parity check (Step 79). The endpoint URL is coupled to the
route filename and can drift silently.

Scope: every `.ts` / `.tsx` under `src/` except the two allowlisted trees.
Detects `/api/` inside single-quoted, double-quoted, and template string
literals. Comments and non-string occurrences are ignored.

Exit 0 clean, 1 on any violation.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = REPO_ROOT / "src"

# Trees allowed to embed literal `/api/...` paths, each with a documented reason.
ALLOWLIST_PREFIXES: dict[str, str] = {
    "src/generated/api/": "generated OpenAPI operationId -> HTTP path table",
    "src/lib/preview-fixtures/": "preview handler routing table keyed by URL",
}

SCAN_EXT = {".ts", ".tsx"}
SKIP_FILES = {"src/routeTree.gen.ts"}

# Match `/api/` when it appears inside a string literal opened on the same line.
# We do not try to reconstruct multi-line template literals; the scanner scans
# each line's stripped-string view and flags any `/api/` that survives outside
# a string, plus any `/api/` seen inside a string on the same line.
STRING_API = re.compile(r"""(?P<q>['"`])/api/[^'"`\n]*(?P=q)""")


def is_allowlisted(rel: str) -> bool:
    return any(rel.startswith(prefix) for prefix in ALLOWLIST_PREFIXES)


def is_scannable(path: Path) -> bool:
    if path.suffix not in SCAN_EXT:
        return False
    rel = path.relative_to(REPO_ROOT).as_posix()
    if rel in SKIP_FILES:
        return False
    return True


def scan_file(path: Path) -> list[tuple[int, str]]:
    rel = path.relative_to(REPO_ROOT).as_posix()
    if is_allowlisted(rel):
        return []
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as err:
        print(f"[check-magic-endpoint-strings] read failed: {rel}: {err}", file=sys.stderr)
        return []
    findings: list[tuple[int, str]] = []
    for lineno, line in enumerate(text.splitlines(), start=1):
        stripped = line.lstrip()
        if stripped.startswith("//") or stripped.startswith("*") or stripped.startswith("/*"):
            continue
        # Drop trailing line comments so a "/api/..." mentioned in a comment
        # after code does not trip. Naive but sufficient for our TS files.
        cut = line.find("//")
        scan = line if cut < 0 else line[:cut]
        if STRING_API.search(scan):
            findings.append((lineno, line.rstrip()))
    return findings


def main() -> int:
    if not SRC_DIR.is_dir():
        print(f"[check-magic-endpoint-strings] src/ not found at {SRC_DIR}", file=sys.stderr)
        return 1
    total = 0
    for path in sorted(SRC_DIR.rglob("*")):
        if not path.is_file() or not is_scannable(path):
            continue
        for lineno, line in scan_file(path):
            rel = path.relative_to(REPO_ROOT).as_posix()
            print(f"{rel}:{lineno}: banned inline `/api/...` literal -> use apiClient.call(Operations[...])")
            print(f"    {line}")
            total += 1
    if total > 0:
        allow = ", ".join(sorted(ALLOWLIST_PREFIXES))
        print(
            f"[check-magic-endpoint-strings] FAIL: {total} violation(s). "
            f"Allowed only under: {allow}. Route calls through the typed operationId table.",
            file=sys.stderr,
        )
        return 1
    print("check-magic-endpoint-strings: OK (no inline /api/ literals outside allowlisted trees)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
