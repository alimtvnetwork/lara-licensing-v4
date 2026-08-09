import { Link } from "@tanstack/react-router";
import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

import { LaraMark } from "../brand/lara-mark";

/**
 * v0.301.0 (Plan 09 §54). Shared split-screen surface for every public
 * auth route: sign-in, register, forgot-password, reset-password. Root
 * cause this exists: `admin.login.tsx` and `register.tsx` each carried a
 * hand-rolled BrandPanel + BackgroundGlow + form-card chrome (~150
 * duplicated lines each), and `forgot-password.tsx` / `reset-password.tsx`
 * diverged with a weaker single-column shell. Centralising the chrome
 * here means new auth flows inherit the fluid palette, motion, and
 * accessibility affordances without copy-paste.
 *
 * The card is intentionally not typed as `children`-only: consumers
 * supply the form-card slot via `children` and optionally a bottom
 * `footerSlot` for "sign in instead" / "back to home" links so the
 * spacing rhythm stays consistent.
 */
export interface AuthCardTrustPoint {
  readonly icon: LucideIcon;
  readonly title: string;
  readonly body: string;
}

export interface AuthCardProps {
  readonly title: string;
  readonly description: string;
  readonly asideHeadline: string;
  readonly asideBody: string;
  readonly asideTrustPoints: ReadonlyArray<AuthCardTrustPoint>;
  readonly asideFooterNote?: string;
  readonly children: ReactNode;
  readonly footerSlot?: ReactNode;
}

export function AuthCard(props: AuthCardProps) {
  return (
    <main className="relative min-h-screen bg-background text-foreground">
      <AuthBackgroundGlow />
      <div className="relative z-10 mx-auto grid min-h-screen w-full max-w-7xl grid-cols-1 gap-10 px-6 py-10 lg:grid-cols-[1.05fr_1fr] lg:gap-16 lg:px-12">
        <AuthBrandPanel
          headline={props.asideHeadline}
          body={props.asideBody}
          trustPoints={props.asideTrustPoints}
          footerNote={props.asideFooterNote}
        />
        <section className="flex items-center justify-center">
          <div className="w-full max-w-md">
            <AuthMobileBrand />
            <div className="surface-elevated rounded-2xl p-8 fade-in">
              <div className="mb-6 flex items-start gap-3">
                <span aria-hidden className="brand-tile shrink-0">
                  <LaraMark className="size-5" />
                </span>
                <div className="space-y-1.5">
                  <h1 className="font-display text-2xl font-semibold tracking-tight text-foreground">
                    {props.title}
                  </h1>
                  <p className="text-sm text-muted-foreground">{props.description}</p>
                </div>
              </div>
              {props.children}
            </div>
            {props.footerSlot ? (
              <div className="mt-6 text-center text-xs text-muted-foreground">
                {props.footerSlot}
              </div>
            ) : null}
          </div>
        </section>
      </div>
    </main>
  );
}

function AuthMobileBrand() {
  return (
    <div className="mb-8 flex items-center gap-3 lg:hidden">
      <Link
        to="/"
        className="flex items-center gap-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
      >
        <LaraMark className="size-10" />
        <span className="font-display text-lg font-semibold tracking-tight">Licensing Portal</span>
      </Link>
    </div>
  );
}

function AuthBackgroundGlow() {
  return (
    <div
      aria-hidden
      className="pointer-events-none absolute inset-0 [background:radial-gradient(ellipse_at_top_left,oklch(0.72_0.15_175/0.18),transparent_55%),radial-gradient(ellipse_at_bottom_right,oklch(0.6_0.18_240/0.16),transparent_60%)]"
    />
  );
}

interface AuthBrandPanelProps {
  readonly headline: string;
  readonly body: string;
  readonly trustPoints: ReadonlyArray<AuthCardTrustPoint>;
  readonly footerNote?: string;
}

function AuthBrandPanel(props: AuthBrandPanelProps) {
  return (
    <aside className="relative hidden overflow-hidden rounded-3xl border border-border/60 bg-gradient-to-br from-[oklch(0.22_0.06_180)] via-[oklch(0.28_0.07_175)] to-[oklch(0.18_0.05_180)] p-12 text-[oklch(0.98_0_0)] lg:flex lg:flex-col lg:justify-between">
      <div className="pointer-events-none absolute inset-0 opacity-40 [background:radial-gradient(ellipse_at_top_right,oklch(0.72_0.15_175/0.35),transparent_55%),radial-gradient(ellipse_at_bottom_left,oklch(0.6_0.18_240/0.28),transparent_60%)]" />
      <div className="relative z-10 flex items-center gap-3">
        <LaraMark className="size-11" />
        <span className="font-display text-xl font-semibold tracking-tight">Licensing Portal</span>
      </div>
      <div className="relative z-10 space-y-6">
        <h2 className="font-display text-4xl font-semibold tracking-tight">{props.headline}</h2>
        <p className="max-w-md text-sm text-[oklch(0.9_0_0)]">{props.body}</p>
        <ul className="space-y-4">
          {props.trustPoints.map((point) => (
            <li key={point.title} className="flex items-start gap-3">
              <point.icon
                aria-hidden
                className="mt-0.5 size-5 shrink-0 text-[oklch(0.82_0.12_175)]"
              />
              <div>
                <p className="font-medium">{point.title}</p>
                <p className="text-sm text-[oklch(0.88_0_0)]">{point.body}</p>
              </div>
            </li>
          ))}
        </ul>
      </div>
      {props.footerNote ? (
        <p className="relative z-10 text-xs text-[oklch(0.85_0_0)]">{props.footerNote}</p>
      ) : null}
    </aside>
  );
}
