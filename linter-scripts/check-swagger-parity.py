#!/usr/bin/env python3
"""
check-swagger-parity.py - Plan 09 Step 63.

Fail CI when a backend route registered in routes/api.php lacks a
corresponding OpenAPI annotation on its controller action.

Strategy:
  1. Parse routes/api.php to collect all Route::get/post/patch/put/delete
     registrations and map them to Controller@method pairs.
  2. Scan every controller PHP file under app/Http/Controllers/ for
     @OA\\ (l5-swagger) or #[OA\\] (PHP 8 attribute) annotations.
  3. Any route whose Controller@method pair has no annotation is a failure.

Exit codes:
  0 - All routes annotated (or skipped via allowlist).
  1 - One or more routes missing OpenAPI annotation.

Usage:
  python linter-scripts/check-swagger-parity.py
  python linter-scripts/check-swagger-parity.py --backend-dir backend
  python linter-scripts/check-swagger-parity.py --allowlist linter-scripts/swagger-parity.allowlist
"""

from __future__ import annotations

import argparse
import os
import re
import sys
from pathlib import Path
from typing import Iterator

# ---- Constants ---------------------------------------------------------------

NAME = "check-swagger-parity"
ROUTE_PATTERN = re.compile(
    r"Route::(get|post|patch|put|delete)\s*\(\s*['\"][^'\"]+['\"]"
    r"\s*,\s*\[([A-Za-z\\]+)::class\s*,\s*['\"](\w+)['\"]\]",
    re.IGNORECASE,
)
CLOSURE_PATTERN = re.compile(
    r"Route::(get|post|patch|put|delete)\s*\(\s*['\"][^'\"]+['\"]"
    r"\s*,\s*function",
)
ANNOTATION_PATTERN = re.compile(
    r"(@OA\\|#\[OA\\|@OA\(|#\[\\OpenApi)",
)
CONTROLLER_METHOD_ANNOTATION = re.compile(
    r"(?:@OA\\(?:Get|Post|Put|Patch|Delete|Operation)|"
    r"#\[OA\\(?:Get|Post|Put|Patch|Delete|Operation))",
)


# ---- Route parsing -----------------------------------------------------------

def parse_routes(api_php: Path) -> list[tuple[str, str]]:
    """Return list of (ControllerShortName, method) pairs from api.php."""
    text = api_php.read_text(encoding="utf-8")
    pairs: list[tuple[str, str]] = []
    for match in ROUTE_PATTERN.finditer(text):
        fqn = match.group(2)
        short = fqn.split("\\")[-1]
        method = match.group(3)
        pairs.append((short, method))
    return pairs


# ---- Controller scanning -----------------------------------------------------

def find_controller_files(controllers_dir: Path) -> Iterator[Path]:
    """Yield all .php files under controllers_dir recursively."""
    for path in controllers_dir.rglob("*.php"):
        yield path


def build_annotation_index(controllers_dir: Path) -> dict[str, set[str]]:
    """Return {ControllerShortName: {annotated_methods}} from PHP files."""
    index: dict[str, set[str]] = {}
    for php_file in find_controller_files(controllers_dir):
        short = php_file.stem
        text = php_file.read_text(encoding="utf-8", errors="replace")
        annotated = extract_annotated_methods(text)
        if annotated:
            index[short] = annotated
    return index


def extract_annotated_methods(source: str) -> set[str]:
    """Return method names that immediately follow an OA annotation block."""
    annotated: set[str] = set()
    lines = source.splitlines()
    prev_had_annotation = False
    for line in lines:
        if CONTROLLER_METHOD_ANNOTATION.search(line):
            prev_had_annotation = True
        method_match = re.search(r"public\s+function\s+(\w+)\s*\(", line)
        if method_match and prev_had_annotation:
            annotated.add(method_match.group(1))
            prev_had_annotation = False
        elif method_match:
            prev_had_annotation = False
    return annotated


# ---- Allowlist ---------------------------------------------------------------

def load_allowlist(path: Path | None) -> set[str]:
    """Return set of 'Controller@method' strings to skip."""
    if path is None or not path.exists():
        return set()
    lines = path.read_text(encoding="utf-8").splitlines()
    return {line.strip() for line in lines if line.strip() and not line.startswith("#")}


# ---- Main --------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=NAME)
    parser.add_argument("--backend-dir", default="backend")
    parser.add_argument("--allowlist", default=None)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    backend = Path(args.backend_dir)
    api_php = backend / "routes" / "api.php"
    controllers_dir = backend / "app" / "Http" / "Controllers"
    allowlist_path = Path(args.allowlist) if args.allowlist else None

    if not api_php.exists():
        print(f"[{NAME}] SKIP: {api_php} not found.", file=sys.stderr)
        return 0

    allowlist = load_allowlist(allowlist_path)
    routes = parse_routes(api_php)
    annotation_index = build_annotation_index(controllers_dir)

    failures: list[str] = []
    for controller, method in routes:
        key = f"{controller}@{method}"
        if key in allowlist:
            continue
        annotated_methods = annotation_index.get(controller, set())
        if method not in annotated_methods:
            failures.append(key)

    if failures:
        print(f"[{NAME}] FAIL: {len(failures)} route(s) missing OpenAPI annotation:",
              file=sys.stderr)
        for failure in sorted(failures):
            print(f"  - {failure}", file=sys.stderr)
        print(
            f"\nAction: add @OA\\Get/Post/Patch/Put/Delete annotation above each method,\n"
            f"or add 'Controller@method' to the allowlist.\n"
            f"Allowlist path: {allowlist_path or 'linter-scripts/swagger-parity.allowlist'}",
            file=sys.stderr,
        )
        return 1

    print(f"[{NAME}] OK - all {len(routes)} route(s) have OpenAPI annotations.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
