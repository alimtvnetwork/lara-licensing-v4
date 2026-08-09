import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { useState } from "react";

import { formatLaraApiError } from "../../lib/lara-api-error";
import {
  revokeAuthSession,
  userSessionsQueryOptions,
  type AuthSession,
} from "../../lib/lara-sessions";

/**
 * v0.298.0. Admin panel listing AuthSessions for a user with a
 * "Revoke" action that writes RevokeReason=AdminForced via
 * DELETE /Api/Admin/Sessions/{SessionId}. Refreshes the list on success.
 */
export function UserSessionsPanel({
  userId,
  callerUserId,
}: {
  userId: number;
  callerUserId: number | null;
}) {
  const [includeEnded, setIncludeEnded] = useState(false);
  const query = useSuspenseQuery(userSessionsQueryOptions(userId, includeEnded));
  const client = useQueryClient();
  const [error, setError] = useState<string>("");
  const revoke = useMutation({
    mutationFn: (sessionId: string) => revokeAuthSession(sessionId),
    onSuccess: async () => {
      setError("");
      await client.invalidateQueries({ queryKey: ["LaraApi", "Admin", "UserSessions", userId] });
    },
    onError: (err) => setError(formatLaraApiError(err)),
  });
  const rows = query.data;

  return (
    <section aria-labelledby="sessions-heading" className="mt-6">
      <div className="mb-3 flex items-center justify-between">
        <h2 id="sessions-heading" className="text-sm font-medium">
          Sessions
        </h2>
        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          <input
            type="checkbox"
            checked={includeEnded}
            onChange={(event) => setIncludeEnded(event.target.checked)}
            className="size-3.5"
          />
          Include ended
        </label>
      </div>
      {error !== "" && (
        <p
          role="alert"
          className="mb-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive"
        >
          {error}
        </p>
      )}
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No sessions match the current filter.</p>
      ) : (
        <ul className="divide-y divide-border rounded-md border border-border">
          {rows.map((row) => (
            <SessionRow
              key={row.SessionId}
              session={row}
              isSelf={callerUserId === row.UserId}
              onRevoke={() => revoke.mutate(row.SessionId)}
              revoking={revoke.isPending}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

interface RowProps {
  session: AuthSession;
  isSelf: boolean;
  onRevoke: () => void;
  revoking: boolean;
}

function SessionRow({ session, isSelf, onRevoke, revoking }: RowProps) {
  const active = session.IsActive;

  return (
    <li className="grid grid-cols-[1fr_auto] items-start gap-3 px-3 py-2 text-xs">
      <div>
        <div className="flex flex-wrap items-center gap-2">
          <code className="font-mono text-[11px]">{session.SessionId}</code>
          <span
            className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${active ? "bg-emerald-500/15 text-emerald-700 dark:text-emerald-300" : "bg-muted text-muted-foreground"}`}
          >
            {active ? "Active" : (session.RevokeReason ?? "Ended")}
          </span>
          <span className="rounded bg-muted px-1.5 py-0.5 text-[10px]">{session.Kind}</span>
          {isSelf && (
            <span className="rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] text-amber-700 dark:text-amber-300">
              You
            </span>
          )}
        </div>
        <div className="mt-1 text-muted-foreground">
          Created {formatTime(session.CreatedAt)} · Expires {formatTime(session.ExpiresAt)}
          {session.EndedAt !== null && <> · Ended {formatTime(session.EndedAt)}</>}
        </div>
      </div>
      {active && (
        <button
          type="button"
          onClick={onRevoke}
          disabled={revoking}
          className="rounded-md border border-destructive/40 px-2.5 py-1 text-xs font-medium text-destructive hover:bg-destructive/10 disabled:opacity-50"
          aria-label={`Revoke session ${session.SessionId}`}
        >
          {revoking ? "Revoking..." : "Revoke"}
        </button>
      )}
    </li>
  );
}

function formatTime(iso: string | null): string {
  if (iso === null) return "-";
  const d = new Date(iso);

  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
}
