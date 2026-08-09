import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";

/**
 * Plan 09 password recovery. Client transport for
 *   POST /Api/Auth/ForgotPassword
 *   POST /Api/Auth/ResetPassword
 *
 * ForgotPassword always returns success (enumeration-safe). ResetPassword
 * throws PasswordResetTokenInvalid on any failure mode; the UI displays
 * one message for every rejection so no side channel leaks token validity.
 */

const messageSchema = z.object({ Message: z.string().min(1) });

export interface ForgotPasswordInput {
  Email: string;
}

export interface ResetPasswordInput {
  Email: string;
  Token: string;
  NewPassword: string;
}

export async function requestPasswordReset(input: ForgotPasswordInput): Promise<string> {
  const [row] = await requestLaraApi("/Auth/ForgotPassword", messageSchema, {
    method: HttpMethodType.Post,
    body: input,
  });

  return row.Message;
}

export async function submitPasswordReset(input: ResetPasswordInput): Promise<string> {
  const [row] = await requestLaraApi("/Auth/ResetPassword", messageSchema, {
    method: HttpMethodType.Post,
    body: input,
  });

  return row.Message;
}
