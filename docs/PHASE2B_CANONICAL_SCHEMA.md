# Phase 2B — Canonical SaaS Business Schema

Branch: `phase-2-saas-foundation`

## Decision

The SaaS path uses a new canonical business model rather than exposing legacy tables directly:

`tenants -> clients -> matters -> documents`

Supporting tables:

- `status_history` for auditable workflow transitions.
- `document_storage` for tenant-scoped storage metadata.

## Scope

Migration `006_canonical_saas_schema.sql` is additive. It creates the canonical tables with tenant ownership enforced by foreign keys. Tenant-scoped APIs have now been added for clients, matters, status history, and documents.

The migration does **not** import legacy records, alter legacy tables, or infer which office owns existing data.

## Tenant isolation rules

1. Every canonical business row carries `tenant_id`.
2. Application queries derive tenant scope from the authenticated session, never from a browser-supplied tenant ID.
3. Child records are checked against the authenticated tenant before access or mutation.
4. Document physical storage uses a server-side private root and generated storage keys; clients cannot choose another tenant's storage path.
5. Sensitive mutations and document operations are audit logged.
6. Document deletion is soft deletion; physical cleanup can be handled later by a controlled retention job.

## Data model

- `clients`: customer/person/entity master data for one office.
- `matters`: a notarial/PPAT case or service belonging to one client.
- `documents`: file metadata attached to a matter; document bytes are stored outside SQL.
- `status_history`: append-only workflow history for a matter.
- `document_storage`: controlled storage-provider/key mapping for a document.

## API boundary

- `api_clients.php`: tenant-scoped client list/create/update/deactivate.
- `api_matters.php`: tenant-scoped matter list/create/update/close.
- `api_status_history.php`: tenant-scoped status history and transactional status changes.
- `api_documents.php`: tenant-scoped document listing/upload/soft-delete, with MIME validation, 15 MB limit, random storage keys, SHA-256 metadata, and private storage root.

All write operations require POST + CSRF. Tenant IDs are never accepted from the browser.

## Legacy boundary

Legacy `tabel_invoice`, `tabel_pelacakan`, and `tabel_agenda` remain transitional. Their existing nullable `tenant_id` fields must only be populated from an auditable mapping.

The old Dian/VIP path remains outside the shared SaaS request path and must not be imported, reused, or exposed through SaaS endpoints.

## Deployment safety

- Do not run the migration against production blindly.
- Take a database backup first.
- Apply it in a staging/test database and verify foreign keys and indexes.
- Build and test application CRUD against the canonical schema before importing legacy data.
- Configure `STORAGE_ROOT` to a directory outside the public web root.
- Test cross-tenant reads, writes, updates, deletes, status changes, and document access before production.
- Perform legacy import only through an explicit, reviewable mapping process.
- Keep `main` unchanged until Phase 2B application tests pass.
