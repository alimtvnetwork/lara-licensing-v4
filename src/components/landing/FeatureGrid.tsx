// Plan 09 Step 13: feature grid reads the typed catalog only. No inline
// copy, so a future spec change touches `landing-features.ts` alone.

import { LANDING_FEATURES } from "@/lib/landing-features";

export function FeatureGrid() {
  return (
    <section
      id="features"
      aria-labelledby="landing-features-title"
      className="mx-auto max-w-6xl px-6 py-20"
      style={{ paddingInline: "clamp(1.5rem, 4vw, 3rem)" }}
    >
      <div className="mb-12 flex flex-col gap-3">
        <p className="text-xs font-semibold uppercase tracking-[0.24em] text-primary">
          Capabilities
        </p>
        <h2
          id="landing-features-title"
          className="max-w-2xl"
          style={{
            fontFamily: "var(--font-display)",
            fontWeight: 700,
            fontSize: "clamp(1.75rem, 3.5vw, 2.5rem)",
            lineHeight: 1.15,
            letterSpacing: "-0.015em",
            color: "var(--foreground)",
          }}
        >
          The whole licensing loop, from issuance to signed self-update.
        </h2>
      </div>
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {LANDING_FEATURES.map((feature) => (
          <li key={feature.Id} className="surface-elevated group p-6">
            <h3
              className="text-base font-semibold"
              style={{ fontFamily: "var(--font-display)", fontWeight: 600 }}
            >
              {feature.Title}
            </h3>
            <p className="mt-3 text-sm leading-relaxed text-muted-foreground">{feature.Summary}</p>
          </li>
        ))}
      </ul>
    </section>
  );
}
