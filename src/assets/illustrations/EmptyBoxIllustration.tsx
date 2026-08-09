// Plan 09 step 94. Inline SVG illustration for "no results" empty states.
// Colors reference OKLCH tokens via currentColor + muted-foreground so the
// same asset re-themes automatically under dark mode without extra assets.

import type { SVGProps } from "react";

export function EmptyBoxIllustration(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 160 120"
      role="img"
      aria-label="Empty box illustration"
      className="text-muted-foreground"
      {...props}
    >
      <defs>
        <linearGradient id="empty-box-face" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="currentColor" stopOpacity="0.14" />
          <stop offset="100%" stopColor="currentColor" stopOpacity="0.06" />
        </linearGradient>
      </defs>
      <ellipse cx="80" cy="106" rx="52" ry="6" fill="currentColor" opacity="0.08" />
      <path
        d="M40 52 L80 34 L120 52 L120 92 L80 108 L40 92 Z"
        fill="url(#empty-box-face)"
        stroke="currentColor"
        strokeOpacity="0.5"
        strokeWidth="1.5"
        strokeLinejoin="round"
      />
      <path
        d="M40 52 L80 70 L120 52"
        fill="none"
        stroke="currentColor"
        strokeOpacity="0.5"
        strokeWidth="1.5"
        strokeLinejoin="round"
      />
      <path d="M80 70 L80 108" stroke="currentColor" strokeOpacity="0.35" strokeWidth="1.5" />
      <circle cx="80" cy="28" r="2.5" fill="currentColor" opacity="0.5" />
      <circle cx="60" cy="30" r="1.5" fill="currentColor" opacity="0.35" />
      <circle cx="102" cy="26" r="1.5" fill="currentColor" opacity="0.35" />
    </svg>
  );
}
