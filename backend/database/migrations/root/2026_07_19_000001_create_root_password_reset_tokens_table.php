<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 09 step: password recovery substrate.
 *
 * Stores hashed reset tokens keyed by EmailLower. TokenHash is
 * sha256(plaintext token) so a Root DB dump does not leak reset URLs.
 * ExpiresAt is enforced at verification time (default 60 min TTL).
 * ConsumedAt marks single-use tokens so a leaked link cannot be reused.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "PasswordResetTokens" (
                "PasswordResetTokenId" BIGSERIAL PRIMARY KEY,
                "EmailLower"           VARCHAR(255) NOT NULL,
                "TokenHash"            CHAR(64)     NOT NULL,
                "CreatedAt"            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "ExpiresAt"            TIMESTAMPTZ  NOT NULL,
                "ConsumedAt"           TIMESTAMPTZ  NULL,
                "RequestIp"            VARCHAR(64)  NULL
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_PasswordResetTokens_EmailLower" ON "PasswordResetTokens" ("EmailLower")');
        DB::connection(self::CONN)->statement('CREATE UNIQUE INDEX IF NOT EXISTS "UX_PasswordResetTokens_TokenHash" ON "PasswordResetTokens" ("TokenHash")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "PasswordResetTokens"');
    }
};
