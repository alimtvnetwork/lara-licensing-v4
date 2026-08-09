/**
 * Breadcrumbs: detail-route location trail per
 * spec/24-app-ui-design-system/14-breadcrumbs-and-page-header.md §3.
 *
 * Root cause the v0.282.0 refit fixes: the `font: var(--text-label)`
 * shorthand silently drops `font-family`, so crumbs rendered in whatever
 * generic sans the reset boundary provided instead of Poppins 13. Middle
 * collapse (§3.4) was also unimplemented, so long trails wrapped onto
 * multiple lines instead of ellipsing.
 *
 * This file now pins family/size/line-height/weight explicitly, wires a
 * hover underline for parent links, and applies §3.4 middle-collapse when
 * the trail carries more than 4 segments.
 */
import { Link } from "@tanstack/react-router";
import { ChevronRight } from "lucide-react";

export interface CrumbSegmentType {
  label: string;
  /** Parent link route. Omit on the current segment. */
  to?: string;
  /** Render label through the identifier typography (`--font-mono`, `--text-code`). */
  identifier?: boolean;
}

interface BreadcrumbsProps {
  segments: CrumbSegmentType[];
}

const MaxVisibleSegments = 4;
const EllipsisLabel = "...";

const trailStyle = {
  fontFamily: "var(--font-sans)",
  fontSize: "0.8125rem",
  lineHeight: 1.4,
  fontWeight: 500,
  color: "var(--color-muted-foreground)",
} as const;

export function Breadcrumbs({ segments }: BreadcrumbsProps) {
  if (segments.length === 0) return null;
  const rendered = collapseSegments(segments);

  return (
    <nav aria-label="Breadcrumb">
      <ol className="flex flex-wrap items-center gap-1.5" style={trailStyle}>
        {rendered.map((seg, index) => (
          <CrumbLi key={`${seg.label}-${index}`} seg={seg} isLast={index === rendered.length - 1} />
        ))}
      </ol>
    </nav>
  );
}

function collapseSegments(segments: CrumbSegmentType[]): CrumbSegmentType[] {
  if (segments.length <= MaxVisibleSegments) return segments;
  const first = segments[0]!;
  const lastTwo = segments.slice(-2);

  return [first, { label: EllipsisLabel }, ...lastTwo];
}

function CrumbLi({ seg, isLast }: { seg: CrumbSegmentType; isLast: boolean }) {
  const labelStyle = seg.identifier
    ? {
        fontFamily: "var(--font-mono)",
        fontSize: "0.8125rem",
        letterSpacing: "-0.005em",
      }
    : undefined;
  const isEllipsis = seg.label === EllipsisLabel && seg.to === undefined;

  return (
    <li className="flex items-center gap-1.5" aria-current={isLast ? "page" : undefined}>
      {isEllipsis ? (
        <span aria-hidden="true" className="px-1 text-muted-foreground">
          {EllipsisLabel}
        </span>
      ) : isLast || seg.to === undefined ? (
        <span
          className="max-w-[24ch] truncate text-foreground"
          style={{ ...labelStyle, fontWeight: 600 }}
        >
          {seg.label}
        </span>
      ) : (
        <Link
          to={seg.to}
          className="max-w-[24ch] truncate rounded-md px-1.5 py-0.5 text-muted-foreground transition-[background-color,color,box-shadow] hover:text-foreground hover:bg-[color-mix(in_oklab,var(--accent)_12%,transparent)] focus-visible:outline-none focus-visible:shadow-[var(--ring-focus-strong)]"
          style={labelStyle}
        >
          {seg.label}
        </Link>
      )}

      {isLast ? null : (
        <ChevronRight aria-hidden="true" className="size-3 shrink-0 text-muted-foreground/60" />
      )}
    </li>
  );
}
