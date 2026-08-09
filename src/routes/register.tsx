import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useMemo, useRef, useState, type FormEvent } from "react";
import { ArrowRight, Eye, EyeOff, Loader2, Lock, Mail, ShieldCheck, Sparkles } from "lucide-react";

import { AuthCard, type AuthCardTrustPoint } from "../components/auth/AuthCard";
import { ApiErrorCodeType, LaraApiError, formatLaraApiError } from "../lib/lara-api-error";
import { registerViaLara } from "../lib/lara-auth";

/**
 * v0.300.0. Bootstrap-only SuperAdmin registration surface.
 * v0.301.0 refit: chrome delegated to shared `AuthCard`.
 *
 * Root cause this file exists: `POST /Api/Auth/Register` has been live in
 * the backend since the first-user-bootstrap turn but no frontend route
 * consumed it, leaving fresh installs unreachable without curl. On 201
 * we store the returned Sanctum PAT and redirect to /admin. When the
 * bootstrap window has already closed the API returns
 * `AuthRegistrationClosed` (403); we render an inline notice with a
 * direct link to /admin/login.
 */
export const Route = createFileRoute("/register")({
  head: () => ({
    meta: [
      { title: "Create workspace | Licensing Portal" },
      {
        name: "description",
        content:
          "Bootstrap your Licensing Portal workspace. The first registered account becomes the SuperAdmin; every subsequent account is provisioned from the console.",
      },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: RegisterPage,
});

const PASSWORD_MIN = 12;
const PASSWORD_MAX = 128;
const EMAIL_MAX = 254;

const TRUST_POINTS: ReadonlyArray<AuthCardTrustPoint> = [
  {
    icon: ShieldCheck,
    title: "First account is SuperAdmin",
    body: "Registration is only open until the first Root user exists; the API rejects further sign-ups.",
  },
  {
    icon: Lock,
    title: "12 char password minimum",
    body: "Matches the Admin/user-provisioning rule; bcrypt at rest, never logged in cleartext.",
  },
  {
    icon: Sparkles,
    title: "Session-scoped bearer",
    body: "Register opens a Normal AuthSession and returns a Sanctum PAT bound to it.",
  },
];

function RegisterPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [capsLockOn, setCapsLockOn] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | undefined>(undefined);
  const [registrationClosed, setRegistrationClosed] = useState(false);
  const passwordRef = useRef<HTMLInputElement | null>(null);

  const disabled = submitting || registrationClosed;
  const passwordHint = useMemo(
    () => `Minimum ${PASSWORD_MIN} characters. Store it in a password manager.`,
    [],
  );

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (disabled) return;
    if (password.length < PASSWORD_MIN) {
      setErrorMessage(`Password must be at least ${PASSWORD_MIN} characters.`);
      passwordRef.current?.focus();

      return;
    }
    setSubmitting(true);
    setErrorMessage(undefined);
    try {
      await registerViaLara({ Email: email.trim(), Password: password });
      await navigate({ to: "/admin", replace: true });
    } catch (error) {
      pushLaraApiError(new Error());
      if (
        error instanceof LaraApiError &&
        error.errorCode === ApiErrorCodeType.AuthRegistrationClosed
      ) {
        setRegistrationClosed(true);
        setErrorMessage(
          "Registration is closed for this workspace. Ask a SuperAdmin to create your account, then sign in.",
        );

        return;
      }
      setErrorMessage(formatLaraApiError(error));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard
      title="Create your SuperAdmin"
      description="The first registered user owns this workspace. All later accounts are provisioned from the console."
      asideHeadline="Bootstrap your workspace in under a minute."
      asideBody="The first account you create becomes the SuperAdmin. From there, provision Resellers, issue licenses, and publish signed self-update builds."
      asideTrustPoints={TRUST_POINTS}
      asideFooterNote="Audit-logged. Rate-limited. Every action carries a lineage badge."
      footerSlot={
        <>
          Already have an account?{" "}
          <Link
            to="/admin/login"
            className="font-medium text-foreground underline underline-offset-4 hover:underline"
          >
            Sign in
          </Link>
          .
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-5" aria-describedby="register-help">
        <FieldEmail value={email} onChange={setEmail} disabled={disabled} />
        <FieldPassword
          value={password}
          onChange={setPassword}
          disabled={disabled}
          showPassword={showPassword}
          onToggleShow={() => setShowPassword((v) => !v)}
          onCapsLockChange={setCapsLockOn}
          inputRef={passwordRef}
          hint={passwordHint}
        />
        {capsLockOn ? (
          <p className="-mt-3 text-xs font-medium text-warning-foreground" role="status">
            Caps Lock is on.
          </p>
        ) : null}
        {errorMessage ? (
          <div
            role="alert"
            className="rounded-lg border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive"
          >
            {errorMessage}
            {registrationClosed ? (
              <p className="mt-2">
                <Link
                  to="/admin/login"
                  className="font-medium text-foreground underline underline-offset-4"
                >
                  Go to sign in
                </Link>
              </p>
            ) : null}
          </div>
        ) : null}
        <button
          type="submit"
          disabled={disabled}
          className="group inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {submitting ? (
            <>
              <Loader2 aria-hidden className="size-4 animate-spin" />
              Creating workspace...
            </>
          ) : (
            <>
              Create workspace
              <ArrowRight
                aria-hidden
                className="size-4 transition-transform group-hover:translate-x-0.5"
              />
            </>
          )}
        </button>
        <p id="register-help" className="text-center text-xs text-muted-foreground">
          Rate-limited. One SuperAdmin per workspace. Sessions expire automatically.
        </p>
      </form>
    </AuthCard>
  );
}

function FieldEmail({
  value,
  onChange,
  disabled,
}: {
  value: string;
  onChange: (v: string) => void;
  disabled: boolean;
}) {
  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-medium text-foreground">Work email</span>
      <div className="relative">
        <Mail
          aria-hidden
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
          type="email"
          autoComplete="username"
          required
          maxLength={EMAIL_MAX}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          disabled={disabled}
          placeholder="admin@company.com"
          className="h-11 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        />
      </div>
    </label>
  );
}

