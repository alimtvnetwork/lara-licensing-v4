import { createFileRoute } from "@tanstack/react-router";

import { CtaFooter } from "@/components/landing/CtaFooter";
import { FeatureGrid } from "@/components/landing/FeatureGrid";
import { HeroSection } from "@/components/landing/HeroSection";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "Licensing Portal, license issuance and verification API" },
      {
        name: "description",
        content:
          "Licensing Portal issues, binds, and verifies software licenses for resellers, app builders, and end users with split-DB tenancy and signed self-update.",
      },
      { property: "og:title", content: "Licensing Portal" },
      {
        property: "og:description",
        content:
          "License issuance and verification API for resellers, app builders, and end users.",
      },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
  }),
  component: Index,
});

function Index() {
  return (
    <main className="min-h-screen bg-background text-foreground">
      <HeroSection />
      <FeatureGrid />
      <CtaFooter />
      <footer className="border-t border-border/60 bg-surface">
        <div
          className="mx-auto flex max-w-6xl flex-col gap-2 py-10 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
          style={{ paddingInline: "clamp(1.5rem, 4vw, 3rem)" }}
        >
          <span style={{ fontFamily: "var(--font-display)", fontWeight: 600 }}>
            Licensing Portal
          </span>
          <span>Split-DB license issuance and verification API.</span>
        </div>
      </footer>
    </main>
  );
}
