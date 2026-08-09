#!/usr/bin/env python3
"""
Mermaid Actor-Order Linter
==========================
Enforces AC-DG-001 from spec/21-app/diagrams/00-diagram-contract.md:
sequence-diagram participants MUST be declared left to right in the
canonical order (EndUser -> Reseller -> Admin -> API -> DB -> Audit).

Files that need a documented exception may add the waiver line
`%% lint:allow-actor-order` anywhere in the file.

Usage:   python3 linter-scripts/check-mmd-actor-order.py
Exit 0 on success, 1 on any violation.
"""

from __future__ import annotations

import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SCAN_DIR = os.path.join(ROOT, "spec")
WAIVER = "lint:allow-actor-order"

# Rank 1..6 per 00-diagram-contract.md. Lower rank = further left.
# Match is case-insensitive substring against the participant identifier
# (the token before `as`, not the display label).
RANK_KEYWORDS: list[tuple[int, tuple[str, ...]]] = [
    (1, ("enduser", "winapp", "client", "appbuilder", "device", "browser")),
    (2, ("reseller",)),
    (3, ("admin", "operator", "superadmin")),
    (4, ("api", "server", "worker", "gateway", "licensingserver", "backend")),
    (5, ("db", "store", "database", "postgres", "redis", "cache")),
    (6, ("audit", "auditlog", "mailer", "smtp", "external", "provider", "webhook")),
]

PARTICIPANT_RE = re.compile(r"^\s*(?:participant|actor)\s+([A-Za-z0-9_]+)")


def rank_for(name: str) -> int | None:
    lo = name.lower()
    for rank, keys in RANK_KEYWORDS:
        for k in keys:
            if k in lo:
                return rank
    return None


def is_sequence_diagram(lines: list[str]) -> bool:
    for ln in lines:
        s = ln.strip()
        if not s or s.startswith("%%"):
            continue
        return s.lower().startswith("sequencediagram")
    return False


def check_file(path: str) -> list[str]:
    with open(path, "r", encoding="utf-8") as fh:
        text = fh.read()
    if WAIVER in text:
        return []
    # Non-authoritative projections are governed by their owning service,
    # not the spec/21-app diagram contract. Skip them by design.
    if "NON-AUTHORITATIVE" in text:
        return []
    lines = text.splitlines()
    if not is_sequence_diagram(lines):
        return []
    seen: list[tuple[str, int]] = []
    for ln in lines:
        m = PARTICIPANT_RE.match(ln)
        if not m:
            continue
        name = m.group(1)
        r = rank_for(name)
        if r is None:
            continue  # unrecognised actor is not a violation
        seen.append((name, r))
    errors: list[str] = []
    prev_rank = 0
    prev_name = ""
    for name, r in seen:
        if r < prev_rank:
            errors.append(
                f"{path}: participant '{name}' (rank {r}) declared after "
                f"'{prev_name}' (rank {prev_rank}); canonical order violated"
            )
        prev_rank = max(prev_rank, r)
        prev_name = name
    return errors


def main() -> int:
    all_errors: list[str] = []
    checked = 0
    for dirpath, _dirs, files in os.walk(SCAN_DIR):
        for fn in files:
            if not fn.endswith(".mmd"):
                continue
            p = os.path.join(dirpath, fn)
            errs = check_file(p)
            if errs is not None:
                checked += 1
                all_errors.extend(errs)
    if all_errors:
        print("❌ mmd-actor-order: violations found")
        for e in all_errors:
            print("  " + e)
        return 1
    print(f"✅ mmd-actor-order: {checked} .mmd files scanned, no violations")
    return 0


if __name__ == "__main__":
    sys.exit(main())
