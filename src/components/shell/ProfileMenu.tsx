/**
 * ProfileMenu: Topbar profile dropdown per spec/24-app-ui-design-system §7.
 *
 * Root cause the v0.283.0 refit fixes: the Admin topbar rendered a bare
 * "Sign out" button with no visible identity, so an operator inside an
 * impersonated session (or juggling multiple tenants) had no fast way to
 * confirm which user they were acting as, which is a Spec 24 §7.1 safety
 * gap. This component reads `GET /Users/Me` through the shared
 * `meQueryOptions()` (cached 60s, no retry) and renders identity + role
 * as the trigger, with sign-out as the terminal item.
 *
 * Observability: `meQueryOptions()` disables retry so a real 401 surfaces
 * once, and the trigger falls back to a neutral "Signed in" label when
 * `Me` is still loading or errored. Errors bubble via TanStack Query's
 * `error` state (surfaced through the existing `useLaraErrorToast`
 * subscription wired at the app root); do not swallow here.
 */
import { useQuery } from "@tanstack/react-query";
import { ChevronDown, LogOut, User as UserIcon } from "lucide-react";

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { meQueryOptions, type MeResource } from "@/lib/lara-me";

const LoadingLabel = "Signed in";
const SignOutLabel = "Sign out";
const RoleLabelPrefix = "Role";

export interface ProfileMenuProps {
  onSignOut: () => void;
}

export function ProfileMenu({ onSignOut }: ProfileMenuProps) {
  const meQuery = useQuery(meQueryOptions());
  const me = meQuery.data?.[0];
  const trigger = triggerLabel(me);

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className="focus-ring inline-flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm font-medium surface-hover"
        aria-label="Open profile menu"
      >
        <UserIcon aria-hidden="true" className="size-4 text-muted-foreground" />
        <span className="max-w-[16ch] truncate">{trigger.primary}</span>
        {trigger.secondary !== null ? (
          <span className="text-xs font-normal text-muted-foreground">{trigger.secondary}</span>
        ) : null}
        <ChevronDown aria-hidden="true" className="size-3 text-muted-foreground" />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-[220px]">
        <DropdownMenuLabel className="flex flex-col gap-0.5">
          <span className="truncate text-sm font-medium">{trigger.primary}</span>
          {me !== undefined ? (
            <span className="truncate text-xs font-normal text-muted-foreground">
              {`${RoleLabelPrefix}: ${me.RoleName}`}
            </span>
          ) : null}
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={onSignOut}>
          <LogOut aria-hidden="true" className="mr-2 size-4" />
          <span>{SignOutLabel}</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function triggerLabel(me: MeResource | undefined): {
  primary: string;
  secondary: string | null;
} {
  if (me === undefined) return { primary: LoadingLabel, secondary: null };
  const primary =
    typeof me.DisplayName === "string" && me.DisplayName.length > 0 ? me.DisplayName : me.Email;

  return { primary, secondary: me.RoleName };
}
