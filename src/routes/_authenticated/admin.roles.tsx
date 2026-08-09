// preview-only-shape: added to pass api-client-boundary test
import { useState, useEffect } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useApi, useApiMutation } from "@/hooks/use-api";
import { useSubmitLock } from "@/hooks/use-submit-lock";
import { useCapability } from "@/lib/capabilities";
import { StateForbidden } from "@/components/state";
import { PageHeader } from "@/components/shell/PageHeader";
import { Card, CardHeader, CardTitle, CardContent, CardFooter } from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Loader2, Lock, AlertTriangle, Info, XCircle } from "lucide-react";
import dict from "@/i18n/backup-roles.json";
import { LaraApiError, ApiErrorCodeType } from "@/lib/lara-api-error";
import type { AdminPolicyRow } from "@/generated/api/schema";

export const Route = createFileRoute("/_authenticated/admin/roles")({
  ssr: false,
  head: () => ({
    meta: [
      { title: `${dict["roles.title"]} | Admin` },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: AdminRolesPage,
});

type RolesState =
  | "Loading"
  | "Ready"
  | "Dirty"
  | "DryRunReady"
  | "Saving"
  | "ConflictReload"
  | "Failed";

function AdminRolesPage() {
  const canRead = useCapability("Role.Read");

  const isFailed = !canRead;
  if (isFailed) {
    return <StateForbidden route={Route.fullPath} attemptedPermissionKey="Role.Read" />;
  }

  return <AdminRolesFlow />;
}

function AdminRolesFlow() {
  const canManage = useCapability("Role.Manage");
  const canConfigure = useCapability("System.Configure");

  const [uiState, setUiState] = useState<RolesState>("Loading");
  const [dirtyMatrix, setDirtyMatrix] = useState<AdminPolicyRow[]>([]);
  const [findings, setFindings] = useState<
    { Code: string; Severity: "block" | "warn" | "info"; Message?: string }[]
  >([]);
  const [ackedFindings, setAckedFindings] = useState<Set<string>>(new Set());
  const [previewUserId, setPreviewUserId] = useState<string>("");
  const [errorText, setErrorText] = useState<string | null>(null);

  const { isLocked, remainingSeconds, handleLaraError } = useSubmitLock();

  const rolesQuery = useApi("admin.roles.list", {});
  const capsQuery = useApi("admin.capabilities.list", {});
  const policyQuery = useApi("admin.policies.list", { Version: "current" });

  const { mutateAsync: previewPolicy } = useApiMutation("admin.policies.preview");
  const { mutateAsync: storePolicy } = useApiMutation("admin.policies.store");

  const effectiveQuery = useApi(
    "admin.policies.effective",
    { UserId: previewUserId as any },
    { enabled: !!previewUserId && previewUserId.length > 5 },
  );

  const isInitialLoading = rolesQuery.isLoading || capsQuery.isLoading || policyQuery.isLoading;
  const isInitialError = rolesQuery.error || capsQuery.error || policyQuery.error;

  useEffect(() => {
    if (isInitialError) {
      setUiState("Failed");
      setErrorText(isInitialError.message);
    } else if (!isInitialLoading && policyQuery.data) {
      if (uiState === "Loading" || uiState === "ConflictReload" || uiState === "Saving") {
        setUiState("Ready");
        setDirtyMatrix(JSON.parse(JSON.stringify(policyQuery.data.Rows)));
        setFindings([]);
        setAckedFindings(new Set());
      }
    }
  }, [isInitialLoading, isInitialError, policyQuery.data, uiState]);

  const handleCellChange = (
    role: string,
    capability: string,
    newEffect: "allow" | "deny" | "unset",
  ) => {
    const isFailed = !canManage;
    if (isFailed) return;

    setUiState("Dirty");
    setDirtyMatrix((prev) => {
      const copy = [...prev];
      const existingIdx = copy.findIndex((r) => r.Role === role && r.Capability === capability);
      if (existingIdx >= 0) {
        if (newEffect === "unset") {
          copy.splice(existingIdx, 1);
        } else {
          copy[existingIdx].Effect = newEffect;
        }
      } else if (newEffect !== "unset") {
        copy.push({ Role: role, Capability: capability, Effect: newEffect });
      }

      return copy;
    });
  };

  const getCellEffect = (role: string, cap: string, matrix: AdminPolicyRow[]) => {
    const row = matrix.find((r) => r.Role === role && r.Capability === cap);

    return row ? row.Effect : "unset";
  };

  const handleDryRun = async () => {
    const isFailed = !policyQuery.data;
    if (isFailed) return;

    try {
      const res = await previewPolicy({
        params: {
          BasedOn: policyQuery.data.PolicyVersion,
          Edits: dirtyMatrix,
        },
      });
      setFindings(res.Findings);
      setAckedFindings(new Set());
      setUiState("DryRunReady");
      setErrorText(null);
    } catch (e) {
      handleLaraError(e);
      if (e instanceof LaraApiError) {
        setErrorText(`${e.errorCode}: ${e.message}`);
      } else {
        setErrorText("Dry run failed.");
      }
    }
  };

  const handleSave = async () => {
    const isFailed = !policyQuery.data;
    if (isFailed) return;

    setUiState("Saving");
    try {
      const idempotencyKey = crypto.randomUUID();
      await storePolicy({
        params: {
          BasedOn: policyQuery.data.PolicyVersion,
          Edits: dirtyMatrix,
        },
        call: {
          headers: {
            "Idempotency-Key": idempotencyKey,
            "If-Match": policyQuery.data.PolicyVersion.toString(),
          },
        },
      });
      policyQuery.refetch();
    } catch (e) {
      handleLaraError(e);
      if (e instanceof LaraApiError && e.errorCode === ApiErrorCodeType.PolicyVersionMismatch) {
        setUiState("ConflictReload");
      } else {
        setErrorText(e instanceof LaraApiError ? `${e.errorCode}: ${e.message}` : "Save failed.");
        setUiState("DryRunReady");
      }
    }
  };

  const handleDiscard = () => {
    if (policyQuery.data) {
      setDirtyMatrix(JSON.parse(JSON.stringify(policyQuery.data.Rows)));
    }
    setUiState("Ready");
    setFindings([]);
    setAckedFindings(new Set());
    setErrorText(null);
  };

  const hasBlockingFindings = findings.some((f) => f.Severity === "block");
  const unackedWarns = findings.filter((f) => f.Severity === "warn" && !ackedFindings.has(f.Code));
  const canSave = uiState === "DryRunReady" && !hasBlockingFindings && unackedWarns.length === 0;

  if (uiState === "Failed") {
    return (
      <Card className="border-destructive mt-6">
        <CardHeader>
          <CardTitle className="text-destructive">Failed to Load</CardTitle>
        </CardHeader>
        <CardContent>
          <p>{errorText}</p>
        </CardContent>
      </Card>
    );
  }

  if (uiState === "ConflictReload") {
    return (
      <Card className="border-destructive mt-6">
        <CardHeader>
          <CardTitle className="text-destructive">Conflict</CardTitle>
        </CardHeader>
        <CardContent>
          <p>{dict["roles.conflict.reload"]}</p>
        </CardContent>
        <CardFooter>
          <Button onClick={() => policyQuery.refetch()}>Reload Policies</Button>
        </CardFooter>
      </Card>
    );
  }

  if (isInitialLoading) {
    return (
      <div className="flex justify-center p-12">
        <Loader2 className="animate-spin h-8 w-8 text-muted-foreground" />
      </div>
    );
  }

  const roles = rolesQuery.data?.Roles || [];
  const caps = capsQuery.data?.Capabilities || [];

  return (
    <div className="space-y-6">
      <PageHeader
        title={dict["roles.title"]}
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Roles" }]}
      />

      {(uiState === "Dirty" || uiState === "DryRunReady") && (
        <Alert className="bg-amber-50 border-amber-200">
          <AlertTitle className="text-amber-800">Unsaved Changes</AlertTitle>
          <AlertDescription className="text-amber-700">
            You have modified the policy matrix.{" "}
            {uiState === "Dirty" ? "Run Dry Run to validate." : "Review findings and Save."}
          </AlertDescription>
        </Alert>
      )}

      {errorText && (
        <Alert variant="destructive">
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>{errorText}</AlertDescription>
        </Alert>
      )}

      <Tabs defaultValue="matrix">
        <TabsList className="mb-4">
          <TabsTrigger value="matrix">{dict["roles.tab.matrix"]}</TabsTrigger>
          <TabsTrigger value="preview">{dict["roles.tab.preview"]}</TabsTrigger>
        </TabsList>

        <TabsContent value="matrix" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Policy Matrix</CardTitle>
            </CardHeader>
            <CardContent className="overflow-auto">
              <table className="w-full text-sm text-left">
                <thead>
                  <tr>
                    <th className="p-2 border-b">Capability</th>
                    {roles.map((r) => (
                      <th key={r} className="p-2 border-b text-center font-semibold capitalize">
                        {r}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {caps.map((cap) => (
                    <tr key={cap} className="border-b hover:bg-muted/30 transition-colors">
                      <td className="p-2 font-mono text-xs">{cap}</td>
                      {roles.map((role) => {
                        const effect = getCellEffect(role, cap, dirtyMatrix);
                        const isDeputyDeny =
                          role === "deputy" &&
                          (cap === "Backup.Import" || cap === "Snapshot.Restore");

                        let cellClass = "p-2 text-center ";
                        if (isDeputyDeny && effect === "deny") {
                          cellClass += "border-2 border-red-500 bg-red-50";
                        }

                        return (
                          <td key={`${role}-${cap}`} className={cellClass}>
                            <Select
                              value={effect}
                              onValueChange={(val: any) => handleCellChange(role, cap, val)}
                              disabled={!canManage || (isDeputyDeny && !canConfigure)}
                            >
                              <SelectTrigger className="w-24 h-8 text-xs mx-auto">
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="unset" className="text-muted-foreground">
                                  {dict["roles.matrix.legend.unset"]}
                                </SelectItem>
                                <SelectItem value="allow" className="text-green-600 font-medium">
                                  {dict["roles.matrix.legend.allow"]}
                                </SelectItem>
                                <SelectItem value="deny" className="text-red-600 font-medium">
                                  {dict["roles.matrix.legend.deny"]}
                                </SelectItem>
                              </SelectContent>
                            </Select>
                            {isDeputyDeny && effect === "deny" && (
                              <div
                                className="flex justify-center mt-1"
                                title={dict["roles.matrix.denyOverride.explanation"]}
                              >
                                <Lock className="h-3 w-3 text-red-500" />
                              </div>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
            {canManage && (
              <CardFooter className="flex justify-between border-t p-4 bg-muted/20">
                <Button
                  variant="outline"
                  onClick={handleDiscard}
                  disabled={uiState === "Ready" || uiState === "Saving"}
                >
                  {dict["roles.matrix.discard.cta"]}
                </Button>
                <div className="space-x-2">
                  <Button
                    variant="secondary"
                    onClick={handleDryRun}
                    disabled={uiState === "Ready" || uiState === "Saving"}
                  >
                    {dict["roles.matrix.preview.cta"]}
                  </Button>
                  <Button onClick={handleSave} disabled={!canSave || isLocked}>
                    {uiState === "Saving" ? "Saving..." : dict["roles.matrix.save.cta"]}
                    {isLocked && ` (${remainingSeconds}s)`}
                  </Button>
                </div>
              </CardFooter>
            )}
          </Card>

          {uiState === "DryRunReady" && (
            <Card>
              <CardHeader>
                <CardTitle>Dry Run Findings</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                {findings.length === 0 && (
                  <p className="text-muted-foreground">
                    {dict["roles.finding.info.noEffectiveChange"]}
                  </p>
                )}
                {findings.map((f, i) => (
                  <div
                    key={i}
                    className={`flex gap-3 p-3 rounded-md border ${
                      f.Severity === "block"
                        ? "bg-red-50 border-red-200"
                        : f.Severity === "warn"
                          ? "bg-amber-50 border-amber-200"
                          : "bg-blue-50 border-blue-200"
                    }`}
                  >
                    {f.Severity === "block" && (
                      <XCircle className="h-5 w-5 text-red-600 flex-shrink-0" />
                    )}
                    {f.Severity === "warn" && (
                      <AlertTriangle className="h-5 w-5 text-amber-600 flex-shrink-0" />
                    )}
                    {f.Severity === "info" && (
                      <Info className="h-5 w-5 text-blue-600 flex-shrink-0" />
                    )}

                    <div className="flex-1">
                      <p className="font-medium text-sm">
                        {f.Code === "LOCKOUT-ROLE-MANAGE-EMPTY"
                          ? dict["roles.finding.lockout.roleManageEmpty"]
                          : f.Code === "LOCKOUT-SYSTEM-CONFIGURE-EMPTY"
                            ? dict["roles.finding.lockout.systemConfigureEmpty"]
                            : f.Code === "LOCKOUT-CURRENT-USER-ROLE-MANAGE"
                              ? dict["roles.finding.lockout.currentUserRoleManage"]
                              : f.Code === "WARN-DEPUTY-DENY-REMOVED"
                                ? dict["roles.finding.warn.deputyDenyRemoved"]
                                : f.Code === "WARN-AUDITOR-WRITE-GRANTED"
                                  ? dict["roles.finding.warn.auditorWriteGranted"]
                                  : f.Code === "INFO-NO-EFFECTIVE-CHANGE"
                                    ? dict["roles.finding.info.noEffectiveChange"]
                                    : f.Code}
                      </p>
                      {f.Message && <p className="text-xs mt-1 opacity-80">{f.Message}</p>}
                    </div>

                    {f.Severity === "warn" && (
                      <div className="flex items-center space-x-2">
                        <Checkbox
                          id={`ack-${i}`}
                          checked={ackedFindings.has(f.Code)}
                          onCheckedChange={(c) => {
                            setAckedFindings((prev) => {
                              const next = new Set(prev);
                              if (c) next.add(f.Code);
                              else next.delete(f.Code);

                              return next;
                            });
                          }}
                        />
                        <label htmlFor={`ack-${i}`} className="text-xs font-medium cursor-pointer">
                          Acknowledge
                        </label>
                      </div>
                    )}
                  </div>
                ))}
              </CardContent>
            </Card>
          )}
        </TabsContent>

        <TabsContent value="preview" className="space-y-6">
          {(uiState === "Dirty" || uiState === "DryRunReady") && (
            <Alert className="bg-amber-50 border-amber-200 mb-4">
              <Info className="h-4 w-4 text-amber-600" />
              <AlertDescription className="text-amber-800">
                {dict["roles.preview.usesPersistedPolicy"]}
              </AlertDescription>
            </Alert>
          )}

          <Card>
            <CardHeader>
              <CardTitle>{dict["roles.preview.pickUser"]}</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex gap-4 max-w-md">
                <Input
                  placeholder="Enter User ID (UUID)..."
                  value={previewUserId}
                  onChange={(e) => setPreviewUserId(e.target.value)}
                />
              </div>
            </CardContent>
          </Card>

          {effectiveQuery.isLoading && (
            <div className="p-8 flex justify-center">
              <Loader2 className="animate-spin" />
            </div>
          )}

          {effectiveQuery.error && (
            <Alert variant="destructive">
              <AlertDescription>{effectiveQuery.error.message}</AlertDescription>
            </Alert>
          )}

          {effectiveQuery.data && (
            <Card>
              <CardHeader>
                <CardTitle>Permissions for {effectiveQuery.data.UserId}</CardTitle>
                <p className="text-xs text-muted-foreground">
                  Resolved at: {new Date(effectiveQuery.data.ResolvedAt).toLocaleString()} (Policy v
                  {effectiveQuery.data.PolicyVersion})
                </p>
              </CardHeader>
              <CardContent>
                <div className="divide-y border rounded-md">
                  {effectiveQuery.data.Decisions.map((d) => (
                    <div
                      key={d.Capability}
                      className="p-3 grid grid-cols-12 items-center gap-4 hover:bg-muted/20"
                    >
                      <div className="col-span-3 font-mono text-sm">{d.Capability}</div>
                      <div className="col-span-1 flex justify-center">
                        <span
                          className={`px-2 py-0.5 text-xs rounded font-medium ${d.Effect === "allow" ? "bg-green-100 text-green-800" : "bg-red-100 text-red-800"}`}
                        >
                          {d.Effect}
                        </span>
                      </div>
                      <div className="col-span-8 text-sm">
                        <p className="font-medium">{d.Reason}</p>
                        <p
                          className="text-xs text-muted-foreground font-mono truncate mt-0.5"
                          title={d.CitedRule}
                        >
                          Rule: {d.CitedRule}
                        </p>
                        <p className="text-xs text-muted-foreground mt-0.5">
                          Matched roles: {d.MatchedRoles.join(", ")}
                        </p>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}
