# Plan 18 · Step 12 · Notification Center FE Plan

Status: draft (produced by Plan 18 Step 12).

Depends on: `docs/backend/plan-18/11-error-manage-plan.md`
(envelope adds `Attributes.Category` + `Attributes.OperationId`,
new `X-Error-Id` header, `lara-audit-errors` NDJSON sink),
`src/hooks/use-app-toast.ts` (routing contract, TOAST_ELIGIBLE set),
`src/components/ui/sonner.tsx` (Sonner transport, `visibleToasts=3`),
`src/lib/lara-api-error.ts:219` (`LaraApiError` shape),
`src/components/shell/AppShell.tsx` (mount point for the bell).

## 1. Purpose

Every `LaraApiError` and every non-error toast surfaced through
`useAppToast()` should also land in a persistent in-app "Notification
Center" so operators can review the last N events after the Sonner
toast has auto-dismissed. Sonner remains the transient surface; the
Notification Center is the durable one.

## 2. Store location and API

New file: `src/stores/notification-center-store.ts`.

- Library: Zustand (already a project dep; see other stores under
  `src/stores/`). No new dep.
- Shape:

```ts
export type NotificationVariant = "success" | "info" | "warning" | "error";
export type NotificationCategory =
  | "Auth" | "Validation" | "RateLimit"
  | "DomainConflict" | "NotFound" | "Internal" | "App";
export interface NotificationEntry {
  id: string;                 // ULID; monotonic for keyboard nav
  createdAt: number;          // Date.now(); source of truth for sort
  variant: NotificationVariant;
  title: string;
  description?: string;
  requestId?: string;
  errorId?: string;
  operationId?: string;
  errorCode?: string;         // ApiErrorCodeType | undefined
  category: NotificationCategory;
  read: boolean;
}
interface NotificationCenterStore {
  entries: NotificationEntry[]; // capped ring buffer, newest first
  unreadCount: number;
  push(input: Omit<NotificationEntry, "id" | "createdAt" | "read">): void;
  markRead(id: string): void;
  markAllRead(): void;
  clear(): void;
}
```

## 3. Ring buffer size

- Cap: **50** entries. Justified by spec 24 §23.2 which already caps
  Sonner at 3 visible + "N earlier"; 50 is one-day-of-activity for a
  single operator without unbounded growth.
- Eviction: FIFO by `createdAt`; `push()` drops the oldest when
  `entries.length === 50` before inserting.
- No `LRU`, no priority weighting; simplest correct behaviour.

## 4. Toast bridge

Extend `src/hooks/use-app-toast.ts` (do NOT create a parallel bridge):

- After `toast[variant](title, ...)` fires in `callToast`, call
  `useNotificationCenterStore.getState().push({...})` with the same
  title, description, requestId, and (when the caller passed a
  `LaraApiError`) `errorId`, `operationId`, `errorCode`, `category`.
- The bridge is **strictly duplicative**, not gating: if a call would
  emit a toast today, it still emits a toast. The store just also
  records it. This keeps existing UX intact.
- Error routing violations (already logged as `ToastRoutingViolation`)
  are pushed with `variant = "warning"` and `category = "Internal"`
  so the notification center is the audit trail of routing bugs.
- `LaraApiError.category` (Plan 18 Step 94) supplies `category`;
  fallback is `"Internal"` when absent.

## 5. Unread badge

- Bell icon rendered in `AppShell.tsx` header row, right of the
  profile menu, using the shared `<IconButton>` primitive.
- Badge component: existing `<Badge>` in `src/components/ui/badge.tsx`,
  variant `"destructive"` when `unreadCount > 0`, hidden when zero.
- Count text: `unreadCount > 9 ? "9+" : String(unreadCount)`.
- ARIA: `aria-label={\`Notifications, ${unreadCount} unread\`}`;
  `aria-live="polite"` on the badge element only, so screen readers
  hear updates without stealing focus.

## 6. Route entry

- Route: `src/routes/_authenticated/notifications.tsx`. Full-page list
  view, reachable from the bell (click) and command palette entry
  "Open notifications" (added in the same step).
- Loader: none; reads directly from the store (client-only surface).
- Contents:
  - Grouped by day (today / yesterday / earlier).
  - Row shows variant chip, title, `errorCode`, `operationId`,
    `requestId`, `errorId`, timestamp, "Mark read", and a "Copy
    correlation IDs" action that copies `RequestId + ErrorId` as a
    single line.
  - Empty state: `<EmptyState>` primitive with copy "No notifications
    yet".
- The bell popover renders the top 5 entries with a "See all" link
  to this route; it is NOT a separate data source.

## 7. Keyboard access

- `Alt+N` opens the bell popover (registered in
  `src/components/shell/CommandPalette.tsx` global hotkey set;
  no new provider).
- Inside the popover / route:
  - `Arrow Up / Arrow Down` move focus between rows (`roving
    tabindex`).
  - `Enter` marks the focused row as read.
  - `Shift+Enter` copies the correlation IDs for the focused row.
  - `Esc` closes the popover.

## 8. Persistence policy

- **Not persisted** across reloads. Rationale: entries contain
  `requestId` and `errorId` which are session-correlation IDs; the
  Notification Center is a within-session log, not an audit log. The
  durable audit path is the BE `lara-audit-errors` NDJSON sink
  defined in Step 11 §5, which is what the admin errors screen
  (Plan 18 Steps 106-108) tails.
- No `localStorage`, no `sessionStorage`, no IndexedDB. Rejected
  explicitly to avoid PII bleed and to keep the browser bundle from
  needing a redaction pass on load.

## 9. Preview / seed integration

- The store is populated organically by `useAppToast()`, so preview
  fixtures automatically flow through it. No preview-specific code.
- The `error` seed profile (Plan 18 Step 7) triggers enough failing
  calls to prove the badge and route render under a red-heavy load;
  Playwright E2E in Step 165 asserts `unreadCount >= 5` after seeding
  `error`.

## 10. Test plan

- `src/stores/notification-center-store.test.ts` (Vitest):
  1. `push` inserts newest-first.
  2. Cap at 50; oldest evicted; `unreadCount` decrements when the
     evicted entry was unread.
  3. `markRead`, `markAllRead`, `clear` update `unreadCount`.
- `src/hooks/use-app-toast.test.ts` extension: asserts that a call
  emitting a toast also pushes a store entry with matching
  `requestId`, `errorId`, `errorCode`, and `category`.
- `src/routes/_authenticated/notifications.test.tsx`: renders empty
  state, renders grouped list, keyboard nav, and "Copy correlation
  IDs" action calls `navigator.clipboard.writeText` with the exact
  `RequestId + ErrorId` line.
- Playwright: `tests/e2e/notification-center.spec.ts` under
  `error` seed profile.

## 11. Out of scope

- Multi-tab sync (would require BroadcastChannel + persistence;
  rejected in §8).
- Server-side push / real-time notifications: no WebSocket surface in
  the project. Any future push arrives via a separate plan.
- Grouping/collapsing repeated errors: post-Plan-18 polish.
- Muting by `errorCode`: post-Plan-18 polish.
