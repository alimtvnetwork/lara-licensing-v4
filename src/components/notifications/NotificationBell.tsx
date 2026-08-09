import { useState } from "react";
import { Bell } from "lucide-react";
import { useErrorFeed } from "../../hooks/use-error-feed";
import { NotificationDrawer } from "./NotificationDrawer";

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const { unreadCount } = useErrorFeed();

  return (
    <>
      <button
        type="button"
        className="relative inline-flex items-center justify-center rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground focus-ring"
        onClick={() => setOpen(true)}
        aria-label="View notifications"
        data-testid="notification-bell"
      >
        <Bell className="size-5" aria-hidden="true" />
        {unreadCount > 0 && (
          <span
            className="absolute top-1.5 right-1.5 flex size-2.5 rounded-full bg-destructive"
            aria-live="polite"
          >
            <span className="sr-only">{unreadCount} unread errors</span>
          </span>
        )}
      </button>

      <NotificationDrawer open={open} onOpenChange={setOpen} />
    </>
  );
}
