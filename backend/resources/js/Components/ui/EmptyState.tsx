import { type ReactNode } from "react";
import { Package } from "lucide-react";
import { cn } from "@/lib/utils";

interface Props {
  headline: string;
  body?: string;
  preset?: "box" | "plain";
  icon?: ReactNode;
  action?: ReactNode;
  className?: string;
}

export function EmptyState({ headline, body, preset = "plain", icon, action, className }: Props) {
  const isBox = preset === "box";
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center text-center",
        isBox && "rounded-lg border-2 border-dashed border-border p-12",
        className
      )}
    >
      <div className="flex size-12 items-center justify-center rounded-full bg-muted">
        {icon ?? <Package className="size-6 text-muted-foreground" />}
      </div>
      <h3 className="mt-4 text-lg font-semibold">{headline}</h3>
      {body && <p className="mt-2 text-sm text-muted-foreground max-w-sm">{body}</p>}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}
