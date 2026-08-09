# CI/CD Index

**Version:** 0.9.0
**Last updated:** 2026-07-15
**Status:** Not configured.

Canonical map of build, test, and deploy pipelines. Update this file when a pipeline is added, changed, or removed.

## Pipelines

None configured. The project runs only in the Lovable preview and does not yet have external CI (GitHub Actions, GitLab CI, CircleCI) or deploy pipelines beyond Lovable publish.

## Hosting

- Preview and publish handled by Lovable. No custom deploy script.

## Local Checks

- Vite dev server auto-started by the sandbox. No manual build/test command required for routine changes.
- Typecheck runs automatically after edits.

## Conventions

- When a real pipeline is added, list it here with: name, trigger, config file path, secrets required, and failure runbook.
- Never store secrets in this file. Reference secret names only.
