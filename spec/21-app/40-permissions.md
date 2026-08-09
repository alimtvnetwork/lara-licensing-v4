# Permissions Catalog

**Version:** 1.0.0
**Status:** stable

Canonical registry of fine-grained permissions (PascalCase) used across Lara.

| Permission Key | Description | Default Roles |
| :--- | :--- | :--- |
| Licenses.Create | Create new licenses. | admin, reseller |
| Licenses.Read | View license details and lists. | admin, reseller, support, auditor |
| Licenses.Update | Modify existing license metadata (CustomerName, etc). | admin, reseller |
| Licenses.Revoke | Revoke or suspend licenses. | admin, reseller |
| Serials.Issue | Generate new serial numbers for licenses. | admin, reseller |
| Serials.Lookup | Lookup license by serial number. | admin, reseller, support, portal |
| Resellers.Manage | Create, update, and manage resellers. | admin |
| Prefixes.Manage | Manage license serial prefixes. | admin |
| Users.Manage | Manage users and their status. | admin |
| Roles.Assign | Assign roles to users. | admin |
| Quotas.Approve | Approve or deny quota increase requests. | admin |
| Updates.Publish | Publish new application updates to the manifest. | admin |
| Audit.Read | Read the global or scoped audit log. | admin, auditor |

## Constraints

- **Case Sensitivity:** Permission keys MUST be PascalCase.
- **Forbidden Synonyms:**
  - Do not use `create_license` or `license:create`. Use `Licenses.Create`.
  - Do not use `admin_only`. Map to a specific functional permission.
- **Admin Override:** Users with the `admin` role implicitly possess all permissions.
