#!/usr/bin/env python3
"""Ratchet the canonical coding-guideline validator across app source."""

from __future__ import annotations

import json
import subprocess
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VALIDATOR = ROOT / "linter-scripts" / "validate-guidelines.py"
TARGETS = {
    "frontend": ROOT / "src",
    "backend": ROOT / "backend" / "app",
}
BASELINE = ROOT / "spec" / "02-coding-guidelines" / "guideline-code-red-baseline.json"


def scan(path: Path) -> Counter[str]:
    command = [sys.executable, str(VALIDATOR), "--path", str(path), "--json"]
    result = subprocess.run(command, capture_output=True, text=True, check=False)
    report = json.loads(result.stdout)
    return Counter(
        row["rule"] for row in report["violations"] if row["severity"] == "CODE-RED"
    )


def compare(current: Counter[str], baseline: dict[str, int]) -> list[str]:
    failures = []
    for key in sorted(set(current) | set(baseline)):
        actual = current.get(key, 0)
        expected = baseline.get(key, 0)
        if actual != expected:
            direction = "regression" if actual > expected else "stale baseline"
            failures.append(f"{direction}: {key}: baseline={expected}, current={actual}")
    return failures


def main() -> int:
    baseline = json.loads(BASELINE.read_text(encoding="utf-8"))["targets"]
    failures = []
    totals = []
    for name, path in TARGETS.items():
        current = scan(path)
        failures.extend(compare(current, baseline[name]))
        totals.append(f"{name}={sum(current.values())}")
    if failures:
        print("Coding-guideline baseline mismatch:")
        print("\n".join(f"  {failure}" for failure in failures))
        return 1
    print(f"check-guideline-baseline: stable ({', '.join(totals)} CODE-RED findings)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())