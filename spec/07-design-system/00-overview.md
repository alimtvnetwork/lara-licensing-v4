# AI-Adaptable Design System

**Version:** 3.3.0  
**Updated:** 2026-07-15  
**Status:** Active  
**AI Confidence:** Reference-Ready  
**Ambiguity:** Medium

---

## Overview

This is the **canonical design system specification** for the project. It defines all visual behavior, interaction patterns, color tokens, motion rules, and component construction guidance in a single, portable reference. Any AI agent or human contributor reading this specification should be able to:

1. **Reproduce** the current visual language on a new website
2. **Extend** the system with new pages and components that remain visually consistent
3. **Re-theme** the entire design by changing centralized CSS custom property values
4. **Migrate** the system to WordPress or any other CMS without rewriting component logic

The design system follows a **variable-driven architecture**: application tokens are defined as CSS custom properties in `src/styles.css` and mapped to Tailwind v4 utilities with `@theme inline`. Components should reference semantic tokens instead of hardcoded colors.

All animations and transitions use **CSS3 only** — no JavaScript-driven animation libraries. This ensures portability, performance, and CMS compatibility.

---

## Design Philosophy

| Principle | Description |
|-----------|-------------|
| **Variable-First** | Every color, spacing, and visual property comes from a CSS custom property. No hardcoded values in components. |
| **Semantic Tokens** | Colors are named by purpose (`--primary`, `--accent`, `--muted`), not by value (`--purple`, `--pink`). |
| **OKLCH Color Model** | Application color tokens use OKLCH values in `src/styles.css`. |
| **CSS3 Motion** | All transitions and animations use CSS transitions, transforms, and keyframes. No JS animation libraries. |
| **Dark/Light Parity** | Every token has both `:root` (light) and `.dark` (dark) values. Components never branch on theme — tokens handle it. |
| **Progressive Enhancement** | Hover effects, transforms, and animations enhance but never gate functionality. |
| **Portability** | The system is platform-agnostic. It works with React, WordPress, static HTML, or any CSS-capable framework. |

---

## Scoring

| Metric | Value |
|--------|-------|
| AI Confidence | Reference-Ready |
| Ambiguity | Medium |
| Health Score | Not scored until all acceptance criteria are exercised |

---

## Keywords

`design-system` · `css-variables` · `theme-tokens` · `hsl-colors` · `dark-mode` · `css3-animations` · `code-blocks` · `typography` · `motion-system` · `component-patterns` · `wordpress-migration` · `ai-adaptable`

---

## File Inventory

| # | File | Category | Description |
|---|------|----------|-------------|
| 00 | [00-overview.md](./00-overview.md) | Overview | This file — design system entry point and index |
| 01 | [01-design-principles.md](./01-design-principles.md) | Principles | Visual philosophy, consistency rules, interaction feel |
| 02 | [02-theme-variable-architecture.md](./02-theme-variable-architecture.md) | Theme | Complete CSS custom property registry — the single source of truth |
| 03 | [03-typography.md](./03-typography.md) | Typography | Font stacks, size hierarchy, weight rules, text spacing |
| 04 | [04-spacing-layout.md](./04-spacing-layout.md) | Layout | Spacing scale, container rules, grid/flex patterns, responsive breakpoints |
| 05 | [05-borders-shapes.md](./05-borders-shapes.md) | Borders | Border thickness, radius, color behavior, state changes |
| 06 | [06-motion-transitions.md](./06-motion-transitions.md) | Motion | CSS3 transition durations, easing, keyframe animations, state transforms |
| 07 | [07-code-blocks.md](./07-code-blocks.md) | Components | Code block rendering, language badges, line interaction, fullscreen |
| 08 | [08-header-navigation.md](./08-header-navigation.md) | Components | Header layout, menu structure, hover underlines, icon transitions |
| 09 | [09-button-system.md](./09-button-system.md) | Components | Button variants, slide text animation, highlight styles |
| 10 | [10-sidebar-system.md](./10-sidebar-system.md) | Components | Sidebar tree, active states, expand/collapse, search |
| 11 | [11-section-patterns.md](./11-section-patterns.md) | Patterns | Reusable section templates (hero, feature, team, CTA) |
| 12 | [12-page-creation-rules.md](./12-page-creation-rules.md) | Guide | Rules for building new pages from the design language |
| 13 | [13-wordpress-migration.md](./13-wordpress-migration.md) | Migration | CMS compatibility notes, block theme mapping, admin theming |
| 97 | [97-acceptance-criteria.md](./97-acceptance-criteria.md) | Testing | Testable criteria for design system compliance |
| 99 | [99-consistency-report.md](./99-consistency-report.md) | Meta | Consistency validation report |

---

## Variable Dependency Architecture

```
┌─────────────────────────────────────────┐
│         CSS Custom Properties           │
│  (src/styles.css :root / .dark)          │
│  --primary, --accent, --background...   │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│       Tailwind v4 Theme Mapping         │
│  (@theme inline in src/styles.css)      │
│  --color-primary: var(--primary)        │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│       Component Token Layer             │
│  Semantic classes: .prose-spec,         │
│  .code-block-wrapper, .checklist-block  │
│  All use hsl(var(--token)) only         │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│       Component States                  │
│  :hover, :focus, :active, .dark         │
│  Transform, opacity, box-shadow shifts  │
│  All driven by tokens + CSS3 transitions│
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│       Page-Level Composition            │
│  Pages assemble components              │
│  No page-specific colors or overrides   │
│  Consistent spacing rhythm              │
└─────────────────────────────────────────┘
```

---

## How to Re-Theme

1. Open `src/styles.css`
2. Change OKLCH values in `:root { }` and `.dark { }` blocks
3. Every component, page, and interaction automatically updates
4. No component files need editing

**Example: change semantic colors:**

```css
:root {
  --primary: oklch(0.62 0.14 180);
  --accent: oklch(0.78 0.16 80);
}
```

Every heading gradient, link color, button, code block glow, and hover effect updates instantly.

---

## Cross-References

| Reference | Location |
|-----------|----------|
| CSS and Tailwind v4 Theme Source | `src/styles.css` |
| Spec Authoring Guide | `../01-spec-authoring-guide/00-overview.md` |
| Docs Viewer UI Spec | `../08-docs-viewer-ui/00-overview.md` |
| Visual Rendering Guide | `../08-docs-viewer-ui/02-features/07-visual-rendering-guide.md` |

---

## Verification

_Auto-generated section — see `spec/07-design-system/97-acceptance-criteria.md` for the full criteria index._

### AC-DS-000: Design-system conformance: Overview

**Given** Scan `src/` for raw color literals, hard-coded spacing, and untokenized typography.  
**When** Run the verification command shown below.  
**Then** All visual properties resolve to semantic tokens declared in `src/styles.css`; no raw color literals appear in components.

**Verification command:**

```bash
bun run lint && bun run build
```

**Expected:** both commands exit 0. This proves source and build validity, while the acceptance criteria still require a separate visual-token audit.

_Verification section last updated: 2026-04-21_
