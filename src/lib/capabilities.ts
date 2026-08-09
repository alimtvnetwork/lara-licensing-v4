import { useApi } from "@/hooks/use-api";

export function useCapabilities() {
  const { data, isLoading, error } = useApi(
    "auth.capabilities",
    {},
    {
      staleTime: 60_000, // Cache for 60s as specified in 17-fe-routes.md
    },
  );

  return {
    capabilities: data?.Capabilities ?? [],
    isLoading,
    error,
  };
}

export function useCapability(cap: string): boolean {
  const { capabilities } = useCapabilities();

  // Casbin often uses * as a wildcard. Let's check for exact match or * matching.
  // Actually, the backend API parses all implicit permissions and returns the exact list of capabilities.
  // The spec says we might see '*' in Casbin rows, but getImplicitPermissionsForUser resolves inherited rules.
  // For safety, let's allow wildcard matching just in case the backend returns something like 'Backup.*'
  return capabilities.some((c) => {
    if (c === cap) return true;
    if (c.endsWith(".*")) {
      const prefix = c.slice(0, -2);

      return cap.startsWith(prefix);
    }
    if (c === "*") return true;

    return false;
  });
}
