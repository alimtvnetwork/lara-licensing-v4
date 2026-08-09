import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LaraShellRoleContext, type LaraShellRoleType } from "@/lib/lara-shell-role";
import { UpdateBanner } from "@/components/update-banner";
import { ChannelType, PlatformType, type UpdateManifest } from "@/lib/lara-self-update";

const manifest: UpdateManifest = {
  Product: "lara-cli",
  Channel: ChannelType.Stable,
  LatestVersion: "1.2.0",
  ReleaseNotesUrl: "https://example.test/notes",
  Assets: [
    {
      Platform: PlatformType.WindowsAmd64,
      Url: "https://example.test/a.zip",
      Sha256: "0".repeat(64),
      SizeBytes: 10,
    },
  ],
};

const fetchMock = vi.fn<() => Promise<UpdateManifest>>();

vi.mock("@/lib/lara-self-update", async () => {
  const actual =
    await vi.importActual<typeof import("@/lib/lara-self-update")>("@/lib/lara-self-update");
  return {
    ...actual,
    fetchUpdateManifest: () => fetchMock(),
    updateManifestQueryOptions: (input: { product: string; channel: string; currentVersion: string; platform: string }) => ({
      queryKey: ["lara", "update-manifest", input.product, input.channel, input.currentVersion, input.platform],
      queryFn: () => fetchMock(),
      staleTime: 0,
    }),
  };
});

function renderBanner(role: LaraShellRoleType | null, currentVersion = "1.0.0") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <LaraShellRoleContext.Provider value={role}>
        <UpdateBanner
          product="lara-cli"
          currentVersion={currentVersion}
          platform={PlatformType.WindowsAmd64}
          viewUpdateHref="/app/update"
        />
      </LaraShellRoleContext.Provider>
    </QueryClientProvider>,
  );
}

async function flushQuery() {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
}

describe("<UpdateBanner />", () => {
  beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockResolvedValue(manifest);
    if (typeof window !== "undefined") window.sessionStorage.clear();
  });
  afterEach(() => cleanup());

  it("renders nothing for Admin shell and never fetches the manifest", async () => {
    const { container } = renderBanner("Admin");
    await flushQuery();
    expect(container.firstChild).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("renders nothing for Reseller shell", async () => {
    const { container } = renderBanner("Reseller");
    await flushQuery();
    expect(container.firstChild).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("renders update banner for AppBuilder when LatestVersion differs", async () => {
    renderBanner("AppBuilder", "1.0.0");
    expect(await screen.findByTestId("update-banner")).toBeDefined();
    expect(fetchMock).toHaveBeenCalledOnce();
    expect(screen.getByText("1.2.0")).toBeDefined();
  });

  it("renders nothing for EndUser when current version matches LatestVersion", async () => {
    renderBanner("EndUser", "1.2.0");
    await flushQuery();
    await flushQuery();
    expect(fetchMock).toHaveBeenCalledOnce();
    expect(screen.queryByTestId("update-banner")).toBeNull();
  });

  it("dismissal is per-version: hides current version but re-shows for a new release", async () => {
    renderBanner("AppBuilder", "1.0.0");
    const dismissBtn = await screen.findByLabelText("Dismiss update banner");
    act(() => dismissBtn.click());
    expect(screen.queryByTestId("update-banner")).toBeNull();
    expect(window.sessionStorage.getItem("lara.update-banner.dismissed.1.2.0")).toBe("1");

    cleanup();
    fetchMock.mockResolvedValue({ ...manifest, LatestVersion: "1.3.0" });
    renderBanner("AppBuilder", "1.0.0");
    expect(await screen.findByText("1.3.0")).toBeDefined();
    expect(screen.getByTestId("update-banner")).toBeDefined();
  });

  it("shellRoleSeesUpdateBanner: null role does not fetch or render", async () => {
    const { container } = renderBanner(null);
    await flushQuery();
    expect(container.firstChild).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
