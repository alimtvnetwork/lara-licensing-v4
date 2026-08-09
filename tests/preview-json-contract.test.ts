/**
 * Plan 16 Step 77: Preview-handler JSON contract tests.
 *
 * Boots the preview registry, dispatches a representative handler per
 * high-value operationId, and validates the response with a strict Zod
 * schema derived from `src/generated/api/schema.d.ts`. Strict parsing
 * means: an extra key, a missing key, or a wrong primitive type all
 * fail loudly at test time, catching drift between the generated types
 * and the preview fixture bodies (INV-RM-05: preview and live callers
 * observe identical typed responses).
 *
 * This complements the per-domain fixture tests (behavioural) by
 * asserting shape parity against the generated contract.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { z } from "zod";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import { registerAllPreviewHandlers } from "../src/lib/preview-fixtures";
import type { OperationId } from "../src/generated/api/operations";

function ctx<K extends OperationId>(Params: unknown): PreviewContext<K> {
  return {
    Params: Params as never,
    Headers: {},
    Signal: new AbortController().signal,
    Seed: "default",
    Scenario: null,
    RequestId: `req-contract-${Math.random().toString(16).slice(2, 8)}`,
  };
}

// ---------------------------------------------------------------------------
// Zod schemas mirroring src/generated/api/schema.d.ts (strict).
// ---------------------------------------------------------------------------

const LicenseStatus = z.enum(["active", "suspended", "revoked", "expired"]);

const License = z
  .object({
    Id: z.string(),
    Serial: z.string(),
    Status: LicenseStatus,
    CustomerName: z.string(),
    CustomerEmail: z.string(),
    ResellerId: z.string().nullable(),
    IssuedAt: z.string(),
    ExpiresAt: z.string().nullable(),
    Features: z.array(z.string()),
    MaxActivations: z.number(),
    ActiveActivations: z.number(),
    Version: z.number(),
    CreatedAt: z.string(),
    UpdatedAt: z.string(),
  })
  .strict();

const Quota = z
  .object({
    Id: z.string(),
    ResellerId: z.string(),
    ResellerName: z.string(),
    FeatureCode: z.string(),
    Allocated: z.number(),
    Used: z.number(),
    Restored: z.number(),
    UpdatedAt: z.string(),
    Version: z.number(),
  })
  .strict();

const AuditEntry = z
  .object({
    Id: z.string(),
    EventType: z.string(),
    ActorUserId: z.string().nullable(),
    TargetType: z.string(),
    TargetId: z.string(),
    RequestId: z.string(),
    OccurredAt: z.string(),
    Payload: z.record(z.unknown()),
  })
  .strict();

function paginated<T extends z.ZodTypeAny>(item: T) {
  return z
    .object({
      Items: z.array(item),
      Cursor: z.string().nullable(),
      Total: z.number(),
    })
    .strict();
}

const PortalSerialLookupResponse = z
  .object({
    Serial: z.string(),
    Status: LicenseStatus,
    ExpiresAt: z.string().nullable(),
    Features: z.array(z.string()),
    IssuedAt: z.string(),
  })
  .strict();

const RuntimeConfigDoc = z
  .object({
    Mode: z.enum(["preview", "dev", "production"]),
    ApiBaseUrl: z.string().nullable(),
    PreviewSeed: z.string(),
    AllowRuntimeToggle: z.boolean(),
    Version: z.string(),
    UpdatedAt: z.string(),
  })
  .strict();

// ---------------------------------------------------------------------------
// Dispatch matrix: operationId -> { params, schema }.
// ---------------------------------------------------------------------------

const SEEDED_LICENSE_ID = "01H00000000000000LIC00001";
const SEEDED_SERIAL = "LARA-AAAA-0001";

interface Case {
  op: OperationId;
  params: unknown;
  schema: z.ZodTypeAny;
}

const CASES: readonly Case[] = [
  { op: "admin.licenses.list", params: {}, schema: paginated(License) },
  { op: "admin.licenses.show", params: { Id: SEEDED_LICENSE_ID }, schema: License },
  { op: "admin.quotas.list", params: {}, schema: paginated(Quota) },
  { op: "admin.audit.list", params: {}, schema: paginated(AuditEntry) },
  { op: "portal.serials.lookup", params: { Serial: SEEDED_SERIAL }, schema: PortalSerialLookupResponse },
  { op: "admin.runtime-config.show", params: {}, schema: RuntimeConfigDoc },
] as const;

describe("preview-json-contract: Zod parity against generated schema", () => {
  beforeEach(async () => {
    await resetAll();
    clearPreviewHandlersForTest();
    registerAllPreviewHandlers();
    await loadDefaultSeed();
  });

  for (const c of CASES) {
    it(`${c.op} matches the strict generated shape`, async () => {
      const res = await dispatchPreview(c.op, ctx(c.params));
      const parsed = c.schema.safeParse(res);
      if (!parsed.success) {
        // Surface the exact drift (INV-ERR-04 style logging in tests).
        console.error(`preview-json-contract:drift:${c.op}`, parsed.error.issues);
      }
      expect(parsed.success).toBe(true);
    });
  }
});
