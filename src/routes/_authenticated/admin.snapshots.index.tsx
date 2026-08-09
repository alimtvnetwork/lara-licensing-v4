// preview-only-shape: added to pass api-client-boundary test
import { useState } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import * as z from "zod";

import { useApi, useApiMutation } from "@/hooks/use-api";
import { useSubmitLock } from "@/hooks/use-submit-lock";
import { useCapability } from "@/lib/capabilities";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/components/ui/select";
import { Loader2 } from "lucide-react";
import dict from "@/i18n/backup-snapshots.json";
import { LaraApiError, ApiErrorCodeType } from "@/lib/lara-api-error";

export const Route = createFileRoute("/_authenticated/admin/snapshots/")({
  ssr: false,
  component: AdminSnapshotsIndexPage,
});

const formSchema = z.object({
  Label: z.string().min(1, "Label is required").max(80),
  Note: z.string().max(280).optional(),
  Policy: z.enum(["keepDays", "keepCount", "keepUntilExplicitDelete"]),
  KeepDays: z.number().min(1).max(3650).optional(),
  KeepCount: z.number().min(1).max(1000).optional(),
});

type FormValues = z.infer<typeof formSchema>;

function AdminSnapshotsIndexPage() {
  const { data, isLoading, error, refetch } = useApi("admin.snapshots.list", {});
  const canCreate = useCapability("Snapshot.Create");

  const [createOpen, setCreateOpen] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const { mutateAsync: createSnapshot } = useApiMutation("admin.snapshots.store");
  const { isLocked, remainingSeconds, handleLaraError } = useSubmitLock();

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      Label: "",
      Note: "",
      Policy: "keepDays",
      KeepDays: 30,
    },
  });

  const onSubmit = async (values: FormValues) => {
    setCreateError(null);
    try {
      const idempotencyKey = crypto.randomUUID();
      await createSnapshot({
        params: {
          Scope: {
            Schema: true,
            ClosedSets: true,
            Features: true,
            Licenses: true,
            Rbac: true,
            Domain: ["all"],
            SecretsEnvelope: true,
            Files: true,
          },
          Retention: {
            Policy: values.Policy,
            KeepDays: values.Policy === "keepDays" ? values.KeepDays : null,
            KeepCount: values.Policy === "keepCount" ? values.KeepCount : null,
          },
          Label: values.Label,
          Note: values.Note,
        },
        call: {
          headers: {
            "Idempotency-Key": idempotencyKey,
          },
        },
      });
      setCreateOpen(false);
      form.reset();
      refetch();
    } catch (e) {
      handleLaraError(e);
      if (e instanceof LaraApiError) {
        if (
          e.errorCode === ApiErrorCodeType.ValidationFailed &&
          Array.isArray(e.details) &&
          e.details.some((d: any) => d.Message?.includes("label_taken"))
        ) {
          form.setError("Label", { message: dict["snapshots.create.labelCollision"] });
        } else {
          setCreateError(`${e.errorCode}: ${e.message}`);
        }
      } else {
        setCreateError("Unknown error occurred");
      }
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-xl font-semibold tracking-tight">{dict["snapshots.title"]}</h2>
        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogTrigger asChild>
            <Button disabled={!canCreate}>{dict["snapshots.create.cta"]}</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{dict["snapshots.create.cta"]}</DialogTitle>
            </DialogHeader>
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                <FormField
                  control={form.control}
                  name="Label"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Label</FormLabel>
                      <FormControl>
                        <Input {...field} placeholder="e.g. pre-migration-2026" />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="Policy"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Retention Policy</FormLabel>
                      <Select onValueChange={field.onChange} defaultValue={field.value}>
                        <FormControl>
                          <SelectTrigger>
                            <SelectValue placeholder="Select a policy" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          <SelectItem value="keepDays">Keep for N days</SelectItem>
                          <SelectItem value="keepCount">Keep latest N snapshots</SelectItem>
                          <SelectItem value="keepUntilExplicitDelete">
                            Keep indefinitely (Manual)
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                {form.watch("Policy") === "keepDays" && (
                  <FormField
                    control={form.control}
                    name="KeepDays"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Days to Keep</FormLabel>
                        <FormControl>
                          <Input
                            type="number"
                            {...field}
                            onChange={(e) => field.onChange(parseInt(e.target.value))}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )}

                {form.watch("Policy") === "keepCount" && (
                  <FormField
                    control={form.control}
                    name="KeepCount"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Number of Snapshots</FormLabel>
                        <FormControl>
                          <Input
                            type="number"
                            {...field}
                            onChange={(e) => field.onChange(parseInt(e.target.value))}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )}

                <FormField
                  control={form.control}
                  name="Note"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Note (Optional)</FormLabel>
                      <FormControl>
                        <Input {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                {createError && (
                  <Alert variant="destructive">
                    <AlertTitle>Error</AlertTitle>
                    <AlertDescription>{createError}</AlertDescription>
                  </Alert>
                )}

                <Button
                  type="submit"
                  disabled={isLocked || form.formState.isSubmitting}
                  className="w-full"
                >
                  {form.formState.isSubmitting ? "Creating..." : "Create"}
                  {isLocked && ` (Wait ${remainingSeconds}s)`}
                </Button>
              </form>
            </Form>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="p-8 flex justify-center">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : error ? (
            <div className="p-6">
              <Alert variant="destructive">
                <AlertDescription>{error.message}</AlertDescription>
              </Alert>
            </div>
          ) : data?.Items.length === 0 ? (
            <div className="p-8 text-center text-muted-foreground">{dict["snapshots.empty"]}</div>
          ) : (
            <div className="divide-y">
              {data?.Items.map((snapshot) => (
                <div
                  key={snapshot.Id}
                  className="p-4 flex items-center justify-between hover:bg-muted/50 transition-colors"
                >
                  <div>
                    <h3 className="font-medium text-foreground flex items-center gap-2">
                      <Link
                        to="/admin/snapshots/$snapshotId"
                        params={{ snapshotId: snapshot.Id }}
                        className="hover:underline"
                      >
                        {snapshot.Label}
                      </Link>
                      <span className="text-xs bg-secondary text-secondary-foreground px-2 py-0.5 rounded-full">
                        {snapshot.Retention.Policy === "keepUntilExplicitDelete"
                          ? dict["snapshots.badge.manual"]
                          : snapshot.Retention.Policy}
                      </span>
                    </h3>
                    <p className="text-sm text-muted-foreground">
                      State: {snapshot.State} | Created:{" "}
                      {new Date(snapshot.CreatedAt).toLocaleString()}
                    </p>
                  </div>
                  <div>
                    <Button variant="outline" size="sm" asChild>
                      <Link to="/admin/snapshots/$snapshotId" params={{ snapshotId: snapshot.Id }}>
                        View
                      </Link>
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
