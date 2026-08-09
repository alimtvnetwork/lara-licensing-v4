# Command: Blind-AI audit of spec/21-app

Slug: blind-ai-audit-folder-21
Status: active
Created: 2026-07-16

## Verbatim

> Read the spec on Folder 21, find flaws between 0 and 100, and write every detail — how the spec should be written so any AI can implement it, including the UI, and add a self-updating endpoint based on spec/14-update. Make sure updates can be published via PowerShell. Audit lands in Folder 25 with the audit spec, based on Folder 21. Audit = blind-AI implementability score. Also flag coding-guideline gaps vs user-management definitions (roles, debugging, clarity). Everything step by step, list what is missing and how the spec must be improved so no AI can miss anything.

## Scope

- Source of truth: `spec/21-app/` (LaraLicensingV1).
- Self-update source: `spec/14-update/` (probe → download → verify → rename → handoff).
- Audit output folder: `spec/25-app-audit/` (new, mirrors `spec/17-consolidated-guidelines/25/26/29-blind-ai-audit-*.md` style).
- PowerShell publishing: reuse `linter-scripts/run.ps1` and `spec/11-powershell-integration/`.

## When it applies

Every time the user asks to "audit folder 21" or requests a blind-AI implementability review of LaraLicensingV1. Score 0-100. Enumerate missing pieces per file. Provide a concrete rewrite outline per gap.
