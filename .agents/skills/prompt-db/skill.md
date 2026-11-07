---
name: prompt-db
description: "Autonomously explore, extract, share, export, and visualize AI prompts, conversations, and attachments using SQLite Split-DB (prompt.db) and embedded Tokyo Night visualizer."
---

# Prompt Database (`prompt.db`) & Conversation Sharing Skill

This skill trains AI agents and developers to interact with the GitMap Prompt Database (`gitmap pdb` / `gitmap promptdb`), managing AI conversations, prompt prompts, attachments, and analytical summaries across projects.

---

## 1. Architectural Overview & SQLite Split-DB Schema

The prompt database decouples prompts and multi-modal attachments into high-performance relational tables using SQLite in WAL mode:

```
+-----------------------------------------------------------------------------+
|                                Project Table                                |
|  ProjectId, RepoUrl, RepoSlug, LastCommit, CommitCount, FilesModified,      |
|  ArchitecturalDecisions, HeatmapData, AgentDecisionMaking, IsActive         |
+-----------------------------------------------------------------------------+
                                       | 1:N
                                       v
+-----------------------------------------------------------------------------+
|                                 Prompt Table                                |
|  PromptId, ProjectId (FK), Sequence, Title, FullPromptText, Category,       |
|  ConversationId, ConversationSlug, Timestamp, CreatedAt, UpdatedAt          |
+-----------------------------------------------------------------------------+
                                       | 1:N
                                       v
+-----------------------------------------------------------------------------+
|                              Attachment Table                               |
|  AttachmentId, PromptId (FK), Sequence, Title, Filename, MimeType,          |
|  TextContent (UTF-8), BlobData (BLOB), SHA256Hash, SourcePathOrURL, Bytes   |
+-----------------------------------------------------------------------------+
```

### Table Definitions

1. **`Project`**:
   - `ProjectId INTEGER PRIMARY KEY AUTOINCREMENT`
   - `RepoUrl TEXT NOT NULL UNIQUE`
   - `RepoSlug TEXT NOT NULL`
   - `LastCommit TEXT NOT NULL DEFAULT ''`
   - `CommitCount INTEGER NOT NULL DEFAULT 0`
   - `FilesModified INTEGER NOT NULL DEFAULT 0`
   - `ArchitecturalDecisions TEXT NOT NULL DEFAULT ''`
   - `HeatmapData TEXT NOT NULL DEFAULT '{}'`
   - `AgentDecisionMaking TEXT NOT NULL DEFAULT ''`
   - `IsActive INTEGER NOT NULL DEFAULT 1`

2. **`Prompt`**:
   - `PromptId INTEGER PRIMARY KEY AUTOINCREMENT`
   - `ProjectId INTEGER NOT NULL REFERENCES Project(ProjectId) ON DELETE CASCADE`
   - `Sequence INTEGER NOT NULL DEFAULT 1`
   - `Title TEXT NOT NULL`
   - `FullPromptText TEXT NOT NULL`
   - `Category TEXT NOT NULL DEFAULT 'feature'` (`feature`, `bugfix`, `chore`, `refactor`, `audit`, `test`, `doc`)
   - `ConversationId TEXT NOT NULL DEFAULT ''`
   - `ConversationSlug TEXT NOT NULL DEFAULT ''`
   - `Timestamp TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP`

3. **`Attachment`**:
   - `AttachmentId INTEGER PRIMARY KEY AUTOINCREMENT`
   - `PromptId INTEGER NOT NULL REFERENCES Prompt(PromptId) ON DELETE CASCADE`
   - `Sequence INTEGER NOT NULL DEFAULT 1`
   - `Title TEXT NOT NULL`
   - `Filename TEXT NOT NULL`
   - `MimeType TEXT NOT NULL`
   - `TextContent TEXT NULL` (UTF-8 text, markdown, source code)
   - `BlobData BLOB NULL` (PNG, JPG, PDF, ZIP binaries)
   - `SHA256Hash TEXT NOT NULL`
   - `SourcePathOrURL TEXT NOT NULL DEFAULT ''`
   - `SizeBytes INTEGER NOT NULL DEFAULT 0`

