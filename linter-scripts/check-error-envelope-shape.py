#!/usr/bin/env python3

import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent

def check_lara_exceptions():
    violations = []
    exceptions_dir = REPO_ROOT / 'backend' / 'app' / 'Exceptions'
    
    if not exceptions_dir.exists():
        return violations

    for path in exceptions_dir.glob('*.php'):
        if path.name == 'LaraException.php':
            continue
            
        content = path.read_text(encoding='utf-8')
        if 'extends LaraException' in content:
            if 'protected string $errorCode' not in content:
                violations.append(f"{path.relative_to(REPO_ROOT)}: missing 'protected string $errorCode'")
            if 'protected int $httpStatus' not in content and 'protected string $httpStatus' not in content:
                # the typing could be either depending on implementation, but typically int
                if 'protected int $httpStatus' not in content:
                   violations.append(f"{path.relative_to(REPO_ROOT)}: missing 'protected int $httpStatus'")
            if 'protected string $category' not in content:
                violations.append(f"{path.relative_to(REPO_ROOT)}: missing 'protected string $category'")
    return violations

def check_controllers(waivers):
    violations = []
    controllers_dir = REPO_ROOT / 'backend' / 'app' / 'Http' / 'Controllers'
    
    if not controllers_dir.exists():
        return violations

    for path in controllers_dir.rglob('*.php'):
        content = path.read_text(encoding='utf-8')
        if re.search(r'new\s+LaraException\s*\(', content):
            rel_path = str(path.relative_to(REPO_ROOT)).replace('\\', '/')
            if rel_path not in waivers:
                violations.append(f"{rel_path}: uses 'new LaraException(' directly instead of a subclass factory")
    return violations

def check_fe_error_access():
    violations = []
    src_dir = REPO_ROOT / 'src'
    
    if not src_dir.exists():
        return violations

    for path in src_dir.rglob('*.ts*'):
        if path.name == 'lara-api-error.ts':
            continue
        content = path.read_text(encoding='utf-8')
        if re.search(r'\.attributes\??\.Category', content):
            violations.append(f"{path.relative_to(REPO_ROOT)}: uses .attributes?.Category instead of .category")
        if re.search(r'\.attributes\??\.ErrorId', content):
            violations.append(f"{path.relative_to(REPO_ROOT)}: uses .attributes?.ErrorId instead of .errorId")
        if re.search(r'\.attributes\??\.OperationId', content):
            violations.append(f"{path.relative_to(REPO_ROOT)}: uses .attributes?.OperationId instead of .operationId")
    return violations

def load_waivers():
    waivers_file = Path(__file__).parent / 'check-error-envelope-shape.waivers.txt'
    waivers = set()
    if waivers_file.exists():
        for line in waivers_file.read_text(encoding='utf-8').splitlines():
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if ' # reason:' not in line:
                print(f"Invalid waiver format (missing '# reason:'): {line}", file=sys.stderr)
                sys.exit(1)
            waivers.add(line.split(' #')[0].strip().replace('\\', '/'))
    return waivers

def main():
    waivers = load_waivers()
    violations = []
    
    violations.extend(check_lara_exceptions())
    violations.extend(check_controllers(waivers))
    violations.extend(check_fe_error_access())
    
    if violations:
        print("Error envelope shape guard violations:", file=sys.stderr)
        for v in violations:
            print(f"  {v}", file=sys.stderr)
        sys.exit(1)
        
    print("check-error-envelope-shape: OK")

if __name__ == '__main__':
    main()
