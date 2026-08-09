/**
 * Plan 10 step 27. Central env resolver for e2e specs.
 *
 * Root cause fixed: each spec would otherwise reach into `process.env`
 * inline, hide missing values behind `??`, and silently fall through to
 * wrong credentials. Failing loudly here is the observability contract
 * the plan requires (spec/03-error-manage/).
 */
export function requireEnv(name: string): string {
  const value = process.env[name];
  if (value === undefined || value === "") {
    throw new Error(
      `Missing required e2e env var: ${name}. Set it in your shell or CI secret store.`,
    );
  }
  return value;
}

export function optionalEnv(name: string, fallback: string): string {
  const value = process.env[name];
  return value === undefined || value === "" ? fallback : value;
}

export const E2E_BASE_URL = optionalEnv("E2E_BASE_URL", "http://localhost:8080");
