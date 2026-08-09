/**
 * Preview transport (Plan 16 Step 31 seed; full contract in Step 33).
 *
 * Registry of preview handlers keyed by operationId. Each handler is a
 * pure `async` function that returns the typed `Response` for its
 * operation or throws a `LaraApiError` shaped like the production
 * envelope (spec/28-runtime-modes/03-preview-fixture-contract.md §H-03).
 *
 * Step 31 ships the registry + dispatch primitive. Steps 33..39 wire in
 * the IndexedDB seed store, latency/offline scenarios, and PreviewSeed
 * selection. Steps 40..50 register handlers for every operationId; a
 * coverage linter (Step 84) fails CI if any operationId lacks one.
 *
 * INV-RM-04: every operationId MUST have a registered handler before
 * preview mode is declared ready. Enforced by `assertPreviewCoverage()`.
 */

import type { OperationId, OperationRequest, OperationResponse } from "@/generated/api/operations";
import { Operations } from "@/generated/api/operations";
import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";
import { assertPreviewShape } from "./preview-fixtures/_shapes";

/**
 * Typed "no preview handler for this operationId" failure (Plan 17 Step 15).
 *
 * Extends `LaraApiError` so it flows through the existing error surface
 * (`useLaraErrorToast`, StateCard, debug drawer log tail) untouched, but
 * pins the failing `operationId` as a first-class property so callers
 * can render it without regex-parsing the message. Prior to this class,
 * the transport threw a bare `LaraApiError(ServerError, 0)` whose only
 * clue was a formatted string; error boundaries and the drawer had no
 * structured hook to display "which op is missing?".
 */
export class PreviewHandlerMissingError extends LaraApiError {
  constructor(
    readonly operationId: OperationId,
    requestId?: string,
  ) {
    super(
      `Preview handler not registered for operation "${operationId}" (INV-RM-04)`,
      ApiErrorCodeType.ServerError,
      0,
      requestId,
    );
    this.name = "PreviewHandlerMissingError";
  }
}

/**
 * Plan 17 Step 17: preview-boot invariant.
 *
 * Thrown by `assertPreviewBootReady()` when preview mode boots without any
 * registered handlers (empty registry) or without any registered fixture
 * modules. Prior to this class, a broken barrel or a silently failing
 * `register()` call left the registry empty and every subsequent
 * `dispatchPreview()` threw `PreviewHandlerMissingError` per-operation,
 * hiding the systemic root cause behind hundreds of downstream failures.
 * INV-RM-04 requires we surface the boot gap once, loudly, at boot.
 */
export class PreviewBootIncompleteError extends LaraApiError {
  constructor(
    readonly moduleCount: number,
    readonly handlerCount: number,
    readonly missingOperationIds: readonly OperationId[],
  ) {
    super(
      `PREVIEW_BOOT_INCOMPLETE: modules=${moduleCount} handlers=${handlerCount} missing=${missingOperationIds.length} (INV-RM-04)`,
      ApiErrorCodeType.ServerError,
      0,
    );
    this.name = "PreviewBootIncompleteError";
  }
}

/**
 * Assert preview-mode boot registered at least one fixture module and one
 * handler. Called once from `router.tsx` after `registerAllPreviewHandlers()`
 * has run and `bootRuntimeConfig()` confirmed `Mode === "preview"`. Never
 * silent (INV-RM-11 / SA-013).
 */
export function assertPreviewBootReady(moduleCount: number): void {
  const handlerCount = REGISTRY.size;
  if (moduleCount > 0 && handlerCount > 0) return;
  const missing = findMissingPreviewHandlers();
  console.error("preview-transport:boot-incomplete", {
    ModuleCount: moduleCount,
    HandlerCount: handlerCount,
    MissingCount: missing.length,
  });
  throw new PreviewBootIncompleteError(moduleCount, handlerCount, missing);
}

export type PreviewSeed = "default" | "empty" | "error";
export type PreviewScenario = "offline" | "slow" | "rate-limited" | null;

export interface PreviewContext<K extends OperationId> {
  Params: OperationRequest<K>;
  Headers: Record<string, string>;
  Signal: AbortSignal;
  Seed: PreviewSeed;
  Scenario: PreviewScenario;
  RequestId: string;
  Token?: string;
}

export type PreviewHandler<K extends OperationId> = (
  ctx: PreviewContext<K>,
) => Promise<OperationResponse<K>>;

