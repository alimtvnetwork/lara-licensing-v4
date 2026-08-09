# Serial Generation

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Format

```
{ResellerPrefix}-{CategoryCode}-{VersionCode}-{Random}
```

Example: `Alim-M-V1-9F3K-2XQ8-77TA`.

| Segment | Rules |
|---------|-------|
| `ResellerPrefix` | 3 to 12 chars, alnum, uppercase enforced on display, reseller-owned. Optional for Admin-issued licenses. |
| `CategoryCode` | Single letter from category enum (`D`, `W`, `M`, `Y`, `L`, `X` for Dev, `K` for Key). |
| `VersionCode` | `V1`, `V2`, etc. matches product major version. |
| `Random` | 4 groups of 4 alnum chars, cryptographic RNG. Configurable length (16, 24, 32 chars). |

## Uniqueness

Enforced by unique index on `Serials.SerialValue`. Retry generation on the vanishingly rare collision, up to 5 attempts, then 500.

## Prefix Ownership

- `Prefixes(PrefixId, ResellerId, PrefixValue, IsActive)`.
- Only the owning `Reseller` or `Admin` can generate serials with a prefix.

## Embedded Metadata

Category and version codes are literal, not encrypted. Reversibility (parse serial → category/version) is a feature; sensitive data must never be embedded.

## Acceptance

- AC-SER-001: Generated serial matches regex `^([A-Z0-9]{3,12}-)?[DWMYLXK]-V\d+(-[A-Z0-9]{4}){3,}$`.
- AC-SER-002: Prefix use by non-owner returns 403.
- AC-SER-003: Collision retry is bounded and observable in logs.
