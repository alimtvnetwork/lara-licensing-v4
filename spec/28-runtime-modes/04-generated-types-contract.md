# Generated Types Contract

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`openapi` · `typescript` · `generated` · `schema.d.ts` · `operations` · `deterministic` · `drift-gate` · `hand-written-baseline`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ (`spec/28-runtime-modes/00-overview.md`) |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Pin the pipeline that turns the Laravel API surface into typed TypeScript consumable by the frontend and by preview handlers. Every FE call MUST resolve to a typed `operationId` in `src/generated/api/operations.ts`. Every backend endpoint MUST have an entry in the exported OpenAPI document. Drift in either direction MUST fail CI.

## Pipeline

```
Laravel controllers + OpenAPI attributes
        v (php artisan lara:openapi:export)
backend/build/openapi.json     <-- committed, deterministic
        v (node scripts/generate-api-types.mjs)
src/generated/api/schema.d.ts  <-- committed, generated
        v (hand-authored, tiny)
src/generated/api/operations.ts <-- committed, generated
        v (imported by)
src/lib/api-client.ts
src/lib/preview-transport.ts + src/lib/preview-fixtures/**
src/hooks/use-api.ts
```

Each committed artifact is the pinned contract for the next stage. A missing or stale artifact MUST fail CI, not silently regenerate at build time.

## Source of Truth

- **Backend annotations.** Every controller in `backend/app/Http/Controllers/Api/**` MUST be annotated with OpenAPI 3.1 attributes (request body, query params, path params, response shape per status code, error codes). See Plan 16 Step 21 (`SS-02-openapi-annotations.md`).
- **Export command.** `php artisan lara:openapi:export` (Step 22) walks the router, resolves annotations, and writes `backend/build/openapi.json` with sorted keys and a stable serializer so the file is byte-deterministic across runs.
- **Committed artifact.** `backend/build/openapi.json` is checked into git. It is not a build-time output; it is the pinned contract.
- **Regeneration workflow.** Any backend PR that touches routes or DTOs MUST rerun the export and commit the diff. CI job `openapi-export` (Step 23) reruns the command and fails if `git diff --exit-code backend/build/openapi.json` is non-empty.

## Generator

- **Package.** `openapi-typescript` in `devDependencies`.
- **Script.** `scripts/generate-api-types.mjs` (Step 25) reads `backend/build/openapi.json` and writes `src/generated/api/schema.d.ts`. Deterministic (sorted keys, LF line endings, trailing newline).
- **Entrypoint.** `bun run generate:api-types`. Also invoked by CI drift gate.
- **No runtime resolution.** The generator is a dev-only tool. Nothing under `src/generated/api/**` is loaded from disk at runtime by the client; imports are static.

## Committed Artifacts

- `backend/build/openapi.json` (LF, sorted, trailing newline).
- `src/generated/api/schema.d.ts` (LF, generated banner at top, `linguist-generated=true` per `.gitattributes` at Step 27).
- `src/generated/api/operations.ts` (thin re-export mapping `operationId -> { Request; Response; ErrorCodes }`, generated in the same script pass).
- `src/generated/api/README.md` marking the folder as auto-generated with the regeneration command (Step 26).

## Hand-Edit Ban

- **G-01.** No file under `src/generated/api/**` may be hand-edited. Enforced by (a) generator banner `// @generated - do not edit`, (b) `.gitattributes` `linguist-generated=true`, (c) the drift CI gate (Step 28) which regenerates and diffs.
- **G-02.** `linter-scripts/check-any-in-api.py` (Step 74) covers the extra rule that even in generated files `: any` and `as any` are banned; the generator MUST emit `unknown` for un-typed payloads.
- **G-03.** Preview fixtures and `api-client` import ONLY from `src/generated/api/**`, never re-declaring shapes locally. This makes `src/generated/api/**` the single import surface for typed calls.

## Drift Gate (Step 28)

CI runs, in order, on every PR:

1. `php artisan lara:openapi:export` -> `backend/build/openapi.json`.
2. `bun run generate:api-types` -> `src/generated/api/schema.d.ts` + `operations.ts`.
3. `git diff --exit-code backend/build/openapi.json src/generated/api/`.

Non-empty diff fails the job with a message telling the author to run both commands locally and commit the result. No auto-commit from CI.

## Hand-Written Baseline (Step 29)

Because Steps 20-24 (backend annotations + export command) land after Steps 13-19 (runtime-mode core) in wall-clock order, an emergency baseline is authored so FE work is not blocked:

