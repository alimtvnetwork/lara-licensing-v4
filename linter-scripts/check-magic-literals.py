#!/usr/bin/env python3
"""
Magic literal linter. Enforces `.lovable/coding-guidelines/coding-guidelines.md`
Hard Rule 8 (No magic strings or numbers).

Flags:
  - Quoted string literals matching known domain vocabulary (roles, statuses,
    ledger actions, tier names, environment names, permission keys, feature
    keys, error codes) that appear outside the canonical catalog files.
  - Numeric literals for HTTP status codes (100-599) outside catalog / test
    files.

Scanned trees: src/, backend/app/, backend/database/migrations/.
Ignored: node_modules/, vendor/, dist/, build/, *.test.*, *.spec.*,
canonical catalog files listed in ALLOWLIST_FILES, and any line ending in
the waiver comment `lint-allow: magic-literal`.

Exit codes:
  0 - clean.
  1 - findings printed to stdout.
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

SCAN_DIRS = ["src", "backend/app", "backend/database/migrations"]

# Files that ARE the catalog. Literals here are the source of truth.
ALLOWLIST_FILES = {
    "src/lib/lara-enums.ts",
    "src/lib/lara-error-codes.ts",
    "src/lib/lara-permissions.ts",
    "src/lib/lara-features.ts",
    "src/lib/lara-environment.ts",
    "src/lib/lara-tiers.ts",
    "src/lib/lara-roles.ts",
    "src/lib/lara-ledger-actions.ts",
    "src/lib/lara-audit-actions.ts",
    "backend/config/lara.php",
    "backend/app/Support/Enums.php",
    "backend/app/Exceptions/LaraException.php",
    "backend/app/Support/ApiEnvelope.php",
}

DOMAIN_VOCAB = {
    # Roles
    "SuperAdmin", "Admin", "Reseller", "AppBuilder", "EndUser",
    # Tiers
    "Tier1", "Tier2", "Tier3", "Unlimited",
    # Environments
    "Production", "Staging", "Development",
    # License status
    "Active", "Suspended", "Revoked", "Expired",
    # Ledger actions
    "QuotaConsumed", "QuotaRestored", "QuotaAdjusted",
    # Quota request status names
    "Pending", "Approved", "Denied", "Cancelled",
    # Shard status
    "Provisioning", "Failed", "Quiesced",
}

WAIVER = "lint-allow: magic-literal"
IGNORE_PARTS = {"node_modules", "vendor", "dist", "build", ".git"}
TEST_SUFFIX = re.compile(r"\.(test|spec)\.[tj]sx?$")

STR_LITERAL = re.compile(r"""['"]([A-Za-z][A-Za-z0-9_.]{2,63})['"]""")
HTTP_NUM = re.compile(r"(?<![\w.])([1-5]\d{2})(?![\w.])")


def is_scannable(path: Path) -> bool:
    if any(part in IGNORE_PARTS for part in path.parts):
        return False
    rel = path.relative_to(ROOT).as_posix()
    if rel in ALLOWLIST_FILES:
        return False
    if TEST_SUFFIX.search(path.name):
        return False
    return path.suffix in {".ts", ".tsx", ".php"}


def scan_file(path: Path) -> list[str]:
    findings: list[str] = []
    rel = path.relative_to(ROOT).as_posix()
    for lineno, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
        if WAIVER in line:
            continue
        stripped = line.lstrip()
        if stripped.startswith(("//", "*", "#", "/*")):
            continue
        for match in STR_LITERAL.finditer(line):
            token = match.group(1)
            if token in DOMAIN_VOCAB:
                findings.append(f"{rel}:{lineno}: magic-string {token!r}")
        for match in HTTP_NUM.finditer(line):
            code = int(match.group(1))
            if 400 <= code <= 599 and "status" in line.lower():
                findings.append(f"{rel}:{lineno}: magic-http-status {code}")
    return findings


