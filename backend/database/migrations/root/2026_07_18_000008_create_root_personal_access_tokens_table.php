<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 43 (login substrate). Sanctum `personal_access_tokens`
 * table on the Root DB.
 *
 * Sanctum's default schema is preserved (snake_case) because Sanctum owns
 * the model; PascalCase would require overriding column accessors that add
 * no value. The `name` column carries the AuthSessions.SessionId UUID for
 * every token minted on login, giving handlers a stable pointer from the
 * bearer token to the parent AuthSession row (spec/21-app/31-auth-session-family.md).
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "personal_access_tokens" (
                "id"             BIGSERIAL     PRIMARY KEY,
                "tokenable_type" VARCHAR(255)  NOT NULL,
                "tokenable_id"   BIGINT        NOT NULL,
                "name"           VARCHAR(255)  NOT NULL,
                "token"          VARCHAR(64)   NOT NULL UNIQUE,
                "abilities"      TEXT          NULL,
                "last_used_at"   TIMESTAMPTZ   NULL,
                "expires_at"     TIMESTAMPTZ   NULL,
                "created_at"     TIMESTAMPTZ   NULL,
                "updated_at"     TIMESTAMPTZ   NULL
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_pat_tokenable" ON "personal_access_tokens" ("tokenable_type","tokenable_id")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "personal_access_tokens"');
    }
};
