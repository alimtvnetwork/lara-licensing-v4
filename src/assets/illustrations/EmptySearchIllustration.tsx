// Plan 09 step 94. Inline SVG illustration for "no filter matches" states,
// distinguishing "nothing exists" (EmptyBoxIllustration) from "your query
// returned nothing" (this file). Both re-theme via currentColor.

import type { SVGProps } from "react";

export function EmptySearchIllustration(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 160 120"
      role="img"
      aria-label="Empty search results illustration"
      className="text-muted-foreground"
      {...props}
    >
      <ellipse cx="80" cy="106" rx="46" ry="5" fill="currentColor" opacity="0.08" />
      <circle
        cx="70"
        cy="56"
        r="26"
        fill="currentColor"
        fillOpacity="0.08"
        stroke="currentColor"
        strokeOpacity="0.5"
        strokeWidth="2.5"
      />
      <line
        x1="90"
        y1="76"
        x2="112"
        y2="98"
        stroke="currentColor"
        strokeOpacity="0.55"
        strokeWidth="4"
        strokeLinecap="round"
      />
      <path
        d="M60 52 L80 52 M60 62 L74 62"
        stroke="currentColor"
        strokeOpacity="0.4"
        strokeWidth="1.5"
        strokeLinecap="round"
      />
    </svg>
  );
}
