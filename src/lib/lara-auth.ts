import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import { setLaraAccessToken, setLaraRefreshToken } from "./lara-api-session";

const tokenResultSchema = z.object({
  AccessToken: z.string().min(1),
  RefreshToken: z.string().min(1),
  TokenType: z.literal("Bearer"),
  ExpiresIn: z.number().int().positive(),
});

export type LaraTokenResult = z.infer<typeof tokenResultSchema>;

/**
 * Plan 09 login modernization. Login payload accepted by the Laravel
 * backend at POST /Api/Auth/Login. RememberMe extends AuthSession TTL
 * to `lara.remember_me_ttl_minutes`. CaptchaChallengeId + CaptchaAnswer
 * satisfy LoginCaptchaRequired (returned after N consecutive failures).
 */
export interface LaraLoginRequest {
  Email: string;
  Password: string;
  RememberMe?: boolean;
  CaptchaChallengeId?: string;
  CaptchaAnswer?: string;
}

const captchaChallengeSchema = z.object({
  ChallengeId: z.string().min(1),
  Question: z.string().min(1),
  ExpiresAt: z.string().min(1),
});

export type LaraCaptchaChallenge = z.infer<typeof captchaChallengeSchema>;

export async function loginToLaraApi(input: LaraLoginRequest): Promise<LaraTokenResult> {
  const [result] = await requestLaraApi("/Auth/Token", tokenResultSchema, {
    method: HttpMethodType.Post,
    body: input,
  });
  setLaraAccessToken(result.AccessToken);
  setLaraRefreshToken(result.RefreshToken);

  return result;
}

export async function fetchLoginCaptcha(): Promise<LaraCaptchaChallenge> {
  const [result] = await requestLaraApi("/Auth/Captcha", captchaChallengeSchema, {
    method: HttpMethodType.Get,
  });

  return result;
}

/**
 * v0.300.0. Bootstrap-only registration.
 *
 * Root cause this exists: `POST /Api/Auth/Register` has shipped since the
 * first-user-bootstrap turn but no frontend surface consumed it, leaving
 * fresh installs unable to mint their own SuperAdmin without curl. The
 * backend returns `Token` (a plaintext Sanctum PAT bound to the newly
 * opened AuthSession), NOT the `AccessToken`/`RefreshToken` pair the
 * `/Auth/Token` endpoint returns; we persist it as the access token so
 * the console lets the new SuperAdmin in immediately. No refresh token
 * is minted here on purpose (bootstrap is a one-shot); the user
 * re-authenticates via /admin/login once ExpiresAt elapses.
 */
const registerResultSchema = z.object({
  UserId: z.number().int().positive(),
  Email: z.string().email(),
  SessionId: z.string().uuid(),
  ExpiresAt: z.string().min(1),
  Token: z.string().min(1),
  Roles: z.array(z.string()).min(1),
});

export type LaraRegisterResult = z.infer<typeof registerResultSchema>;

export interface LaraRegisterRequest {
  Email: string;
  Password: string;
}

export async function registerViaLara(input: LaraRegisterRequest): Promise<LaraRegisterResult> {
  const [result] = await requestLaraApi("/Auth/Register", registerResultSchema, {
    method: HttpMethodType.Post,
    body: input,
  });
  setLaraAccessToken(result.Token);

  return result;
}