interface FieldPasswordProps {
  value: string;
  onChange: (v: string) => void;
  disabled: boolean;
  showPassword: boolean;
  onToggleShow: () => void;
  onCapsLockChange: (on: boolean) => void;
  inputRef: React.RefObject<HTMLInputElement | null>;
  hint: string;
}

function FieldPassword(props: FieldPasswordProps) {
  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-medium text-foreground">Password</span>
      <div className="relative">
        <Lock
          aria-hidden
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
          ref={props.inputRef}
          type={props.showPassword ? "text" : "password"}
          autoComplete="new-password"
          required
          minLength={PASSWORD_MIN}
          maxLength={PASSWORD_MAX}
          value={props.value}
          onChange={(event) => props.onChange(event.target.value)}
          onKeyUp={(event) => props.onCapsLockChange(event.getModifierState("CapsLock"))}
          onKeyDown={(event) => props.onCapsLockChange(event.getModifierState("CapsLock"))}
          disabled={props.disabled}
          placeholder={`At least ${PASSWORD_MIN} characters`}
          className="h-11 w-full rounded-lg border border-input bg-background pl-9 pr-10 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        />
        <button
          type="button"
          aria-label={props.showPassword ? "Hide password" : "Show password"}
          onClick={props.onToggleShow}
          disabled={props.disabled}
          className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
        >
          {props.showPassword ? (
            <EyeOff aria-hidden className="size-4" />
          ) : (
            <Eye aria-hidden className="size-4" />
          )}
        </button>
      </div>
      <p className="text-xs text-muted-foreground">{props.hint}</p>
    </label>
  );
}