// Registry is module-scoped and typed erased at the boundary; the
// generic `dispatchPreview` re-narrows per call. INV-RM-04 assertion
// consumes the raw keys to compare against `Operations`.
type ErasedHandler = (ctx: PreviewContext<OperationId>) => Promise<unknown>;

const REGISTRY = new Map<OperationId, ErasedHandler>();

export function registerPreviewHandler<K extends OperationId>(
  operationId: K,
  handler: PreviewHandler<K>,
): void {
  if (!(operationId in Operations)) {
    throw new Error(`preview-transport: unknown operationId "${operationId}"`);
  }
  REGISTRY.set(operationId, handler as unknown as ErasedHandler);
}

export function hasPreviewHandler(operationId: OperationId): boolean {
  return REGISTRY.has(operationId);
}

export function listRegisteredPreviewHandlers(): OperationId[] {
  return Array.from(REGISTRY.keys());
}

export function clearPreviewHandlersForTest(): void {
  REGISTRY.clear();
}

/**
 * v0.672.0: unregister a single preview handler so tests can force
 * `PreviewHandlerMissingError` (which carries both `operationId` and
 * `requestId`) without wiping the entire registry. Used by
 * `route-error-correlation.spec.ts` to verify the route errorComponent
 * surfaces both correlation IDs.
 */
export function unregisterPreviewHandlerForTest(operationId: OperationId): boolean {
  return REGISTRY.delete(operationId);
}

export async function dispatchPreview<K extends OperationId>(
  operationId: K,
  ctx: PreviewContext<K>,
): Promise<OperationResponse<K>> {
  const handler = REGISTRY.get(operationId) as PreviewHandler<K> | undefined;
  const isFailed = !handler;
  if (isFailed) {
    console.error("preview-transport:handler-missing", {
      OperationId: operationId,
      RequestId: ctx.RequestId,
      RegisteredCount: REGISTRY.size,
    });
    throw new PreviewHandlerMissingError(operationId, ctx.RequestId);
  }
  // Plan 17 Step 19: per-handler timing surfaced under a stable
  // `preview-transport:<op>` tag so the debug drawer log tail can render
  // start/end/duration without regex-parsing free-form messages. Uses
  // `performance.now()` when available (browser + jsdom) and falls back
  // to `Date.now()` in bare Node test environments. `console.info` so it
  // never pollutes the error stream. Errors thrown by the handler are
  // logged with the same duration + a `status: "error"` field, then
  // re-thrown untouched so `LaraApiError` / envelope contracts stay
  // intact (never swallow, INV-RM-11).
  const tag = `preview-transport:${operationId}`;
  const now =
    typeof performance !== "undefined" && typeof performance.now === "function"
      ? () => performance.now()
      : () => Date.now();
  const startedAt = now();
  console.info(tag, {
    Phase: "start",
    OperationId: operationId,
    RequestId: ctx.RequestId,
    Seed: ctx.Seed,
    Scenario: ctx.Scenario,
  });
  try {
    const raw = await handler(ctx);
    // Plan 17 Step 42: shape-assert every fixture response against the
    // generated schema. INV-RM-05 (preview + live parity) enforced at
    // runtime; drift throws PreviewFixtureShapeError (a LaraApiError).
    const result = assertPreviewShape(operationId, raw, ctx.RequestId);
    const durationMs = Math.round((now() - startedAt) * 1000) / 1000;
    console.info(tag, {
      Phase: "end",
      OperationId: operationId,
      RequestId: ctx.RequestId,
      Status: "ok",
      DurationMs: durationMs,
    });

    return result;
  } catch (err) {
    const durationMs = Math.round((now() - startedAt) * 1000) / 1000;
    console.info(tag, {
      Phase: "end",
      OperationId: operationId,
      RequestId: ctx.RequestId,
      Status: "error",
      DurationMs: durationMs,
      ErrorName: err instanceof Error ? err.name : "unknown",
    });
    throw err;
  }
}

/**
 * INV-RM-04 assertion. Called at preview-mode boot (Step 39) and by the
 * coverage linter suite (Step 84). Returns the list of missing
 * operationIds; caller decides whether to throw.
 */
export function findMissingPreviewHandlers(): OperationId[] {
  const missing: OperationId[] = [];
  for (const key of Object.keys(Operations) as OperationId[]) {
    if (REGISTRY.has(key) === false) missing.push(key);
  }

  return missing;
}
