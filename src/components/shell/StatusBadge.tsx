/**
 * StatusBadge: enum-state pill rebuilt on the refit `Badge` intent registry
 * per spec/24-app-ui-design-system/25-component-badge-status.md §4 and
 * spec/24-app-ui-design-system/14-breadcrumbs-and-page-header.md §4.2.
 *
 * Two call shapes:
 *   1. Enum-driven (preferred): `<StatusBadge registry="LicenseState" value="Active" />`
 *      resolves (intent, icon, label) from the closed-set registry. Unknown
 *      values throw in dev / warn in prod (AC-BDG-002); silent neutral
 *      fallback is banned.
 *   2. Tone-driven (legacy, kept for existing routes / tests): the caller
 *      supplies `tone` + `label` and optionally `icon` / `children`.
 *
 * Rules preserved from the earlier version (AC-ADS-005): icon always
 * present, tone exposed as `data-tone`, label rendered as text so screen
 * readers announce state without relying on color, trailing count slot
 * uses `.tabular` for tabular numerals.
 */
import type { LucideIcon } from "lucide-react";
import { CheckCircle2, CircleSlash, Clock, Info, TriangleAlert } from "lucide-react";
import type { ReactNode } from "react";

import { Badge, type BadgeIntent } from "@/components/ui/badge";
import {
  resolveBadgeSpec,
  type BadgeRegistryName,
  type BadgeSpec,
} from "@/components/badge/registry";

export type StatusToneType = "success" | "warning" | "destructive" | "neutral" | "info" | "accent";

type EnumProps = {
  registry: BadgeRegistryName;
  value: string;
  tone?: never;
  label?: string;
  icon?: LucideIcon;
  children?: ReactNode;
};

type LegacyProps = {
  registry?: never;
  value?: never;
  tone: StatusToneType;
  label: string;
  icon?: LucideIcon;
  children?: ReactNode;
};

type StatusBadgeProps = EnumProps | LegacyProps;

const toneToIntent: Record<StatusToneType, BadgeIntent> = {
  success: "success",
  warning: "warning",
  destructive: "destructive",
  neutral: "neutral",
  info: "info",
  accent: "accent",
};

const intentToTone: Record<BadgeIntent, StatusToneType> = {
  success: "success",
  warning: "warning",
  destructive: "destructive",
  neutral: "neutral",
  info: "info",
  accent: "accent",
};

const toneFallbackIcon: Record<StatusToneType, LucideIcon> = {
  success: CheckCircle2,
  warning: Clock,
  destructive: CircleSlash,
  neutral: TriangleAlert,
  info: Info,
  accent: Info,
};

function resolve(props: StatusBadgeProps): { spec: BadgeSpec; tone: StatusToneType } {
  if ("registry" in props && props.registry) {
    const spec = resolveBadgeSpec(props.registry, props.value);
    const label = props.label ?? spec.label;
    const icon = props.icon ?? spec.icon;

    return { spec: { intent: spec.intent, icon, label }, tone: intentToTone[spec.intent] };
  }
  const tone = props.tone;
  const intent = toneToIntent[tone];

  return {
    spec: { intent, icon: props.icon ?? toneFallbackIcon[tone], label: props.label },
    tone,
  };
}

export function StatusBadge(props: StatusBadgeProps) {
  const { spec, tone } = resolve(props);
  const Icon = spec.icon;

  return (
    <Badge
      role="status"
      data-tone={tone}
      intent={spec.intent}
      className="rounded-full uppercase tracking-[0.04em] h-6 px-2"
    >
      <Icon aria-hidden="true" className="size-3.5" />
      <span>{spec.label}</span>
      {props.children ? <span className="tabular tabular-nums">{props.children}</span> : null}
    </Badge>
  );
}
