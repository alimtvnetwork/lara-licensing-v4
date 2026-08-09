import { type LaraApiError } from "../lib/lara-api-error";
import { useRetryAfterCountdown, isRateLimited } from "../lib/use-retry-after-countdown";
import { Banner, BannerTitle, BannerDescription, BannerActions } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";

interface Props {
  error: unknown;
  onRetry?: () => void;
}

/**
 * Inline banner surfaced next to submit buttons when a request fails with
 * RateLimited. Renders the bucket name, live Retry-After countdown, and a
 * retry button that stays disabled until the countdown reaches zero, per
 * spec/21-app/14-rate-limiting.md. When Retry-After is absent we still show
 * the banner (retry stays enabled) so operators are never left guessing.
 *
 * Plan 15 Step 23: refitted onto the shared <Banner> primitive with the
 * "warning" intent so all persistent banners share elevation, radius, and
 * icon geometry from spec 24 §23.3. The countdown state machine stays here.
 */
export function RetryAfterBanner({ error, onRetry }: Props) {
  const remaining = useRetryAfterCountdown(error);
  if (isRateLimited(error) === false) return null;
  const lara = error as LaraApiError;
  const bucket = lara.rateLimit?.bucket;
  const requestId = lara.requestId;
  const disabled = remaining !== undefined && remaining > 0;

  return (
    <Banner intent="warning">
      <BannerTitle>Rate limit hit.</BannerTitle>
      <BannerDescription className="text-xs">
        {bucket ? (
          <>
            Bucket <span className="font-mono">{bucket}</span>.{" "}
          </>
        ) : null}
        {remaining === undefined
          ? "Retry-After was not provided by the server."
          : remaining > 0
            ? `Retry available in ${remaining}s.`
            : "You may retry now."}
        {requestId ? (
          <>
            {" "}
            Request <span className="font-mono">{requestId}</span>.
          </>
        ) : null}
      </BannerDescription>
      {onRetry ? (
        <BannerActions>
          <Button type="button" onClick={onRetry} disabled={disabled} variant="outline" size="sm">
            {disabled ? `Retry in ${remaining}s` : "Retry now"}
          </Button>
        </BannerActions>
      ) : null}
    </Banner>
  );
}
