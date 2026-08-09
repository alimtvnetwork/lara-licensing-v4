# Licensing Portal v1 - Backend (Laravel 11)

Scaffolded by Plan 06 step 1. Do not run this in production until Plan 06 completes.

## Local setup

```
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

## Layout

- `app/` PSR-4 root (`App\`).
- `bootstrap/app.php` registers global middleware (`RequestId`, `IdempotencyKey`, `Etag`) per Plan 06 steps 7-9.
- `routes/api.php` will host `/Api/Admin/*`, `/Api/Reseller/*`, `/Api/Portal/*`, `/Api/SelfUpdate/Manifest`.
- `database/migrations/` holds Root DB and Shard DB migrations (Plan 06 steps 11-19).

## Coding standard

PSR-12 + Laravel Pint defaults. No em dashes in code or comments. Errors follow spec/21-app/12-error-taxonomy.md: catch, log with `errorId`, rethrow. Never `catch {}`.
