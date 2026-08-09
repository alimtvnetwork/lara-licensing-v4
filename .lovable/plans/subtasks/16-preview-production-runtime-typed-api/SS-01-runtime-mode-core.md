---
Slug: runtime-mode-core
Status: pending
Created: 2026-07-20
Parent: 16-preview-production-runtime-typed-api
---

# SS-01: Runtime-mode core primitives

## Files

- `src/lib/runtime-mode.ts` (new)
- `src/hooks/use-runtime-mode.ts` (new)
- `src/lib/version-json-loader.ts` (new)
- `tests/runtime-mode.test.ts` (new)

## Contract

```ts
export type RuntimeMode = "preview" | "dev" | "production";

export type RuntimeConfig = {
  Mode: RuntimeMode;
  ApiBaseUrl: string | null; // null in preview mode
  PreviewSeed: "default" | "empty" | "error";
  Version: string;
  UpdatedAt: string; // ISO 8601
  AllowRuntimeToggle: boolean;
};

export function getRuntimeConfig(): RuntimeConfig;
export function getRuntimeMode(): RuntimeMode;
export function getApiBaseUrl(): string | null;
export function getPreviewSeed(): RuntimeConfig["PreviewSeed"];
export function isPreview(): boolean;
export function isDev(): boolean;
export function isProduction(): boolean;
```

## Precedence

1. `localStorage["lara.runtime.override"]` if present AND `AllowRuntimeToggle` is true.
2. `/version.json` fetched at boot.
3. Compile-time default: `{ Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default", AllowRuntimeToggle: true }`.

## Rules

- All functions must be pure and synchronous after boot hydration.
- No function body may exceed 15 lines (project coding-guidelines cap).
- No magic strings: seed names and mode names go through a const object.
- JSON keys returned to callers use PascalCase (project convention).
- Log all mode transitions through the error/observability channel with a `RuntimeModeChanged` event carrying `{ From, To, Reason, RequestId }`.

## Tests

- Precedence: localStorage overrides version.json overrides default.
- `AllowRuntimeToggle=false` disables localStorage override branch.
- Malformed `version.json` falls back to compile-time default AND emits a `RuntimeConfigParseFailed` observability event (must not silently swallow).
- Hydration is idempotent: calling `getRuntimeConfig()` before boot returns the compile-time default without throwing.
