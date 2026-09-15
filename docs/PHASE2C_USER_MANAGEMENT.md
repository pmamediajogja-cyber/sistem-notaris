# Phase 2C — User & Staff Management

Status: IMPLEMENTED ON `phase-2-saas-foundation` — NOT MERGED TO `main`

## Scope

Tenant-scoped management of office users. The platform administrator remains outside this endpoint.

Endpoint: `api_users.php`

Supported actions:

- `GET api_users.php?action=list`
- `POST api_users.php?action=create`
- `POST api_users.php?action=update`
- `POST api_users.php?action=set_active`
- `POST api_users.php?action=reset_password`

All write actions require an authenticated tenant user and CSRF validation.

## Role matrix

| Actor | Can manage |
|---|---|
| OWNER | OWNER, NOTARIS, ADMIN, STAFF |
| NOTARIS | ADMIN, STAFF |
| ADMIN | STAFF |
| STAFF | none |
| SUPER_ADMIN | not through tenant endpoint |

The actor cannot change their own role or deactivate their own account.

The last active OWNER/NOTARIS in a tenant cannot be deactivated. This prevents accidental loss of office administration access.

## Security rules

1. `tenant_id` is always derived from the authenticated session; it is never accepted from the browser as an authority field.
2. User listing is restricted by `tenant_id`.
3. Target users are re-checked against the actor's tenant before mutation.
4. Passwords are accepted only for creation/reset and are stored with `password_hash()`; password hashes are never returned by the API.
5. Email remains subject to the existing global `users.email` uniqueness constraint.
6. Roles are allowlisted: `OWNER`, `NOTARIS`, `ADMIN`, `STAFF`.
7. SUPER_ADMIN accounts cannot be reached through the tenant endpoint because platform accounts have no tenant membership.
8. Mutations write audit events.
9. No document contents or private storage access is exposed by this endpoint.

## Before production

- Add automated cross-tenant authorization tests for every action.
- Test role escalation attempts for every actor role.
- Test last OWNER/NOTARIS protection.
- Test duplicate email and inactive-account behavior.
- Test CSRF, session expiry, and rate limiting at the deployment layer.
- Do not run against production until the Phase 2B security test plan passes.
