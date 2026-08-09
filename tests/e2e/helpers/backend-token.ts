import { execFile } from "node:child_process";
import { promisify } from "node:util";

import { optionalEnv } from "./env";

const exec = promisify(execFile);

export interface MintedResetToken {
  readonly EmailLower: string;
  readonly Token: string;
  readonly ExpiresAt: string;
}

/**
 * Plan 10 step 30. Shell out to the backend artisan command
 * `e2e:mint-reset-token` and parse its JSON stdout.
 *
 * Root cause this helper exists: Playwright cannot read the plaintext
 * token from a Mail-faked reset email or from server logs; the artisan
 * command is the one deterministic mint path. This helper wraps it so
 * specs stay declarative and any spawn error surfaces with the full
 * stderr instead of an empty string.
 *
 * Overrides:
 *   E2E_ARTISAN_CMD: full command, e.g. "php backend/artisan"
 *   E2E_ARTISAN_CWD: working dir, default repo root
 */
export async function mintPasswordResetToken(email: string): Promise<MintedResetToken> {
  const raw = optionalEnv("E2E_ARTISAN_CMD", "php backend/artisan");
  const parts = raw.split(/\s+/).filter(Boolean);
  const [bin, ...baseArgs] = parts;
  if (bin === undefined) {
    throw new Error("E2E_ARTISAN_CMD is empty; cannot invoke artisan.");
  }
  const cwd = optionalEnv("E2E_ARTISAN_CWD", process.cwd());
  const args = [...baseArgs, "e2e:mint-reset-token", email];

  let stdout: string;
  try {
    const result = await exec(bin, args, { cwd, timeout: 15_000 });
    stdout = result.stdout;
  } catch (error) {
    const err = error as NodeJS.ErrnoException & { stderr?: string; stdout?: string };
    throw new Error(
      `mintPasswordResetToken failed: ${err.message}\nSTDOUT: ${err.stdout ?? ""}\nSTDERR: ${err.stderr ?? ""}`,
    );
  }

  // Command may emit Laravel bootstrap noise; take the last non-empty line.
  const lines = stdout.split(/\r?\n/).map((l) => l.trim()).filter((l) => l.length > 0);
  const jsonLine = lines[lines.length - 1];
  if (jsonLine === undefined) {
    throw new Error(`mintPasswordResetToken: empty stdout for ${email}`);
  }

  const parsed = JSON.parse(jsonLine) as {
    Sent?: boolean;
    Token?: string;
    EmailLower?: string;
    ExpiresAt?: string;
    Reason?: string;
  };
  if (parsed.Sent !== true || typeof parsed.Token !== "string") {
    throw new Error(
      `mintPasswordResetToken refused: ${parsed.Reason ?? "unknown"} (raw=${jsonLine})`,
    );
  }
  return {
    EmailLower: parsed.EmailLower ?? email.toLowerCase(),
    Token: parsed.Token,
    ExpiresAt: parsed.ExpiresAt ?? "",
  };
}
