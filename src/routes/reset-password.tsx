import { createFileRoute, Link, useNavigate, useSearch } from "@tanstack/react-router";
import { useState, type FormEvent } from "react";
import { KeyRound, Loader2, Lock, ShieldCheck, Sparkles } from "lucide-react";
import { z } from "zod";

import { AuthCard, type AuthCardTrustPoint } from "../components/auth/AuthCard";
import { submitPasswordReset } from "../lib/lara-password-reset";
import { formatLaraApiError } from "../lib/lara-api-error";

/**
 * v0.302.0. Refit onto shared `AuthCard`. Token+Email default to the
 * search params emitted in the reset email link.
 */
const resetSearchSchema = z.object({
  Email: z.string().optional().default(""),
  Token: z.string().optional().default(""),
});

export const Route = createFileRoute("/reset-password")({
  validateSearch: (search) => resetSearchSchema.parse(search),
  head: () => ({
    meta: [
      { title: "Reset Password | Licensing Portal" },
      {
        name: "description",
        content: "Set a new password for your Licensing Portal console account.",
      },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: ResetPasswordPage,
});

const PASSWORD_MIN = 8;

const TRUST_POINTS: ReadonlyArray<AuthCardTrustPoint> = [
  {
    icon: ShieldCheck,
    title: "Single-use token",
    body: "Reset tokens are hashed at rest and marked consumed the moment the new password lands.",
  },
  {
    icon: Lock,
    title: "8 char minimum",
    body: "Enforced client and server side; bcrypt-hashed before it touches the users table.",
  },
  {
    icon: Sparkles,
    title: "Session-scoped bearer on next sign-in",
    body: "Existing sessions are unaffected. Your next sign-in opens a fresh audit-logged AuthSession.",
  },
];

function ResetPasswordPage() {
  const search = useSearch({ from: "/reset-password" });
  const navigate = useNavigate();
  const [email, setEmail] = useState(search.Email);
  const [token, setToken] = useState(search.Token);
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) return;
    if (password.length < PASSWORD_MIN) {
      setError(`Password must be at least ${PASSWORD_MIN} characters.`);

      return;
    }
    if (password !== confirm) {
      setError("Passwords do not match.");

      return;
    }
    setSubmitting(true);
    setError(null);
    setMessage(null);
    try {
      const reply = await submitPasswordReset({
        Email: email.trim(),
        Token: token.trim(),
        NewPassword: password,
      });
      setMessage(reply);
      setTimeout(() => {
        void navigate({ to: "/admin/login" });
      }, 1500);
    } catch (failure) {
      pushLaraApiError(new Error());
      setError(formatLaraApiError(failure));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard
      title="Choose a new password"
      description="Your token is single-use and expires in 60 minutes. Store the new password in a password manager."
      asideHeadline="One token, one password, one audit row."
      asideBody="Every reset consumes the token in the same transaction that rehashes your password, so a replay after success is rejected with a clear error."
      asideTrustPoints={TRUST_POINTS}
      asideFooterNote="Audit-logged. Rate-limited. Tokens expire in 60 minutes."
      footerSlot={
        <Link
          to="/admin/login"
          className="font-medium text-foreground underline underline-offset-4 hover:underline"
        >
          Back to sign in
        </Link>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" aria-describedby="reset-help">
        <ResetField
          id="reset-email"
          label="Email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          disabled={submitting}
        />
        <ResetField
          id="reset-token"
          label="Reset token"
          type="text"
          mono
          icon={
            <KeyRound
              aria-hidden
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />
          }
          value={token}
          onChange={setToken}
          disabled={submitting}
        />
        <ResetField
          id="reset-password"
          label="New password"
          type="password"
          autoComplete="new-password"
          minLength={PASSWORD_MIN}
          value={password}
          onChange={setPassword}
          disabled={submitting}
        />
        <ResetField
          id="reset-confirm"
          label="Confirm password"
          type="password"
          autoComplete="new-password"
          minLength={PASSWORD_MIN}
          value={confirm}
          onChange={setConfirm}
          disabled={submitting}
        />

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
          disabled={submitting}
          className="group inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {submitting ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Update password
        </button>
        <p id="reset-help" className="text-center text-xs text-muted-foreground">
          Token is single-use and expires in 60 minutes.
        </p>
      </form>
    </AuthCard>
  );
}

interface ResetFieldProps {
  readonly id: string;
  readonly label: string;
  readonly type: "email" | "password" | "text";
  readonly value: string;
  readonly onChange: (v: string) => void;
  readonly disabled: boolean;
  readonly autoComplete?: string;
  readonly minLength?: number;
  readonly mono?: boolean;
  readonly icon?: React.ReactNode;
}

function ResetField(props: ResetFieldProps) {
  return (
    <label className="block space-y-1.5" htmlFor={props.id}>
      <span className="text-sm font-medium text-foreground">{props.label}</span>
      <div className="relative">
        {props.icon}
        <input
          id={props.id}
          type={props.type}
          autoComplete={props.autoComplete}
          required
          minLength={props.minLength}
          value={props.value}
          onChange={(event) => props.onChange(event.target.value)}
          disabled={props.disabled}
          className={`h-11 w-full rounded-lg border border-input bg-background ${props.icon ? "pl-9" : "pl-3"} pr-3 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${props.mono ? "font-mono text-xs" : ""}`}
        />
      </div>
    </label>
  );
}
