import { execFile } from "node:child_process";
import { promisify } from "node:util";

import { optionalEnv } from "./env";

const exec = promisify(execFile);

export interface FirstReseller {
  readonly ResellerId: number;
  readonly Slug: string;
}

/**
 * Plan 10 step 32. Shell out to `e2e:first-reseller-id` and parse
 * JSON stdout. Overrides mirror `mintPasswordResetToken`:
 *   E2E_ARTISAN_CMD (default "php backend/artisan")
 *   E2E_ARTISAN_CWD (default process.cwd())
 *   E2E_RESELLER_ID: hard override; when set, skips artisan and
 *   returns { ResellerId: N, Slug: "" }.
 */
export async function firstResellerId(): Promise<FirstReseller> {
  const override = optionalEnv("E2E_RESELLER_ID", "");
  if (override.length > 0) {
    const parsed = Number(override);
    if (!Number.isInteger(parsed) || parsed <= 0) {
      throw new Error(`E2E_RESELLER_ID must be a positive integer, got ${override}`);
    }
    return { ResellerId: parsed, Slug: "" };
  }

  const raw = optionalEnv("E2E_ARTISAN_CMD", "php backend/artisan");
  const parts = raw.split(/\s+/).filter(Boolean);
  const [bin, ...baseArgs] = parts;
  if (bin === undefined) {
    throw new Error("E2E_ARTISAN_CMD is empty; cannot invoke artisan.");
  }
  const cwd = optionalEnv("E2E_ARTISAN_CWD", process.cwd());

  let stdout: string;
  try {
    const result = await exec(bin, [...baseArgs, "e2e:first-reseller-id"], {
      cwd,
      timeout: 15_000,
    });
    stdout = result.stdout;
  } catch (error) {
    const err = error as NodeJS.ErrnoException & { stderr?: string; stdout?: string };
    throw new Error(
      `firstResellerId failed: ${err.message}\nSTDOUT: ${err.stdout ?? ""}\nSTDERR: ${err.stderr ?? ""}`,
    );
  }

  const lines = stdout.split(/\r?\n/).map((l) => l.trim()).filter((l) => l.length > 0);
  const jsonLine = lines[lines.length - 1];
  if (jsonLine === undefined) {
    throw new Error("firstResellerId: empty stdout");
  }
  const parsed = JSON.parse(jsonLine) as {
    Found?: boolean;
    ResellerId?: number;
    Slug?: string;
    Reason?: string;
  };
  if (parsed.Found !== true || typeof parsed.ResellerId !== "number") {
    throw new Error(`firstResellerId refused: ${parsed.Reason ?? "unknown"} (raw=${jsonLine})`);
  }
  return { ResellerId: parsed.ResellerId, Slug: parsed.Slug ?? "" };
}
