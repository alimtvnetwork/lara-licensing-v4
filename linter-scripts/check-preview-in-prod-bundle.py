#!/usr/bin/env python3
"""
Preview tree-shake guard (Plan 16 Step 84).

Bans static `from "...PreviewDebugDrawer"` imports outside the drawer
module itself, its lazy wrapper (which uses `import()`), and tests.
Any static import re-anchors the drawer chain (preview-scenario,
version-json-loader) into the production entry bundle, defeating
INV-RM-04.

Exit codes:
    0  OK
    1  Violations found

Wire into `lint:api-surface` alongside the other axis linters.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = REPO_ROOT / "src"
DIST_DIR = REPO_ROOT / "dist"
ALLOWED_FILES = {
    SRC_DIR / "components" / "shell" / "PreviewDebugDrawer.tsx",
    SRC_DIR / "components" / "shell" / "PreviewDebugDrawerLazy.tsx",
}
STATIC_IMPORT_RE = re.compile(
    r"""(?mx)
    ^\s* import \b [^;]*?
    from \s* ['"] (?P<spec>[^'"]*PreviewDebugDrawer) ['"]
    """
)


def scan_file(path: Path) -> list[str]:
    if path in ALLOWED_FILES:
        return []
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError):
        return []
    hits: list[str] = []
    for m in STATIC_IMPORT_RE.finditer(text):
        line = text.count("\n", 0, m.start()) + 1
        hits.append(f"{path.relative_to(REPO_ROOT)}:{line}: static import of {m.group('spec')!r}")
    return hits


def scan_dist_file(path: Path) -> list[str]:
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError):
        return []
    hits = []
    for marker in ["PREVIEW_", "DEMO_LOGIN_PANEL_MARKER", "SEED_PROFILE_MARKER"]:
        if marker in text:
            hits.append(f"{path.relative_to(REPO_ROOT)}: found banned marker {marker!r}")
    return hits

def main() -> int:
    violations: list[str] = []
    for path in SRC_DIR.rglob("*.ts*"):
        violations.extend(scan_file(path))
    if DIST_DIR.exists():
        for path in DIST_DIR.rglob("*.js"):
            violations.extend(scan_dist_file(path))
    if violations:
        print("Preview drawer tree-shake guard violations (Plan 16 Step 84):", file=sys.stderr)
        for v in violations:
            print(f"  {v}", file=sys.stderr)
        print(
            "\nUse `PreviewDebugDrawerLazy` (dynamic import) instead so the drawer "
            "and its preview-only chain stay out of the production bundle. "
            "Ensure DEMO_LOGIN_PANEL_MARKER and SEED_PROFILE_MARKER are strictly gated.",
            file=sys.stderr,
        )
        return 1
    print("check-preview-in-prod-bundle: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
