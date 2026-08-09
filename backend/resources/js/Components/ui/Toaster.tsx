import { Toaster as Sonner } from "sonner";

type ToasterProps = React.ComponentProps<typeof Sonner>;

/**
 * Plan 06 step 73: announcement surface for the Inertia console.
 *
 * Mirrors the SPA's `src/components/ui/sonner.tsx` transport (spec 24 §23.2):
 * top-inline-end, max 3 visible toasts, 3px inline-start accent per intent.
 * Sonner renders its list region with `aria-live="polite"` + `aria-atomic`,
 * which is the announcement contract every mutating console component
 * (LicenseDetailActions, QuotaRequestTable, QuotaRequestSubmitForm,
 * SerialIssueForm, ImpersonationActions) already assumed existed.
 *
 * Styling uses the same Tailwind semantic classes as the rest of
 * `resources/js` (bg-background / border-input / text-foreground) because the
 * Laravel stylesheet does not ship the SPA's CSS custom properties.
 */
export function Toaster(props: ToasterProps) {
  return (
    <Sonner
      position="top-right"
      visibleToasts={3}
      className="toaster group"
      toastOptions={{
        classNames: {
          toast:
            "group toast border border-input border-l-[3px] bg-background text-foreground rounded-lg shadow-lg motion-reduce:transition-none motion-reduce:animate-none",
          title: "text-sm font-semibold leading-tight tracking-tight",
          description: "text-sm text-muted-foreground mt-1",
          actionButton: "group-[.toast]:bg-primary group-[.toast]:text-primary-foreground group-[.toast]:rounded-md",
          cancelButton: "group-[.toast]:bg-muted group-[.toast]:text-muted-foreground group-[.toast]:rounded-md",
          success: "group-[.toaster]:border-l-emerald-500",
          info: "group-[.toaster]:border-l-sky-500",
          warning: "group-[.toaster]:border-l-amber-500",
          error: "group-[.toaster]:border-l-destructive",
        },
      }}
      {...props}
    />
  );
}
