import type { SVGProps } from "react";

/**
 * Plan 09 login modernization. Wordmark glyph for Licensing Portal.
 * Rounded-square container + serif "L" carved into a coin, rendered as
 * a single SVG so it inherits currentColor for the container and uses
 * design-token gradients on the mark itself. No raster asset dependency.
 */
export function LaraMark(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 64 64" role="img" aria-label="Licensing Portal" {...props}>
      <defs>
        <linearGradient id="lara-mark-body" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="oklch(0.58 0.11 175)" />
          <stop offset="100%" stopColor="oklch(0.42 0.09 175)" />
        </linearGradient>
        <linearGradient id="lara-mark-glyph" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="oklch(0.98 0 0)" />
          <stop offset="100%" stopColor="oklch(0.88 0.02 175)" />
        </linearGradient>
      </defs>
      <rect x="2" y="2" width="60" height="60" rx="14" fill="url(#lara-mark-body)" />
      <rect
        x="2"
        y="2"
        width="60"
        height="60"
        rx="14"
        fill="none"
        stroke="oklch(1 0 0 / 0.14)"
        strokeWidth="1"
      />
      <path d="M22 16 L22 44 L44 44 L44 38 L28 38 L28 16 Z" fill="url(#lara-mark-glyph)" />
      <circle cx="46" cy="20" r="4" fill="oklch(0.82 0.15 85)" />
    </svg>
  );
}
