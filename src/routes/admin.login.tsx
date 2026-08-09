import { createFileRoute, useNavigate, Link } from "@tanstack/react-router";
import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import {
  ArrowRight,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  Lock,
  Mail,
  RefreshCw,
  ShieldCheck,
  Sparkles,
} from "lucide-react";

import { AuthCard, type AuthCardTrustPoint } from "../components/auth/AuthCard";
import { DemoLoginPanel } from "../components/auth/DemoLoginPanel";
import { ApiErrorCodeType, LaraApiError, formatLaraApiError } from "../lib/lara-api-error";
import { fetchLoginCaptcha, loginToLaraApi, type LaraCaptchaChallenge } from "../lib/lara-auth";
import { getRuntimeMode } from "../lib/runtime-mode";
import { useHydrated } from "../hooks/use-hydrated";

export const Route = createFileRoute("/admin/login")({
  head: () => ({
    meta: [
      { title: "Admin Sign In | Licensing Portal" },
      {
        name: "description",
        content:
          "Sign in to the Licensing Portal console. Session-scoped tokens, audit-logged, protected by rate-limits and CAPTCHA.",
      },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: AdminLoginPage,
});

const REMEMBER_ME_STORAGE_KEY = "lara.login.remember-me";
const REMEMBERED_EMAIL_STORAGE_KEY = "lara.login.remembered-email";

const TRUST_POINTS: ReadonlyArray<AuthCardTrustPoint> = [
  {
    icon: ShieldCheck,
    title: "Session-scoped tokens",
    body: "Every sign-in opens a Root AuthSession row; logout revokes the bearer at the source.",
  },
  {
    icon: KeyRound,
    title: "Rate-limited + CAPTCHA",
    body: "Repeated failures trip a stateless HMAC challenge before any hint reaches the client.",
  },
  {
    icon: Sparkles,
    title: "Audit-logged actions",
    body: "Destructive changes carry lineage badges and land in the Admin AuditLogs viewer.",
  },
];

function AdminLoginPage() {
  const navigate = useNavigate();

  const [email, setEmail] = useState<string>("");
  const [password, setPassword] = useState("");
  const [rememberMe, setRememberMe] = useState<boolean>(false);
  const [showPassword, setShowPassword] = useState(false);
  const [capsLockOn, setCapsLockOn] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | undefined>(undefined);
  const [captcha, setCaptcha] = useState<LaraCaptchaChallenge | undefined>(undefined);
  const [captchaAnswer, setCaptchaAnswer] = useState("");
  const [captchaLoading, setCaptchaLoading] = useState(false);
  const hydrated = useHydrated();
  const isSeedMode = hydrated && getRuntimeMode().Mode === "preview";
  const passwordRef = useRef<HTMLInputElement | null>(null);

  // localStorage may only be read after hydration; reading in useState
  // initializers hydration-mismatches under SSR (see mem://~user rule).
  useEffect(() => {
    setEmail(readRememberedEmail());
    setRememberMe(readRememberMeDefault());
  }, []);

  async function refreshCaptcha(reason: "manual" | "required" | "invalid") {
    setCaptchaLoading(true);
    try {
      const next = await fetchLoginCaptcha();
      setCaptcha(next);
      setCaptchaAnswer("");
    } catch (error) {
      pushLaraApiError(new Error());
      setErrorMessage(formatLaraApiError(error));
    } finally {
      setCaptchaLoading(false);
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setErrorMessage(undefined);
    try {
      await loginToLaraApi({
        Email: email,
        Password: password,
        RememberMe: rememberMe,
        CaptchaChallengeId: captcha?.ChallengeId,
        CaptchaAnswer: captcha ? captchaAnswer : undefined,
      });
      persistRememberChoice(rememberMe, email);
      await navigate({ to: "/admin", replace: true });
    } catch (error) {
      pushLaraApiError(new Error());
      if (error instanceof LaraApiError) {
        if (error.errorCode === ApiErrorCodeType.LoginCaptchaRequired) {
          await refreshCaptcha("required");
          setErrorMessage("For your security, please solve the challenge below and try again.");

          return;
        }
        if (error.errorCode === ApiErrorCodeType.LoginCaptchaInvalid) {
          await refreshCaptcha("invalid");
          setErrorMessage("Captcha answer was wrong. A new challenge is ready.");

          return;
        }
      }
      setErrorMessage(formatLaraApiError(error));
    } finally {
      setSubmitting(false);
    }
  }

  const heading = useMemo(
    () => (hydrated && email ? "Welcome back" : "Sign in to Licensing Portal"),
    [hydrated, email],
  );

  return (
    <AuthCard
      title={heading}
      description="Access the Licensing Portal console. Multi-tenant, audit-logged."
      asideHeadline="Licensing, quotas, and self-updates. One console, every reseller."
      asideBody="Session-scoped tokens, rate-limited endpoints, and every destructive action lineage-tagged in the Admin AuditLogs viewer."
      asideTrustPoints={TRUST_POINTS}
      asideFooterNote={`© ${new Date().getFullYear()} Licensing Portal. All systems normal.`}
      footerSlot={
        <>
          Trouble signing in?{" "}
          <a
            href="mailto:admin@licensingportal.local"
            className="font-medium text-foreground underline-offset-4 hover:underline"
          >
            Contact your workspace administrator
          </a>
          .
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-5" aria-describedby="login-help">
        <FieldEmail value={email} onChange={setEmail} disabled={submitting} />
        <FieldPassword
          value={password}
          onChange={setPassword}
          disabled={submitting}
          showPassword={showPassword}
          onToggleShow={() => setShowPassword((v) => !v)}
          onCapsLockChange={setCapsLockOn}
          inputRef={passwordRef}
        />
        {capsLockOn ? (
          <p className="-mt-3 text-xs font-medium text-warning-foreground" role="status">
            Caps Lock is on.
          </p>
        ) : null}
        <div className="flex items-center justify-between">
          <label className="inline-flex cursor-pointer select-none items-center gap-2 text-sm text-foreground">
            <input
              type="checkbox"
              checked={rememberMe}
              onChange={(event) => setRememberMe(event.target.checked)}
              disabled={submitting}
              className="size-4 rounded border-input text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            />
            Remember me for 30 days
          </label>
          <Link
            to="/forgot-password"
            className="text-sm font-medium text-primary underline-offset-4 hover:underline"
          >
            Forgot password?
          </Link>
        </div>
        {captcha ? (
          <CaptchaField
            challenge={captcha}
            answer={captchaAnswer}
            onChange={setCaptchaAnswer}
            onRefresh={() => refreshCaptcha("manual")}
            loading={captchaLoading}
            disabled={submitting}
          />
        ) : null}
        {errorMessage ? (
          <div
            role="alert"
            className="rounded-lg border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive"
          >
            {errorMessage}
          </div>
        ) : null}
        <button
          type="submit"
          disabled={submitting}
          className="group inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {submitting ? (
            <>
              <Loader2 aria-hidden className="size-4 animate-spin" />
              Signing in...
            </>
          ) : (
            <>
              Sign in
              <ArrowRight
                aria-hidden
                className="size-4 transition-transform group-hover:translate-x-0.5"
              />
            </>
          )}
        </button>
        <p id="login-help" className="text-center text-xs text-muted-foreground">
          Protected by rate-limits and HMAC-signed CAPTCHA. Sessions expire automatically.
        </p>
        {isSeedMode && (
          <DemoLoginPanel
            onSuccess={() => navigate({ to: "/admin", replace: true })}
            disabled={submitting}
          />
        )}
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
    <div className="space-y-1.5">
      <label htmlFor="admin-email" className="text-sm font-medium text-foreground">
        Email
      </label>
      <div className="relative">
        <Mail
          aria-hidden
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
          id="admin-email"
          type="email"
          required
          autoComplete="email"
          disabled={disabled}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder="you@company.com"
          className="h-11 w-full rounded-lg border border-input bg-background pl-10 pr-3 text-sm text-foreground placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-60"
        />
      </div>
    </div>
  );
}

function FieldPassword({
  value,
  onChange,
  disabled,
  showPassword,
  onToggleShow,
  onCapsLockChange,
  inputRef,
}: {
  value: string;
  onChange: (v: string) => void;
  disabled: boolean;
  showPassword: boolean;
  onToggleShow: () => void;
  onCapsLockChange: (on: boolean) => void;
  inputRef: React.MutableRefObject<HTMLInputElement | null>;
}) {
  return (
    <div className="space-y-1.5">
      <label htmlFor="admin-password" className="text-sm font-medium text-foreground">
        Password
      </label>
      <div className="relative">
        <Lock
          aria-hidden
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
          ref={inputRef}
          id="admin-password"
          type={showPassword ? "text" : "password"}
          required
          minLength={8}
          maxLength={128}
          autoComplete="current-password"
          disabled={disabled}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          onKeyUp={(event) => onCapsLockChange(event.getModifierState("CapsLock"))}
          onKeyDown={(event) => onCapsLockChange(event.getModifierState("CapsLock"))}
          className="h-11 w-full rounded-lg border border-input bg-background pl-10 pr-11 text-sm text-foreground placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-60"
        />
        <button
          type="button"
          onClick={onToggleShow}
          tabIndex={-1}
          aria-label={showPassword ? "Hide password" : "Show password"}
          aria-pressed={showPassword}
          className="absolute right-2 top-1/2 inline-flex size-8 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          {showPassword ? (
            <EyeOff aria-hidden className="size-4" />
          ) : (
            <Eye aria-hidden className="size-4" />
          )}
        </button>
      </div>
    </div>
  );
}

function CaptchaField({
  challenge,
  answer,
  onChange,
  onRefresh,
  loading,
  disabled,
}: {
  challenge: LaraCaptchaChallenge;
  answer: string;
  onChange: (v: string) => void;
  onRefresh: () => void;
  loading: boolean;
  disabled: boolean;
}) {
  return (
    <div className="space-y-1.5">
      <label htmlFor="admin-captcha" className="text-sm font-medium text-foreground">
        Security check
      </label>
      <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/40 p-3">
        <div className="flex flex-1 items-center gap-3">
          <span
            aria-label="Captcha challenge"
            className="rounded-md bg-background px-3 py-2 font-mono text-base font-semibold tracking-wider text-foreground shadow-inner"
          >
            {challenge.Question} = ?
          </span>
          <input
            id="admin-captcha"
            type="text"
            inputMode="numeric"
            autoComplete="off"
            required
            disabled={disabled}
            value={answer}
            onChange={(event) => onChange(event.target.value)}
            placeholder="Answer"
            className="h-10 w-24 rounded-md border border-input bg-background px-2 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <button
          type="button"
          onClick={onRefresh}
          disabled={loading || disabled}
          aria-label="Refresh challenge"
          className="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted disabled:opacity-60"
        >
          <RefreshCw aria-hidden className={`size-4 ${loading ? "animate-spin" : ""}`} />
        </button>
      </div>
    </div>
  );
}

function readRememberMeDefault(): boolean {
  if (typeof window === "undefined") return false;
  try {
    return window.localStorage.getItem(REMEMBER_ME_STORAGE_KEY) === "true";
  } catch {
    return false;
  }
}

function readRememberedEmail(): string {
  if (typeof window === "undefined") return "";
  try {
    return window.localStorage.getItem(REMEMBERED_EMAIL_STORAGE_KEY) ?? "";
  } catch {
    return "";
  }
}

function persistRememberChoice(remember: boolean, email: string): void {
  if (typeof window === "undefined") return;
  try {
    if (remember) {
      window.localStorage.setItem(REMEMBER_ME_STORAGE_KEY, "true");
      window.localStorage.setItem(REMEMBERED_EMAIL_STORAGE_KEY, email);
    } else {
      window.localStorage.removeItem(REMEMBER_ME_STORAGE_KEY);
      window.localStorage.removeItem(REMEMBERED_EMAIL_STORAGE_KEY);
    }
  } catch {
    // Storage may be blocked (private mode); the login itself still worked.
  }
}
