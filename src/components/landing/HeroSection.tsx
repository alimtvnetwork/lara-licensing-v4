// Plan 09 Step 12: landing hero surface. Uses OKLCH primary/accent tokens
// via a layered gradient background and Ubuntu display type for the H1.
// Motion is gated by `useReducedMotion` per spec 24 §5, so the ambient
// glow animation is fully suppressed for users with the OS preference.

import { Link } from "@tanstack/react-router";

import { useReducedMotion } from "@/hooks/use-reduced-motion";
import { RuntimeModeSwitch } from "@/components/shell/RuntimeModeSwitch";

const OrbLayer = ({ reduced }: { reduced: boolean }) => (
  <div
    aria-hidden="true"
    className="pointer-events-none absolute inset-0 overflow-hidden"
    style={{
      background:
        "radial-gradient(60% 55% at 15% 20%, color-mix(in oklch, var(--primary) 22%, transparent) 0%, transparent 60%), radial-gradient(50% 45% at 85% 30%, color-mix(in oklch, var(--accent) 18%, transparent) 0%, transparent 65%)",
      opacity: reduced ? 0.6 : 1,
      transition: "opacity var(--motion-duration-lg) var(--motion-ease-out)",
    }}
  />
);

export function HeroSection() {
  const reduced = useReducedMotion();

  return (
    <section
      aria-labelledby="landing-hero-title"
      className="relative isolate overflow-hidden border-b border-border/60"
      style={{ backgroundColor: "var(--background)" }}
    >
      <OrbLayer reduced={reduced} />
      <div
        aria-hidden="true"
        className="dot-pattern pointer-events-none absolute inset-0 opacity-40"
        style={{
          maskImage: "radial-gradient(60% 45% at 50% 30%, black 30%, transparent 75%)",
          WebkitMaskImage: "radial-gradient(60% 45% at 50% 30%, black 30%, transparent 75%)",
        }}
      />
      <div
        className="relative mx-auto flex max-w-6xl flex-col gap-8 px-6 py-24 sm:py-32"
        style={{ paddingInline: "clamp(1.5rem, 4vw, 3rem)" }}
      >
        <p className="text-xs font-semibold uppercase tracking-[0.24em] text-primary">
          Licensing Portal
        </p>
        <h1
          id="landing-hero-title"
          className="gradient-headline max-w-4xl text-balance"
          style={{
            fontFamily: "var(--font-display)",
            fontWeight: 700,
            fontSize: "clamp(2.5rem, 6vw, 4.25rem)",
            lineHeight: 1.05,
            letterSpacing: "-0.02em",
          }}
        >
          License issuance and verification, tuned for resellers and app builders.
        </h1>

        <p
          className="max-w-2xl text-pretty text-lg text-muted-foreground"
          style={{ fontFamily: "var(--font-sans)" }}
        >
          Issue tiered licenses, bind serials to machines, verify offline with Ed25519 signatures,
          and audit every mutation. Split-DB tenancy keeps each reseller isolated by design.
        </p>
        <div className="flex flex-wrap items-center gap-3">
          <Link
            to="/admin/login"
            className="inline-flex h-11 items-center rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
          >
            Sign in to admin console
          </Link>
          <a
            href="#features"
            className="inline-flex h-11 items-center rounded-md border border-input bg-background px-5 text-sm font-medium text-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
          >
            Explore capabilities
          </a>
          <RuntimeModeSwitch />
        </div>
      </div>
    </section>
  );
}
