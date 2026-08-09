const ACCESS_TOKEN_KEY = "LicensingPortal.AccessToken";
const REFRESH_TOKEN_KEY = "LicensingPortal.RefreshToken";

function hasSessionStorage(): boolean {
  return typeof window === "object";
}

export function getLaraAccessToken(): string | undefined {
  if (hasSessionStorage()) return window.sessionStorage.getItem(ACCESS_TOKEN_KEY) ?? undefined;

  return undefined;
}

export function setLaraAccessToken(accessToken: string): void {
  if (hasSessionStorage()) window.sessionStorage.setItem(ACCESS_TOKEN_KEY, accessToken);
}

export function getLaraRefreshToken(): string | undefined {
  if (hasSessionStorage()) return window.sessionStorage.getItem(REFRESH_TOKEN_KEY) ?? undefined;

  return undefined;
}

export function setLaraRefreshToken(refreshToken: string): void {
  if (hasSessionStorage()) window.sessionStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
}

export function clearLaraSession(): void {
  if (hasSessionStorage() === false) return;
  window.sessionStorage.removeItem(ACCESS_TOKEN_KEY);
  window.sessionStorage.removeItem(REFRESH_TOKEN_KEY);
}
