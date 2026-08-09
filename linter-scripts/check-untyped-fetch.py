#!/usr/bin/env python3
"""
Plan 16 Step 73: ban raw `fetch(` calls outside the audited transport shims.

Root cause guarded: a raw `fetch(` skips `laraFetch` -> `LaraApiError`, the
envelope parser, `X-Request-Id` header, bearer + one-shot refresh, error
store capture, and Retry-After propagation. That silently degrades the
entire error-manage contract (`spec/03-error-manage/`) into a bare
`TypeError("Failed to fetch")` with no `RequestId`/`ErrorId`. Every UI
data call MUST go through `apiClient.call` -> `laraFetch` ->
`requestLaraApi` (or, in preview, `dispatchPreview`). External-URL
transports (Worker entry, signed upload URLs, self-update download) are
explicitly allowlisted below.

Behavior: single-file scan of every `.ts`/`.tsx` under `src/`. Matches
the literal `fetch(` when not preceded by an identifier character
(to avoid `myFetch(`, `prefetch(`) and not part of `.fetch(` on an
identifier chain. Reports one line per finding.

Exit code: 0 clean, 1 on any violation. Allowlist entries carry a
one-line reason so future readers know why each exception exists.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = REPO_ROOT / "src"

# Allowlisted files may call `fetch(` directly. Every entry documents WHY.
ALLOWLIST: dict[str, str] = {
    # Canonical envelope-aware fetch entry point.
    "src/lib/lara-fetch.ts": "canonical laraFetch entry point",
    # Low-level shim laraFetch delegates to; sole caller of `fetch(url)`.
    "src/lib/lara-api-client.ts": "requestLaraApi network shim",
    # Preview transport dispatcher; declared allowlist target in Step 73.
    "src/lib/preview-transport.ts": "preview handler dispatcher (may fetch fixtures)",
    # Cloudflare Worker entry: `async fetch(request, env, ctx)` handler.
    "src/server.ts": "Worker fetch handler (not a fetch() call site)",
    # Signed upload URLs are external S3-style targets, no envelope.
    "src/lib/lara-app-updates.ts": "signed upload URL to storage backend",
    # Self-update downloads a signed release URL, external.
    "src/lib/lara-self-update.ts": "signed release download URL",
}

# Match `fetch(` not preceded by [A-Za-z0-9_$.] so `myFetch(`,
# `prefetch(`, `foo.fetch(` do not trip. Also skip `async fetch(`
# method definitions (Worker handler shape) by not matching when
# preceded by `function ` or `async `. We rely on the allowlist for
# `src/server.ts` instead of a fragile regex for the method form.
PATTERN = re.compile(r"(?<![A-Za-z0-9_$.])fetch\s*\(")

SCAN_EXT = {".ts", ".tsx"}
SKIP_FILES = {"src/routeTree.gen.ts"}


def is_scannable(path: Path) -> bool:
    if path.suffix not in SCAN_EXT:
        return False
    rel = path.relative_to(REPO_ROOT).as_posix()
    if rel in SKIP_FILES:
        return False
    if "/generated/" in rel:
        return False
    return True


def scan_file(path: Path) -> list[tuple[int, str]]:
    rel = path.relative_to(REPO_ROOT).as_posix()
    if rel in ALLOWLIST:
        return []
    findings: list[tuple[int, str]] = []
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as err:
        print(f"[check-untyped-fetch] read failed: {rel}: {err}", file=sys.stderr)
        return []
    for lineno, line in enumerate(text.splitlines(), start=1):
        stripped = line.lstrip()
        if stripped.startswith("//") or stripped.startswith("*"):
            continue
        if PATTERN.search(line):
            findings.append((lineno, line.rstrip()))
    return findings


def main() -> int:
    if not SRC_DIR.is_dir():
        print(f"[check-untyped-fetch] src/ not found at {SRC_DIR}", file=sys.stderr)
        return 1
    total = 0
    for path in sorted(SRC_DIR.rglob("*")):
        if not path.is_file() or not is_scannable(path):
            continue
        for lineno, line in scan_file(path):
            rel = path.relative_to(REPO_ROOT).as_posix()
            print(f"{rel}:{lineno}: banned raw `fetch(` -> route through apiClient.call / laraFetch")
            print(f"    {line}")
            total += 1
    if total > 0:
        print(
            f"[check-untyped-fetch] FAIL: {total} violation(s). "
            "Route data calls through apiClient.call; external URLs must be added to ALLOWLIST with a reason.",
            file=sys.stderr,
        )
        return 1
    print("check-untyped-fetch: OK (no raw fetch outside allowlisted transports)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