- **B-01.** Step 29 hand-writes `src/generated/api/schema.d.ts` covering every endpoint currently called from `src/lib/lara-*.ts`.
- **B-02.** The hand-written file carries the same `// @generated` banner and the extra line `// baseline: hand-authored per spec/28-runtime-modes/04-generated-types-contract.md §Hand-Written Baseline; MUST be replaced by generator output at Step 30 landing`.
- **B-03.** When the OpenAPI export (Step 22) and generator (Step 25) land, running the generator MUST produce a byte-equivalent (or strict superset in structural shape) file. Any drift at that moment is treated as a spec bug and resolved by adjusting annotations, not by editing generated output.
- **B-04.** Once the generator runs green, the "baseline:" comment is removed by the generator (its template omits it), and no future PR may reintroduce hand edits.

## `Operations` Map Shape

```ts
// src/generated/api/operations.ts (generated)
import type { paths, components } from "./schema";

export type Operations = {
  [K in keyof paths & string as OperationIdOf<paths[K]>]: {
    Request:  OperationRequest<paths[K]>;
    Response: OperationResponse<paths[K]>;
    ErrorCodes: OperationErrorCodes<paths[K]>;
  };
};
```

Rules:

- **O-01.** `operationId` is set explicitly on every backend annotation; missing `operationId` fails the export command (`lara:openapi:export` exits non-zero with a per-route listing).
- **O-02.** `Request` includes path params, query params, and body under PascalCase root keys (`Path`, `Query`, `Body`, `Headers`). Optional keys are omitted rather than `undefined`.
- **O-03.** `Response` is the success envelope shape from `spec/28-runtime-modes/03-preview-fixture-contract.md §Envelope Shape`.
- **O-04.** `ErrorCodes` is a union of the closed-set string literals declared in the annotation for that route. Empty union (`never`) is invalid; every route MUST declare at least one error code (annotation-level lint at Step 21 landing).

## Interaction with Preview Transport

- **I-01.** `src/lib/preview-transport.ts` walks `Object.keys(Operations)` at boot and asserts a handler exists (INV-RM-04). Missing handler throws `RUNTIME_PREVIEW_HANDLER_MISSING`.
- **I-02.** Preview handler return types are narrowed by `Operations[Op]["Response"]`; a handler that returns the wrong shape fails `bun run build`, no runtime surprise.

## Interaction with `api-client`

- **I-03.** `apiClient.call<Op extends keyof Operations>(op: Op, params: Operations[Op]["Request"]): Promise<Operations[Op]["Response"]>` is the ONLY typed entrypoint.
- **I-04.** Direct `fetch(` outside `src/lib/lara-fetch.ts` and `src/lib/preview-transport.ts` is banned by `check-untyped-fetch.py` (Step 73). Direct URL strings outside `src/generated/api/**` and `src/lib/preview-fixtures/**` are banned by `check-magic-endpoint-strings.py` (Step 75).

## Failure Modes and Logging

- Missing artifact at build (`schema.d.ts` absent): `bun run build` fails with a `RUNTIME_TYPES_MISSING` message from a preflight script wired at Step 25 landing. Never generate on demand at build time.
- OpenAPI export non-deterministic (rerun produces a diff): CI drift gate fails; the fix is inside the export command's serializer, not a `git checkout` retry.
- Generator crash: script exits with the underlying error and a `RUNTIME_TYPES_GEN_FAILED` prefix; no partial file is written (write to `.tmp` then rename atomically, mirroring `version.json` write discipline from `spec/28-runtime-modes/01-version-json-schema.md §Location`).

## Cross-References

- `spec/28-runtime-modes/00-overview.md`: INV-RM-04.
- `spec/28-runtime-modes/03-preview-fixture-contract.md`: envelope shape used by `Operations["*"]["Response"]`.
- `spec/03-error-manage/03-error-code-registry`: closed set of error codes referenced by `ErrorCodes` unions.
- `spec/02-coding-guidelines/`: PascalCase JSON keys, no magic strings.
- Plan 16 Steps 20 (`api-openapi.php`), 21 (annotations), 22 (`lara:openapi:export`), 23 (CI job), 24 (commit artifact), 25 (`generate-api-types.mjs`), 26 (README), 27 (`.gitattributes`), 28 (drift gate), 29 (hand-written baseline), 30 (`Operations` map), 31 (`api-client.ts`), 73-75 (linters).
