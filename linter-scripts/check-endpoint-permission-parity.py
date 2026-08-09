#!/usr/bin/env python3
import re
import sys
import os

def run():
    perm_file = 'spec/21-app/40-permissions.md'
    endpoint_file = 'spec/21-app/10-endpoints.md'
    
    if not os.path.exists(perm_file):
        print(f"Error: {perm_file} missing")
        sys.exit(1)
    if not os.path.exists(endpoint_file):
        print(f"Error: {endpoint_file} missing")
        sys.exit(1)

    # 1. Parse valid permissions from 40-permissions.md
    valid_perms = {'None'}
    with open(perm_file, 'r') as f:
        content = f.read()
        # Extract from table rows: | Licenses.Create | ... |
        matches = re.findall(r'\| ([\w\.]+) \|', content)
        for m in matches:
            valid_perms.add(m.strip())
    
    print(f"Loaded {len(valid_perms)} valid permissions (including 'None')")

    # 2. Parse permissions used in 10-endpoints.md
    errors = 0
    with open(endpoint_file, 'r') as f:
        lines = f.readlines()
        for i, line in enumerate(lines, 1):
            # Look for table rows: | /path | METHOD | Auth | PermissionKey | ... |
            # We match rows starting with | / and having at least 4 columns
            if line.strip().startswith('| /'):
                parts = [p.strip() for p in line.split('|')]
                if len(parts) >= 5:
                    perm = parts[4]
                    if perm and perm not in valid_perms:
                        print(f"{endpoint_file}:{i}: Invalid permission '{perm}'")
                        errors += 1
    
    if errors > 0:
        print(f"FAILED: {errors} parity errors found")
        sys.exit(1)
    else:
        print("PASSED: All endpoint permissions are valid")

if __name__ == '__main__':
    run()
