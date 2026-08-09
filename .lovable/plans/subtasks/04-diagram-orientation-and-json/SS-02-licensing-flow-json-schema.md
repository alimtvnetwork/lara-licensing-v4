# SS-02: licensing-flow.json shape

Parent: 04-diagram-orientation-and-json
Slug: licensing-flow-json-schema
Status: pending
Created: 2026-07-17

## Purpose
Define the JSON companion for `spec/21-app/diagrams/licensing-flow.mmd` so tooling and downstream AIs consume the communication flow without parsing Mermaid.

## Filename
`spec/21-app/diagrams/licensing-flow.json` (colocated, searchable by name).

## Shape (PascalCase, per AF-CG-007)
```
{
  "Version": "1.0.0",
  "Title": "Licensing communication flow",
  "Actors": [
    { "Id": "EndUser",  "Label": "End-user App",  "Column": 1 },
    { "Id": "Reseller", "Label": "Reseller UI",   "Column": 2 },
    { "Id": "Admin",    "Label": "Admin UI",      "Column": 3 },
    { "Id": "API",      "Label": "Lara API",      "Column": 4 },
    { "Id": "DB",       "Label": "Split-DB",      "Column": 5 },
    { "Id": "Audit",    "Label": "AuditLog",      "Column": 6 }
  ],
  "Sections": [
    {
      "Id": "Verify",
      "Title": "3. Verify, end-user runtime lookup",
      "Steps": [
        {
          "From": "EndUser",
          "To": "API",
          "Method": "GET",
          "Path": "/Serials/{SerialValue}",
          "Headers": ["Authorization", "X-Request-Id"],
          "Branches": [
            { "Case": "found and valid",   "Response": { "Status": 200, "Body": { "IsRevoked": false } } },
            { "Case": "revoked or inactive","Response": { "Status": 200, "Body": { "IsRevoked": true  } } },
            { "Case": "missing",            "Response": { "Status": 404, "ErrorCode": "SerialNotFound" } }
          ]
        }
      ]
    }
  ],
  "Invariants": [
    "Every request carries X-Request-Id, echoed on response",
    "Idempotency-Key required on POST /Licenses/{Id}/Serials",
    "AuditLog receives { RequestId, ActorUserId, Action, Decision } per spec/21-app/22-log-line-contract.md"
  ]
}
```

Populate all four sections from the current `.mmd`: Issuance, SerialIssuance, Verify, Revocation.
