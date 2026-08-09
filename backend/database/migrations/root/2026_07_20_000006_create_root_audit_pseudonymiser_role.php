<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 2c. Root DB `audit_pseudonymiser` migration role +
 * privilege wiring for `BackupAuditEvents` + placeholder shell for
 * `fn_pseudonymise_actor(uuid, text)`.
 *
 * Normative sources:
 *  - spec/26-backup-restore/23-audit-and-compliance.md v1.0.0 §"Table"
 *    "No UPDATE, no DELETE to any application role", §"GDPR
 *    Right-to-Erasure" ("only the `audit_pseudonymiser` role holds
 *    UPDATE on the pseudonymisation columns, and only via the vetted
 *    procedure").
 *  - spec/26-backup-restore/25-migration-and-rollout.md v1.0.0
 *    §"Migration Order" migration 6.
 *  - INV-BR-AU-4 (immutability), INV-BR-AU-6 (only vetted procedure may
 *    rewrite rows), INV-BR-AU-7 (pseudonymisation preserves chain).
 *
 * Placement: root DB. `BackupAuditEvents` lives on root (migration 5),
 * so its grants and the pseudonymiser role belong on the same
 * connection.
 *
 * Scope of this migration:
 *   1. Create the `audit_pseudonymiser` login-less role (NOLOGIN,
 *      NOINHERIT) idempotently.
 *   2. Apply the closed grant matrix on `BackupAuditEvents` per spec 23
 *      §"Grants":
 *        - authenticated: SELECT, INSERT (RLS still applies at app
 *          layer; policy wiring lands in Plan 14 step 3 when the
 *          Casbin PDP joins).
 *        - service_role  : ALL.
 *        - audit_pseudonymiser: UPDATE (pseudonymisation-only).
 *   3. Install a SECURITY DEFINER shell `fn_pseudonymise_actor(uuid,
 *      text)` that raises `exception 'not_implemented'`. The real
 *      procedure body lands in Plan 14 step 25 (GDPR pseudonymisation
 *      flow); publishing the shell here reserves the signature and
 *      lets step 25 be a body-only ALTER FUNCTION per INV-BR-MG-2.
 *
 * Roles the grants reference (`authenticated`, `service_role`) may not
 * exist in a plain local Postgres; each GRANT is wrapped in a
 * `pg_roles` guard so the migration is idempotent across Supabase and
 * self-hosted Postgres.
 *
 * Reversibility: reversible in S0 (revokes grants, drops function,
 * drops role) before any pseudonymisation has run.
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const ROLE_NAME = 'audit_pseudonymiser';

    private const APP_ROLES = [
        'authenticated',
        'service_role',
    ];

    public function up(): void
    {
        $this->createPseudonymiserRole();
        $this->applyAuditGrants();
        $this->installPseudonymiseFunctionShell();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP FUNCTION IF EXISTS "FnPseudonymiseActor"(uuid, text)');
        $this->revokeAuditGrants();
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'audit_pseudonymiser') THEN
                    DROP ROLE audit_pseudonymiser;
                END IF;
            END
            $$;
        SQL);
    }

    private function createPseudonymiserRole(): void
    {
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'audit_pseudonymiser') THEN
                    CREATE ROLE audit_pseudonymiser NOLOGIN NOINHERIT;
                END IF;
            END
            $$;
        SQL);
    }

    private function applyAuditGrants(): void
    {
        $this->grantIfRoleExists('authenticated', 'GRANT SELECT, INSERT ON "BackupAuditEvents" TO authenticated');
        $this->grantIfRoleExists('service_role',  'GRANT ALL ON "BackupAuditEvents" TO service_role');
        $this->grantIfRoleExists('audit_pseudonymiser',
            'GRANT UPDATE ("ActorUserId","Payload","PrevHash","RowHash") ON "BackupAuditEvents" TO audit_pseudonymiser');
    }

    private function revokeAuditGrants(): void
    {
        foreach ([...self::APP_ROLES, self::ROLE_NAME] as $role) {
            $this->grantIfRoleExists($role, "REVOKE ALL ON \"BackupAuditEvents\" FROM {$role}");
        }
    }

    private function grantIfRoleExists(string $role, string $sql): void
    {
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE \$stmt\${$sql}\$stmt\$;
                END IF;
            END
            $$;
        SQL);
    }

    private function installPseudonymiseFunctionShell(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION "FnPseudonymiseActor"(subject_id uuid, legal_basis text)
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'not_implemented'
                    USING HINT = 'Body lands in Plan 14 step 25; shell reserves signature per INV-BR-MG-2.';
            END;
            $fn$;
        SQL);
        DB::connection(self::CONN)->statement(
            'REVOKE ALL ON FUNCTION "FnPseudonymiseActor"(uuid, text) FROM PUBLIC'
        );
        $this->grantIfRoleExists(
            'audit_pseudonymiser',
            'GRANT EXECUTE ON FUNCTION "FnPseudonymiseActor"(uuid, text) TO audit_pseudonymiser'
        );
    }
};
