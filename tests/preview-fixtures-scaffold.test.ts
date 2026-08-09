/**
 * Plan 16 Step 34: preview-fixtures scaffold coverage.
 *
 * Guarantees the barrel wires all 18 domain modules and that preview
 * coverage is complete (Phase D parity).
 */
import { beforeEach, describe, expect, it } from "vitest";
import {
  PREVIEW_FIXTURE_MODULES,
  PREVIEW_FIXTURE_MODULE_NAMES,
  registerAllPreviewHandlers,
} from "@/lib/preview-fixtures";
import {
  clearPreviewHandlersForTest,
  findMissingPreviewHandlers,
  listRegisteredPreviewHandlers,
} from "@/lib/preview-transport";

describe("preview-fixtures scaffold (Plan 16 Step 34 updated for Plan 18 Phase D)", () => {
  beforeEach(() => {
    clearPreviewHandlersForTest();
  });

  it("wires all 19 domain modules into the barrel", () => {
    expect(PREVIEW_FIXTURE_MODULES).toHaveLength(19);
    const names = PREVIEW_FIXTURE_MODULES.map((m) => m.name).sort();
    expect(names).toEqual([...PREVIEW_FIXTURE_MODULE_NAMES].sort());
  });

  it("registerAllPreviewHandlers() is safe to call and idempotent", () => {
    expect(() => registerAllPreviewHandlers()).not.toThrow();
    expect(() => registerAllPreviewHandlers()).not.toThrow();
  });

  it("all 19 domain modules register their operations; preview coverage is complete", () => {
    registerAllPreviewHandlers();
    const registered = listRegisteredPreviewHandlers().sort();
    expect(registered).toEqual([
      "admin.abuse.list",
      "admin.appUpdates.list",
      "admin.audit.list",
      "admin.features.list",
      "admin.impersonation.start",
      "admin.impersonation.stop",
      "admin.licenses.create",
      "admin.licenses.delete",
      "admin.licenses.list",
      "admin.licenses.show",
      "admin.licenses.update",
      "admin.metrics.kpis",
      "admin.quotaRequests.list",
      "admin.quotas.list",
      "admin.quotas.update",
      "admin.resellers.list",
      "admin.runtime-config.show",
      "admin.runtime-config.update",
      "admin.users.create",
      "admin.users.delete",
      "admin.users.list",
      "admin.users.update",
      "auth.login",
      "auth.logout",
      "auth.me",
      "auth.refresh",
      "password-reset.confirm",
      "password-reset.request",
      "portal.serials.lookup",
      "portal.updates.manifest",
      "admin.backup.exports.store",
      "admin.backup.imports.store",
      "admin.backup.jobs.show",
      "admin.capabilities.list",
      "admin.errors.list",
      "admin.policies.effective",
      "admin.policies.list",
      "admin.policies.preview",
      "admin.policies.store",
      "admin.roles.list",
      "admin.sessions.delete",
      "admin.snapshots.delete",
      "admin.snapshots.list",
      "admin.snapshots.pin",
      "admin.snapshots.show",
      "admin.snapshots.store",
      "admin.snapshots.unpin",
      "admin.snapshots.yank",
      "auth.capabilities"
    ].sort());
    expect(findMissingPreviewHandlers()).toEqual([]);
  });
});
