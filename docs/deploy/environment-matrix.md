# Environment Variables Matrix (cPanel Deploy)

When deploying Lara Licensing to a cPanel/WHM environment, map the following `.env` keys according to your database and application configuration.

## Core Application
| Key | Example / Default | Description |
|-----|-------------------|-------------|
| `APP_NAME` | `Lara Licensing` | The name of your application. |
| `APP_ENV` | `production` | Must be `production` for live deployments. |
| `APP_KEY` | `base64:...` | The application encryption key (generate via `php artisan key:generate`). |
| `APP_DEBUG` | `false` | Must be `false` in production to prevent leaking stack traces. |
| `APP_URL` | `https://api.yourdomain.com` | The public-facing URL of the backend (cPanel addon domain or subdomain). |

## Root Database (Central Admin)
This database holds global data: Users, Roles, Resellers, and Prefixes.
| Key | cPanel Mapping | Description |
|-----|----------------|-------------|
| `DB_ROOT_CONNECTION` | `pgsql` | Lara Licensing requires PostgreSQL. cPanel must have PgSQL enabled. |
| `DB_ROOT_HOST` | `127.0.0.1` | Usually localhost on shared hosting. |
| `DB_ROOT_PORT` | `5432` | Default PgSQL port. |
| `DB_ROOT_DATABASE` | `cpaneluser_root` | The database name created in cPanel PostgreSQL databases. |
| `DB_ROOT_USERNAME` | `cpaneluser_lara` | The database user. |
| `DB_ROOT_PASSWORD` | `...` | The database user's password. |

## Shard Template (Tenant Databases)
The application dynamically routes queries to reseller-specific databases. The `{reseller}` token is replaced at runtime.
| Key | cPanel Mapping | Description |
|-----|----------------|-------------|
| `DB_SHARD_TEMPLATE_CONNECTION`| `pgsql` | Same connection driver as root. |
| `DB_SHARD_TEMPLATE_HOST` | `127.0.0.1` | Usually localhost. |
| `DB_SHARD_TEMPLATE_PORT` | `5432` | Default PgSQL port. |
| `DB_SHARD_TEMPLATE_DATABASE`| `cpaneluser_shard_{reseller}`| Template for shard database names. |
| `DB_SHARD_TEMPLATE_USERNAME`| `cpaneluser_lara` | Usually the same user as root, granted access to all shard DBs. |
| `DB_SHARD_TEMPLATE_PASSWORD`| `...` | The database user's password. |

## Security & Cache
| Key | Example / Default | Description |
|-----|-------------------|-------------|
| `CACHE_STORE` | `database` | Defaults to database for zero-config cPanel deployments. Redis is supported if available. |
| `SESSION_DRIVER` | `database` | Stores web sessions. |
| `QUEUE_CONNECTION` | `database` | For background jobs (e.g., backup workers). |
| `LARA_IDEMPOTENCY_TTL` | `86400` | Idempotency key retention time in seconds (24 hours). |
| `LARA_IMPERSONATION_TTL_MINUTES` | `30` | Time before an admin impersonation session expires. |

## Updates & Telemetry
| Key | Example / Default | Description |
|-----|-------------------|-------------|
| `SELF_UPDATE_MANIFEST_URL` | `https://updates.../manifest.json` | The URL for retrieving application updates. |
| `SELF_UPDATE_PUBLIC_KEY` | `...` | Ed25519 public key to verify update signatures. |
| `SELF_UPDATE_SIGNING_KEY_PATH` | `/var/keys/private.pem` | (Optional) Path to the private key for signing updates locally. |

---
*Note: Make sure to clear the config cache (`php artisan config:cache` or via web artisan tools if CLI is unavailable) after modifying the `.env` file on cPanel.*
