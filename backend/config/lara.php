<?php

/**
 * Licensing Portal v1 closed-set enums.
 *
 * Single source of truth for backend runtime. Every value here MUST match its
 * canonical spec file exactly. If a spec file bumps its Version, this file MUST
 * be updated in the same commit and cross-referenced from CHANGELOG.md.
 *
 * Sources:
 * - Roles:         spec/21-app/04-roles.md
 * - LicenseTier:   spec/21-app/43-license-tiers.md (SOLE OWNER)
 * - Environment:   spec/21-app/44-environments.md
 * - ApiErrorCode:  spec/21-app/12-error-taxonomy.md
 * - LedgerAction:  spec/21-app/28-audit-action-enum.md
 *                  (values re-derived below; keep in sync when spec 28 lands)
 *
 * Do not add synonyms. Forbidden aliases per spec 43 (Basic/Bronze/Standard/
 * Silver/Premium/Gold/Enterprise/Pro) are NOT valid and MUST be rejected by
 * validators with `ValidationFailed` per spec 12.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL (used by password recovery + magic links)
    |--------------------------------------------------------------------------
    | Origin of the TanStack SPA. Falls back to APP_URL when unset so a fresh
    | env still generates a working reset link, but callers should set this
    | to the actual SPA origin in production so the emailed URL matches the
    | user-facing host.
    */
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:8080')),

    /*
    |--------------------------------------------------------------------------
    | Runtime config file path (Plan 16 step 58)
    |--------------------------------------------------------------------------
    | Absolute path to the on-disk `version.json` (spec/28-runtime-modes/
    | 01-version-json-schema.md). RuntimeConfigService reads and atomically
    | rewrites this file. Default resolves to repo-root `public/version.json`
    | so cPanel deploys serve the same file the SPA fetches.
    */
    'runtime_config_path' => env('LARA_RUNTIME_CONFIG_PATH', dirname(base_path()) . '/public/version.json'),
    'runtime_config_allow_prod_to_preview' => (bool) env('LARA_ALLOW_PROD_TO_PREVIEW', false),

    /*
    |--------------------------------------------------------------------------
    | Backup / Restore (Plan 14 step 11)
    |--------------------------------------------------------------------------
    | Absolute path to the archive sink used by App\Services\BR\BrArchiveStorage.
    | Every export archive materialises under `<archive_root>/<archiveId>/`
    | (staging + finalized). Defaults to `storage/app/br/archives` so a fresh
    | env works out of the box; production hosts should point at a dedicated
    | volume (see spec 26 §26 "Runbooks"). The sink NEVER writes outside this
    | root; the archiveId is validated as a UUID before any FS syscall.
    */
    'br' => [
        'archive_root' => env('LARA_BR_ARCHIVE_ROOT', storage_path('app/br/archives')),
        // Manifest `appVersion` value written by BrManifestBuilder. Defaults to
        // the release tag pinned in the root README when unset (INV-BR-MS-1).
        'app_version'  => env('LARA_APP_VERSION', '0.639.0'),
        // Absolute filesystem path to the root migration files whose bodies
        // feed the SC-A schemaHash. Defaults to the Laravel root migrations
        // directory when unset; kept configurable so cPanel releases with a
        // relocated `database/migrations/` tree can still export correctly.
        'root_migrations_path' => env('LARA_BR_ROOT_MIGRATIONS_PATH'),
        // Plan 14 step 19. SC-F Root-scope domain tables allowlist. Every
        // Root `public.*` table not covered by SC-A..E and not enumerated
        // in spec/26-backup-restore/06-scope-exclusions.md MUST appear
        // here (INV-BR-SC-2). Sorted alphabetically at collect time so
        // archive bytes are stable regardless of config ordering.
        'domain_root_tables' => [
            'AppUpdateAssets',
            'AppUpdates',
            'AuditLogs',
            'BackupAuditEvents',
            'BackupJobs',
            'BackupSnapshots',
            'BrAdvisoryLockKeys',
            'BrKekEpochs',
            'FeatureFlags',
            'LicenseTiers',
            'Prefixes',
            'ResellerShardRoutes',
            'Resellers',
        ],
        // Plan 14 step 20. SC-H File-objects index sources. Each entry
        // projects one Root SC-F table's file-reference columns into the
        // `scope/files/index.jsonl.zst` chunk. Fields:
        //   table         : Root table name.
        //   sha256Column  : column holding the content SHA-256 hex.
        //   pathColumn    : column holding the object-storage path.
        //   bytesColumn   : column holding the size in bytes (>0).
        //   bucket        : logical bucket label recorded in the index.
        //   whereColumn   : optional column-name filter (e.g. IsFinalized).
        //   whereValue    : matching value (e.g. 1) for the above filter.
        // INV-BR-SC-5 pins content-addressed dedupe by sha256; adding a
        // new SC-F table with file references means adding a source here.
        'file_sources' => [
            [
                'table' => 'AppUpdateAssets',
                'sha256Column' => 'Sha256',
                'pathColumn' => 'StoragePath',
                'bytesColumn' => 'SizeBytes',
                'bucket' => 'app-updates',
                'whereColumn' => 'IsFinalized',
                'whereValue' => 1,
            ],
        ],
        // Plan 14 step 21. SC-G Secrets envelope sources. Each entry
        // projects one Root table's sensitive per-row column into the
        // sealed SC-G blob. Fields:
        //   table         : Root table name.
        //   pkColumn      : primary-key column (drives ORDER BY + nonce
        //                   derivation for reproducible archive bytes).
        //   fieldColumn   : logical field label recorded in the JSONL
        //                   entry (e.g. 'ApiKey', 'WebhookSecret').
        //   valueColumn   : column carrying the plaintext secret.
        //   whereColumn   : optional filter column name.
        //   whereValue    : optional filter value.
        // Root default is empty because no Root table today carries a
        // per-row sensitive column (secrets live in `.env` per EX-A).
        // Adding a source here means adding to this list, not code.
        'secret_sources' => [],

    ],


    /*
    |--------------------------------------------------------------------------
    | Roles (spec/21-app/04-roles.md)
    |--------------------------------------------------------------------------
    | Role gate strings used verbatim on the wire (`/Admin/Users/{UserId}/Role`
    | request/response bodies). Never store roles on Profiles; use UserRoles
    | with HasRole($userId, $role) semantics.
    */
    'roles' => [
        'SuperAdmin',
        'Admin',
        'Reseller',
        'AppBuilder',
        'EndUser',
    ],


    /*
    |--------------------------------------------------------------------------
    | LicenseTier (spec/21-app/43-license-tiers.md)
    |--------------------------------------------------------------------------
    | Closed set of exactly three members. TierName is the canonical wire
    | value; Ordinal is the stable numeric used in logs/serializers.
    */
    'license_tiers' => [
        'Tier1' => 1,
        'Tier2' => 2,
        'Tier3' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | LicenseCategory (spec/23-app-db/01-schema.md §LicenseCategories)
    |--------------------------------------------------------------------------
    | Closed catalog matching the seeded rows for shard `LicenseCategories`.
    | Ordinals 1..7 are stable and used by `QuotaRequests.LicenseCategoryId`
    | and `Licenses.LicenseCategoryId`. Adding a member requires bumping the
    | schema spec AND this array in the same commit. Codes are single-char
    | mnemonics used by license-key generators.
    */
    'license_categories' => [
        'Daily' => 1,
        'Weekly' => 2,
        'Monthly' => 3,
        'Yearly' => 4,
        'Lifetime' => 5,
        'Dev' => 6,
        'Key' => 7,
    ],

    /*
    | Single-character mnemonic codes used by serial generators
    | (spec/21-app/07-serial-generation.md §Category segment). Keyed by
    | the ordinals in `license_categories` above; adding a category
    | requires adding both entries in the same commit.
    */
    'license_category_codes' => [
        1 => 'D',
        2 => 'W',
        3 => 'M',
        4 => 'Y',
        5 => 'L',
        6 => 'V',
        7 => 'K',
    ],


    /*
    |--------------------------------------------------------------------------
    | Environment (spec/21-app/44-environments.md)
    |--------------------------------------------------------------------------
    | Closed set. Wire values used verbatim on POST /Licenses and Verify
    | endpoints. Mismatch on verify returns EnvironmentMismatch (409) with
    | opaque `<Requested>/<Licensed>` markers only.
    */
    'environments' => [
        'Production',
        'Staging',
        'Development',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shard provisioning state machine (spec/23-app-db/10 §Provisioning
    | Lifecycle). Closed set; mirrors the CHECK constraint on
    | ResellerShardRoutes.ShardStatus. `initial` names the state written
    | by ShardSeeder / ShardProvisionCommand at row creation time.
    |--------------------------------------------------------------------------
    */
    'shard_statuses' => [
        'initial' => 'Provisioning',
        'members' => [
            'Provisioning',
            'Active',
            'Failed',
            'Quiesced',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Binding defaults (spec/21-app/30-machine-bindings.md §Quota,
    | spec/21-app/06-license-variations.md §UserCount)
    |--------------------------------------------------------------------------
    | Server-side ceiling on concurrent MachineBindings and UserBindings
    | when the License row does not carry an override. Per-category
    | overrides land with LicenseCategory variations (plan 06 step 43);
    | until then every license uses these defaults. RebindCooldownMinutes
    | is the wall-clock delta added to `ReleasedAt` when a machine is
    | unbound (spec 30 §Unbind step 1).
    */
    'binding_defaults' => [
        'MaxConcurrentBindings' => 1,
        'MaxUserCount' => 1,
        'RebindCooldownMinutes' => 15,
    ],


    /*
    |--------------------------------------------------------------------------
    | ApiErrorCode (spec/21-app/12-error-taxonomy.md)
    |--------------------------------------------------------------------------
    | Full closed set. Any thrown LaraException MUST carry one of these
    | values as its ErrorCode. Adding a new code requires bumping spec 12
    | AND spec/21-app/27-error-code-test-matrix.md AND this array.
    */
    'error_codes' => [
        'AbuseBlocked',
        'AuthForbidden',
        'AuthInvalidCredentials',
        'AuthRefreshRaceLost',
        'AuthRefreshReused',
        'AuthRegistrationClosed',
        'AuthSaltRotationFailed',
        'AuthTokenExpired',
        'AuthUnauthorized',
        'AuthSessionNotFound',
        'AuthSessionAlreadyClosed',

        'AuthzLastAdminProtected',
        // Plan 14 steps 4..8 (v0.609.0..v0.613.0). BR closed-set error codes
        // for feature-flag gate, day-one backfills, ops surface, manifest
        // validator, and encryption/KEK service. Registered together so
        // LaraException::make stays a total function for every BR call site.
        'BackupCorrupt',
        'BackupExportProductionPending',
        'BackupKeyEpochRetired',
        'BackupStorageFailure',
        'BackupVersionMismatch',
        'BackupWorkerFailure',
        'BackupWorkerTransitionFailed',
        'BackupZstdUnavailable',
        'BrBackfillFailed',
        'BrOpsNotYetImplemented',
        'BrOpsQueryFailed',

        'AuthzPermissionDenied',
        'AuthzRoleDenied',
        'AuthzRowScopeDenied',
        'EnvironmentMismatch',
        'FeatureCatalogUnseeded',
        'FeatureNotAvailable',
        'FeatureUnknown',
        'FeatureValueInvalid',
        'IdempotencyConflict',
        'IdempotencyKeyRequired',
        'ImpersonationAlreadyActive',
        'ImpersonationParentSessionInvalid',
        'InvalidJson',

        'LicenseConflict',
        'LicenseExpired',
        'LicenseMachineLimit',
        'LicenseNotFound',
        'LicenseRevoked',
        'LicenseUserLimit',
        // Plan 11 step 13 (v0.406.0). Login-captcha closed-set codes were
        // present in 'error_http_status' since v0.302.0 but never listed
        // here, so the canonical taxonomy diverged from the runtime map.
        // Kept alphabetically grouped with the other Login/Auth codes; see
        // spec/03-error-manage/98-audit-input.md §3 and Plan 11 SS-02.
        'LoginCaptchaInvalid',
        'LoginCaptchaRequired',
        'MachineRebindCooldownActive',
        'MethodNotAllowed',
        'OAuthInvalidClient',
        'OAuthInvalidGrant',
        'OAuthInvalidRequest',
        'OAuthUnsupportedGrantType',
        'PreconditionFailed',
        'PreconditionRequired',
        'PrefixConflict',
        'PrefixForbidden',
        'PrefixInUse',
        'PrefixNotFound',
        'PasswordResetTokenInvalid',

        'QuotaCategoryUnauthorized',
        'QuotaExhausted',
        'QuotaLedgerConflict',
        'RateLimited',
        'RequestIdMissing',
        'ResellerConflict',
        'ResellerInUse',
        'ResellerNotFound',
        // Plan 16 step 58 (v0.563.0). Runtime-config admin surface closed-set.
        // spec/28-runtime-modes/05-admin-runtime-toggle.md §Envelope Codes.
        'RuntimeConfigConflict',
        'RuntimeConfigForbidden',
        'RuntimeConfigInvalidField',
        'RuntimeConfigLocked',
        'RuntimeConfigModeMismatch',
        'RuntimeConfigWriteFailed',
        'ResourceRoleAlreadyAssigned',
        'ResourceRoleNotAssigned',
        'RoleAssignmentNotFound',
        'SerialInvalid',
        'SerialNotFound',
        'SerialRevoked',
        'ServerError',
        'ServiceUnavailable',
        'UnknownServerError',
        'UnsupportedMediaType',
        'UpdateAssetNotFound',
        'UpdateAssetUploadFailed',
        'UpdateAssetVerificationFailed',
        'UpdateChannelForbidden',
        'UpdateChannelUnknown',
        'UpdateChecksumMismatch',
        'UpdateDownloadFailed',
        'UpdateManifestUnavailable',
        'UpdateSignatureUnavailable',

        'UpdateVersionDowngradeBlocked',
        'UserConflict',
        'UserNotFound',
        'ValidationConflict',
        'ValidationFailed',
        'ValidationInputInvalid',
        'ValidationInvalidRole',
        'ValidationInvalidVersion',

        'VerifyHashInvalid',
        'VerifyKeyConsumed',
        'VerifyKeyExpired',
        'VerifyKeyMismatch',
    ],

    /*
    |--------------------------------------------------------------------------
    | ApiErrorCode -> HTTP status (spec/21-app/12-error-taxonomy.md §Canonical codes)
    |--------------------------------------------------------------------------
    | Each code maps to exactly one HTTP status per AC-ERR-001. Codes marked
    | with an asterisk in the spec are client-only synthetics and are given
    | a server-side default of 500 here (they are never emitted by Laravel;
    | listed only so LaraException::make stays a total function).
    */
    'error_http_status' => [
        'AbuseBlocked' => ['status' => 403],
        'AuthForbidden' => ['status' => 403],
        'AuthInvalidCredentials' => ['status' => 401],
        'AuthRefreshRaceLost' => ['status' => 409],
        'AuthRefreshReused' => ['status' => 401],
        'AuthRegistrationClosed' => ['status' => 403],

        'AuthSaltRotationFailed' => ['status' => 500],
        'AuthTokenExpired' => ['status' => 401],
        'AuthUnauthorized' => ['status' => 401],
        'AuthSessionNotFound' => ['status' => 404],
        'AuthSessionAlreadyClosed' => ['status' => 409],
        'AuthzLastAdminProtected' => ['status' => 409],
        'AuthzPermissionDenied' => ['status' => 403],
        'AuthzRoleDenied' => ['status' => 403],
        'AuthzRowScopeDenied' => ['status' => 403],
        'BackupCorrupt' => ['status' => 422],
        'BackupExportProductionPending' => ['status' => 501],
        'BackupKeyEpochRetired' => ['status' => 409],
        'BackupStorageFailure' => ['status' => 500],
        'BackupVersionMismatch' => ['status' => 409],
        'BackupWorkerFailure' => ['status' => 500],
        'BackupWorkerTransitionFailed' => ['status' => 500],
        'BackupZstdUnavailable' => ['status' => 500],
        'BrBackfillFailed' => ['status' => 500],
        'BrOpsNotYetImplemented' => ['status' => 501],
        'BrOpsQueryFailed' => ['status' => 500],
        'EnvironmentMismatch' => ['status' => 409],
        'FeatureCatalogUnseeded' => ['status' => 500],
        'FeatureNotAvailable' => ['status' => 501],
        'FeatureUnknown' => ['status' => 400],
        'FeatureValueInvalid' => ['status' => 400],
        'IdempotencyConflict' => ['status' => 409],
        'IdempotencyKeyRequired' => ['status' => 400],
        'ImpersonationAlreadyActive' => ['status' => 409],
        'ImpersonationParentSessionInvalid' => ['status' => 409],

        'InvalidJson' => ['status' => 400],
        'LicenseConflict' => ['status' => 409],
        'LicenseExpired' => ['status' => 409],
        'LicenseMachineLimit' => ['status' => 409],
        'LicenseNotFound' => ['status' => 404],
        'LicenseRevoked' => ['status' => 409],
        'LicenseUserLimit' => ['status' => 409],
        'LoginCaptchaInvalid' => ['status' => 401],
        'LoginCaptchaRequired' => ['status' => 428],
        'MachineRebindCooldownActive' => ['status' => 409],
        'MethodNotAllowed' => ['status' => 405],
        'OAuthInvalidClient' => ['status' => 401],
        'OAuthInvalidGrant' => ['status' => 400],
        'OAuthInvalidRequest' => ['status' => 400],
        'OAuthUnsupportedGrantType' => ['status' => 400],
        'PreconditionFailed' => ['status' => 412],
        'PreconditionRequired' => ['status' => 428],
        'PrefixConflict' => ['status' => 409],
        'PrefixForbidden' => ['status' => 403],
        'PrefixInUse' => ['status' => 409],
        'PrefixNotFound' => ['status' => 404],
        'PasswordResetTokenInvalid' => ['status' => 400],

        'QuotaCategoryUnauthorized' => ['status' => 403],
        'QuotaExhausted' => ['status' => 409],
        'QuotaLedgerConflict' => ['status' => 500],
        'RateLimited' => ['status' => 429],
        'RequestIdMissing' => ['status' => 400],
        'ResellerConflict' => ['status' => 409],
        'ResellerInUse' => ['status' => 409],
        'ResellerNotFound' => ['status' => 404],
        // Plan 16 step 58 (v0.563.0). Runtime-config admin surface.
        'RuntimeConfigConflict' => ['status' => 412],
        'RuntimeConfigForbidden' => ['status' => 403],
        'RuntimeConfigInvalidField' => ['status' => 422],
        'RuntimeConfigLocked' => ['status' => 423],
        'RuntimeConfigModeMismatch' => ['status' => 422],
        'RuntimeConfigWriteFailed' => ['status' => 500],
        'ResourceRoleAlreadyAssigned' => ['status' => 409],
        'ResourceRoleNotAssigned' => ['status' => 404],
        'RoleAssignmentNotFound' => ['status' => 404],
        'SerialInvalid' => ['status' => 400],
        'SerialNotFound' => ['status' => 404],
        'SerialRevoked' => ['status' => 409],
        'ServerError' => ['status' => 500],
        'ServiceUnavailable' => ['status' => 503],
        'UnknownServerError' => ['status' => 500],
        'UnsupportedMediaType' => ['status' => 415],
        'UpdateAssetNotFound' => ['status' => 404],
        'UpdateAssetUploadFailed' => ['status' => 502],
        'UpdateAssetVerificationFailed' => ['status' => 409],
        'UpdateChannelForbidden' => ['status' => 403],
        'UpdateChannelUnknown' => ['status' => 400],
        'UpdateChecksumMismatch' => ['status' => 409],
        'UpdateDownloadFailed' => ['status' => 500],
        'UpdateManifestUnavailable' => ['status' => 503],
        'UpdateSignatureUnavailable' => ['status' => 404],

        'UpdateVersionDowngradeBlocked' => ['status' => 409],
        'UserConflict' => ['status' => 409],
        'UserNotFound' => ['status' => 404],
        'ValidationConflict' => ['status' => 409],
        'ValidationFailed' => ['status' => 400],
        'ValidationInputInvalid' => ['status' => 400],
        'ValidationInvalidRole' => ['status' => 400],
        'ValidationInvalidVersion' => ['status' => 400],

        'VerifyHashInvalid' => ['status' => 400],
        'VerifyKeyConsumed' => ['status' => 409],
        'VerifyKeyExpired' => ['status' => 409],
        'VerifyKeyMismatch' => ['status' => 400],
    ],


    /*
    |--------------------------------------------------------------------------
    | LedgerAction (spec/21-app/28-audit-action-enum.md)
    |--------------------------------------------------------------------------
    | Written to LicenseLedger.LedgerAction. Includes QuotaAdjusted and
    | QuotaRestored per spec 42 v1.1.0 and spec 48 v1.0.0.
    */
    'ledger_actions' => [
        'LicenseIssued',
        'LicenseRenewed',
        'LicenseRevoked',
        'SerialIssued',
        'SerialRevoked',
        'QuotaAdjusted',
        'QuotaRestored',
        'RoleAssigned',
        'RoleRevoked',
        'ImpersonationStarted',
        'ImpersonationEnded',
        'ImpersonationForceEnded',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency-Key TTL (spec/21-app/11-api-contracts/03-idempotency.md)
    |--------------------------------------------------------------------------
    */
    'idempotency_ttl_seconds' => (int) env('LARA_IDEMPOTENCY_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Impersonation TTL + enums (spec/21-app/46-impersonation.md v1.1.0)
    |--------------------------------------------------------------------------
    | `session_kinds` and `impersonation_end_reasons` mirror the CHECK
    | constraints on the Root `AuthSessions` and `ImpersonationIndex`
    | migrations (2026_07_18_000006, 2026_07_18_000007). Adding a member
    | requires a matching CHECK migration AND a spec 46 version bump.
    */
    'impersonation_ttl_minutes' => (int) env('LARA_IMPERSONATION_TTL_MINUTES', 30),
    'session_kinds' => ['Normal', 'Impersonation', 'ServiceAccount'],
    'impersonation_end_reasons' => ['OperatorEnded', 'Timeout', 'AdminForced'],

    /*
    |--------------------------------------------------------------------------
    | Normal session TTL (spec/21-app/31-auth-session-family.md)
    |--------------------------------------------------------------------------
    | Applies to AuthSessions rows written on login. Refresh flow will
    | extend ExpiresAt; expiry itself lives on the AuthSession row, not on
    | the Sanctum token, so this is the single source of truth.
    */
    'normal_session_ttl_minutes' => (int) env('LARA_NORMAL_SESSION_TTL_MINUTES', 480),

    /*
    |--------------------------------------------------------------------------
    | Remember Me (Plan 09 login modernization)
    |--------------------------------------------------------------------------
    | When LoginRequest carries RememberMe=true, AuthSessionService pins the
    | AuthSession.ExpiresAt (and Sanctum PAT expiry) to this longer window.
    | Default: 30 days. Enforced server-side; client cannot pick the value.
    */
    'remember_me_ttl_minutes' => (int) env('LARA_REMEMBER_ME_TTL_MINUTES', 43200),

    /*
    |--------------------------------------------------------------------------
    | AuthSessions retention (v0.298.0, spec 31)
    |--------------------------------------------------------------------------
    | Root AuthSessions rows with Kind=Normal are hard-deleted once
    | max(EndedAt, ExpiresAt) is older than this many days. Scheduled by
    | AuthSessionsRetentionSweepCommand (daily). Impersonation rows are
    | excluded from the sweep because they anchor audit lineage.
    */
    'auth_sessions_retention_days' => (int) env('LARA_AUTH_SESSIONS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Login CAPTCHA challenge (Plan 09 login modernization)
    |--------------------------------------------------------------------------
    | Stateless HMAC-signed math challenge. Challenge payload is base64url
    | JSON {a,b,op,exp} concatenated with `.` and an HMAC-SHA256 signature
    | using APP_KEY. LoginController re-verifies signature and expiry, then
    | compares the client's Answer against the computed value.
    |
    | `required_after_failed_attempts` counts consecutive failures per
    | (Email + IP) via the cache store; below the threshold the captcha is
    | optional. `challenge_ttl_seconds` bounds replay + solve window.
    */
    'login_captcha' => [
        'challenge_ttl_seconds' => (int) env('LARA_LOGIN_CAPTCHA_TTL', 300),
        'required_after_failed_attempts' => (int) env('LARA_LOGIN_CAPTCHA_THRESHOLD', 2),
        'failure_window_seconds' => (int) env('LARA_LOGIN_CAPTCHA_WINDOW', 900),
    ],


    /*
    |--------------------------------------------------------------------------
    | Self-update (spec/21-app/17-self-update-endpoint.md v1.3.0)
    |--------------------------------------------------------------------------
    | v1.0 pins the rollout channel to Stable. `channel` query param is NOT
    | accepted; Beta enum reserved but unused.
    */
    'self_update' => [
        'channel' => 'Stable',
        'manifest_url' => env('SELF_UPDATE_MANIFEST_URL'),
        'public_key' => env('SELF_UPDATE_PUBLIC_KEY'),
        'signing_key_path' => env('SELF_UPDATE_SIGNING_KEY_PATH'),
        // Closed sets per spec 17 §"Platform enum" and §"v1.0 rollout policy".
        // Adding a member requires bumping spec 17 AND spec 23 schema in the
        // same commit. Case is strict PascalCase; aliases are forbidden.
        'products' => ['lara-cli'],
        'channels' => ['Stable', 'Beta'],
        'platforms' => ['WindowsAmd64', 'LinuxAmd64', 'DarwinArm64'],
        // Stable is anonymous; Beta requires AppBuilder|Admin (spec 17 §"Auth policy summary").
        // v1.0 rollout policy pins the wire channel to Stable at every UI
        // call site; the enum member stays reserved for post-v1.0.
        'manifest_cache_max_age_seconds' => (int) env('SELF_UPDATE_MANIFEST_CACHE_MAX_AGE', 30),
        // Storage root used by the publish path (step 2, next release). Read
        // path resolves URLs via named routes and never hits this directly.
        'asset_storage_root' => env('SELF_UPDATE_ASSET_ROOT', storage_path('app/self-update')),
        // Plan 06 step 71. Console update banner inputs. The banner compares
        // the installed desktop-client version against the resolved manifest
        // server-side (spec 16 §3a + AC-UI-007); the browser never fetches
        // the manifest and never re-verifies Sha256/signature.
        'banner_product' => env('SELF_UPDATE_BANNER_PRODUCT', 'lara-cli'),
        'banner_platform' => env('SELF_UPDATE_BANNER_PLATFORM', 'WindowsAmd64'),
        'installed_client_version' => env('SELF_UPDATE_INSTALLED_VERSION', '0.0.0'),
        'banner_view_update_href' => env('SELF_UPDATE_BANNER_HREF', '/app/update'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature registry (spec/21-app/45-license-features.md v1.0.0 §2)
    |--------------------------------------------------------------------------
    | Closed-set catalog used by App\Support\FeatureValidator on every
    | admin write path (Plan 06 step 31 + step 41). Adding a key requires
    | bumping spec 45 Version AND updating this array in the same commit.
    */
    'feature_registry' => [
        'Modules.Reports'    => ['ValueType' => 'Boolean'],
        'Modules.Api'        => ['ValueType' => 'Boolean'],
        'Limits.MaxUsers'    => ['ValueType' => 'Number', 'IntegerOnly' => true],
        'Limits.MaxProjects' => ['ValueType' => 'Number', 'IntegerOnly' => true],
        'Branding.Watermark' => ['ValueType' => 'Boolean'],
        'Support.Tier'       => ['ValueType' => 'String', 'AllowedValues' => ['Community', 'Standard', 'Priority']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Portal signing keys (Plan 06 step 28)
    |--------------------------------------------------------------------------
    | Map of `KeyId => Base64Secret` used by `SignedRequestMiddleware` to
    | verify HMAC-SHA256 signatures on `/Api/Portal/*` requests. Values
    | are loaded from `PORTAL_SIGNING_KEYS_JSON` (JSON object) in the
    | environment. In production this MUST be populated per-Reseller;
    | leaving it empty locks all Portal endpoints (fail-closed, correct
    | for staging/dev deploys that have not provisioned any keys yet).
    |
    | Format example (env value, one line):
    |   PORTAL_SIGNING_KEYS_JSON={"acme":"BASE64=="}
    */
    'portal_signing_keys' => (function (): array {
        $raw = trim((string) env('PORTAL_SIGNING_KEYS_JSON', ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, static fn ($v): bool => is_string($v) && $v !== '');
    })(),

    /*
    |--------------------------------------------------------------------------
    | Auth rate limits (v0.297.0)
    |--------------------------------------------------------------------------
    | Bucket -> {max_attempts, decay_seconds}. Applied by RateLimitAuthMiddleware.
    | Keyed by IP always, plus SHA-256(Email) when the request carries an Email
    | field so credential-stuffing and per-account brute force are both bounded.
    | Both keys must be under the limit; whichever fills first emits `RateLimited`
    | (429) with `Retry-After`. Values tuned for interactive UX + brute defense.
    */
    'auth_rate_limits' => [
        'login' => ['max_attempts' => 10, 'decay_seconds' => 60],
        'captcha' => ['max_attempts' => 30, 'decay_seconds' => 60],
        'register' => ['max_attempts' => 5, 'decay_seconds' => 300],
        'forgot' => ['max_attempts' => 5, 'decay_seconds' => 900],
        'reset' => ['max_attempts' => 10, 'decay_seconds' => 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint audit allowlist (Plan 10 step 1)
    |--------------------------------------------------------------------------
    | Route names ALLOWED to skip the endpoint parity gate in
    | tests/Feature/Endpoints/EndpointInventoryTest.php. Use sparingly:
    | this is for endpoints whose coverage lives under an integration test
    | that does not mention the controller's short name (for example the
    | HMAC handshake `Ping` endpoints, exercised by HmacSignatureTest via
    | the middleware). Do NOT add rows here to hide missing tests; if a
    | real endpoint has no test, write one instead.
    */
    'endpoint_audit_allowlist' => [
        'api.admin.ping',
        'api.reseller.ping',
        'api.portal.ping',
    ],


    /*
    |--------------------------------------------------------------------------
    | E2E fixtures (Plan 10 step 12)
    |--------------------------------------------------------------------------
    | Env-sourced credentials for the deterministic Admin + EndUser seeded by
    | E2EFixturesSeeder. Guarded by `enabled`: true only when APP_ENV is
    | testing/ci OR when LARA_E2E_SEED=1 is set explicitly. Credentials come
    | from env (never hardcoded); missing values on an enabled run fail fast
    | with a named RuntimeException so the seed never proceeds with a blank
    | password.
    */
    'e2e_fixtures' => [
        'enabled' => (bool) env('LARA_E2E_SEED', in_array(
            (string) env('APP_ENV', 'production'),
            ['testing', 'ci'],
            true,
        )),
        'admin' => [
            'email'    => env('LARA_E2E_ADMIN_EMAIL'),
            'password' => env('LARA_E2E_ADMIN_PASSWORD'),
        ],
        'enduser' => [
            'email'    => env('LARA_E2E_ENDUSER_EMAIL'),
            'password' => env('LARA_E2E_ENDUSER_PASSWORD'),
        ],
        'profile' => env('LARA_E2E_PROFILE', 'default'),
    ],

    // Plan 11 SS-01/step 8: closed-set of substrings that mark a scalar
    // stack-frame argument as sensitive. Matched case-insensitively against
    // (a) the parameter/property key when the arg is an assoc array and
    // (b) the string arg's own content when it clearly looks like a token
    // (>= 20 chars, no whitespace). Redacted values become '***REDACTED***'.
    'trace_redact_keys' => [
        'password', 'passwd', 'secret', 'token', 'apikey', 'api_key',
        'authorization', 'auth', 'cookie', 'session', 'bearer',
        'private_key', 'privatekey', 'refresh_token', 'access_token',
        'client_secret', 'signature', 'hmac', 'otp', 'captcha',
    ],

];



