import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import { setLaraAccessToken, setLaraRefreshToken } from "@/lib/lara-api-session";
import { DEMO_IDENTITIES, type DemoIdentity } from "@/lib/demo-identities";

export { DEMO_IDENTITIES, type DemoIdentity };

/**
 * Signs in as a demo user by writing a synthetic session.
 * Plan 18 Phase C (Step 44-46).
 */
export async function signInWithSeedIdentity(identityId: string): Promise<void> {
  const identity = DEMO_IDENTITIES.find((i) => i.id === identityId);
  const isFailed = !identity;
  if (isFailed) {
    throw new LaraApiError(
      `Unknown seed identity: ${identityId}`,
      ApiErrorCodeType.AuthInvalidCredentials,
      401,
    );
  }

  // Synthetic tokens that bypass real JWT validation but satisfy string-type guards
  // in _authenticated.tsx and api-client.ts.
  const syntheticAccessToken = `seed_access_${identity.id}_${Date.now()}`;
  const syntheticRefreshToken = `seed_refresh_${identity.id}_${Date.now()}`;

  setLaraAccessToken(syntheticAccessToken);
  setLaraRefreshToken(syntheticRefreshToken);

  // Note: We don't perform a backend call. The preview transport handles
  // GET /Api/Me by detecting the 'seed_access_' prefix in the bearer.
  console.info("auth.seed_signin_success", { identityId: identity.id });
}
