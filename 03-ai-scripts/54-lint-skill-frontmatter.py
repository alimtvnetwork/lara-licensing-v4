#!/usr/bin/env python3
"""Linter to validate YAML frontmatter across all skills in gitmap, D:/work, and .gemini."""

from __future__ import annotations

import os
import sys
import yaml

def check_file(path: str) -> None:
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            lines = f.readlines()
        if not lines or not lines[0].startswith("---"):
            return
        fm_lines = []
        for line in lines[1:]:
            if line.strip() == "---":
                break
            fm_lines.append(line)
        fm_content = "".join(fm_lines)
        yaml.safe_load(fm_content)
    except Exception as exc:
        print(f"FAILED: {path}\n  Error: {exc}")
        if len(lines) >= 3:
            print(f"  Line 1: {lines[0].strip()}")
            print(f"  Line 2: {lines[1].strip()}")
            print(f"  Line 3: {lines[2].strip()}")

def main():
    paths_to_check = [
        "d:/work/gitmap/.agents/skills",
        "d:/work/gitmap/.cursor/skills",
        "C:/Users/Administrator/.gemini/antigravity/builtin/skills",
        "C:/Users/Administrator/.gemini/config/plugins",
    ]
    # Also check other repos in D:/work
    try:
        for entry in os.listdir("d:/work"):
            repo_path = os.path.join("d:/work", entry)
            if os.path.isdir(repo_path):
                s1 = os.path.join(repo_path, ".agents", "skills")
                if os.path.isdir(s1):
                    paths_to_check.append(s1)
                s2 = os.path.join(repo_path, ".cursor", "skills")
                if os.path.isdir(s2):
                    paths_to_check.append(s2)
    except Exception as e:
        print(f"Warning reading d:/work: {e}")

    checked = 0
    for root_dir in paths_to_check:
        if not os.path.exists(root_dir):
            continue
        for root, dirs, files in os.walk(root_dir):
            for file in files:
                if file.lower() in ("skill.md", "skill.yaml"):
                    full_path = os.path.join(root, file)
                    check_file(full_path)
                    checked += 1

    print(f"Total checked: {checked}")

if __name__ == "__main__":
    main()
