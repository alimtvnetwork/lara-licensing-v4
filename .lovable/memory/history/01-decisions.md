# Decisions

## 2026-07-16 — Reset spec/21-app to a single 1.0.0 baseline

Every file under `spec/21-app/` (except the raw dictation `01-initial-sepc.md`) had its frontmatter reset from mixed tags (`3.3.0`, `0.1.0`, `0.2.0`, `0.4.0`, `0.21.0`, `0.22.0`, `0.54.0`, `0.55.0`, `0.57.0`) to `Version: 1.0.0`, `Updated: 2026-07-16`. Rationale: after the blind-AI audit sealed at 100.0 Band A, the folder became the single normative surface, so leaf files must share one baseline before further edits. Recorded per plan `02-spec-21-audit-remediation` step 4. Follow-on edits bump from `1.0.0`.

## 2026-07-16 — Bind spec/21-app to coding-guidelines and error-manage

`spec/21-app/00-overview.md` now cites `.lovable/coding-guidelines/`, `spec/02-coding-guidelines/`, `spec/03-error-manage/`, and `.lovable/strictly-avoid/` as normative sources with a conflict-resolution rule (leaf beats overview, folder-spec beats `.lovable/*.md`). Recorded per plan `02-spec-21-audit-remediation` step 5.
