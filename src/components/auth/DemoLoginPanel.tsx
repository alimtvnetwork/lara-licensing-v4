import { useEffect, useState } from "react";
import { Copy, ShieldCheck, User, Users, UserRound, Loader2, Check } from "lucide-react";
import { toast } from "sonner";
import { DEMO_IDENTITIES, signInWithSeedIdentity, type DemoIdentity } from "@/lib/preview-auth";
import { cn } from "@/lib/utils";

interface DemoLoginPanelProps {
  onSuccess: () => void;
  disabled?: boolean;
}

/**
 * Quick-access identities for Seed Mode.
 * Plan 18 Phase C (Steps 43-47, 80).
 */
export function DemoLoginPanel({ onSuccess, disabled }: DemoLoginPanelProps) {
  const [signingIn, setSigningIn] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);

  // Ctrl+Shift+D hotkey registry (Step 47, 80)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.ctrlKey && e.shiftKey && e.key === "D") {
        e.preventDefault();
        // Focus first button if shortcut hit
        const first = document.querySelector<HTMLButtonElement>('[data-testid="demo-login-admin"]');
        first?.focus();
      }
    };
    window.addEventListener("keydown", handleKeyDown);

    return () => window.removeEventListener("keydown", handleKeyDown);
  }, []);

  const handleSignIn = async (id: string) => {
    if (disabled || signingIn) return;
    setSigningIn(id);
    try {
      await signInWithSeedIdentity(id);
      toast.success(`Signed in as ${id}`);
      onSuccess();
    } catch (err) {
      toast.error("Demo login failed");
      console.error("demo_login.error", err);
    } finally {
      setSigningIn(null);
    }
  };

  const handleCopy = (email: string) => {
    navigator.clipboard.writeText(email);
    setCopied(email);
    toast.info("Email copied to clipboard");
    setTimeout(() => setCopied(null), 2000);
  };

  return (
    <div className="mt-8 space-y-4 rounded-xl border border-primary/20 bg-primary/5 p-4 animate-in fade-in slide-in-from-bottom-2 duration-300">
      <div className="flex items-center gap-2 text-xs font-bold tracking-wider text-primary uppercase">
        <ShieldCheck className="size-3.5" />
        Seed Mode Identites
      </div>

      <div className="grid gap-3" role="list" aria-label="Demo accounts">
        {DEMO_IDENTITIES.map((identity) => (
          <DemoIdentityRow
            key={identity.id}
            identity={identity}
            isSigningIn={signingIn === identity.id}
            disabled={disabled || (signingIn !== null && signingIn !== identity.id)}
            onSignIn={() => handleSignIn(identity.id)}
            onCopy={() => handleCopy(identity.email)}
            isCopied={copied === identity.email}
          />
        ))}
      </div>

      <p className="text-[10px] text-muted-foreground leading-relaxed">
        <span className="font-semibold text-primary/70">PRO TIP:</span> Use{" "}
        <kbd className="rounded bg-muted px-1.5 py-0.5 font-mono text-[9px] border border-border shadow-sm">
          Ctrl+Shift+D
        </kbd>{" "}
        to focus this panel. Sessions are client-side only.
      </p>
    </div>
  );
}

function DemoIdentityRow({
  identity,
  isSigningIn,
  disabled,
  onSignIn,
  onCopy,
  isCopied,
}: {
  identity: DemoIdentity;
  isSigningIn: boolean;
  disabled: boolean;
  onSignIn: () => void;
  onCopy: () => void;
  isCopied: boolean;
}) {
  const Icon =
    identity.role === "admin" ? ShieldCheck : identity.role === "reseller" ? Users : User;

  return (
    <div className="group relative flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-background/50 p-2.5 transition-all hover:border-primary/30 hover:bg-background">
      <div className="flex items-center gap-3">
        <div
          className={cn(
            "flex size-9 items-center justify-center rounded-md border shadow-sm",
            identity.role === "admin"
              ? "bg-primary/10 text-primary border-primary/20"
              : identity.role === "reseller"
                ? "bg-blue-500/10 text-blue-600 border-blue-500/20"
                : "bg-muted text-muted-foreground border-border",
          )}
        >
          <Icon className="size-4.5" />
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="text-sm font-semibold text-foreground leading-none">
            {identity.label}
          </span>
          <span className="text-[11px] text-muted-foreground truncate max-w-[140px]">
            {identity.description}
          </span>
        </div>
      </div>

      <div className="flex items-center gap-1.5">
        <button
          type="button"
          onClick={onCopy}
          title="Copy email"
          className="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
          {isCopied ? <Check className="size-3.5 text-success" /> : <Copy className="size-3.5" />}
        </button>
        <button
          type="button"
          data-testid={`demo-login-${identity.id}`}
          onClick={onSignIn}
          disabled={disabled}
          className="inline-flex h-8 items-center justify-center rounded-md bg-primary px-3 text-[11px] font-bold text-primary-foreground shadow-sm transition-all hover:bg-primary/90 disabled:opacity-50"
        >
          {isSigningIn ? <Loader2 className="size-3 animate-spin" /> : "Quick Sign-in"}
        </button>
      </div>
    </div>
  );
}
