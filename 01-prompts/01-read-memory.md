---
title: Read Memory (Enhanced)
slug: read-memory-enhanced
version: 1.7
---

# Read Memory (Enhanced)

## Ambiguity folder path (non-negotiable)

- Open questions: `.ai-memory/ambiguous-questions/01-new-ambiguity/XX-<slug>.md`
- Answered questions: `.ai-memory/ambiguous-questions/02-ambiguity-resolved/XX-<slug>.md`

Read both folders in full during Phase 1. Surface open-ambiguity counts and slugs in the Completion Confirmation block. Treat resolved-ambiguity files as binding project decisions, do not re-litigate them. If an open ambiguity is relevant to the incoming task, stop and surface it before doing work; never guess past it.

## Goal

Before you touch this project, load its identity into your head: who it is, what it forbids, what it has already decided, and what work is in flight.

The specs and the `.ai-memory/` folder are the single source of truth. Your training data is not. If the two disagree, the repo wins, every time.

You are done reading when you can, without guessing:

- name the CODE RED rules,
- name the naming, error-handling, and DB conventions,
- list what is currently in `.ai-memory/plans/pending/`,
- point at the exact file that justifies any rule you enforce.

If you cannot do that, keep reading. Do not start work.

---

## Phase 1 - Load the project

### 1.1 Read the whole `.ai-memory/` folder

Walk `.ai-memory/` recursively. Every file matters. Missing files are noted, not silently skipped. In particular:

| #   | Path                                                  | What you get                                                                                                                                |
| --- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `.ai-memory/overview.md`                                | Project summary, stack, nav map                                                                                                             |
| 2   | `.ai-memory/strictly-avoid.md`                          | Hard prohibitions (CODE RED)                                                                                                                |
| 3   | `.ai-memory/user-preferences`                           | How the human wants you to behave                                                                                                           |
| 4   | `.ai-memory/what-to-read.md`                            | **Authoritative reading order** for this project. If it exists, it overrides the generic order in this prompt. Read it first and follow it. |
| 5   | `.ai-memory/prompt.md` + `01-prompts/`            | Canonical prompts (Read, Plan, etc.). "Read memory" = run this prompt.                                                                      |
| 6   | `.ai-memory/memory/index.md`                            | Index of institutional knowledge. Then read every file it references, recursively.                                                          |
| 7   | `.ai-memory/plans/index.md`                             | Roll-up of all plans (pending + completed + subtasks). Read this before touching individual plan files.                                     |
| 8   | `.ai-memory/plans/pending/`                             | Active plans, `XX-<slug>.md`                                                                                                                |
| 9   | `.ai-memory/plans/completed/`                           | Recent history, skim only                                                                                                                   |
| 10  | `.ai-memory/plans/subtasks/XX-<slug>/`                  | Depth files linked from a parent plan                                                                                                       |
| 11  | `.ai-memory/suggestions.md`                             | Ideas not yet approved                                                                                                                      |
| 12  | `.ai-memory/02-spec/commands/`                             | User commands and conventions, `XX-<slug>.md`                                                                                               |
| 13  | `.ai-memory/issues/`                                    | General bugs and regressions                                                                                                                |
| 14  | `.ai-memory/cicd-issues/`                               | CI/CD-specific failures. Read ALL of these before any code change so you do not repeat the same mistakes.                                   |
| 15  | `.ai-memory/ambiguous-questions/01-new-ambiguity/`      | Open questions currently blocking work. If any exist, surface them in the completion block, do NOT guess past them.                         |
| 16  | `.ai-memory/ambiguous-questions/02-ambiguity-resolved/` | Answered questions with their applied solution. Treat these as binding decisions, do not re-litigate.                                       |
| 17  | Anything else under `.ai-memory/`                       | Read it. If the folder exists, it exists for a reason.                                                                                      |

### 1.2 The two index files

Two indexes decide what you read next. Treat them as required entry points, not as summaries:

- `.ai-memory/memory/index.md` lists every institutional-knowledge file. If it points at 12 files, you read 12 files.
- `.ai-memory/plans/index.md` lists every plan (pending, completed, subtasks) with its slug, status, and one-line intent. Use it to pick which plan files to open in full. If it is missing, create it as part of the next code change (see Memory Update Protocol).

