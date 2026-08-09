// spec/24-app-ui-design-system/26-iconography-and-assets.md.
// Single wrapper for every icon rendered in the UI. Enforces closed size
// token set (§2), accessible-name modes (§4), and single-family constraint
// (§1) at the type level via IconConcept from the registry.

import * as React from "react";
import { cn } from "@/lib/utils";
import { ICON_CONCEPTS, type IconConcept } from "@/components/icon/registry";

export type IconSize = "xs" | "sm" | "md" | "lg" | "xl";

// Style objects keep pixel size on inline `width`/`height` so the closed
// token set in styles.css remains the single source of truth (AC-ICO-003).
const SIZE_STYLE: Record<IconSize, React.CSSProperties> = {
  xs: { width: "var(--icon-xs)", height: "var(--icon-xs)" },
  sm: { width: "var(--icon-sm)", height: "var(--icon-sm)" },
  md: { width: "var(--icon-md)", height: "var(--icon-md)" },
  lg: { width: "var(--icon-lg)", height: "var(--icon-lg)" },
  xl: { width: "var(--icon-xl)", height: "var(--icon-xl)" },
};

type IconBaseProps = {
  concept: IconConcept;
  size?: IconSize;
  className?: string;
};

// Accessible-name discriminator (spec §4). Decorative is the default because
// nearly every icon is paired with a visible label.
type IconDecorative = IconBaseProps & { decorative?: true; label?: never };
type IconMeaningful = IconBaseProps & { decorative: false; label: string };

export type IconProps = IconDecorative | IconMeaningful;

export function Icon(props: IconProps): React.ReactElement {
  const { concept, size = "sm", className } = props;
  const Glyph = ICON_CONCEPTS[concept];
  const ariaProps =
    props.decorative === false
      ? { role: "img" as const, "aria-label": props.label }
      : { "aria-hidden": true as const };
  return (
    <Glyph
      style={SIZE_STYLE[size]}
      className={cn("shrink-0", className)}
      strokeWidth={1.5}
      {...ariaProps}
    />
  );
}
