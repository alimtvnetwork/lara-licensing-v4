# Release Notes - v0.690.0

## Fluid UI & cPanel Release (Plan 09)

This release completes Plan 09, introducing major UI improvements and preparing the application for deployment to shared hosting environments like cPanel.

### Key Highlights
- **License Wizard**: Replaced the static license creation form with a dynamic 5-step stepper wizard (Reseller, Tier, Features, Environment, Confirm) featuring integrated quota preflight.
- **API Documentation**: Integrated `l5-swagger` into the backend. Added a new `admin.api-docs` view to the frontend for SuperAdmins and Admins to browse the API contract interactively via an embedded Swagger UI iframe.
- **Swagger Parity Enforcement**: Introduced `check-swagger-parity.py` in our CI pipelines to ensure all registered API routes are properly annotated with OpenAPI docs.
- **Environment Matrix Documentation**: Created `docs/deploy/environment-matrix.md` to map standard `.env` keys for shared hosting configurations (e.g., cPanel/WHM PostgreSQL setups).

### Housekeeping
- Old superseded plans (05, 06, 07, 12) have been archived into `.lovable/plans/completed/`.
- Repaired minor regressions and executed a clean build of all front-end assets.
