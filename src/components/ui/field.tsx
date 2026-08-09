import * as React from "react";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

/**
 * Field composition primitive per spec 24 §18.3 (Anatomy) and §18.5 (aria).
 *
 * Contract:
 * - Owns generated ids for label / helper / error and wires them onto the
 *   Input via cloneElement so callers do not hand-manage `aria-describedby`
 *   or `aria-invalid`. AC-INP-003: aria-describedby lists the helper id when
 *   helper renders, the error id when error renders, never both.
 * - Helper and Error occupy the SAME 20px block; only one renders at a time
 *   and the wrapper reserves `min-block-size: 20px` to prevent layout shift
 *   on validation surface change (spec §18.3 bullet 3).
 * - `required` toggles the visible `*` marker (aria-hidden per §18.3) and
 *   sets `aria-required="true"` on the underlying control.
 * - `error` presence implies `aria-invalid="true"` on the control and flips
 *   `data-invalid="true"` on the wrapper so the input primitive's
 *   `aria-[invalid=true]:border-[var(--destructive)]` rule fires.
 *
 * The wrapped control is expected to be a single ReactElement forwarding
 * ref (Input, Textarea, Select trigger). Errors originate from Zod or the
 * server envelope's `Data.FieldErrors` (spec §18.7); this primitive is
 * source-agnostic and only paints what the caller passes as `error`.
 */

type FieldContextValue = {
  fieldId: string;
  helperId: string;
  errorId: string;
  invalid: boolean;
  required: boolean;
  hasHelper: boolean;
};

const FieldContext = React.createContext<FieldContextValue | null>(null);

function useFieldContext(component: string): FieldContextValue {
  const ctx = React.useContext(FieldContext);
  const isFailed = !ctx;
  if (isFailed) {
    throw new Error(`<${component}> must be rendered inside <Field>.`);
  }
  return ctx;
}

export interface FieldProps extends React.HTMLAttributes<HTMLDivElement> {
  id?: string;
  required?: boolean;
  error?: React.ReactNode;
  helper?: React.ReactNode;
}

const Field = React.forwardRef<HTMLDivElement, FieldProps>(function Field(
  { id, required = false, error, helper, className, children, ...rest },
  ref,
) {
  const generatedId = React.useId();
  const fieldId = id ?? `field-${generatedId}`;
  const helperId = `${fieldId}-helper`;
  const errorId = `${fieldId}-error`;
  const invalid = Boolean(error);
  const ctx = React.useMemo<FieldContextValue>(
    () => ({
      fieldId,
      helperId,
      errorId,
      invalid,
      required,
      hasHelper: Boolean(helper),
    }),
    [fieldId, helperId, errorId, invalid, required, helper],
  );
  return (
    <FieldContext.Provider value={ctx}>
      <div
        ref={ref}
        data-invalid={invalid ? "true" : undefined}
        className={cn("flex flex-col gap-[var(--space-1_5,0.375rem)]", className)}
        {...rest}
      >
        {children}
        <div className="min-h-5">
          {invalid ? (
            <FieldError>{error}</FieldError>
          ) : helper ? (
            <FieldHelper>{helper}</FieldHelper>
          ) : null}
        </div>
      </div>
    </FieldContext.Provider>
  );
});

const FieldLabel = React.forwardRef<HTMLLabelElement, React.ComponentPropsWithoutRef<typeof Label>>(
  function FieldLabel({ className, children, ...rest }, ref) {
    const { fieldId, required } = useFieldContext("FieldLabel");
    return (
      <Label
        ref={ref}
        htmlFor={fieldId}
        className={cn("text-[var(--foreground)]", className)}
        {...rest}
      >
        {children}
        {required ? (
          <span aria-hidden className="ml-0.5 text-[var(--destructive)]">
            *
          </span>
        ) : null}
      </Label>
    );
  },
);

type ControlChildProps = {
  id?: string;
  "aria-invalid"?: boolean | "true" | "false";
  "aria-describedby"?: string;
  "aria-required"?: boolean | "true" | "false";
  required?: boolean;
};

interface FieldControlProps {
  children: React.ReactElement<ControlChildProps>;
}

function FieldControl({ children }: FieldControlProps) {
  const { fieldId, helperId, errorId, invalid, required, hasHelper } =
    useFieldContext("FieldControl");
  const child = React.Children.only(children);
  const existingDescribedBy = child.props["aria-describedby"];
  const describedBy = invalid ? errorId : hasHelper ? helperId : undefined;
  const merged = [existingDescribedBy, describedBy].filter(Boolean).join(" ") || undefined;
  return React.cloneElement(child, {
    id: child.props.id ?? fieldId,
    "aria-invalid": invalid ? "true" : child.props["aria-invalid"],
    "aria-describedby": merged,
    "aria-required": required ? "true" : child.props["aria-required"],
    required: required || child.props.required,
  });
}

const FieldHelper = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(function FieldHelper({ className, ...rest }, ref) {
  const { helperId } = useFieldContext("FieldHelper");
  return (
    <p
      ref={ref}
      id={helperId}
      className={cn("text-xs text-[var(--muted-foreground)] leading-5", className)}
      {...rest}
    />
  );
});

const FieldError = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(function FieldError({ className, ...rest }, ref) {
  const { errorId } = useFieldContext("FieldError");
  return (
    <p
      ref={ref}
      id={errorId}
      role="alert"
      aria-live="polite"
      className={cn("text-xs text-[var(--destructive)] leading-5", className)}
      {...rest}
    />
  );
});

export { Field, FieldLabel, FieldControl, FieldHelper, FieldError };
