# Phase 2 — SaaS Foundation / Tenant Isolation

Branch: `phase-2-saas-foundation`

## Goal

Move the legacy office workflows toward safe multi-tenant access without guessing ownership of existing records.

## Implemented

- Added `005_phase2_business_isolation.sql` as a controlled schema-preparation migration.
- Agenda API now requires an authenticated tenant user.
- Agenda reads are scoped by `tenant_id`.
- Agenda writes require POST + CSRF and only replace the current tenant's rows; no global `TRUNCATE`.
- Pelacakan API now requires an authenticated tenant user for business actions.
- Pelacakan reads, writes, deletes, and locks are scoped by `tenant_id`.
- Lock ownership is derived from the authenticated user rather than a browser-supplied `client_id`.
- Business endpoints no longer create/ALTER tables during requests.
- Business mutations create audit-log entries.

## Not yet safe to deploy

1. Existing legacy records still need an explicit tenant mapping.
2. `tenant_id` is intentionally nullable until the mapping is verified.
3. Do not run the tenant schema migration against production blindly.
4. `api_pelacakan_dian.php` and `koneksi_dian.php` remain legacy/VIP code and require a separate audit before being enabled in SaaS.
5. Frontends calling the changed endpoints must be updated to send the authenticated session and CSRF token.
6. Foreign keys and `NOT NULL` constraints should be added only after data mapping is complete.

## Rollout order

1. Backup production database.
2. Inventory table structures and row counts.
3. Identify which legacy rows belong to which office.
4. Create/verify tenant records.
5. Backfill `tenant_id` with an auditable mapping.
6. Validate zero unexpected NULL tenant IDs.
7. Apply final NOT NULL + foreign-key constraints.
8. Switch frontend calls to authenticated tenant APIs.
9. Test cross-tenant access explicitly.
10. Only then consider merging into `main`.
