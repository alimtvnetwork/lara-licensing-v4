#!/usr/bin/env python3

import os
import json
import re
import sys

def main():
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    operations_file = os.path.join(root, 'src', 'generated', 'api', 'operations.lock.json')
    routes_file = os.path.join(root, 'backend', 'routes', 'api.php')
    waivers_file = os.path.join(os.path.dirname(__file__), 'check-endpoint-operation-parity.waivers.txt')

    if not os.path.exists(operations_file):
        print("Missing operations.lock.json")
        sys.exit(1)

    with open(operations_file, 'r', encoding='utf-8') as f:
        ops_data = json.load(f)
        fe_operations = set(ops_data.keys())

    be_operations = set()
    if os.path.exists(routes_file):
        with open(routes_file, 'r', encoding='utf-8') as f:
            content = f.read()
            # find all ->name('...')
            matches = re.findall(r"->name\(['\"]([^'\"]+)['\"]\)", content)
            be_operations.update(matches)

    waivers = set()
    if os.path.exists(waivers_file):
        with open(waivers_file, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                # format: operationId # reason: ...
                if ' # reason:' not in line:
                    print(f"Invalid waiver format (missing '# reason:'): {line}")
                    sys.exit(1)
                waivers.add(line.split(' #')[0].strip())

    missing_in_be = (fe_operations - be_operations) - waivers
    missing_in_fe = (be_operations - fe_operations) - waivers

    if missing_in_be or missing_in_fe:
        print("Parity mismatch between FE operations and BE routes:")
        if missing_in_be:
            print("Missing in BE:")
            for op in sorted(missing_in_be):
                print(f"  - {op}")
        if missing_in_fe:
            print("Missing in FE:")
            for op in sorted(missing_in_fe):
                print(f"  - {op}")
        sys.exit(1)

    print("Parity check passed.")

if __name__ == '__main__':
    main()
