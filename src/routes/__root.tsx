import { type QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { Outlet, createRootRouteWithContext, HeadContent, Scripts } from "@tanstack/react-router";
import { useEffect, type ReactNode } from "react";

import appCss from "../styles.css?url";
import { reportLovableError } from "../lib/lovable-error-reporting";
import { Toaster } from "../components/ui/sonner";
import { StateError, StateNotFound } from "../components/state";
import { GlobalErrorModal } from "../components/global/GlobalErrorModal";
import { GlobalRateLimitBanner } from "../components/global/GlobalRateLimitBanner";
import { useLaraErrorToast } from "../hooks/use-lara-error-toast";

function NotFoundComponent() {
  const path = typeof window === "undefined" ? "" : window.location.pathname;

  return <StateNotFound route="__root__" attemptedPath={path} />;
}

function ErrorComponent({ error, reset }: { error: Error; reset: () => void }) {
  useEffect(() => {
    reportLovableError(error, { boundary: "tanstack_root_error_component" });
  }, [error]);

  return <StateError route="__root__" error={error} reset={reset} />;
}

export const Route = createRootRouteWithContext<{ queryClient: QueryClient }>()({
  head: () => ({
    meta: [
      { charSet: "utf-8" },
      { name: "viewport", content: "width=device-width, initial-scale=1" },
      { title: "Licensing Portal" },
      {
        name: "description",
        content:
          "Licensing Portal: multi-tenant license generation, verification, and hash/verify key API platform.",
      },
      { property: "og:title", content: "Licensing Portal" },
      {
        property: "og:description",
        content: "Multi-tenant license generation and verification API platform.",
      },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
    links: [
      {
        rel: "stylesheet",
        href: appCss,
      },
      { rel: "icon", href: "/favicon.ico", type: "image/x-icon" },
      // Typography families of record per Plan 09 Step 3.
      // Ubuntu for headings (--font-display), Poppins for body (--font-sans).
      // Weights: Ubuntu 500/700 (headings), Poppins 400/500/600 (body/labels).
      // Loaded via <link> per Tailwind v4 rule (no remote @import in styles.css).
      { rel: "preconnect", href: "https://fonts.googleapis.com" },
      { rel: "preconnect", href: "https://fonts.gstatic.com", crossOrigin: "anonymous" },
      {
        rel: "stylesheet",
        href: "https://fonts.googleapis.com/css2?family=Ubuntu:wght@500;700&family=Poppins:wght@400;500;600&display=swap",
      },
    ],
  }),
  shellComponent: RootShell,
  component: RootComponent,
  notFoundComponent: NotFoundComponent,
  errorComponent: ErrorComponent,
});

function RootShell({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <head>
        <HeadContent />
      </head>
      <body>
        {children}
        <Scripts />
      </body>
    </html>
  );
}

function RootComponent() {
  const { queryClient } = Route.useRouteContext();
  useLaraErrorToast();

  return (
    <QueryClientProvider client={queryClient}>
      {/* Required: nested routes render here. Removing <Outlet /> breaks all child routes. */}
      <Outlet />
      <GlobalErrorModal />
      <GlobalRateLimitBanner />
      <Toaster />
    </QueryClientProvider>
  );
}
