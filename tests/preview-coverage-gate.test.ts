/**
 * Plan 16 Step 51: preview coverage CI gate (INV-RM-04).
 *
 * The scaffold test at `tests/preview-fixtures-scaffold.test.ts` snapshots
 * *today's* registered set. That protects against accidental removal, but
 * it does NOT fail when a brand-new `operationId` is added to
 * `src/generated/api/operations.ts` without a matching preview handler,
 * because the snapshot is agnostic to the total universe of ops.
 *
 * This gate closes that hole. It asserts:
 *
 *   Universe(Operations) \ Registered = ALLOWLIST
 *
 * where ALLOWLIST is an explicit, spec-tied set of operationIds that are
 * intentionally not-yet-implemented. Any drift, new op with no handler,
 * removed op still on the allowlist, allowlisted op that got implemented,
 * breaks the build immediately with a targeted message.
 *
 * Maps to INV-RM-04 (spec/28-runtime-modes/00-overview.md).
 */
import { beforeEach, describe, expect, it } from "vitest";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import {
  clearPreviewHandlersForTest,
  findMissingPreviewHandlers,
  listRegisteredPreviewHandlers,
} from "@/lib/preview-transport";
import { Operations } from "@/generated/api/operations";

/**
 * Operations that are intentionally not-yet-handled in preview.
 *
 * Each entry MUST cite the plan step that removes it. Adding an entry
 * without a citation, or leaving an entry after its step lands, fails
 * the gate below.
 */
const PREVIEW_COVERAGE_ALLOWLIST: ReadonlyArray<string> = [
];


describe("preview coverage gate (Plan 16 Step 51, INV-RM-04)", () => {
  beforeEach(() => {
    clearPreviewHandlersForTest();
  });

  it("universe of operations is non-empty (sanity check)", () => {
    const universe = Object.keys(Operations);
    expect(universe.length).toBeGreaterThan(0);
  });

  it("every operation is either registered or explicitly allowlisted", () => {
    registerAllPreviewHandlers();
    const registered = new Set(listRegisteredPreviewHandlers());
    const allowlist = new Set(PREVIEW_COVERAGE_ALLOWLIST);
    const universe = Object.keys(Operations);

    const unaccounted = universe
      .filter((op) => !registered.has(op as never) && !allowlist.has(op))
      .sort();

    expect(
      unaccounted,
      `INV-RM-04 breach: ${unaccounted.length} operation(s) have no preview handler and are not on the Step 51 allowlist. Add a handler under src/lib/preview-fixtures/, or add a TODO(plan-16, step-NN) allowlist entry citing the plan step that will implement it. Offenders: ${JSON.stringify(unaccounted)}`,
    ).toEqual([]);
  });

  it("allowlist entries all still correspond to unhandled operations", () => {
    registerAllPreviewHandlers();
    const registered = new Set(listRegisteredPreviewHandlers());
    const universe = new Set(Object.keys(Operations));

    const stale = PREVIEW_COVERAGE_ALLOWLIST.filter(
      (op) => !universe.has(op) || registered.has(op as never),
    ).sort();

    expect(
      stale,
      `Stale PREVIEW_COVERAGE_ALLOWLIST entries: either the operation has been removed from Operations, or a handler has been registered (which means the allowlist entry should be deleted in this same commit). Offenders: ${JSON.stringify(stale)}`,
    ).toEqual([]);
  });

  it("findMissingPreviewHandlers() agrees with the allowlist exactly", () => {
    registerAllPreviewHandlers();
    const missing = findMissingPreviewHandlers().slice().sort();
    const expected = [...PREVIEW_COVERAGE_ALLOWLIST].sort();
    expect(missing).toEqual(expected);
  });
});
