import { Link } from "@inertiajs/react";
import { ChevronRight } from "lucide-react";

export interface CrumbSegmentType {
  label: string;
  to?: string;
  identifier?: boolean;
}

interface BreadcrumbsProps {
  segments: CrumbSegmentType[];
}

export function Breadcrumbs({ segments }: BreadcrumbsProps) {
  if (segments.length === 0) return null;
  return (
    <nav aria-label="Breadcrumb">
      <ol className="flex flex-wrap items-center gap-1.5 text-[0.8125rem] font-medium text-muted-foreground">
        {segments.map((seg, index) => (
          <li key={index} className="flex items-center gap-1.5">
            {index > 0 && <ChevronRight className="size-3.5 opacity-40" />}
            {seg.to ? (
              <Link href={seg.to} className="hover:text-foreground transition-colors">
                {seg.label}
              </Link>
            ) : (
              <span className="text-foreground font-semibold">{seg.label}</span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
