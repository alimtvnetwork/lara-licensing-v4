/**
 * Preview fixtures: me domain (Plan 16 Step 34 scaffold).
 * Handlers are wired in a later step; register() is a no-op today so
 * INV-RM-04 (findMissingPreviewHandlers) still reports coverage gaps.
 */
import type { PreviewFixtureModule } from "./_module";

const mod: PreviewFixtureModule = {
  name: "me",
  operations: [],
  register(): void {
    // Intentionally empty. Handlers land in Plan 16 Steps 40..50.
  },
};

export default mod;
