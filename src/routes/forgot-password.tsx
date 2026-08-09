import { createFileRoute, Link } from "@tanstack/react-router";
import { useState, type FormEvent } from "react";
import { KeyRound, Loader2, Mail, ShieldCheck } from "lucide-react";

import { AuthCard, type AuthCardTrustPoint } from "../components/auth/AuthCard";
import { requestPasswordReset } from "../lib/lara-password-reset";
import { formatLaraApiError } from "../lib/lara-api-error";

/**
 * v0.302.0. Refit onto shared `AuthCard` (previously a bespoke single-
 * column shell that diverged from `/admin/login` and `/register`).
 */
export const Route = createFileRoute("/forgot-password")({
  head: () => ({
    meta: [
      { title: "Forgot Password | Licensing Portal" },
      {
        name: "description",
        content: "Request a password reset link for your Licensing Portal console account.",
      },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: ForgotPasswordPage,
});

const TRUST_POINTS: ReadonlyArray<AuthCardTrustPoint> = [
  {
    icon: ShieldCheck,
    title: "Anti-enumeration",
    body: "We always return the same neutral message whether or not the email exists in this workspace.",
  },
  {
    icon: KeyRound,
    title: "Single-use, 60-minute token",
    body: "Reset links carry a hashed one-shot token; a second click after redemption is rejected.",
  },
  {
    icon: Mail,
    title: "Rate-limited endpoint",
    body: "Repeated requests for the same email are throttled and audit-logged.",
  },
];

function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    setMessage(null);
    try {
      const reply = await requestPasswordReset({ Email: email.trim() });
      setMessage(reply);
    } catch (failure) {
      console.error("auth.forgot_password_failed", failure);
      setError(formatLaraApiError(failure));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard
      title="Reset your password"
      description="Enter the email tied to your Licensing Portal account. We will send a single-use reset link."
      asideHeadline="Recovery, without the enumeration risk."
      asideBody="Every forgot-password submission returns the same neutral response, so an attacker cannot fingerprint valid accounts by trying emails."
      asideTrustPoints={TRUST_POINTS}
      asideFooterNote="Audit-logged. Rate-limited. Tokens expire in 60 minutes."
      footerSlot={
        <>
          Remembered it after all?{" "}
          <Link
            to="/admin/login"
            className="font-medium text-foreground underline underline-offset-4 hover:underline"
          >
            Back to sign in
          </Link>
          .
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-5" aria-describedby="forgot-help">
        <label className="block space-y-1.5">
          <span className="text-sm font-medium text-foreground">Work email</span>
          <div className="relative">
            <Mail
              aria-hidden
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
              id="forgot-email"
              type="email"
              autoComplete="email"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              disabled={submitting}
              placeholder="you@example.com"
              className="h-11 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
            />
          </div>
        </label>

        {message ? (
          <p
            role="status"
            className="rounded-lg border border-emerald-500/40 bg-emerald-500/5 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300"
          >
            {message}
          </p>
        ) : null}
        {error ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive"
          >
            {error}
          </p>
        ) : null}

        <button
          type="submit"
          disabled={submitting || email.trim() === ""}
          className="group inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {submitting ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Send reset link
        </button>
        <p id="forgot-help" className="text-center text-xs text-muted-foreground">
          Rate-limited. Neutral response whether or not the address exists.
        </p>
      </form>
    </AuthCard>
  );
}
