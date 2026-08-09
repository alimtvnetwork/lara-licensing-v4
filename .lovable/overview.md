# Project Overview

> AI onboarding file. Read this after `README.md` and `.lovable/what-to-read.md`, before touching anything else. Every AI session starts with amnesia; this file is the shared frame.

---

## 1. What This Project Is

This repository is a **specification-first, AI-operated knowledge base**. Its primary artifact is not application code, it is the corpus of specs, guidelines, memories, and linter tooling that instructs future AI sessions (and humans) how to build and maintain downstream systems consistently.

The `src/` tree is a minimal TanStack Start v1 shell used to preview and later host a docs/dashboard viewer over the spec content. It is intentionally thin. The center of gravity is `spec/` and `.lovable/`, not `src/`.

## 2. Deliverables

1. `spec/` — the formal, numbered specification phases (authoring guide, coding guidelines, error management, DB conventions, split-DB, design system, docs viewer, code block system, distribution and runner, generic release, main worker service, app, app-db, app-ui, etc.).
2. `.lovable/` — the AI's persistent memory: reading list, coding guidelines mirror, memory index, plans, suggestions, prompts, pending tasks and issues.
3. `linter-scripts/` and `linters/` — enforcement tooling that keeps the spec and repo internally consistent (cross-links, forbidden strings, function lengths, prompt loading, README canonicals, MWS error codes, etc.).
4. `scripts/` — repo maintenance scripts (fix-repo, visibility-change), cross-platform (`.sh` and `.ps1` pairs).
5. A published docs viewer served from the TanStack Start app in `src/` (later phase).

## 3. Primary Audiences

- **AI sessions.** Every session is treated as a fresh onboarding. `.lovable/what-to-read.md` is the entry protocol; this file is step 2 of it.
- **Human maintainers.** Read `README.md` first, then this file, then the phase of `spec/` relevant to the task.

## 4. High-Level Layout

```
.
├── README.md                # Human + AI entry point
├── .lovable/                # Persistent AI memory (this folder)
│   ├── what-to-read.md      # Ordered reading list, authoritative
│   ├── overview.md          # THIS FILE
│   ├── coding-guidelines/   # Mandatory coding rules for this repo
│   ├── memory/              # Institutional memory (index + subfolders)
│   ├── prompts/             # Reusable prompt registry
│   ├── suggestions/         # Verbatim suggestion capture
│   ├── pending-tasks/       # One .md per active task
│   ├── pending-issues/      # One .md per open issue
│   └── strictly-avoid/      # One .md per hard prohibition
├── spec/                    # Numbered specification phases (00 to 24)
├── linter-scripts/          # Python + shell linters
├── linters/                 # Per-language linter configs (Go, PHP, C#, Sonar)
├── scripts/                 # Repo maintenance scripts (bash + PowerShell)
└── src/                     # TanStack Start app shell (docs viewer host)
```

There is exactly ONE memory folder: `.lovable/memory/`. Never create `.lovable/memories/`.

## 5. Non-Negotiable Conventions

These are the rules that appear repeatedly across `spec/` and the linters. Break one and the linters fail the build.

### Naming
- Folder and file slugs: **kebab-case**, numeric prefix for ordering (`04-database-conventions/`, `17-consolidated-guidelines/`). See `spec/02-coding-guidelines/01-cross-language/28-slug-conventions.md`.
- Tables, types, entities: **PascalCase**.
- Fields, columns, variables: **camelCase**.
- JSON keys and values: **PascalCase** (see `spec/02-coding-guidelines/01-cross-language/11-key-naming-pascalcase.md`).
- Booleans: prefix with `is` or `has`, always positive, never negated.

### Code shape
- Function length tiers: best 8, hard cap 15, waiver window 16 to 25 with `# lint-allow: function-length`, framework ceiling 60. Enforced by `linter-scripts/check-function-lengths.py`.
- No nested `if`. No negated conditions. No magic strings or numbers (use Enums or Constants).
- No swallowed errors: every `catch` logs per `spec/03-error-manage/`.
- Files and classes: 80 to 100 lines max.
- Types: narrow. `any` / `unknown` only at trust boundaries, narrow immediately.
- React/TS components: small, reusable, planned with a Mermaid component diagram for multi-component features.

