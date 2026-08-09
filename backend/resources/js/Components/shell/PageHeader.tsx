import { type ReactNode } from "react";
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
      <div className="flex flex-wrap items-center gap-3">
        <h1
          className="text-foreground font-display font-semibold tracking-tight"
          style={{ fontSize: "clamp(1.75rem, 1.4rem + 1.2vw, 2rem)", lineHeight: 1.15 }}
        >
          {props.title}
        </h1>
        {props.statusBadge && <span data-slot="status">{props.statusBadge}</span>}
        {props.identifier && <span data-slot="identifier">{props.identifier}</span>}
      </div>
      <span
        aria-hidden="true"
        className="block h-[3px] w-10 rounded-full"
        style={{ backgroundImage: "linear-gradient(90deg, var(--primary), var(--accent))" }}
      />
      {props.description && (
        <p className="text-muted-foreground text-sm leading-relaxed max-w-[70ch]">
          {props.description}
        </p>
      )}
    </header>
  );
}
