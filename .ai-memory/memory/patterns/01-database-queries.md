# Database Queries and Boolean Flags

When making a query in PHP (especially via Eloquent), use the `DbQuery` wrapper to provide automatic logging and standard success/failure boolean properties.

## Rule: No Negated Booleans

Never use `if (!$exists)` or similar negations in `if` statements, per the coding guidelines. Instead, use the wrapper or extract the negated result into an `$isFailed` variable.

## The Wrapper Pattern

When checking for the existence of records or fetching a single record, use the wrapper to handle the query safely and yell (log) if it fails.

### Example
Instead of:
```php
$exists = Reseller::query()->whereKey($tenantId)->exists();
$isFailed = !$exists;
if ($isFailed) {
    throw NotFoundException::notFound(...);
}
```

Use:
```php
$result = DbQuery::run(
    fn() => Reseller::query()->whereKey($tenantId)->exists(),
    "Reseller check for tenant {$tenantId}"
);

if ($result->isFailed) {
    throw NotFoundException::notFound(...);
}

// Access data if needed
// $row = $result->data;
```

This ensures we reduce boilerplate logging while strictly adhering to the Boolean naming guidelines (using `isSuccess` and `isFailed`) and eliminating negated `if (!...)` conditions.
