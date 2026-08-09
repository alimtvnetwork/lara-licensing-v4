// spec/24-app-ui-design-system/26-iconography-and-assets.md §5.
// Normative concept-to-Lucide binding. Reuse the same glyph for the same
// concept across every surface (AC-ICO-005). Extending this map requires a
// same-commit update to §5 of the spec.

import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  ArrowUpRight,
  Ban,
  Boxes,
  CalendarX2,
  CheckCircle2,
  Copy,
  Download,
  Gauge,
  Info,
  Loader2,
  MoreHorizontal,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  SlidersHorizontal,
  Timer,
  ToggleRight,
  Upload,
  X,
  XCircle,
  type LucideIcon,
} from "lucide-react";

export const ICON_CONCEPTS = {
  Search,
  Filter: SlidersHorizontal,
  Refresh: RefreshCw,
  Copy,
  Overflow: MoreHorizontal,
  Close: X,
  Success: CheckCircle2,
  Info,
  Warning: AlertTriangle,
  Error: XCircle,
  RateLimited: Timer,
  ExternalLink: ArrowUpRight,
  Download,
  Upload,
  Add: Plus,
  Edit: Pencil,
  Revoke: Ban,
  Expired: CalendarX2,
  Environment: Boxes,
  Feature: ToggleRight,
  Quota: Gauge,
  SortAscending: ArrowUp,
  SortDescending: ArrowDown,
  Spinner: Loader2,
} as const satisfies Record<string, LucideIcon>;

export type IconConcept = keyof typeof ICON_CONCEPTS;
