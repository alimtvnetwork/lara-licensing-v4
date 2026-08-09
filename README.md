# Project

AI-first project. Every AI session starts with amnesia, so all durable knowledge lives in `.lovable/` and `spec/`. Read the files listed below before doing any work.

**Version:** 0.691.0

## Status

[![backend-static-analysis](https://github.com/OWNER/REPO/actions/workflows/backend-static-analysis.yml/badge.svg?branch=main)](https://github.com/OWNER/REPO/actions/workflows/backend-static-analysis.yml)
[![frontend-static-analysis](https://github.com/OWNER/REPO/actions/workflows/frontend-static-analysis.yml/badge.svg?branch=main)](https://github.com/OWNER/REPO/actions/workflows/frontend-static-analysis.yml)
[![error-contract](https://github.com/OWNER/REPO/actions/workflows/error-contract.yml/badge.svg?branch=main)](https://github.com/OWNER/REPO/actions/workflows/error-contract.yml)
[![backend-e2e](https://github.com/OWNER/REPO/actions/workflows/backend-e2e.yml/badge.svg?branch=main)](https://github.com/OWNER/REPO/actions/workflows/backend-e2e.yml)
[![frontend-e2e](https://github.com/OWNER/REPO/actions/workflows/frontend-e2e.yml/badge.svg?branch=main)](https://github.com/OWNER/REPO/actions/workflows/frontend-e2e.yml)
[![nightly-e2e](https://github.com/OWNER/REPO/actions/workflows/nightly-e2e.yml/badge.svg)](https://github.com/OWNER/REPO/actions/workflows/nightly-e2e.yml)
[![release-smoke](https://github.com/OWNER/REPO/actions/workflows/release-smoke.yml/badge.svg)](https://github.com/OWNER/REPO/actions/workflows/release-smoke.yml)
![version](https://img.shields.io/badge/version-0.691.0-blue)

## Folder Structure

```
.
├── README.md                       # This file: entry point for humans and AI
├── .lovable/                       # AI-readable project knowledge (persistent memory)
│   ├── what-to-read.md             # Ordered reading list for every AI session
│   ├── coding-guidelines/          # Mandatory coding rules
│   │   └── coding-guidelines.md
│   ├── memory/                     # Institutional knowledge
│   │   ├── index.md                # Canonical index of all memory files
│   │   ├── architecture/           # System design decisions
│   │   ├── avoid/                  # Things to skip
│   │   ├── constraints/            # Hard constraints
│   │   ├── decisions/              # Recorded decisions
│   │   ├── history/                # historical logs
│   │   ├── patterns/               # Reusable patterns
│   │   ├── processes/              # Workflows
│   │   ├── specs/                  # Verbatim user specs
│   │   ├── standards/              # Technical standards
│   │   ├── style/                  # Naming and style
│   │   └── workflow/               # Current workflow state
│   ├── prompts/                    # Reusable prompt registry
│   │   └── index.md
│   ├── suggestions/                # Verbatim suggestion capture
│   │   └── index.md
│   ├── pending-tasks/              # Active tasks (one .md each)
│   ├── completed-tasks/            # Finished tasks archive
│   ├── pending-issues/             # Open issues
│   ├── solved-issues/              # Resolved issues with root cause
│   ├── strictly-avoid/             # Hard prohibitions (one .md each)
│   ├── cicd-issues/                # CI/CD issue records
│   ├── plan.md                     # Current roadmap
│   ├── suggestions.md              # Suggestion tracker (single file)
│   ├── strictly-avoid.md           # Quick-read prohibition summary
│   ├── cicd-index.md               # CI/CD issue index
│   ├── overview.md                 # AI onboarding
│   └── user-preferences.md         # User communication preferences
├── spec/                           # Formal specifications
└── src/                            # Application code
```

Folder rules: kebab-case, numeric prefixes for ordering. There is exactly ONE memory folder: `.lovable/memory/`. Never create `.lovable/memories/`.

## What The AI Must Read

The canonical, ordered reading list lives in `.lovable/what-to-read.md`. Every AI session must open that file first.

Short version, in order:

1. `.lovable/what-to-read.md` (this list, authoritative)
2. `.lovable/overview.md` (project onboarding)
3. `.lovable/strictly-avoid.md` and `.lovable/strictly-avoid/` (hard prohibitions)
4. `.lovable/user-preferences.md` (communication style)
5. `.lovable/coding-guidelines/coding-guidelines.md` (mandatory rules)
6. `.lovable/memory/index.md` (survey all memory)
7. `.lovable/plan.md` or `.lovable/plans/index.md` (roadmap)
8. `.lovable/suggestions.md` (pending suggestions)
9. `.lovable/pending-tasks/` and `.lovable/pending-issues/` (open work)
10. Task-specific spec under `spec/`
