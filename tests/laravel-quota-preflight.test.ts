import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import {
  assertQuotaPreflight,
  evaluateQuotaPreflight,
  findQuotaRow,
  QuotaPreflightCode,
  QuotaPreflightError,
  remainingFor,
  type QuotaPreflightRow,
} from '../backend/resources/js/lib/quotaPreflight';

const read = (p: string) => readFileSync(resolve(process.cwd(), p), 'utf8');

const rows: QuotaPreflightRow[] = [
  { LicenseCategoryId: 1, LicenseTierId: 1, LicensesGranted: 10, LicensesConsumed: 4, LicensesRemaining: 6 },
  { LicenseCategoryId: 2, LicenseTierId: 3, LicensesGranted: 5, LicensesConsumed: 5, LicensesRemaining: 0 },
];

describe('Plan 06 step 80: quotaPreflight', () => {
  it('returns Unknown when no rows are loaded so the server decides', () => {
    expect(evaluateQuotaPreflight([], 1, 1)).toEqual({ Outcome: 'Unknown' });
    expect(evaluateQuotaPreflight(undefined, 1, 1)).toEqual({ Outcome: 'Unknown' });
    expect(evaluateQuotaPreflight(null, 1, 1)).toEqual({ Outcome: 'Unknown' });
  });

  it('allows a tuple with headroom and reports remaining', () => {
    expect(evaluateQuotaPreflight(rows, 1, 1)).toEqual({ Outcome: 'Allowed', Remaining: 6 });
  });

  it('blocks an unprovisioned tuple with QuotaCategoryUnauthorized/403', () => {
    const decision = evaluateQuotaPreflight(rows, 7, 2);
    expect(decision).toMatchObject({
      Outcome: 'Blocked',
      Code: QuotaPreflightCode.QuotaCategoryUnauthorized,
      HttpStatus: 403,
    });
  });

  it('blocks an exhausted tuple with QuotaExhausted/409 for the issue intent', () => {
    expect(evaluateQuotaPreflight(rows, 2, 3, 'issue')).toMatchObject({
      Outcome: 'Blocked',
      Code: QuotaPreflightCode.QuotaExhausted,
      HttpStatus: 409,
    });
  });

  it('warns instead of blocking an exhausted tuple for the request intent', () => {
    expect(evaluateQuotaPreflight(rows, 2, 3, 'request')).toMatchObject({
      Outcome: 'Warned',
      Code: QuotaPreflightCode.QuotaExhausted,
      Remaining: 0,
    });
  });

  it('never leaks absolute granted/consumed counts in messages', () => {
    // Only the tuple ordinals may appear; the allowance numbers (10/4, 5/5)
    // must not, per the no-leak clause of AC-ERR-006.
    for (const intent of ['issue', 'request'] as const) {
      for (const [category, tier] of [[7, 2], [2, 3]] as const) {
        const decision = evaluateQuotaPreflight(rows, category, tier, intent);
        const message = 'Message' in decision ? decision.Message : '';
        const numbers = (message.match(/\d+/g) ?? []).map(Number);
        expect(numbers).toEqual([category, tier]);
      }
    }
  });

  it('derives LicensesRemaining when the prop omits it', () => {
    expect(remainingFor({ LicenseCategoryId: 1, LicenseTierId: 1, LicensesGranted: 9, LicensesConsumed: 2 })).toBe(7);
    expect(remainingFor({ LicenseCategoryId: 1, LicenseTierId: 1, LicensesGranted: 9, LicensesConsumed: 2, LicensesRemaining: null })).toBe(7);
  });

  it('findQuotaRow matches on both ordinals, not either', () => {
    expect(findQuotaRow(rows, 1, 3)).toBeUndefined();
    expect(findQuotaRow(rows, 2, 3)?.LicensesGranted).toBe(5);
  });

  it('assertQuotaPreflight throws a LaraApiError-shaped QuotaPreflightError', () => {
    try {
      assertQuotaPreflight(rows, 7, 2);
      throw new Error('expected throw');
    } catch (cause) {
      expect(cause).toBeInstanceOf(QuotaPreflightError);
      const err = cause as QuotaPreflightError;
      expect(err.code).toBe(QuotaPreflightCode.QuotaCategoryUnauthorized);
      expect(err.httpStatus).toBe(403);
      expect(err.requestId).toBe('local-preflight');
      expect(err.operationId).toBe('quota.preflight');
    }
    expect(assertQuotaPreflight(rows, 1, 1)).toEqual({ Outcome: 'Allowed', Remaining: 6 });
  });

  it('the submit form runs the preflight before the mutating request', () => {
    const form = read('backend/resources/js/Components/quota/QuotaRequestSubmitForm.tsx');
    expect(form).toContain('assertQuotaPreflight(quotas, categoryId, tierId, "request")');
    expect(form.indexOf('assertQuotaPreflight')).toBeLessThan(form.indexOf('await laraRequest'));
    expect(form).toContain('QuotaPreflightError');
  });

  it('both reseller routes project quota rows through one helper', () => {
    const web = read('backend/routes/web.php');
    expect(web.match(/ResellerQuotaProjection::forReseller/g)?.length).toBe(2);
    expect(web).not.toContain('Quota::query()');
    const php = read('backend/app/Support/ResellerQuotaProjection.php');
    expect(php).toContain("'LicensesRemaining' => (int) \$q->LicensesGranted - (int) \$q->LicensesConsumed");
  });
});
