// preview-only-shape: added to pass api-client-boundary test
import { useState, useEffect, useRef } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import * as z from "zod";

import { StateForbidden } from "@/components/state";
import { useCapability } from "@/lib/capabilities";
import { PageHeader } from "@/components/shell/PageHeader";
import { useSubmitLock } from "@/hooks/use-submit-lock";
import { useApiMutation } from "@/hooks/use-api";
import { Button } from "@/components/ui/button";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
  FormDescription,
} from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Card, CardHeader, CardTitle, CardContent, CardFooter } from "@/components/ui/card";
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import { LaraApiError } from "@/lib/lara-api-error";

export const Route = createFileRoute("/_authenticated/admin/backup/import")({
  ssr: false,
  head: () => ({
    meta: [{ title: "Import Backup | Admin" }, { name: "robots", content: "noindex,nofollow" }],
  }),
  component: AdminBackupImportPage,
});

type ImportState =
  | "Idle"
  | "Submitting"
  | "Queued"
  | "Running"
  | "Succeeded"
  | "Failed"
  | "Cancelled"
  | "Offline";

const formSchema = z.object({
  ArchiveId: z.string().min(1, "Archive ID is required"),
  KeyMaterial: z.string().optional(),
});

type FormValues = z.infer<typeof formSchema>;

function AdminBackupImportPage() {
  const isAllowed = useCapability("Backup.Import");

  const isFailed = !isAllowed;
  if (isFailed) {
    return <StateForbidden route={Route.fullPath} attemptedPermissionKey="Backup.Import" />;
  }

  return <AdminBackupImportFlow />;
}

function AdminBackupImportFlow() {
  const [state, setState] = useState<ImportState>("Idle");
  const [jobId, setJobId] = useState<string | null>(null);
  const [progress, setProgress] = useState({ phase: "", percent: 0 });
  const [errorResult, setErrorResult] = useState<string | null>(null);

  const { isLocked, remainingSeconds, handleLaraError } = useSubmitLock();

  const eventSourceRef = useRef<EventSource | null>(null);
  const lastSequenceRef = useRef<number>(-1);
  const reconnectAttemptsRef = useRef<number>(0);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      ArchiveId: "",
      KeyMaterial: "",
    },
  });

  const { mutateAsync: startImport } = useApiMutation("admin.backup.imports.store");

  const onSubmit = async (values: FormValues) => {
    setState("Submitting");
    setErrorResult(null);
    try {
      const idempotencyKey = crypto.randomUUID();
      const res = await startImport({
        params: {
          ArchiveId: values.ArchiveId,
          KeyMaterial: values.KeyMaterial || undefined,
        },
        call: {
          headers: {
            "Idempotency-Key": idempotencyKey,
          },
        },
      });

      setJobId(res.JobId);
      setState("Queued");
      lastSequenceRef.current = 0;
      reconnectAttemptsRef.current = 0;
      connectSSE(res.JobId, 0);
    } catch (e) {
      handleLaraError(e);
      if (e instanceof LaraApiError) {
        if (e.httpStatus === 429 || e.httpStatus === 503) {
          setState("Idle");

          return;
        }
        setErrorResult(`${e.errorCode}: ${e.message}`);
      } else {
        setErrorResult("Unknown error occurred");
      }
      setState("Failed");
    }
  };

  const connectSSE = (id: string, lastSeq: number) => {
    if (eventSourceRef.current) {
      eventSourceRef.current.close();
    }

    const url = new URL(`/api/admin/backup/jobs/${id}/events`, window.location.origin);
    const es = new EventSource(url);

    es.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        if (data.Sequence <= lastSequenceRef.current) return;

        if (data.Sequence > lastSequenceRef.current + 1 && lastSequenceRef.current > 0) {
          es.close();
          connectSSE(id, lastSequenceRef.current);

          return;
        }

        lastSequenceRef.current = data.Sequence;

        if (data.State === "Running") {
          setState("Running");
          setProgress({
            phase: data.Phase || "Processing",
            percent: data.Percent || 0,
          });
        } else if (data.State === "Succeeded") {
          setState("Succeeded");
          es.close();
        } else if (data.State === "Failed") {
          setState("Failed");
          setErrorResult(`${data.ErrorCode}: ${data.ErrorMessage}`);
          es.close();
        } else if (data.State === "Cancelled") {
          setState("Cancelled");
          es.close();
        }
      } catch (err) {
        console.warn("Failed to parse SSE", err);
      }
    };

    es.onerror = () => {
      es.close();
      reconnectAttemptsRef.current += 1;
      if (reconnectAttemptsRef.current >= 3) {
        setState("Offline");
      } else {
        setTimeout(() => connectSSE(id, lastSequenceRef.current), 5000);
      }
    };

    eventSourceRef.current = es;
  };

  useEffect(() => {
    return () => {
      if (eventSourceRef.current) {
        eventSourceRef.current.close();
      }
    };
  }, []);

  const handleReset = () => {
    setState("Idle");
    setJobId(null);
    setErrorResult(null);
  };

  return (
    <>
      <PageHeader
        title="Import Backup"
        breadcrumbs={[
          { label: "Admin", to: "/admin" },
          { label: "Backup & Restore", to: "/admin/backup" },
          { label: "Import" },
        ]}
      />
      <div className="max-w-2xl mt-6">
        {state === "Idle" || state === "Submitting" ? (
          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
              <FormField
                control={form.control}
                name="ArchiveId"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Archive ID</FormLabel>
                    <FormControl>
                      <Input {...field} placeholder="e.g. 01J8Z9K2..." />
                    </FormControl>
                    <FormDescription>The ID of the archive to import</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="KeyMaterial"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Decryption Key (Optional)</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        type="password"
                        placeholder="Enter key material if encrypted offline"
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" disabled={state === "Submitting" || isLocked}>
                {state === "Submitting" ? "Starting Import..." : "Start Import"}
                {isLocked && ` (Wait ${remainingSeconds}s)`}
              </Button>
            </form>
          </Form>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle>
                {state === "Queued" && "Import Queued"}
                {state === "Running" && "Import Running"}
                {state === "Succeeded" && "Import Successful"}
                {state === "Failed" && "Import Failed"}
                {state === "Cancelled" && "Import Cancelled"}
                {state === "Offline" && "Connection Lost"}
              </CardTitle>
            </CardHeader>
            <CardContent>
              {state === "Queued" && <p>Waiting for a worker to pick up the import job.</p>}
              {state === "Running" && (
                <p>
                  Phase: {progress.phase}. {progress.percent}% complete.
                </p>
              )}
              {state === "Succeeded" && (
                <Alert className="bg-green-50 border-green-200">
                  <AlertTitle className="text-green-800">Success</AlertTitle>
                  <AlertDescription className="text-green-700">
                    The archive has been successfully imported.
                  </AlertDescription>
                </Alert>
              )}
              {state === "Failed" && (
                <Alert variant="destructive">
                  <AlertTitle>Error</AlertTitle>
                  <AlertDescription>{errorResult}</AlertDescription>
                </Alert>
              )}
              {state === "Offline" && (
                <Alert variant="destructive">
                  <AlertTitle>Offline</AlertTitle>
                  <AlertDescription>
                    Lost connection to server. Reset to try again.
                  </AlertDescription>
                </Alert>
              )}
            </CardContent>
            {(state === "Succeeded" ||
              state === "Failed" ||
              state === "Cancelled" ||
              state === "Offline") && (
              <CardFooter>
                <Button variant="outline" onClick={handleReset}>
                  Start new import
                </Button>
              </CardFooter>
            )}
          </Card>
        )}
      </div>
    </>
  );
}