### Data
- Every primary key: `int auto-increment`, named `{PascalCaseTableName}Id`.
- `Type`, `Status`, `Category`, `Kind` columns: normalize to 1-N or N-M join tables, never inline strings.
- Default DB: SQLite, ORM preferred, joins and PK/FK explicit, every DB discussion carries a Mermaid ERD.

### Versioning and releases
- Every change to the codebase bumps the minor version.
- Changelog and release notes update in the same commit.
- Version is pinned in the root `README.md` when feasible.
- Do not hand-edit `version.json` or `src/data/specTree.json`; they are generated.

### Cross-references
- Spec files cross-link with relative paths. The `linter-scripts/check-spec-cross-links.py` and `check-spec-folder-refs.py` linters enforce this.
- `spec/*/00-overview.md` is the required entry file for every phase (see `spec/01-spec-authoring-guide/03-required-files.md`).

## 6. How Work Gets Done

1. Open `.lovable/what-to-read.md` and follow it top to bottom.
2. Identify the task type: coding, spec authoring, SEO, release, memory, prompt.
3. Read the matching guideline (`.lovable/coding-guidelines/` for code, `spec/01-spec-authoring-guide/` for spec, `spec/16-generic-release/` for release).
4. State the root cause in one sentence before writing any fix.
5. Make the minimum correct change. No symptom patching, no silent try/catch.
6. Verify with build output, logs, or preview. Show before/after signal.
7. Bump minor version, update changelog and release notes, pin in root README where applicable.
8. Update or create the relevant memory file under `.lovable/memory/` if the change encodes a new decision, constraint, or pattern.

## 7. Pointers Into `spec/`

Open the phase that matches your task; do not read the whole spec tree per session.

- Authoring specs → `spec/01-spec-authoring-guide/`
- Writing code → `spec/02-coding-guidelines/`, especially `01-cross-language/15-master-coding-guidelines/`
- Handling errors → `spec/03-error-manage/`
- Database work → `spec/04-database-conventions/`, `spec/05-split-db-architecture/`, `spec/23-app-db/`
- Config seeding → `spec/06-seedable-config-architecture/`
- UI, design tokens, docs viewer, code blocks → `spec/07-design-system/`, `spec/08-docs-viewer-ui/`, `spec/09-code-block-system/`, `spec/24-app-ui-design-system/`
- CLI and PowerShell tooling → `spec/11-powershell-integration/`, `spec/13-generic-cli/`
- CI/CD → `spec/12-cicd-pipeline-workflows/`
- Update, distribution, release → `spec/14-update/`, `spec/15-distribution-and-runner/`, `spec/16-generic-release/`
- Cross-cutting consolidation → `spec/17-consolidated-guidelines/`
- WordPress plugin gold-standard → `spec/18-wp-plugin-how-to/`
- Main worker service → `spec/19-main-worker-service/`
- App scope → `spec/21-app/`, `spec/22-app-issues/`. Licensing communication protocol lives as a paired artifact: the rendered diagram in `spec/21-app/diagrams/licensing-flow.mmd` and its searchable JSON companion in `spec/21-app/diagrams/licensing-flow.json`. Downstream tooling and any query that needs to walk actors, messages, or branches without parsing Mermaid MUST consume the JSON. Ordering rules and JSON companion contract: `spec/21-app/diagrams/00-diagram-contract.md`. Machine enforcement: `linter-scripts/check-mmd-actor-order.py`.

## 8. Current State

Scaffolding phase. The orientation files and hard-constraint files now exist. The reading list still references the full `memory/` subtree, `plan.md`, `suggestions.md`, `pending-tasks/`, `pending-issues/`, `prompts/index.md`, and `prompts/01-write-memory.md`. Creating them in reading-list order is the current active work.

## 9. What This File Is Not

- Not a plan. The active roadmap lives in `.lovable/plan.md`.
- Not a rule list. Hard prohibitions live in `.lovable/strictly-avoid.md` + `strictly-avoid/`.
- Not a decision log. Recorded decisions live under `.lovable/memory/decisions/`.
- Not a spec. Formal specifications live under `spec/`.

Keep this file short, stable, and boring. Update it only when the project's identity, deliverables, audiences, or top-level conventions change.

---

*Version tracked with the repo. Bump on structural change only.*
