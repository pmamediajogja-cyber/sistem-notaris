# Phase 2B — Canonical SaaS Business Schema

Branch: `phase-2-saas-foundation`

## Decision

The SaaS path uses a new canonical business model rather than exposing legacy tables directly:

`tenants -> clients -> matters -> documents`

Supporting tables:

- `status_history` for auditable workflow transitions.
- `document_storage` for tenant-scoped storage metadata.

## Scope

Migration `006_canonical_saas_schema.sql` is additive. It creates the canonical tables with tenant ownership enforced by foreign keys.

The migration does **not** import legacy records, alter legacy tables, or infer which office owns existing data.

## Tenant isolation rules

1. Every canonical business row carries `tenant_id`.
2. Application queries must derive tenant scope from the authenticated session, never from a browser-supplied tenant ID.
3. Child records must be checked against the authenticated tenant before access or mutation.
4. Storage keys are metadata only; physical files must live under tenant-controlled storage and require authorization before serving.
5. Audit logging remains required for sensitive mutations and document operations.

## Data model

- `clients`: customer/person/entity master data for one office.
- `matters`: a notarial/PPAT case or service belonging to one client.
- `documents`: file metadata attached to a matter; no document bytes are stored in SQL.
- `status_history`: append-only workflow history for a matter.
- `document_storage`: controlled storage-provider/key mapping for a document.

## Legacy boundary

Legacy `tabel_invoice`, `tabel_pelacakan`, and `tabel_agenda` remain transitional. Their existing nullable `tenant_id` fields must only be populated from an auditable mapping.

The old Dian/VIP path is removed from the SaaS architecture. It is not part of Phase 2B and must not be imported, reused, or exposed through SaaS endpoints.

## Deployment safety

- Do not run the migration against production blindly.
- Take a database backup first.
- Apply it in a staging/test database and verify foreign keys and indexes.
- Build application CRUD against the canonical schema before importing legacy data.
- Perform legacy import only through an explicit, reviewable mapping process.
- Keep `main` unchanged until Phase 2B application tests pass.
