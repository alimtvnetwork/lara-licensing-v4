/**
 * Identifier: mono-typed identifier chip with optional middle-ellipsis and
 * copy affordance per spec/24-app-ui-design-system/09-typography-scale.md §6
 * and spec/24-app-ui-design-system/14-breadcrumbs-and-page-header.md §4.3.
 *
 * Truncation invariant: the DISPLAYED string may be shortened via
 * middle-ellipsis (character `…`, never three dots per §8), but the COPY
 * action copies the complete `value`. The `title` tooltip always shows the
 * full untruncated value.
 */
import { Copy } from "lucide-react";
import { toast } from "sonner";

const IDENTIFIER_KEEP_HEAD = 8;
const IDENTIFIER_KEEP_TAIL = 4;
const IDENTIFIER_MIN_TRUNCATE = IDENTIFIER_KEEP_HEAD + IDENTIFIER_KEEP_TAIL + 1;

interface IdentifierProps {
  value: string;
  /** Hard character budget for the visible label; defaults to full value. */
  maxChars?: number;
  /** Render a trailing copy button. Defaults to true. */
  copyable?: boolean;
  /** Human-readable resource name for the copy button aria-label. */
  resource?: string;
}

/** Middle-ellipsis using the canonical `…` character per §8. */
export function middleEllipsis(value: string, maxChars: number): string {
  if (value.length <= maxChars) return value;
  if (maxChars < IDENTIFIER_MIN_TRUNCATE) return value.slice(0, maxChars - 1) + "…";

  return value.slice(0, IDENTIFIER_KEEP_HEAD) + "…" + value.slice(-IDENTIFIER_KEEP_TAIL);
}

export function Identifier({
  value,
  maxChars,
  copyable = true,
  resource = "identifier",
}: IdentifierProps) {
  const display = maxChars === undefined ? value : middleEllipsis(value, maxChars);
  const truncated = display !== value;

  return (
    <span
      className="inline-flex items-center gap-1 rounded-md border border-border bg-muted px-2 py-0.5"
      style={{ fontFamily: "var(--font-mono)", font: "var(--text-code)", fontWeight: 500 }}
    >
      <span title={truncated ? value : undefined} data-identifier-value>
        {display}
      </span>
      {copyable ? <IdentifierCopyButton value={value} resource={resource} /> : null}
    </span>
  );
}

function IdentifierCopyButton({ value, resource }: { value: string; resource: string }) {
  return (
    <button
      type="button"
      aria-label={`Copy ${resource}`}
      className="focus-ring inline-flex size-6 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground"
      onClick={() => copyIdentifier(value, resource)}
    >
      <Copy aria-hidden className="size-3.5" />
    </button>
  );
}

async function copyIdentifier(value: string, resource: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(value);
    toast.success(`Copied ${resource}`, { description: value });
  } catch (error) {
    console.error("Identifier.copy failed", { resource, error });
    toast.error(`Could not copy ${resource}`);
  }
}
