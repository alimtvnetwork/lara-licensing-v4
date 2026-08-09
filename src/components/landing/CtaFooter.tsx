import { Link } from "@tanstack/react-router";
import { useEffect, useState } from "react";

import { getLaraAccessToken } from "@/lib/lara-api-session";

/**
 * CtaFooter (Plan 09 Step 14). Role-aware sign-in surface for the landing
 * page. Shows the three actor entry points (Admin, Reseller, Portal) with a
 * lightweight hint when a session token is present client-side, so returning
 * users see a "resume" affordance instead of a raw sign-in row. Renders
 * without a session by default (SSR-safe) and upgrades after hydration.
 *
 * No inline strings for CTA targets: entries live in a typed catalog below.
 */

type CtaId = "AdminConsole" | "ResellerConsole" | "EndUserPortal";

interface CtaEntry {
  Id: CtaId;
  Label: string;
  Description: string;
  To: string;
}

const CTA_ENTRIES: readonly CtaEntry[] = [
  {
    Id: "AdminConsole",
    Label: "Admin console",
    Description: "Issue licenses, manage resellers, publish updates.",
    To: "/admin/login",
  },
  {
    Id: "ResellerConsole",
    Label: "Reseller console",
    Description: "Sell licenses under a prefix, request quota, audit ledger.",
    To: "/admin/login",
  },
  {
    Id: "EndUserPortal",
    Label: "End-user portal",
    Description: "Look up a serial, verify a machine, download updates.",
    To: "/admin/login",
  },
] as const;

function useHasSession(): boolean {
  const [hasSession, setHasSession] = useState(false);
  useEffect(() => {
    setHasSession(Boolean(getLaraAccessToken()));
  }, []);

  return hasSession;
}

export function CtaFooter() {
  const hasSession = useHasSession();

  return (
    <section
      id="get-started"
      className="border-t border-border/60 bg-surface"
      aria-labelledby="cta-footer-title"
    >
      <div
        className="mx-auto flex max-w-6xl flex-col gap-8 py-16"
        style={{ paddingInline: "clamp(1.5rem, 4vw, 3rem)" }}
      >
        <header className="flex flex-col gap-2">
          <h2
            id="cta-footer-title"
            className="text-3xl font-semibold tracking-tight text-foreground"
            style={{ fontFamily: "var(--font-display)" }}
          >
            Pick your entry point
          </h2>
          <p className="max-w-2xl text-sm text-muted-foreground">
            {hasSession
              ? "Session detected on this device. Continue to the console for your role."
              : "Every actor uses the same auth surface. Role routing happens after sign-in."}
          </p>
        </header>
        <ul className="grid gap-4 sm:grid-cols-3">
          {CTA_ENTRIES.map((entry) => (
            <li key={entry.Id}>
              <Link
                to={entry.To}
                data-cta-id={entry.Id}
                className="group flex h-full flex-col justify-between gap-3 rounded-2xl border border-border/60 bg-background p-5 transition-colors hover:border-primary/50 focus-visible:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
              >
                <div className="flex flex-col gap-1">
                  <span
                    className="text-lg font-semibold text-foreground"
                    style={{ fontFamily: "var(--font-display)" }}
                  >
                    {entry.Label}
                  </span>
                  <span className="text-sm text-muted-foreground">{entry.Description}</span>
                </div>
                <span
                  aria-hidden="true"
                  className="text-sm font-medium text-primary transition-transform group-hover:translate-x-0.5"
                >
                  {hasSession ? "Resume" : "Sign in"} &rarr;
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