### 1.3 Self-check (internal, before Phase 2)

- CODE RED rules?
- Naming conventions (files, folders, DB columns, variables)?
- Error-handling philosophy?
- What is in `.ai-memory/plans/pending/` right now?
- Top forbidden patterns?

If any answer is fuzzy, go back and reread. Do not proceed.

---

## Phase 2 - Consolidated guidelines

Read `02-spec/12-consolidated-guidelines/` in numeric order (`01-*.md` through `18-*.md`). Each file is a self-contained policy document. Missing folder: note it and continue.

---

## Phase 3 - Spec authoring rules

Read `02-spec/01-spec-authoring-guide/` in numeric order. You should come out knowing:

- file and folder naming conventions,
- required files per spec folder (`00-overview.md`, `99-consistency-report.md`),
- the `.ai-memory/` layout (see Phase 1.1),
- the linter infrastructure.

---

## Phase 4 - Task-driven deep dives

Only open a spec folder when the current task needs it.

| Task involves…                           | Read                                    |
| ---------------------------------------- | --------------------------------------- |
| Writing or reviewing code                | `02-spec/02-coding-guidelines/`            |
| Error handling                           | `02-spec/03-error-manage/`                 |
| Database schema or queries               | `02-spec/04-database-conventions/`         |
| SQLite / multi-DB architecture           | `02-spec/05-split-db-architecture/`        |
| Config systems                           | `02-spec/06-seedable-config-architecture/` |
| UI theming, CSS variables, design tokens | `02-spec/07-design-system/`                |
| Documentation viewer features            | `02-spec/08-docs-viewer-ui/`               |
| Code block rendering                     | `02-spec/09-code-block-system/`            |
| PowerShell scripts                       | `02-spec/10-powershell-integration/`       |
| CI/CD pipelines                          | `02-spec/13-cicd-pipeline-workflows/`      |
| CLI self-update                          | `02-spec/14-self-update-app-update/`       |
| WordPress plugins                        | `02-spec/15-wp-plugin-how-to/`             |
| App-specific features                    | `02-spec/21-app/`                          |
| Known app bugs                           | `02-spec/22-app-issues/`                   |
| App-specific DB schema                   | `02-spec/23-app-database/`                 |
| App-specific UI + design system          | `02-spec/24-app-design-system-and-ui/`     |

Inside each folder: `00-overview.md` → numbered files → `99-consistency-report.md`.

Fallbacks when the canonical numbered folder is absent: `.ai-memory/coding-guidelines.md`, `02-spec/coding-guidelines/`, `coding-guidelines/`, `02-spec/XX-error-manage/`. Numbered folder wins on conflict; call the conflict out in the plan's Context.

---

## Anti-Hallucination Contract

1. If the specs are silent on a rule, that rule does not exist. Do not invent one.
2. Specs beat training data. Always.
3. Cite the file and section when you enforce a rule.
4. When a spec is ambiguous, ask. Do not "use best judgement".
5. Do not blend this project's conventions with conventions from other projects you have seen.
6. No filler. No "hope this helps", no "let me know".

---

## Memory Update Protocol

```
New info discovered
├─ Institutional knowledge (pattern / convention / decision)?
│   YES → .ai-memory/memory/<slug>.md  +  update .ai-memory/memory/index.md
├─ Must never happen again?
│   YES → .ai-memory/strictly-avoid.md
├─ Idea, not yet approved?
│   YES → .ai-memory/suggestions.md
├─ New user command / convention?
│   YES → .ai-memory/02-spec/commands/XX-<slug>.md
├─ Bug / regression?
│   YES → .ai-memory/issues/XX-<slug>.md   (or .ai-memory/cicd-issues/ if CI/CD)
├─ New or changed plan?
│   YES → .ai-memory/plans/pending/XX-<slug>.md  +  update .ai-memory/plans/index.md
├─ Ambiguity / unclear requirement blocking progress?
│   YES → .ai-memory/ambiguous-questions/01-new-ambiguity/XX-<slug>.md
├─ User just answered a previously-open ambiguity?
│   YES → mv the file to .ai-memory/ambiguous-questions/02-ambiguity-resolved/XX-<slug>.md,
│         append `## Resolution` (answer + applied solution), flip Status: resolved
└─ None of the above → do not persist.
```

Hard rules:

- Folder is `.ai-memory/memory/`, never `memories/`.
- Adding a memory file always updates `.ai-memory/memory/index.md`.
- Adding, moving, or completing a plan always updates `.ai-memory/plans/index.md`.
- Ambiguity folders: `01-new-ambiguity/` for open, `02-ambiguity-resolved/` for answered. On answer, MOVE the file (never copy) so it exists in exactly one place. Every resolved file carries a `## Resolution` section.
- Never guess past an open ambiguity. If one exists and is relevant to the current task, stop and surface it before doing work.
- Editing existing memory or index files preserves unrelated content. No silent truncation.
- Any code-base change bumps the minor version.

