# Phase 2C — User Management Security Test Plan

Status: TEST PLAN READY — DO NOT RUN AGAINST PRODUCTION

## Authorization
- Unauthenticated request → 401.
- SUPER_ADMIN accessing tenant user endpoint → 403.
- STAFF accessing write actions → 403.
- ADMIN may manage STAFF only.
- NOTARIS may manage ADMIN/STAFF.
- OWNER may manage OWNER/NOTARIS/ADMIN/STAFF.

## Tenant isolation
- User list contains only the authenticated tenant's users.
- Create always uses tenant_id from session, never browser input.
- Update/activate/deactivate/reset-password for another tenant's user ID → 404.
- No cross-tenant user data is returned by any action.

## Account safety
- User cannot change their own role.
- User cannot deactivate their own account.
- The only active OWNER/NOTARIS cannot be demoted to ADMIN/STAFF.
- The only active OWNER/NOTARIS cannot be deactivated.
- Password is never returned by list/update responses.
- Passwords are stored only as password hashes.

## Input/security
- Invalid email/name/role rejected.
- Password must be 12–255 characters.
- Write actions require POST + CSRF.
- Prepared statements are used for database writes/queries.
- Duplicate email fails without exposing database internals.

## Audit
- create → `user.create`
- update → `user.update`
- activate → `user.activate`
- deactivate → `user.deactivate`
- reset password → `user.password_reset`

## Concurrency gate
Before production deployment, test concurrent attempts to demote/deactivate privileged users. The tenant must never be left without an active OWNER/NOTARIS because of a race condition.

## Deployment gate
Do not merge to `main` until the above tests pass in staging against a disposable database with at least two tenants.
