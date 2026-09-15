# Phase 2 — SaaS Foundation / Tenant Isolation

Branch: `phase-2-saas-foundation`

## Goal

Move the legacy office workflows toward safe multi-tenant access without guessing ownership of existing records.

## Implemented

- Added `005_phase2_business_isolation.sql` as a controlled schema-preparation migration.
- Agenda API requires an authenticated tenant user and scopes reads/writes by `tenant_id`.
- Agenda writes use a tenant-scoped transaction; there is no global `TRUNCATE`.
- Pelacakan API requires an authenticated tenant user and scopes reads, writes, deletes, and locks by `tenant_id`.
- Pelacakan lock ownership is derived from the authenticated user rather than a browser-supplied `client_id`.
- Invoice API requires tenant ownership for load/create/update/delete operations.
- Business endpoints no longer create/ALTER tables during requests.
- Business mutations create audit-log entries.
- Phase 2A.2 completed the legacy workflow/data-boundary audit in `docs/PHASE2A2_AUDIT.md`.
- Repository ignore rules were restored to a conventional root `.gitignore`.

## Phase 2A.2 conclusions

- Existing legacy records must not be assigned to a tenant by guesswork.
- Agenda requires a surrogate `agenda_id` because the legacy primary key was `tanggal`.
- The current `akta.html` is a client-side DOCX proofreader, not evidence of a persistent `akta` database table.
- `foto_patok/*` must not become shared SaaS private storage; future uploads require tenant-scoped storage metadata and authorization.
- `api_pelacakan_dian.php` and `koneksi_dian.php` remain outside the SaaS path until a separate VIP migration is designed and tested.
- The long-term SaaS business model should be canonical `clients -> matters/cases -> documents`, with invoices and status history linked to the case/client, instead of exposing unknown legacy tables directly.

## Not yet safe to deploy

1. Existing legacy records still need an explicit tenant mapping before final constraints are applied.
2. `tenant_id` remains nullable until mapping is verified.
3. Do not run tenant schema migrations against production blindly.
4. Frontends calling changed endpoints must use the authenticated session and CSRF token.
5. Foreign keys and `NOT NULL` constraints should be added only after data mapping/validation.
6. Document uploads require tenant-scoped storage and authorization before SaaS production.

## Rollout order

1. Backup production database.
2. Inventory table structures and row counts.
3. Identify which legacy rows belong to which office.
4. Create/verify tenant records.
5. Backfill `tenant_id` only from an auditable mapping.
6. Validate zero unexpected NULL tenant IDs.
7. Apply final `NOT NULL` + foreign-key constraints.
8. Switch frontend calls to authenticated tenant APIs.
9. Test cross-tenant access explicitly.
10. Only then consider merging into `main`.
