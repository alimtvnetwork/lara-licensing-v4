#!/usr/bin/env python3
"""
check-command-telemetry.py

Enforces CR-05 / AC-CROSS-004 shorthand rule pinned in
`spec/24-app-ui-design-system/32-command-registry.md` §8.1.

Blueprints 33..42 use `<Verb>Confirmed` / `<Verb>Executed` / `<Verb>Failed`
tokens as aliases for the canonical `CommandConfirmed` / `CommandExecuted` /
`CommandFailed` events (§8) with `CommandId` bound to a §7 row.

This linter verifies every `<Verb>` in blueprint bodies resolves to at least
one §7 CommandId whose last dotted segment or Action verb contains `<Verb>`
(case-insensitive substring on a canonicalised form).

Exit: 0 clean, 1 findings, 2 IO / usage error.
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC_DIR = ROOT / "spec" / "24-app-ui-design-system"
REGISTRY_FILE = SPEC_DIR / "32-command-registry.md"
ANALYTICS_FILE = SPEC_DIR / "58-analytics-event-catalog.md"
TAXONOMY_FILE = ROOT / "spec" / "21-app" / "12-error-taxonomy.md"


SHORTHAND_RE = re.compile(r"`([A-Z][A-Za-z]+?)(Confirmed|Executed|Failed)`")
COMMANDID_RE = re.compile(r"`([A-Z][A-Za-z]+(?:\.[A-Z][A-Za-z]+){1,2})`")
BLUEPRINT_GLOB = [f"{n}-*.md" for n in range(33, 43)]


def load_command_ids() -> set[str]:
    text = REGISTRY_FILE.read_text(encoding="utf-8")
    # Only §7 rows carry CommandIds; scope by §7 region.
    m = re.search(r"^## 7\.[^\n]*\n(.+?)^## 8\.", text, flags=re.MULTILINE | re.DOTALL)
    section = m.group(1) if m else text
    return set(COMMANDID_RE.findall(section))


def canonical_verb(word: str) -> str:
    return word.lower()


def build_verb_index(command_ids: set[str]) -> set[str]:
    """Concatenated lowercased last two segments plus the leaf, for substring match."""
    idx: set[str] = set()
    for cid in command_ids:
        parts = cid.split(".")
        leaf = parts[-1].lower()
        idx.add(leaf)
        if len(parts) >= 2:
            idx.add((parts[-2] + parts[-1]).lower())
    return idx


def load_error_codes() -> set[str]:
    if not TAXONOMY_FILE.exists():
        return set()
    return set(re.findall(r"`([A-Z][A-Za-z]+)`", TAXONOMY_FILE.read_text(encoding="utf-8")))


def load_analytics_families() -> set[str]:
    """Return lowercased leaf and last-two-segment concatenations from 58- §4.4."""
    if not ANALYTICS_FILE.exists():
        return set()
    tokens = re.findall(r"`([A-Z][A-Za-z]+(?:\.[A-Z][A-Za-z]+){1,2})`", ANALYTICS_FILE.read_text(encoding="utf-8"))
    idx: set[str] = set()
    for t in tokens:
        parts = t.split(".")
        idx.add(parts[-1].lower())
        if len(parts) >= 2:
            idx.add((parts[-2] + parts[-1]).lower())
            idx.add((parts[-1] + parts[-2]).lower())
    return idx


ERRORCODE_CTX_RE = re.compile(r"ErrorCode|error message|12-error-taxonomy")


def main() -> int:
    if not REGISTRY_FILE.exists():
        print(f"ERROR: {REGISTRY_FILE} missing", file=sys.stderr)
        return 2
    command_ids = load_command_ids()
    verb_index = build_verb_index(command_ids)
    analytics_index = load_analytics_families()
    error_codes = load_error_codes()

    files: list[Path] = []
    for pat in BLUEPRINT_GLOB:
        files.extend(sorted(SPEC_DIR.glob(pat)))

    findings: list[tuple[Path, int, str]] = []
    total_shorthand = 0
    for f in files:
        for lineno, line in enumerate(f.read_text(encoding="utf-8").splitlines(), 1):
            for m in SHORTHAND_RE.finditer(line):
                total_shorthand += 1
                token = f"{m.group(1)}{m.group(2)}"
                verb = canonical_verb(m.group(1))
                # ErrorCode context: token is a taxonomy code, not shorthand.
                if token in error_codes and ERRORCODE_CTX_RE.search(line):
                    continue
                # Match §7 CommandId.
                if any(verb in key or key in verb for key in verb_index):
                    continue
                # Fallback: match 58- §4.4 analytics family (end-user routes).
                if any(verb in key or key in verb for key in analytics_index):
                    continue
                findings.append((f, lineno, token))

    if not findings:
        print(f"check-command-telemetry: 0 finding(s); {total_shorthand} shorthand token(s) across {len(files)} blueprint(s) resolve to 32- §7 or 58- §4.4")
        return 0
    for f, lineno, tok in findings:
        rel = f.relative_to(ROOT)
        print(f"{rel}:{lineno}: shorthand `{tok}` does not resolve to 32- §7 or 58- §4.4")
    print(f"check-command-telemetry: {len(findings)} finding(s) / {total_shorthand} shorthand token(s) scanned")
    return 1



if __name__ == "__main__":
    sys.exit(main())
