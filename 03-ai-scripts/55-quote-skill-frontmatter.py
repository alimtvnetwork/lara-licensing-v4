#!/usr/bin/env python3
"""Standardizes and safely quotes all YAML frontmatter descriptions across .agents/skills and .cursor/skills."""

from __future__ import annotations

import os
import re
import yaml

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

def clean_description(val: str) -> str:
    val = val.strip()
    if (val.startswith('"') and val.endswith('"')) or (val.startswith("'") and val.endswith("'")):
        val = val[1:-1].strip()
    # Escape any backslashes and double quotes
    val = val.replace('\\', '\\\\').replace('"', '\\"')
    return f'"{val}"'

def fix_skill_file(file_path: str) -> bool:
    try:
        with open(file_path, "r", encoding="utf-8", errors="ignore") as f:
            content = f.read()

        if not content.startswith("---"):
            return False

        parts = content.split("---", 2)
        if len(parts) < 3:
            return False

        fm_text = parts[1]
        body = parts[2]

        lines = fm_text.splitlines()
        modified = False
        new_lines = []

        i = 0
        while i < len(lines):
            line = lines[i]
            trimmed = line.strip()
            if trimmed.startswith("description:"):
                after_colon = trimmed[len("description:"):].strip()
                if after_colon in (">", ">-", "|", "|-"):
                    new_lines.append(line)
                elif after_colon.startswith('"') and after_colon.endswith('"') and len(after_colon) >= 2:
                    # Already double quoted, but verify no broken escapes
                    inner = after_colon[1:-1]
                    # Ensure unescaped quotes inside are escaped
                    sanitized = inner.replace('\\"', '"').replace('"', '\\"')
                    new_lines.append(f'description: "{sanitized}"')
                    if f'description: "{sanitized}"' != line:
                        modified = True
                else:
                    quoted = clean_description(after_colon)
                    new_lines.append(f"description: {quoted}")
                    modified = True
            else:
                new_lines.append(line)
            i += 1

        if modified:
            new_fm = "\n".join(new_lines) + "\n"
            # Verify valid yaml
            yaml.safe_load(new_fm)
            new_content = "---" + new_fm + "---" + body
            with open(file_path, "w", encoding="utf-8", newline="\n") as f:
                f.write(new_content)
            return True
    except Exception as e:
        print(f"Error fixing {file_path}: {e}")
        return False
    return False

def main():
    dirs_to_process = [
        os.path.join(REPO_ROOT, ".agents", "skills"),
        os.path.join(REPO_ROOT, ".cursor", "skills"),
    ]

    # Discover all .agents/skills and .cursor/skills in d:/work
    work_dir = "d:/work"
    if os.path.exists(work_dir):
        for root, dirs, _ in os.walk(work_dir):
            if "node_modules" in root or ".git" in root or ".gemini" in root:
                continue
            base = os.path.basename(root)
            if base in (".agents", ".cursor"):
                skills_dir = os.path.join(root, "skills")
                if os.path.isdir(skills_dir) and skills_dir not in dirs_to_process:
                    dirs_to_process.append(skills_dir)

    total_fixed = 0
    for root_dir in dirs_to_process:
        if not os.path.exists(root_dir):
            continue
        for root, dirs, files in os.walk(root_dir):
            for file in files:
                if file.lower() == "skill.md":
                    full_path = os.path.join(root, file)
                    if fix_skill_file(full_path):
                        total_fixed += 1

    print(f"Total skills frontmatter fixed across all repositories: {total_fixed}")

if __name__ == "__main__":
    main()