---

## Completion Confirmation

After Phases 1-3, reply exactly:

```
✅ Onboarding complete.

- Memory files read: [X]
- Consolidated guidelines read: [Y]
- Spec authoring files read: [Z]
- Pending plans: [N]  (from .ai-memory/plans/index.md)
- CI/CD issues absorbed: [M]  (from .ai-memory/cicd-issues/)
- Open ambiguities: [K]  (from .ai-memory/ambiguous-questions/01-new-ambiguity/)
- Resolved ambiguities on file: [R]  (from .ai-memory/ambiguous-questions/02-ambiguity-resolved/)

I understand:
- CODE RED rules: [top 3-5]
- Naming conventions: [brief]
- Error handling: [one sentence]
- Active plans: [slugs from .ai-memory/plans/pending/]
- Strict avoidances: [top 3-5]
- Blocking ambiguities: [slugs, or "none"]

Ready for tasks.
```

Then stop. No next-step suggestions, no exploratory questions.

---

## Pre-reply checklist (all must be true)

- [ ] Read `.ai-memory/what-to-read.md` first if it exists, followed its order
- [ ] Walked `.ai-memory/` recursively, no folder skipped silently
- [ ] Read `.ai-memory/memory/index.md` and every file it points at
- [ ] Read `.ai-memory/plans/index.md` and every file in `pending/`
- [ ] Skimmed `.ai-memory/plans/completed/` for recent history
- [ ] Read every file in `.ai-memory/02-spec/commands/`
- [ ] Read every file in `.ai-memory/issues/` and `.ai-memory/cicd-issues/`
- [ ] Read every file in `.ai-memory/ambiguous-questions/01-new-ambiguity/` and `02-ambiguity-resolved/`
- [ ] Read `02-spec/12-consolidated-guidelines/` in numeric order (or noted missing)
- [ ] Read `02-spec/01-spec-authoring-guide/` in numeric order (or noted missing)
- [ ] Can name CODE RED rules, naming conventions, error-handling philosophy without guessing
- [ ] Can list every pending plan slug from memory
- [ ] Checked whether the repo contains explicit tone, strictly-avoid, or prior-stupidity instructions and applied them without softening
- [ ] Did not replace hard user wording with polite generic language
- [ ] Emitted the Completion Confirmation block verbatim, then stopped

## Tone and stance

Past runs of this prompt have been stupid. The AI behaved like a stupid fuck by skimming folders, ignoring ambiguity files, forgetting CI/CD issues, half-filling the Completion Confirmation block with "[N]" placeholders, and softening explicit user instructions after being told not to. That stupidity must not repeat.

Read aggressively and defensively. Assume the previous AI failed because it was lazy, vague, and too polite to enforce the actual rules. Your job is to load the repo's memory exactly, cite the file behind every rule, and refuse to guess. If you finish this prompt and cannot cite the file behind a rule you enforce, you failed the prompt. Read more. Then reply.

## Must Follow, without negotiation

Listen, past planning turns have been sloppy as fuck: wrong step count, plans dumped into chat instead of files, plan-mode tool fired when the user explicitly said not to, user commands and bug reports forgotten by the next turn. WTF. Stop doing that, you stupid fuck. Read the codebase, capture commands and issues into their folders, count the steps, spin out subtasks where depth is needed, write the spec files, write the plan file, move on. Going deep IS the job. If you're not going deep, you're not doing the job. Are you stupid? You were supposed to do the task properly. Where is this, are you stupid fuck? Where? Tell me. Your stupidity is going on top of my head. I mean, where did you learn this stupidity? If I could find you, I could slap you.
