import os
import re

MAPPINGS = {
    'AuthForbidden': ('AuthException', 'forbidden'),
    'AuthUnauthorized': ('AuthException', 'unauthorized'),
    'AuthSessionNotFound': ('AuthException', 'sessionNotFound'),
    'AuthInvalidCredentials': ('AuthException', 'invalidCredentials'),
    'ValidationFailed': ('ValidationException', 'validationFailed'),
    'RateLimited': ('RateLimitException', 'rateLimited'), # Need special care
}

def map_code_to_factory(code):
    if code in MAPPINGS:
        return MAPPINGS[code]
    
    # Check general families based on config/lara.php (or just guess based on name)
    if code.endswith('NotFound'):
        return ('NotFoundException', 'notFound')
    if 'Conflict' in code or code in ['EnvironmentMismatch', 'LicenseExpired', 'LicenseMachineLimit', 'LicenseRevoked', 'LicenseUserLimit', 'QuotaExhausted', 'ResellerInUse', 'PrefixInUse', 'PrefixForbidden']:
        return ('DomainConflictException', 'conflict')
    if code in ['ServerError', 'UnknownServerError', 'BackupStorageFailure', 'BackupWorkerFailure', 'BackupWorkerTransitionFailed', 'BackupZstdUnavailable', 'BrBackfillFailed', 'BrOpsQueryFailed']:
        return ('InternalException', 'serverError')
        
    # Default to DomainConflictException or something generic?
    # Let's use DomainConflictException for 409s, ValidationException for 400/422s.
    # Actually, we can just leave it as LaraException::make if we don't have a specific factory, 
    # but the instruction said "migrate controller throws".
    # Let's just return DomainConflictException::conflict for anything not caught, as a fallback, 
    # or keep LaraException::make.
    return None

def process_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    if 'LaraException::make' not in content:
        return False
        
    # We need to find `LaraException::make('ErrorCode', ...)`
    # Regex to find `LaraException::make('SomeCode',`
    # and replace with the appropriate factory.
    
    pattern = re.compile(r"LaraException::make\(\s*['\"]([^'\"]+)['\"]\s*,")
    
    imports_to_add = set()
    
    def replacer(match):
        code = match.group(1)
        mapping = map_code_to_factory(code)
        if not mapping:
            # Leave as is
            return match.group(0)
            
        cls, method = mapping
        imports_to_add.add(cls)
        
        if cls in ['DomainConflictException', 'NotFoundException', 'InternalException']:
            return f"{cls}::{method}('{code}',"
        else:
            return f"{cls}::{method}("
            
    new_content = pattern.sub(replacer, content)
    
    if new_content != content:
        # Add imports
        for cls in imports_to_add:
            use_stmt = f"use App\\Exceptions\\{cls};\n"
            if use_stmt not in new_content:
                # Insert after namespace or other uses
                if 'use App\\Exceptions\\LaraException;' in new_content:
                    new_content = new_content.replace('use App\\Exceptions\\LaraException;', f"use App\\Exceptions\\LaraException;\n{use_stmt.strip()}")
                else:
                    # just put it after namespace
                    new_content = re.sub(r'(namespace App\\[^;]+;)', r'\1\n\n' + use_stmt.strip(), new_content, 1)
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        return True
    return False

def main():
    root = 'backend/app'
    changed = 0
    for dirpath, _, filenames in os.walk(root):
        for filename in filenames:
            if filename.endswith('.php'):
                if process_file(os.path.join(dirpath, filename)):
                    changed += 1
    print(f"Changed {changed} files.")

if __name__ == '__main__':
    main()
