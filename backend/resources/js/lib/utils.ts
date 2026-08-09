// Plan 06 step 66. Shared class-name helper for the Inertia console,
// mirroring src/lib/utils.ts in the SPA. Components ported in steps
// 62-66 (EmptyState, Button, UserTable, ResellerTable) import `cn` from
// here.

import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