4. **`ViewPromptSummary`**:
   High-speed analytical view aggregating prompt length and attachment count without reading heavy BLOBs:
   ```sql
   SELECT p.PromptId, p.ProjectId, COALESCE(pr.RepoUrl, '') AS RepoUrl,
          COALESCE(pr.RepoSlug, '') AS RepoSlug, p.Sequence, p.Title,
          p.Category, p.ConversationId, p.ConversationSlug, p.Timestamp,
          LENGTH(p.FullPromptText) AS PromptLength,
          COUNT(a.AttachmentId) AS AttachmentCount
   FROM Prompt p
   LEFT JOIN Project pr ON p.ProjectId = pr.ProjectId
   LEFT JOIN Attachment a ON p.PromptId = a.PromptId
   GROUP BY p.PromptId;
   ```

---

## 2. CLI Command Suite Reference

Agents use `gitmap pdb` to inspect, extract, and manipulate prompts:

| Command | Purpose |
|---------|---------|
| `gitmap pdb list` | List prompts in database with sequence, category, project, attachment count, and title. |
| `gitmap pdb list --json` | Output prompt list in machine-readable JSON for AI tool calling. |
| `gitmap pdb list --category bugfix` | Filter prompts by category enum. |
| `gitmap pdb list --search <kw>` | Filter prompts by keyword in title or full text. |
| `gitmap pdb show <id>` | Display full prompt text, metadata, and attachment summaries. |
| `gitmap pdb show <id> --copy` | Copy full prompt text directly to system clipboard. |
| `gitmap pdb show <id> --extract` | Extract attachments and print Antigravity reference markdown links. |
| `gitmap pdb extract <id>` | Extract attachments to `$TEMP/gitmap-prompt-cache/<slug>/` with Antigravity links. |
| `gitmap pdb extract <id> --copy` | Extract attachments and copy all reference markdown links to clipboard. |
| `gitmap pdb create [path]` | Initialize a new `prompt.db` database schema on the fly. |
| `gitmap pdb ui` | Launch Tokyo Night browser visualizer at `http://127.0.0.1:<port>`. |
| `gitmap pdb ui --chrome` | Launch visualizer directly in Google Chrome. |
| `gitmap pdb export <conv-id>` | Package an Antigravity conversation into a portable bundle folder. |
| `gitmap pdb import <file.json>` | Import prompt and attachments from JSON into `prompt.db`. |

---

## 3. Antigravity Attachment Reference Link Invariant

When extracting binary or text attachments from the database, GitMap extracts them on-demand to the local filesystem cache and emits the canonical Antigravity reference link format:

```markdown
[Attachment: <title> (<sequence>)](file;<target-path>)
```

Example output:
```markdown
[Attachment: Architecture Diagram (1)](file;C:/Users/Administrator/AppData/Local/Temp/gitmap-prompt-cache/cg-sync/arch-01.png)
```

AI agents can directly copy or ingest this reference link into instructions or documentation.

---

## 4. Conversation Export Bundle Structure

When running `gitmap pdb export <conversation-id> --out ./exports --commit`, GitMap generates a self-contained, portable folder:

```
conversation-<conv-id>/
├── conversation.db      # Compact SQLite database containing prompts & attachments
├── manifest.json        # Export metadata (step count, exportedAt, version)
├── README.md            # Markdown summary with titles, categories, and relative links
└── attachments/         # Harvested binaries and images
    ├── screenshot-01.png
    └── diagram.png
```

---

## 5. Browser UI Visualizer (`gitmap pdb ui`)

The embedded local HTTP server provides:
- **Tokyo Night Dark Palette**: Matching GitMap CLI visual aesthetics.
- **Split-Pane Layout**: Left sidebar listing all prompts with search bar and category filters; right pane displaying active prompt details.
- **1-Click "Copy Prompt"**: Instantly copies the full prompt text to clipboard.
- **Inline Multi-Modal Media**: Inline rendering of images (`image/*`) and file badges.
- **1-Click Antigravity Reference**: Copies `[Attachment: <title> (<seq>)](file;<path>)` directly for AI prompting.
