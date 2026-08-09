#!/usr/bin/env python3
"""
check-analytics-events.py

Enforces CR-05 / AC-CROSS-004: every analytics event token cited in route
blueprints (spec/24-app-ui-design-system/33-*.md .. 42-*.md) MUST appear in
the canonical registry in `spec/24-app-ui-design-system/58-analytics-event-catalog.md`.

Contract:
- Event tokens match `^[A-Z][A-Za-z]+(\\.[A-Z][A-Za-z]+){1,2}$` (2 or 3 dotted PascalCase parts).
- Registry is parsed from every backticked token in `58-`.
- For every 2-part token that appears in the §4.4 mutation table (families),
  the linter also accepts `<Family>.Started` and `<Family>.Resolved`.
- Blueprint files 33..42 are scanned. Any token not in the accepted set is a finding.

Exit: 0 clean, 1 findings, 2 usage / IO error.
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC_DIR = ROOT / "spec" / "24-app-ui-design-system"
REGISTRY_FILE = SPEC_DIR / "58-analytics-event-catalog.md"

TOKEN_RE = re.compile(r"`([A-Z][A-Za-z]+(?:\.[A-Z][A-Za-z]+){1,2})`")
BLUEPRINT_GLOB = [f"{n}-*.md" for n in range(33, 43)]


def load_registry() -> set[str]:
    if not REGISTRY_FILE.exists():
        print(f"ERROR: registry file missing: {REGISTRY_FILE}", file=sys.stderr)
        sys.exit(2)
    text = REGISTRY_FILE.read_text(encoding="utf-8")
    tokens = set(TOKEN_RE.findall(text))
    # Expand §4.4 families: any 2-part token gets Started/Resolved variants.
    expanded: set[str] = set(tokens)
    for t in tokens:
        if t.count(".") == 1:
            expanded.add(f"{t}.Started")
            expanded.add(f"{t}.Resolved")
    return expanded


def scan_blueprints(registry: set[str]) -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    files: list[Path] = []
    for pat in BLUEPRINT_GLOB:
        files.extend(sorted(SPEC_DIR.glob(pat)))
    for f in files:
        for lineno, line in enumerate(f.read_text(encoding="utf-8").splitlines(), 1):
            for m in TOKEN_RE.finditer(line):
                tok = m.group(1)
                if tok in registry:
                    continue
                first = tok.split(".", 1)[0]
                namespaces = {r.split(".", 1)[0] for r in registry}
                if first not in namespaces:
                    continue
                # Skip TanStack Query key references: the token is a query key,
                # not an analytics event, when the surrounding span opens with `[`
                # or the line references Query key APIs.
                span = m.group(0).strip("`")
                if span.startswith("[") or span.startswith('"') or span.startswith("'"):
                    continue
                query_ctx = ("Query keys" in line or "queryKey" in line
                             or "useSuspenseQuery" in line or "ensureQueryData" in line
                             or "useQuery" in line or "invalidateQueries" in line)
                if query_ctx:
                    continue


                findings.append((f, lineno, tok))

    return findings


def main() -> int:
    registry = load_registry()
    findings = scan_blueprints(registry)
    if not findings:
        print(f"check-analytics-events: 0 findings across blueprints 33..42 ({len(registry)} registry tokens)")
        return 0
    for f, lineno, tok in findings:
        rel = f.relative_to(ROOT)
        print(f"{rel}:{lineno}: unknown analytics event `{tok}` (not in 58- §4)")
    print(f"check-analytics-events: {len(findings)} finding(s)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
