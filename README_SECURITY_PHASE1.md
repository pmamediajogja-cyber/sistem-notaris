# Phase 1 — Security & SaaS Foundation

This branch contains the non-breaking foundation for turning Sistem Notaris into a multi-tenant SaaS for the first 10 offices.

## Current status

- Environment-based configuration template
- Secure PHP session foundation
- Authentication helper
- CSRF helper
- API guard helper
- Audit log helper
- Users schema
- Tenants schema
- Audit logs schema

## Important deployment rule

Do **not** copy `.env.example` to production with the sample password. Create a private production `.env` outside Git and use a dedicated database user with only the required privileges.

Do **not** merge this branch to `main` until the existing frontend/API contract has been migrated and regression-tested.

## Tenant isolation rule

Every business record must eventually belong to exactly one tenant. The tenant must be derived from the authenticated session, never from a client-supplied `tenant_id`.

Platform administrators are separate from office users. Platform support access to office data must be explicit and auditable.

## Next steps

1. Add database-backed login page and logout route.
2. Migrate invoice API to the authentication guard.
3. Migrate tracking API to the authentication guard and tenant scope.
4. Migrate agenda API to the authentication guard and tenant scope.
5. Audit `koneksi_dian.php` and legacy data before any tenant migration.
6. Add rate limiting and production security configuration.
7. Run regression tests and open a PR to `main`.
