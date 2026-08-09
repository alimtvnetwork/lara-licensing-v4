import { Toaster as Sonner } from "sonner";

type ToasterProps = React.ComponentProps<typeof Sonner>;

/**
 * Refit to spec 24 §23.2. Sonner is the transport for Toast primitives; the
 * routing contract lives in `src/hooks/use-app-toast.ts`. Geometry: card fill,
 * --radius-md, elevation-1, 3px inline-start accent per intent (info/success/
 * warning/error) applied via inline data-attributes on individual toasts.
 *
 * Position: top-inline-end per spec §23.2.2; sonner defaults align. Max 3
 * visible + "N earlier" chip is enforced via sonner's `visibleToasts` prop.
 */
const Toaster = ({ ...props }: ToasterProps) => {
  return (
    <Sonner
      position="top-right"
      visibleToasts={3}
      className="toaster group"
      toastOptions={{
        classNames: {
          toast: [
            "group toast fade-in",
            "group-[.toaster]:bg-[var(--card)]",
            "group-[.toaster]:text-[var(--foreground)]",
            "group-[.toaster]:border group-[.toaster]:border-[var(--border)]",
            "group-[.toaster]:rounded-[var(--radius-xl)]",
            "group-[.toaster]:shadow-[var(--shadow-elevation-2)]",
            "group-[.toaster]:border-l-[3px]",
            "group-[.toaster]:backdrop-blur-md",
            "group-[.toaster]:motion-reduce:transition-none",
            "group-[.toaster]:motion-reduce:animate-none",
          ].join(" "),
          title: "text-sm font-semibold leading-tight tracking-tight",
          description: "text-sm text-[var(--muted-foreground)] mt-[var(--space-1)]",
          actionButton:
            "group-[.toast]:bg-[var(--primary)] group-[.toast]:text-[var(--primary-foreground)] group-[.toast]:rounded-[var(--radius-md)] group-[.toast]:shadow-[var(--shadow-elevation-1)]",
          cancelButton:
            "group-[.toast]:bg-[var(--muted)] group-[.toast]:text-[var(--muted-foreground)] group-[.toast]:rounded-[var(--radius-md)]",
          success: "group-[.toaster]:border-l-[var(--success,var(--primary))]",
          info: "group-[.toaster]:border-l-[var(--info,var(--primary))]",
          warning: "group-[.toaster]:border-l-[var(--warning,var(--primary))]",
          error: "group-[.toaster]:border-l-[var(--destructive)]",
        },
      }}
      {...props}
    />
  );
};

export { Toaster };
