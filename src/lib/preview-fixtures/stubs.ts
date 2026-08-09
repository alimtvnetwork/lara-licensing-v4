import { registerPreviewHandler } from "../preview-transport";

export function registerStubHandlers() {
  registerPreviewHandler("admin.backup.exports.store", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.backup.imports.store", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.backup.jobs.show", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.capabilities.list", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.errors.list", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.policies.effective", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.policies.list", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.policies.preview", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.policies.store", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.roles.list", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.sessions.delete", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.snapshots.delete", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.snapshots.list", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.snapshots.pin", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.snapshots.show", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.snapshots.store", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.snapshots.unpin", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("admin.snapshots.yank", async (req) => {
    return { data: {} as any };
  });
  registerPreviewHandler("auth.capabilities", async (req) => {
    return { data: {} as any };
  });
}
