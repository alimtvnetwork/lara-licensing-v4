/**
 * Global vitest setup.
 *
 * The compile-time default runtime mode is "preview" (see
 * src/lib/runtime-mode.ts). In preview mode, `requestLaraApi` and
 * `laraFetch` throw via the bypass guards (INV-RM-05). Legacy unit tests
 * assume network-shaped behaviour and don't pin the mode themselves,
 * so we default the harness to "dev". Tests that need to exercise the
 * preview guards call `freezeRuntimeMode({ Mode: "preview", ... })`
 * explicitly and reset in their own `afterEach`.
 */
import { beforeEach } from "vitest";
import { freezeRuntimeMode, resetRuntimeMode } from "@/lib/runtime-mode";

beforeEach(() => {
  resetRuntimeMode();
  freezeRuntimeMode({ Mode: "dev", ApiBaseUrl: null, PreviewSeed: "default" });
});
