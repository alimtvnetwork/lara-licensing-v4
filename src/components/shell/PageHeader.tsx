/**
 * PageHeader: authenticated-route header composition per
 * spec/24-app-ui-design-system/14-breadcrumbs-and-page-header.md §2, §4, §5.
 *
 * Slots: `breadcrumb` (detail routes only), `title` (H1 text), inline
 * `statusBadge` and `identifier` chips after the H1, optional
 * `description` sentence below. Renders one H1 per route per AC-ADS-046.
 * `page-actions` is a sibling grid area owned by AppShell.
 */
import type { ReactNode } from "react";

import { Breadcrumbs, type CrumbSegmentType } from "./Breadcrumbs";

export interface PageHeaderProps {
  title: string;
  breadcrumbs?: CrumbSegmentType[];
  statusBadge?: ReactNode;
  identifier?: ReactNode;
  description?: string;
}

export function PageHeader(props: PageHeaderProps) {
  return (
    <header className="fade-in flex flex-col gap-3" data-page-region="page-header-inner">
      {props.breadcrumbs && props.breadcrumbs.length > 0 ? (
        <Breadcrumbs segments={props.breadcrumbs} />
      ) : null}
      <TitleRow title={props.title} statusBadge={props.statusBadge} identifier={props.identifier} />
      <span
        aria-hidden="true"
        className="block h-[3px] w-10 rounded-full"
        style={{ backgroundImage: "linear-gradient(90deg, var(--primary), var(--accent))" }}
      />
      {props.description ? <PageDescription>{props.description}</PageDescription> : null}
    </header>
  );
}

function TitleRow(props: { title: string; statusBadge?: ReactNode; identifier?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-center gap-3">
      {/*
        Plan 09 Step 17: H1 pins Ubuntu (--font-display) at clamp(1.75rem, 1.4rem + 1.2vw, 2rem)
        (28-32px per Spec 24 §14). Explicit family + size + line-height + letter-spacing
        avoids the `font:` shorthand family-drop bug fixed originally in Step 6.
      */}
      <h1
        className="text-foreground"
        style={{
          fontFamily: "var(--font-display)",
          fontSize: "clamp(1.75rem, 1.4rem + 1.2vw, 2rem)",
          lineHeight: 1.15,
          letterSpacing: "-0.01em",
          fontWeight: 600,
        }}
      >
        {props.title}
      </h1>
      {props.statusBadge ? <span data-slot="status">{props.statusBadge}</span> : null}
      {props.identifier ? <span data-slot="identifier">{props.identifier}</span> : null}
    </div>
  );
}

function PageDescription({ children }: { children: ReactNode }) {
  return (
    <p
      className="text-muted-foreground"
      style={{
        fontFamily: "var(--font-sans)",
        fontSize: "0.875rem",
        lineHeight: 1.5,
        maxInlineSize: "70ch",
      }}
    >
      {children}
    </p>
  );
}
