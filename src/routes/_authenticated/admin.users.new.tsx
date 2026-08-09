// v0.326.0. Admin "Create user" panel.
//
// Root cause this addresses: `Admin\UserController::store` has been live
// since Plan 06 step 34 but no UI path invokes it, so operators cannot
// seat additional Admin/Reseller/AppBuilder accounts after a fresh
// cPanel deploy without shell access. This route closes that gap by
// binding a typed form to `POST /Api/Admin/Users` via
// `src/lib/lara-user-create.ts` and navigating to the freshly-created
// user's detail route on success.
//
// Error routing follows spec 24 §23.2.6:
//   - `ValidationFailed` (inline surface) -> inline banner + field hint.
//   - `UserConflict` (toast-eligible) -> `useAppToast().fromApiError`.
//   - Any other `LaraApiError` -> banner with `formatLaraApiError`.
// No try/catch fallback values; errors are surfaced with full context
// (RequestId, error code) so support can trace them per Plan 09 §20.

import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { createFileRoute, useNavigate } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { useAppToast } from "../../hooks/use-app-toast";
import { ApiErrorCodeType, LaraApiError, formatLaraApiError } from "../../lib/lara-api-error";
import { createUser } from "../../lib/lara-user-create";
import { userRolesQueryOptions } from "../../lib/lara-user-role";

export const Route = createFileRoute("/_authenticated/admin/users/new")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Create user | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: NewUserPage,
});

interface FormState {
  Email: string;
  Password: string;
  TenantId: string;
  IsActive: boolean;
}

const EMPTY: FormState = { Email: "", Password: "", TenantId: "", IsActive: true };
const PASSWORD_MIN = 12;

function NewUserPage() {
  const navigate = useNavigate();
  const toast = useAppToast();
  const queryClient = useQueryClient();
  const [form, setForm] = React.useState<FormState>(EMPTY);
  const [bannerError, setBannerError] = React.useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: createUser,
    onSuccess: async (user) => {
      setBannerError(null);
      toast.success("User created", { description: user.Email });
      await queryClient.invalidateQueries({ queryKey: userRolesQueryOptions.queryKey });
      navigate({
        to: "/admin/users/$userId",
        params: { userId: user.UserId },
      });
    },
    onError: (err) => {
      if (err instanceof LaraApiError && err.errorCode === ApiErrorCodeType.UserConflict) {
        toast.fromApiError(err, "Email already registered");
        setBannerError(null);

        return;
      }
      setBannerError(formatLaraApiError(err));
    },
  });

  const tenantParsed = parseTenantId(form.TenantId);
  const clientInvalid =
    form.Email.trim() === "" || form.Password.length < PASSWORD_MIN || tenantParsed === "invalid";

  return (
    <>
      <PageHeader
        title="Create user"
        description="Seat a new operator account. The invitee will sign in with the password you set here and can rotate it from the profile menu."
        breadcrumbs={[
          { label: "Users", to: "/admin/users" },
          { label: "New", to: "/admin/users/new" },
        ]}
      />
      <form
        className="mt-8 max-w-xl space-y-6"
        onSubmit={(event) => {
          event.preventDefault();
          if (mutation.isPending) return;
          if (clientInvalid) {
            setBannerError("Fix the highlighted fields before submitting.");

            return;
          }
          setBannerError(null);
          mutation.mutate({
            Email: form.Email.trim(),
            Password: form.Password,
            TenantId: tenantParsed === "empty" ? null : tenantParsed,
            IsActive: form.IsActive,
          });
        }}
      >
        {bannerError ? (
          <div
            role="alert"
            className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
          >
            {bannerError}
          </div>
        ) : null}

        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            autoComplete="off"
            required
            value={form.Email}
            onChange={(event) => setForm((prev) => ({ ...prev, Email: event.target.value }))}
          />
          <p className="text-xs text-muted-foreground">
            Must be unique across the Licensing Portal Root DB.
          </p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="password">Password</Label>
          <Input
            id="password"
            type="password"
            autoComplete="new-password"
            required
            minLength={PASSWORD_MIN}
            value={form.Password}
            onChange={(event) => setForm((prev) => ({ ...prev, Password: event.target.value }))}
          />
          <p className="text-xs text-muted-foreground">
            At least {PASSWORD_MIN} characters. Backend enforces {PASSWORD_MIN}-128 in
            <code className="ml-1 rounded bg-muted px-1 py-0.5 font-mono text-[0.7rem]">
              validateCreate()
            </code>
            .
          </p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="tenant">
            Tenant ID <span className="text-muted-foreground">(optional)</span>
          </Label>
          <Input
            id="tenant"
            type="text"
            inputMode="numeric"
            pattern="[0-9]*"
            value={form.TenantId}
            onChange={(event) => setForm((prev) => ({ ...prev, TenantId: event.target.value }))}
            aria-invalid={tenantParsed === "invalid"}
          />
          <p className="text-xs text-muted-foreground">
            Leave blank for platform-level operators (Admin, AppBuilder). Set to a valid reseller
            tenant only for Reseller/EndUser accounts.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <input
            id="active"
            type="checkbox"
            checked={form.IsActive}
            onChange={(event) => setForm((prev) => ({ ...prev, IsActive: event.target.checked }))}
            className="focus-ring size-4 rounded border-input"
          />
          <Label htmlFor="active" className="font-normal">
            Active on creation
          </Label>
        </div>

        <div className="flex items-center gap-3 pt-2">
          <Button type="submit" disabled={mutation.isPending || clientInvalid}>
            {mutation.isPending ? "Creating…" : "Create user"}
          </Button>
          <Button
            type="button"
            variant="ghost"
            onClick={() => navigate({ to: "/admin/users" })}
            disabled={mutation.isPending}
          >
            Cancel
          </Button>
        </div>
      </form>
    </>
  );
}

function parseTenantId(raw: string): number | "empty" | "invalid" {
  const trimmed = raw.trim();
  if (trimmed === "") return "empty";
  if (!/^\d+$/.test(trimmed)) return "invalid";
  const parsed = Number.parseInt(trimmed, 10);
  if (!Number.isFinite(parsed) || parsed <= 0) return "invalid";

  return parsed;
}
