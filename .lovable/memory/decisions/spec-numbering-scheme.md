---
name: Spec numbering scheme
description: Top-level spec display numbers and references must match their on-disk folder prefixes.
type: decision
version: 0.13.0
date: 2026-07-15
---

# Spec Numbering Scheme

## Decision

The numeric prefix of each top-level `spec/` folder is canonical. Index display numbers and cross-references must use that exact prefix.

## Rationale

Matching labels, links, and folder prefixes removes translation rules and prevents onboarding references from resolving to the wrong module.

## Consequences

- New top-level spec modules use the next available numeric prefix.
- Renumbering a folder requires updating every index and cross-reference in the same change.
- Reserved or absent numbers remain gaps rather than causing later modules to be relabeled.