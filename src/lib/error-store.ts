/**
 * Plan 11 step 28: canonical FE error store.
 *
 * Normalizes every observed `LaraApiError` (including the network-wrapped
 * `httpStatus === 0` variant emitted by `laraFetch`) into one flat entry
 * shape so downstream surfaces (step 29 Global Error Modal, step 30
 * toast, step 33 window `unhandledrejection` capture, step 36 error
 * history panel) subscribe to ONE source of truth. Without this, each
 * surface has to inspect `LaraApiError` internals independently and can
 * drift on `retryable` / `errorId` / de-duplication.
 *
 * Deliberately dependency-free (no zustand): the store is a tiny
 * `useSyncExternalStore`-compatible pub/sub, which keeps the seam small
 * and testable in a plain Vitest environment (no React needed).
 *
 * Root cause note (see CHANGELOG v0.425.0): steps 24-27 built
 * `laraFetch`, `LaraApiError` fields, envelope decoder, and
 * `isRetryable`, but nothing captured errors in a shared place. This
 * module closes that gap; step 33 will forward window errors here too.
 */

import { type LaraApiError, type RateLimitMetadata } from "./lara-api-error";
import type { ApiErrorCodeType } from "./lara-api-error";
import { classifyRetryPolicy, isRetryable, type RetryPolicyType } from "./lara-retry";

export type ErrorStoreEntry = {
  readonly id: string;
  readonly errorCode: ApiErrorCodeType;
  readonly httpStatus: number;
  readonly message: string;
  readonly requestId: string | undefined;
  readonly errorId: string | undefined;
  readonly operationId: string | undefined;
  readonly details: ReadonlyArray<unknown> | undefined;
  /**
   * Preserves 429 Retry-After metadata verbatim so the
   * `GlobalRateLimitBanner` can drive its countdown without a second
   * parse of `details`. Root cause fixed in Plan 11 step 35: the
   * previous shape dropped this field between `laraFetch` and the
   * banner, so real 429s rendered "Retry-After was not provided by
   * the server" even when the envelope carried a valid header.
   */
  readonly rateLimit: RateLimitMetadata | undefined;
  readonly retryable: boolean;
  readonly retryPolicy: RetryPolicyType;
  readonly at: number;
  /**
   * Optional caller-supplied component/route hint. Populated when the
   * push site knows which UI surface observed the failure so the Global
   * Error Modal can render a `Source` row per spec/03-error-manage
   * (compliance-matrix.md v1.1, Modal "source component" row).
   */
  readonly sourceComponent: string | undefined;
};

export type ErrorStoreListener = (entries: ReadonlyArray<ErrorStoreEntry>) => void;

const MAX_ENTRIES = 50;
const DEDUPE_WINDOW_MS = 1500;
const SESSION_KEY = "lara.error-history.v1";

let entries: ReadonlyArray<ErrorStoreEntry> = [];
try {
  const stored = typeof window !== "undefined" ? sessionStorage.getItem(SESSION_KEY) : null;
  if (stored !== null) {
    const parsed = JSON.parse(stored);
    if (Array.isArray(parsed)) {
      entries = parsed;
    }
  }
} catch {
  // Ignore parse errors, just start empty
}

const listeners = new Set<ErrorStoreListener>();
let entryCounter = 0;

function nextId(now: number): string {
  entryCounter += 1;

  return `err-${now.toString(36)}-${entryCounter.toString(36)}`;
}

function isDuplicate(next: ErrorStoreEntry): boolean {
  const head = entries[0];
  if (head === undefined) return false;
  if (next.at - head.at > DEDUPE_WINDOW_MS) return false;
  if (head.errorCode !== next.errorCode) return false;
  if (head.httpStatus !== next.httpStatus) return false;
  // Strongest key: matching ErrorId (5xx correlation) or RequestId.
  if (next.errorId !== undefined || head.errorId !== undefined) {
    return next.errorId === head.errorId;
  }
  if (next.requestId !== undefined || head.requestId !== undefined) {
    return next.requestId === head.requestId;
  }

  return head.message === next.message;
}

function emit(): void {
  try {
    if (typeof window !== "undefined") {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify(entries));
    }
  } catch {
    // Ignore storage errors (e.g. quota exceeded or strict privacy modes)
  }
  for (const listener of listeners) listener(entries);
}

export function pushLaraApiError(
  error: LaraApiError,
  at: number = Date.now(),
  sourceComponent?: string,
): ErrorStoreEntry {
  const policy = classifyRetryPolicy(error);
  const entry: ErrorStoreEntry = {
    id: nextId(at),
    errorCode: error.errorCode,
    httpStatus: error.httpStatus,
    message: error.message,
    requestId: error.requestId,
    errorId: error.errorId,
    operationId: error.operationId,
    details: error.details,
    rateLimit: error.rateLimit,
    retryable: isRetryable(error),
    retryPolicy: policy,
    at,
    sourceComponent,
  };
  if (isDuplicate(entry)) return entries[0]!;
  const next = [entry, ...entries].slice(0, MAX_ENTRIES);
  entries = next;
  emit();

  return entry;
}

export function subscribeErrorStore(listener: ErrorStoreListener): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

export function getErrorStoreSnapshot(): ReadonlyArray<ErrorStoreEntry> {
  return entries;
}

export function clearErrorStore(): void {
  if (entries.length === 0) return;
  entries = [];
  try {
    if (typeof window !== "undefined") {
      sessionStorage.removeItem(SESSION_KEY);
    }
  } catch {
    // ignore
  }
  emit();
}

/** Test-only reset. Not exported from a barrel. */
export function __resetErrorStoreForTests(): void {
  entries = [];
  listeners.clear();
  entryCounter = 0;
}
