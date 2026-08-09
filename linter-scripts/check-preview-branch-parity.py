#!/usr/bin/env python3
"""
Plan 17 Step 12: preview-branch parity for legacy `requestLaraApi` callers.

Root cause guarded: every legacy resource module under `src/lib/lara-*.ts`
that still reaches `src/lib/lara-fetch.ts::requestLaraApi(...)` on the
preview path throws `LaraApiError` at `assertRequestNotPreview` in
`src/lib/preview-transport.ts` and blocks the route it feeds. Steps 3-11
branched the biggest admin/portal loaders one by one. This linter locks
that progress: a new un-branched caller in a file NOT on the baseline
waiver list is a hard failure, and waived files cannot silently
regress (the waiver becomes "stale" once branched or once the call is
removed, and stale waivers also fail so we do not carry dead entries).

Contract:
  - Scan `src/lib/lara-*.ts` (excluding infra: `lara-fetch.ts`,
    `lara-api-*.ts`, `lara-envelope.ts`, `lara-environment.ts`,
    `lara-retry.ts`, `lara-shell-role.ts`, `lara-sidebar-collapsed.ts`).
  - A file is "branched" iff it contains the literal
    `getRuntimeMode().Mode === "preview"`.
  - A file is "un-branched" iff it calls `requestLaraApi(` at least once
    and is not branched.
  - Waivers in `check-preview-branch-parity.waivers.txt` list currently
    un-branched files. Any un-branched file NOT on the waiver list → FAIL
    (new regression). Any waiver entry that is now branched or no longer
    calls `requestLaraApi` → FAIL (stale waiver; remove it).

Exit 0 on clean scan, 1 on any violation. One line per finding.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
LIB_DIR = REPO_ROOT / "src" / "lib"
WAIVER_FILE = REPO_ROOT / "linter-scripts" / "check-preview-branch-parity.waivers.txt"

# Infra modules that legitimately host `requestLaraApi` or unrelated shell state.
INFRA_SKIP = {
    "src/lib/lara-fetch.ts",
    "src/lib/lara-api-client.ts",
    "src/lib/lara-api-contract.ts",
    "src/lib/lara-api-error.ts",
    "src/lib/lara-api-response.ts",
    "src/lib/lara-api-session.ts",
    "src/lib/lara-envelope.ts",
    "src/lib/lara-environment.ts",
    "src/lib/lara-retry.ts",
    "src/lib/lara-shell-role.ts",
    "src/lib/lara-sidebar-collapsed.ts",
}

CALL_PATTERN = re.compile(r"\brequestLaraApi\s*\(")
BRANCH_LITERAL = 'getRuntimeMode().Mode === "preview"'


def load_waivers() -> set[str]:
    if not WAIVER_FILE.is_file():
        return set()
    out: set[str] = set()
    for raw in WAIVER_FILE.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        out.add(line)
    return out


def classify(path: Path) -> tuple[bool, bool]:
    """Return (calls_request_lara_api, is_branched)."""
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as err:
        print(f"[check-preview-branch-parity] read failed: {path}: {err}", file=sys.stderr)
        return (False, False)
    return (bool(CALL_PATTERN.search(text)), BRANCH_LITERAL in text)


def main() -> int:
    if not LIB_DIR.is_dir():
        print(f"[check-preview-branch-parity] src/lib not found at {LIB_DIR}", file=sys.stderr)
        return 1
    waivers = load_waivers()
    violations: list[str] = []
    seen_waivers: set[str] = set()
    for path in sorted(LIB_DIR.glob("lara-*.ts")):
        rel = path.relative_to(REPO_ROOT).as_posix()
        if rel in INFRA_SKIP:
            continue
        calls, branched = classify(path)
        if not calls:
            if rel in waivers:
                violations.append(
                    f"{rel}: stale waiver (no `requestLaraApi(` calls remain); "
                    "remove from check-preview-branch-parity.waivers.txt"
                )
                seen_waivers.add(rel)
            continue
        if branched:
            if rel in waivers:
                violations.append(
                    f"{rel}: stale waiver (file is now preview-branched); "
                    "remove from check-preview-branch-parity.waivers.txt"
                )
                seen_waivers.add(rel)
            continue
        # calls && !branched -> must be waived
        if rel in waivers:
            seen_waivers.add(rel)
            continue
        violations.append(
            f"{rel}: un-branched `requestLaraApi(` call in preview-reachable "
            "module; add a `getRuntimeMode().Mode === \"preview\"` branch or "
            "route through `src/lib/lara-api-client.ts::apiClient.call(...)`."
        )
    orphan_waivers = waivers - seen_waivers
    for rel in sorted(orphan_waivers):
        violations.append(
            f"{rel}: waiver targets a file that does not exist under src/lib/lara-*.ts; "
            "remove from check-preview-branch-parity.waivers.txt"
        )
    if violations:
        for line in violations:
            print(line)
        print(
            f"[check-preview-branch-parity] FAIL: {len(violations)} violation(s).",
            file=sys.stderr,
        )
        return 1
    print(
        f"check-preview-branch-parity: OK ({len(waivers)} legacy file(s) still on "
        "baseline waiver; graduate one per Plan 17 step)."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
