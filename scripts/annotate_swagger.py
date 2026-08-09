import os
import re

pattern = re.compile(r'^\s*public\s+function\s+(index|show|store|issue|update|destroy|revoke|ledger|bindings|release|clearCooldown|assignRole|revokeRole|impersonate|endImpersonation|forceEndImpersonation|approve|deny|uploadTicket|publish|yank|shardStatus|serial|hash|final)\s*\(')

def annotate_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    new_lines = []
    for i, line in enumerate(lines):
        match = pattern.search(line)
        if match:
            method = match.group(1)
            # check if previous lines have annotation
            has_annotation = False
            for j in range(max(0, i-5), i):
                if '@OA\\' in lines[j] or '#[OA\\' in lines[j] or '/**' in lines[j] and '*/' not in lines[j]:
                    has_annotation = True
                    break
            
            if not has_annotation:
                verb = "Get"
                if method in ["store", "issue", "publish", "yank", "serial", "hash", "final", "approve", "deny", "uploadTicket", "assignRole", "impersonate", "endImpersonation", "forceEndImpersonation", "release", "clearCooldown"]: verb = "Post"
                if method in ["update", "renew"]: verb = "Patch"
                if method in ["destroy", "revoke", "revokeRole"]: verb = "Delete"

                annotation = f'    /**\n     * @OA\\{verb}(\n     *     path="TODO",\n     *     @OA\\Response(response=200, description="Successful operation")\n     * )\n     */\n'
                new_lines.append(annotation)
        new_lines.append(line)

    with open(filepath, 'w', encoding='utf-8') as f:
        f.writelines(new_lines)

for root, _, files in os.walk('backend/app/Http/Controllers'):
    for file in files:
        if file.endswith('.php'):
            annotate_file(os.path.join(root, file))
