import { queryOptions } from "@tanstack/react-query";
import { apiClient } from "./api-client";
import type { AdminErrorRow } from "@/generated/api/schema";

export function adminErrorsQueryOptions() {
  return queryOptions({
    queryKey: ["admin", "errors"],
    queryFn: ({ signal }) => apiClient.call("admin.errors.list", {}, { signal }),
  });
}
