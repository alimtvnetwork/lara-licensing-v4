import { useState, useCallback, useMemo } from "react";
import { format } from "date-fns";
import { Bell, Copy, AlertCircle, RefreshCw, Filter } from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import { useErrorFeed } from "../../hooks/use-error-feed";
import { adminErrorsQueryOptions } from "../../lib/lara-errors";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "../ui/sheet";
import { Badge } from "../ui/badge";
import type { ErrorStoreEntry } from "../../lib/error-store";
import { useCapability } from "../../lib/capabilities";

export function NotificationDrawer({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const { entries: clientEntries, markAsRead } = useErrorFeed();
  const isAdmin = useCapability("Admin");

  const [filterCode, setFilterCode] = useState<string>("All");

  const serverQuery = useQuery({
    ...adminErrorsQueryOptions(),
    enabled: isAdmin,
  });

  const merged = useMemo(() => {
    const map = new Map<string, any>();
    // Seed with client entries first
    clientEntries.forEach((e) => {
      if (e.errorId) {
        map.set(e.errorId, { ...e, _source: "client" });
      }
    });
    // Overwrite with server entries if available
    if (serverQuery.data) {
      serverQuery.data.forEach((se) => {
        if (se.ErrorId) {
          map.set(se.ErrorId, {
            id: se.ErrorId,
            errorId: se.ErrorId,
            requestId: se.RequestId,

            errorCode: se.ErrorCode,
            httpStatus: se.HttpStatus,
            message: "Server Error",
            at: new Date(se.RequestedAt).getTime(),
            _source: "server",
          });
        }
      });
    }

    let result = Array.from(map.values()).sort((a, b) => b.at - a.at);
    if (filterCode !== "All") {
      result = result.filter((e) => e.errorCode === filterCode);
    }

    return result;
  }, [clientEntries, serverQuery.data, filterCode]);

  const handleOpen = (next: boolean) => {
    if (next) markAsRead();
    onOpenChange(next);
  };

  const copyToClipboard = useCallback((text: string) => {
    navigator.clipboard.writeText(text).catch((err) => {
      pushLaraApiError(new Error());
    });
  }, []);

  const uniqueCodes = useMemo(() => {
    const codes = new Set<string>();
    clientEntries.forEach((e) => codes.add(e.errorCode));
    if (serverQuery.data) {
      serverQuery.data.forEach((se) => {
        if (se.ErrorCode) codes.add(se.ErrorCode);
      });
    }

    return Array.from(codes).sort();
  }, [clientEntries, serverQuery.data]);

  return (
    <Sheet open={open} onOpenChange={handleOpen}>
      <SheetContent className="w-full sm:max-w-md overflow-y-auto">
        <SheetHeader className="mb-6">
          <div className="flex items-center justify-between">
            <SheetTitle className="flex items-center gap-2">
              <AlertCircle className="size-5 text-muted-foreground" />
              System Errors
            </SheetTitle>
            <select
              className="text-sm border rounded p-1"
              value={filterCode}
              onChange={(e) => setFilterCode(e.target.value)}
            >
              <option value="All">All types</option>
              {uniqueCodes.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
          </div>
        </SheetHeader>

        {merged.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <Bell className="size-10 text-muted-foreground/30 mb-4" />
            <p className="text-sm font-medium text-foreground">No errors recorded</p>
            <p className="text-xs text-muted-foreground mt-1">Your error history is clean.</p>
          </div>
        ) : (
          <div className="flex flex-col gap-3">
            {merged.map((entry) => (
              <div
                key={entry.id}
                className="rounded-lg border bg-card text-card-foreground p-3 shadow-sm text-sm"
                data-testid="notification-entry"
              >
                <div className="flex items-center justify-between mb-2">
                  <Badge variant={entry.httpStatus >= 500 ? "destructive" : "secondary"}>
                    {entry.errorCode || `HTTP ${entry.httpStatus}`}
                  </Badge>
                  <time className="text-xs text-muted-foreground">
                    {format(entry.at, "HH:mm:ss")}
                  </time>
                </div>

                <p className="font-medium mb-3 line-clamp-2">{entry.message}</p>

                <dl className="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1 text-xs text-muted-foreground">
                  {entry.operationId && (
                    <>
                      <dt>Operation</dt>
                      <dd className="font-mono truncate">{entry.operationId}</dd>
                    </>
                  )}
                  {entry.requestId && (
                    <>
                      <dt>Request</dt>
                      <dd className="font-mono truncate">{entry.requestId}</dd>
                    </>
                  )}
                  {entry.errorId && (
                    <>
                      <dt>Error ID</dt>
                      <dd className="font-mono truncate flex items-center justify-between gap-2">
                        <span className="truncate">{entry.errorId}</span>
                        <button
                          type="button"
                          className="hover:text-foreground shrink-0"
                          onClick={() => copyToClipboard(entry.errorId!)}
                          title="Copy Error ID"
                        >
                          <Copy className="size-3" />
                        </button>
                      </dd>
                    </>
                  )}
                </dl>
              </div>
            ))}
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