def aggregate(findings: list[str]) -> dict[str, dict[str, int]]:
    """
    Collapse per-line findings into a ratchet-friendly shape:
    { "<file>": { "<kind>:<token>": <count> } }
    Line numbers are dropped so cosmetic reformatting does not thrash the
    baseline; a real regression grows the count for a (file, kind, token) key.
    """
    agg: dict[str, dict[str, int]] = {}
    for f in findings:
        # "<file>:<lineno>: <kind> <literal>"
        try:
            head, tail = f.split(": ", 1)
            file_path = head.rsplit(":", 1)[0]
        except ValueError:
            continue
        agg.setdefault(file_path, {})
        agg[file_path][tail] = agg[file_path].get(tail, 0) + 1
    return agg


def diff_against_baseline(current: dict[str, dict[str, int]], baseline: dict[str, dict[str, int]]) -> tuple[list[str], list[str]]:
    """
    Ratchet-only comparison:
      - regressions: current count > baseline count for a (file, key), or a
        (file, key) exists in current but not in baseline.
      - stale: baseline (file, key) with count > current (violation was
        removed or reduced but baseline was not shrunk). Forces the list to
        only shrink over time.
    """
    regressions: list[str] = []
    stale: list[str] = []
    seen_files = set(current) | set(baseline)
    for fp in sorted(seen_files):
        cur = current.get(fp, {})
        base = baseline.get(fp, {})
        for key in sorted(set(cur) | set(base)):
            c = cur.get(key, 0)
            b = base.get(key, 0)
            if c > b:
                regressions.append(f"{fp}: {key} (+{c - b}, now {c}, baseline {b})")
            elif c < b:
                stale.append(f"{fp}: {key} (baseline {b}, now {c}) - shrink baseline")
    return regressions, stale


def main() -> int:
    import argparse
    import json

    ap = argparse.ArgumentParser()
    ap.add_argument("--baseline", help="Path to ratchet-baseline JSON. When set, only new/grown findings fail.")
    ap.add_argument("--write-baseline", help="Overwrite baseline JSON from the current scan and exit 0.")
    args = ap.parse_args()

    all_findings: list[str] = []
    for sub in SCAN_DIRS:
        base = ROOT / sub
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if path.is_file() and is_scannable(path):
                all_findings.extend(scan_file(path))

    current = aggregate(all_findings)

    if args.write_baseline:
        Path(args.write_baseline).write_text(
            json.dumps({"note": "Ratchet-only baseline for check-magic-literals.py. New findings fail CI; entries can only shrink.", "entries": current}, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        print(f"check-magic-literals: wrote baseline with {sum(sum(v.values()) for v in current.values())} findings across {len(current)} files.")
        return 0

    if args.baseline:
        baseline_path = Path(args.baseline)
        if not baseline_path.exists():
            print(f"check-magic-literals: baseline file not found: {baseline_path}", file=sys.stderr)
            return 2
        data = json.loads(baseline_path.read_text(encoding="utf-8"))
        baseline = data.get("entries", {})
        regressions, stale = diff_against_baseline(current, baseline)
        if regressions:
            print("Magic literal REGRESSIONS (new or grown findings):")
            for r in regressions:
                print(f"  {r}")
        if stale:
            print("Magic literal baseline STALE (findings shrank; update the baseline):")
            for s in stale:
                print(f"  {s}")
            print(f"\nRegenerate with: python3 linter-scripts/check-magic-literals.py --write-baseline {baseline_path}")
        if regressions or stale:
            return 1
        print(f"check-magic-literals: within baseline ({sum(sum(v.values()) for v in current.values())} findings, no regressions).")
        return 0

    # Legacy hard-fail mode (fails on ANY finding). Retained for local use.
    if all_findings:
        print("Magic literal findings (Hard Rule 8):")
        for finding in all_findings:
            print(f"  {finding}")
        print(f"\nTotal: {len(all_findings)}. See .lovable/memory/standards/no-magic-literals.md.")
        return 1
    print("check-magic-literals: clean.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

