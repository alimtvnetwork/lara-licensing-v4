# Issue 03: Verify path drift between licensing-flow and legacy verify diagrams

Status: closed
Closed: 2026-07-17
Resolution: End-user verify uses the POST /Verify/Serial -> /Verify/Hash -> /Verify/Final handshake (spec/21-app/11-api-contracts/03-verification-contracts.md). GET /Serials/{SerialValue} is an Admin/Reseller-scoped lookup only (spec/21-app/10-endpoints.md line 52) and is NOT the end-user verify path. Diagrams spec/21-app/diagrams/licensing-flow.{mmd,json} updated to v1.1.0 to show the handshake as section 1 and demote GET /Serials/{SerialValue} to a separate Admin/Reseller lookup section. Client code src/lib/lara-serial.ts#lookupSerial is unchanged: it is the correct caller of the GET lookup (used by Admin/Reseller UI), not an end-user verify path.
Created: 2026-07-17

## Symptom
`spec/21-app/diagrams/licensing-flow.mmd` (and its JSON companion) declares the end-user runtime lookup as `GET /Serials/{SerialValue}`. `spec/23-app-db/03-oauth-client-credentials.mmd` and `spec/23-app-db/09-verify-sequence.mmd` still show `POST /Verify/Serial` as the first step of the same lookup.

## Question to resolve
Is `GET /Serials/{SerialValue}` a distinct read-only lookup that coexists with the `POST /Verify/Serial` -> `/Verify/Hash` -> `/Verify/Final` handshake (activation flow), or has the handshake's first step been replaced by the GET?

## Impact
Blind AI implementing verify has two contradictory contracts. Cannot ship either without picking one arbitrarily.

## Related
- spec/21-app/diagrams/licensing-flow.mmd
- spec/21-app/diagrams/licensing-flow.json
- spec/23-app-db/03-oauth-client-credentials.mmd
- spec/23-app-db/09-verify-sequence.mmd
- spec/21-app/10-endpoints.md
- spec/21-app/11-api-contracts/03-verification-contracts.md

## Proposed resolution (pending user confirmation)
Both endpoints coexist: `GET /Serials/{SerialValue}` is an anonymous cache-friendly lookup; `POST /Verify/Serial` remains step 1 of the machine-binding handshake for licensed apps. If confirmed, add a note to `licensing-flow.mmd` clarifying this and leave the sibling diagrams as-is.
