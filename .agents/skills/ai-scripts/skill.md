---
name: ai-scripts
description: >-
  Standard index, execution catalog, and guidelines for reusable Python automation scripts in 03-ai-scripts/ and .agents/scripts/. Enforces reuse-first principles and fast-forwarding CI/CD checks.
---

# AI Scripts Catalog & Automation Engine

> **Invocation:** `@[ai-scripts](file;.agents/skills/ai-scripts)`  
> **Location:** `03-ai-scripts/` and mirrored in `.agents/scripts/`  
> **Discipline:** Reuse First — Never write temporary disposable code if a canonical script exists.

---

## 1. Executive Summary & Reuse-First Principle

Before writing any custom scripts, one-off CLI commands, or regex replacers, agents MUST consult this catalog to verify if a canonical script already implements the workflow.

All scripts in `03-ai-scripts/` adhere to:
1. **Zero External Dependencies**: Standard library Python 3.10+ only (except optional dev tools).
2. **Cross-Platform Compatibility**: Clean execution across Windows (PowerShell/CMD), macOS, and Linux.
3. **Additive-Only Invariant**: During multi-repo synchronization, scripts are additive; repos never overwrite local enhancements made by target projects.
4. **Fast Performance**: Sub-second execution using compiled regexes, thread pools, and memory caching.

---

## 2. Canonical AI Scripts Directory

| # | Script Name | Purpose & Capabilities | Command Usage |
| :---: | :--- | :--- | :--- |
| **02** | `02-shared-engine.py` | Shared caching engine, regex registry, mtime invalidation | Imported by other scripts |
| **06** | `06-cicd-local-runner.py` | Full local CI/CD pipeline runner executing 18 quality gates | `python 03-ai-scripts/06-cicd-local-runner.py` |
| **07** | `07-relative-path-fixer.py` | Detects and replaces absolute paths (`C:\`, `D:\`, `file:///`) with clean relative paths | `python 03-ai-scripts/07-relative-path-fixer.py` |
| **08** | `08-naming-autofixer.py` | Enforces positive booleans (`is*`, `has*`) and implicit truth checks | `python 03-ai-scripts/08-naming-autofixer.py` |
| **10** | `10-encoding-normalizer.py` | UTF-8 and strict UNIX LF line ending normalizer | `python 03-ai-scripts/10-encoding-normalizer.py` |
| **11** | `11-fast-file-scanner.py` | AST & regex fast file scanner (<15ms) across Go, TS, Python, Rust | `python 03-ai-scripts/11-fast-file-scanner.py` |
| **13** | `13-file-size-guard.py` | Scans for oversized files (>100 lines, >500 lines) and binary blobs | `python 03-ai-scripts/13-file-size-guard.py` |
| **14** | `14-version-sync-checker.py` | Cross-validates version numbers across `version.json`, code manifests, and tags | `python 03-ai-scripts/14-version-sync-checker.py` |
| **38** | `38-sync-prompts-skills-scripts.py` | Propagates canonical prompts, skills, specs 01-20, and scripts across all 43 repos | `python 03-ai-scripts/38-sync-prompts-skills-scripts.py` |

---

## 3. High-Frequency Operational Workflows

### 3.1 Pre-Flight Quality Check
Before staging or committing code, run the local CI runner to verify all quality gates pass:
```bash
python 03-ai-scripts/06-cicd-local-runner.py --fast
```

### 3.2 Dual-Tree Parity Verification
Verify that `.agents/skills/` and `.cursor/skills/` are in 100% synchronization:
```bash
python 03-ai-scripts/14-version-sync-checker.py
```

### 3.3 Fleet Synchronization Dry-Run
Preview which repositories in the 43-repo fleet have drifted from canonical prompts and skills:
```bash
python 03-ai-scripts/38-sync-prompts-skills-scripts.py --dry-run
```

---

## 4. Script Authoring Standards

When adding or modifying scripts in `03-ai-scripts/`:
1. Use `#!/usr/bin/env python3` shebang.
2. Include comprehensive docstrings specifying inputs, outputs, and safety boundaries.
3. Enforce strict positive booleans (`is_dry_run`, `has_changes`, `is_quiet`).
4. Support `--dry-run` on any script performing file mutations.
5. Mirror all new scripts into `.agents/scripts/` to ensure parity.
