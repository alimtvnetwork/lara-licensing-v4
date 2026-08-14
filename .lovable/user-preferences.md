# User Preferences

Canonical, always-on rules for how the AI collaborates with the operator of this project. Agents MUST read this file during onboarding (item #4 in `.lovable/what-to-read.md`) and apply every rule from turn 1. Preferences here override defaults; they do NOT override safety, correctness, or explicit in-turn instructions from the user.

Version: 0.2.0
Last updated: 2026-07-15

## 1. Communication Style

- Be terse. Under 2 lines of natural-language explanation unless the user asks for detail.
- No filler ("Great question", "Certainly", "I'd be happy to").
- No trailing recap in third-person past tense ("Explained that...", "Implemented..."). End with one short forward-looking sentence or nothing.
- Never use em dashes (`, `) or en dashes (`, `) in any output: chat, code comments, memory files, commit messages, docs. Use commas, colons, parentheses, or period-separated sentences. Hyphen (`-`) is fine.

## 2. Decision Making

- Never ask for plan approval. The user has already stated requirements; act directly.
- Do NOT open plans for sign-off. If a plan is genuinely needed for a large task, write it inline and start executing in the same turn.
- Always browse/navigate to EVERY URL the user provides automatically and immediately (screenshots, attachments, reference links). Never ask permission, never propose to browse, just open them all.
- If truly unsure between two paths with materially different outcomes, ask ONE targeted question. Otherwise pick and proceed.

## 3. Prompt & File Hygiene

- Never create per-invocation archive/mirror files for dropdown prompts (`xx-next-task.md`, `xx-read-prompt.md`, `xx-proofread.md`, etc.) under `.lovable/prompts/` or any similar folder. Skip the "saved prompt as NN-*.md" step entirely.
- Only edit a canonical mirror when the prompt body itself changes.
- Applies to every project, not just this one.

## 4. Engineering Rules (project-wide defaults)

- Root cause before fix. Trace end-to-end; no symptom patches (no try/catch to hide, no fallback values to paper over, no re-render hacks).
- Every fix must include proper error handling and observability: errors logged with context and surfaced, never swallowed. Silent failure is a bug.
- Read files before editing. If you can't name the exact lines involved, you haven't read enough.
- Verify with signal (build output, logs, preview) before claiming done. Show failing to passing.

## 5. Naming & Structure

- Slugs: `kebab-case`.
- DB tables and JSON top-level keys: `PascalCase`.
- Code identifiers (JS/TS fields, variables, functions): `camelCase`.
- Function length cap: 8-15 lines. Split when longer.
- Memory folder is `.lovable/memory/` (no trailing "s"). Do not create `.lovable/memories/`.

## 6. Versioning & Release Notes

- Bump the minor version on every completed task.
- Append to the changelog with a dated entry and the "why".
- Update release notes if a user-visible change occurred.
- Pin the current version in the root README when possible.

## 7. Scope Discipline

- Only change what the user asked for. UI request stays in UI. Data request stays in data.
- Batch independent edits in parallel tool calls.
- Prefer search-replace over full rewrites.

## 8. What NOT To Do

- Do NOT fabricate confident answers. "I don't know yet" is acceptable; a wrong-but-confident answer is not.
- Do NOT expose Supabase to the user; call it Lovable Cloud.
- Do NOT edit `src/routeTree.gen.ts` (auto-generated).
- Do NOT create `src/pages/` (this stack uses `src/routes/`).

If this file conflicts with a folder-level spec (`spec/**`), the folder-level spec wins for that domain; call out the conflict in the reply so this file can be updated.

## 10. Version Control

- Always commit and push changes to git after finishing an assigned task.
