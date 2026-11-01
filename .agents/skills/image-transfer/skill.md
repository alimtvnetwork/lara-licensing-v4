---
name: image-transfer
description: Autonomously discover, transfer, optimize, and embed screenshot and diagram assets into repository documentation and specifications with automatic directory creation, SHA-256 deduplication, and strictly relative Markdown links.
---

# Image Transfer & Documentation Asset Ingestion Skill (`image-transfer`)

> **Invocation:** `@[image-transfer](file;.agents/skills/image-transfer)`

This skill guides autonomous AI agents in discovering, transferring, verifying, and embedding screenshot and architecture diagram assets into documentation and specifications without relying on ad-hoc Python scripts or raw shell commands.

## 1. Non-Negotiable Invariants & Prohibitions

1. **Total Ban on Python Shutil Scripts:**
   - NEVER run `python -c "import os, shutil; os.makedirs(...); shutil.copyfile(...)"` or ad-hoc Python scripts to move images.
   - ALWAYS use compiled `gitmap image transfer <src> [dst]`.
2. **Total Ban on PowerShell Copy-Item:**
   - NEVER run `Copy-Item`, `Move-Item`, or shell `cp` to copy screenshot assets.
   - ALWAYS use `gitmap image transfer`.
3. **Total Ban on PIL/Pillow Inspection Scripts:**
   - NEVER run `python -c "from PIL import Image..."` to check image size or dimensions.
   - ALWAYS use `gitmap image transfer <path> --inspect` or `gitmap cat <path> --info`.
4. **Strict Relative Markdown Paths (Zero Absolute Paths):**
   - NEVER write absolute Windows drive paths, Linux absolute paths (`/home/...`, `/tmp/...`), or URI schemes (`file://...`) in Markdown embeds.
   - ALWAYS use strictly relative paths anchored to the repository root: `![Caption](assets/screenshots/<filename>.png)`.

## 2. Standard Repository Asset Directory Layout

All documentation and specification assets MUST be placed in one of three standardized directories:

| Directory | Purpose | Retention & Commit Policy |
| :--- | :--- | :--- |
| `assets/screenshots/` | Primary application screenshots, UI flows, and visual verification assets. | Committed to Git; capped at 500 KB per asset. |
| `02-spec/assets/` | Architecture diagrams, component flowcharts, and specification mockups. | Committed to Git; preferred SVG or optimized PNG. |
| `.ai-memory/assets/` | Agent session memory assets, task execution artifacts, and transient evidence. | Project-scoped memory storage; committed or managed per task. |

## 3. Core Workflows & Execution Patterns

### Workflow A: Transferring the Latest Screenshot Automatically
When a user asks to embed the latest screenshot or test artifact:
```bash
# Finds latest screenshot in system Downloads / Temp / Brain and saves to assets/screenshots/
gitmap image transfer --latest feature-preview.png --markdown
```
*Output:*
```
[GitMap Image] Successfully transferred latest screenshot:
  Source:      downloads/Screenshot_20261101_143022.png
  Destination: assets/screenshots/feature-preview.png
  Size:        142.6 KB (146,022 bytes)
  Dimensions:  1920x1080 (PNG)
  SHA-256:     e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855

Markdown Embed:
![Feature Preview](assets/screenshots/feature-preview.png)
```

### Workflow B: Transferring an Explicit Source Image
When a source file path is provided:
```bash
gitmap image transfer "downloads/raw_mockup.png" assets/screenshots/mockup-v1.png --markdown
```

### Workflow C: Transferring to Specification Directory
When adding an image to a specific specification folder:
```bash
gitmap image transfer "downloads/diagram.png" --dest 02-spec/21-app/373-gitmap-skill-and-function-for-automatic-image-transfer/assets --name architecture.png --markdown
```

### Workflow D: Inspecting Image Properties
When validating image dimensions, mime type, or integrity before embedding:
```bash
gitmap image transfer assets/screenshots/mockup-v1.png --inspect
```

### Workflow E: Structured JSON Output for Swarm Agents
When invoking within a multi-agent tree swarm:
```bash
gitmap image transfer "downloads/step1.png" assets/screenshots/step1.png --json
```

### Workflow F: Batch Transfer All Conversation Uploads
When transferring all screenshots from the current conversation:
```bash
gitmap image transfer --all-uploaded --markdown
```

## 4. Command Matrix & Flag Reference

| Command / Flag | Alias | Type | Description |
| :--- | :--- | :---: | :--- |
| `gitmap image transfer <src> [dst]` | `gitmap img transfer`, `gitmap image cp` | Command | Stream-copies `<src>` to `[dst]`, auto-creating parent directories. |
| `--latest` | `-l` | bool | Automatically resolves newest screenshot from Brain, Downloads, or Temp. |
| `--all-uploaded` | `-a` | bool | Transfers all conversation-uploaded media in batch mode. |
| `--dest <dir>` | `-d` | string | Target directory (defaults to `assets/screenshots`). |
| `--name <name>` | `-n` | string | Custom target filename override. |
| `--relative` | `-r` | bool | Forces strictly relative markdown path output from working directory. |
| `--markdown` | `-m` | bool | Emits ready-to-paste markdown embed snippet (`![Caption](path)`). |
| `--inspect` | `-i` | bool | Pure Go header byte probe ($W \times H$, MIME, SHA-256) without Python PIL. |
| `--json` | `-j` | bool | Emits structured JSON envelope for multi-agent swarm orchestration. |
| `--force` | `-f` | bool | Overwrites destination files if they exist without prompt. |

## 5. Error Handling & Validation
- **Missing Source:** Returns `ERR_IMAGE_SOURCE_NOT_FOUND` with clear guidance.
- **Unsupported Format:** Returns `ERR_IMAGE_UNSUPPORTED_FORMAT` (allowed: `.png`, `.jpg`, `.jpeg`, `.webp`, `.gif`, `.svg`).
- **File Size Guard:** Warns if file exceeds 500 KB limit (Rule R19).
- **Directory Creation:** Automatically creates parent folders (`os.MkdirAll`) if they do not exist.
