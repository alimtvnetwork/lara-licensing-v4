<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 17. Shard DB `LicenseLedger` (append-only journal).
 *
 * Per spec/23-app-db/01-schema.md §ResellerQuotaLedger and
 * spec/21-app/28-audit-action-enum.md: this is the append-only source of
 * truth for quota movement. Every issue writes a `QuotaConsumed` (Delta=-1);
 * every eligible revoke writes a `QuotaRestored` (Delta=+1) per
 * spec/21-app/48-quota-restore-on-revoke.md v1.0.0; every approved
 * quota-request adjustment writes a `QuotaAdjusted` (Delta signed, non-zero)
 * per spec/21-app/42-quota-requests.md v1.1.0. Invariant
 * `SUM(Delta) = LicensesGranted - LicensesConsumed` per
 * `(ResellerId, LicenseTierId)` is enforced at read time by Check 22 of
 * spec/21-app/99-consistency-report.md.
 *
 * The ledger lives on the shard because every row references a shard-local
 * `LicenseId`. The Root `QuotaRequests` inbox mirror (spec 42 v1.1.0) is
 * added by the next migration (Plan 06 step 18); `QuotaRequestId` here is a
 * loose FK number, not a physical FK across DBs.
 *
 * CHECK constraints encode the three business rules from spec 23
 * §ResellerQuotaLedger directly: consume/restore require `LicenseId`,
 * adjust requires `QuotaRequestId`, and the Delta sign is bound to the
 * action. `QuotaRestored` uses Delta=+1 (not +N) so multi-license restore
 * writes N rows, one per license, keeping the audit granular per
 * spec 48 §Ledger contract.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "LicenseLedger" (
                "LicenseLedgerId" BIGSERIAL PRIMARY KEY,
                "ResellerId"      BIGINT NOT NULL,
                "TierName"        VARCHAR(16) NOT NULL
                    CHECK ("TierName" IN (\'Tier1\',\'Tier2\',\'Tier3\',\'Unlimited\')),
                "LedgerAction"    VARCHAR(24) NOT NULL
                    CHECK ("LedgerAction" IN (\'QuotaConsumed\',\'QuotaRestored\',\'QuotaAdjusted\')),
                "Delta"           SMALLINT NOT NULL CHECK ("Delta" <> 0),
                "LicenseId"       BIGINT NULL
                    REFERENCES "Licenses"("LicenseId") ON DELETE RESTRICT,
                "QuotaRequestId"  BIGINT NULL,
                "RequestId"       VARCHAR(64) NOT NULL,
                "ActorUserId"     BIGINT NOT NULL,
                "CreatedAt"       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT "CkLedgerConsumeLicense"
                    CHECK ("LedgerAction" NOT IN (\'QuotaConsumed\',\'QuotaRestored\') OR "LicenseId" IS NOT NULL),
                CONSTRAINT "CkLedgerAdjustRequest"
                    CHECK ("LedgerAction" <> \'QuotaAdjusted\' OR "QuotaRequestId" IS NOT NULL),
                CONSTRAINT "CkLedgerDeltaSign"
                    CHECK (
                        ("LedgerAction" = \'QuotaConsumed\' AND "Delta" = -1)
                     OR ("LedgerAction" = \'QuotaRestored\' AND "Delta" =  1)
                     OR ("LedgerAction" = \'QuotaAdjusted\' AND "Delta" <> 0)
                    )
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Ledger_Reseller_Tier" ON "LicenseLedger" ("ResellerId","TierName")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Ledger_Action"        ON "LicenseLedger" ("LedgerAction")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Ledger_LicenseId"     ON "LicenseLedger" ("LicenseId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Ledger_RequestId"     ON "LicenseLedger" ("RequestId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Ledger_CreatedAt"     ON "LicenseLedger" ("CreatedAt")');

        // Append-only: block UPDATE and DELETE at the DB layer so no bug in
        // service code can mutate history. Spec 23 §ResellerQuotaLedger
        // "Append-only; no UpdatedAt, no DeletedAt."
        DB::connection(self::CONN)->statement('
            CREATE OR REPLACE FUNCTION "TrgFn_LicenseLedger_AppendOnly"() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION \'LEDGER_APPEND_ONLY\';
            END;
            $$ LANGUAGE plpgsql
        ');
        DB::connection(self::CONN)->statement('
            CREATE TRIGGER "TrgLicenseLedgerNoUpdate"
            BEFORE UPDATE ON "LicenseLedger"
            FOR EACH ROW EXECUTE FUNCTION "TrgFn_LicenseLedger_AppendOnly"()
        ');
        DB::connection(self::CONN)->statement('
            CREATE TRIGGER "TrgLicenseLedgerNoDelete"
            BEFORE DELETE ON "LicenseLedger"
            FOR EACH ROW EXECUTE FUNCTION "TrgFn_LicenseLedger_AppendOnly"()
        ');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TRIGGER IF EXISTS "TrgLicenseLedgerNoUpdate" ON "LicenseLedger"');
        DB::connection(self::CONN)->statement('DROP TRIGGER IF EXISTS "TrgLicenseLedgerNoDelete" ON "LicenseLedger"');
        DB::connection(self::CONN)->statement('DROP FUNCTION IF EXISTS "TrgFn_LicenseLedger_AppendOnly"()');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "LicenseLedger"');
    }
};
