#!/usr/bin/env python3
"""
E2E Verification Script for Task 429: Installation & Role Management Enhancements.
Validates Onboarding, Turbo Mode, Crypto Hashing, Backup Codes, RBAC Matrix,
Fleet Audit Provenance, REST Endpoints, and React Web UI Role Studio.
"""

import subprocess
import sys
import time
from pathlib import Path

WORKSPACE = Path(__file__).resolve().parent.parent

def run_step(title: str, cmd: list[str], cwd: Path = WORKSPACE) -> bool:
    print(f"\n[RUNNING] {title}...")
    start = time.time()
    try:
        proc = subprocess.run(
            cmd,
            cwd=str(cwd),
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            shell=True,
            check=False
        )
        duration = time.time() - start
        if proc.returncode == 0:
            print(f"[PASS] {title} ({duration:.2f}s)")
            return True
        else:
            print(f"[FAIL] {title} (exit {proc.returncode}):\n{proc.stdout}")
            return False
    except Exception as exc:
        print(f"[ERROR] {title}: {exc}")
        return False

def main() -> int:
    print("=" * 70)
    print("GitMap Task 429 End-to-End (E2E) Test Verification Suite")
    print("=" * 70)

    steps = [
        (
            "Go E2E Task 429 Suite (Lifecycle, Mode, Crypto, Backup Codes, RBAC, Fleet Audit, REST)",
            ["go", "test", "-C", "cli", "-v", "./tests/e2e", "-run", "TestE2E_.*"],
            WORKSPACE
        ),
        (
            "Crypto Subsystem (Argon2id, PBKDF2, RSA-2048/4096, 20 Single-Use Backup Codes)",
            ["go", "test", "-C", "cli", "./crypto/..."],
            WORKSPACE
        ),
        (
            "RBAC Enforcer & Policy Engine (Admin, Manager, Editor, Custom Roles)",
            ["go", "test", "-C", "cli", "./rbac/..."],
            WORKSPACE
        ),
        (
            "Operational Mode CLI & Switcher (Turbo, Interactive, Paranoid)",
            ["go", "test", "-C", "cli", "./cmdmode/..."],
            WORKSPACE
        ),
        (
            "Onboarding Wizard & Lifecycle Detector",
            ["go", "test", "-C", "cli", "./cmdinstall/...", "-run", "Test.*Onboarding.*"],
            WORKSPACE
        ),
        (
            "Embedded Web UI REST Endpoints (/api/v1/mode, /roles, /backup-codes, /fleet)",
            ["go", "test", "-C", "cli", "./cmdui/...", "-run", "TestAPI.*"],
            WORKSPACE
        ),
        (
            "Vitest Web UI Role Management Studio (src/pages/RoleManagement.test.tsx)",
            ["pnpm", "vitest", "run", "src/pages/RoleManagement.test.tsx"],
            WORKSPACE
        ),
    ]

    all_passed = True
    for title, cmd, cwd in steps:
        is_success = run_step(title, cmd, cwd)
        if not is_success:
            all_passed = False

    print("\n" + "=" * 70)
    if all_passed:
        print(">>> ALL 7 E2E TEST SUITES PASSED (100% GREEN) <<<")
        print("=" * 70)
        return 0
    else:
        print(">>> E2E VERIFICATION FAILED <<<")
        print("=" * 70)
        return 1

if __name__ == "__main__":
    sys.exit(main())
