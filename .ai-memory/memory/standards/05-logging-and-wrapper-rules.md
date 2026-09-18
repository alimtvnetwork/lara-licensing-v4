# Automatic Logging and Query Wrappers

**Version:** 1.0.0
**Updated:** 2026-08-10

- All queries must be wrapped in a dedicated query wrapper in PHP/Python/TS that handles automatic failure logging, reducing scattered logging code.
- Enums must be used in TypeScript rather than string union types (e.g. `"pass" | "fail"`).
- All Enum names must end with the `Type` suffix (e.g. `StatusType`).
- Explicit boolean state checks like `response.isFail` must be used. Never invert success booleans (e.g., avoid `!response.isSuccess`).
- No magic strings or magic numbers unless it is explicitly for the logger (and documented in typing).
