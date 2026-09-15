# Phase 2B — Security Test Plan

Status: TEST PLAN READY — DO NOT RUN AGAINST PRODUCTION
Branch: `phase-2-saas-foundation`

## Goal

Prove tenant isolation and request protections before the canonical SaaS migration is used in production.

## Test fixtures

Create a dedicated staging database with at least:

- Tenant A and Tenant B.
- One active non-SUPER_ADMIN user in each tenant.
- One client in each tenant.
- One matter in each tenant.
- One document in each matter.
- Valid CSRF token for each authenticated session.

Never use real client documents for these tests.

## Authentication and CSRF

| Test | Expected |
|---|---|
| Unauthenticated request to `api_clients.php` | HTTP 401/403 |
| Unauthenticated request to `api_matters.php` | HTTP 401/403 |
| Unauthenticated request to `api_status_history.php` | HTTP 401/403 |
| Unauthenticated request to `api_documents.php` | HTTP 401/403 |
| POST without CSRF | Rejected |
| POST with invalid CSRF | Rejected |
| Valid authenticated POST + CSRF | Accepted when payload is valid |
| Browser-supplied `tenant_id` differs from session tenant | Ignored; session tenant remains authoritative |

## Tenant isolation

For every test below, authenticate as Tenant A and attempt to access Tenant B data.

1. List clients: Tenant B client must never appear.
2. Update Tenant B client by ID: must not modify it.
3. Deactivate Tenant B client by ID: must not modify it.
4. List matters: Tenant B matter must never appear.
5. Update Tenant B matter by ID: must not modify it.
6. Close Tenant B matter by ID: must not modify it.
7. Read Tenant B status history: must not return it.
8. Change Tenant B matter status: must be rejected.
9. Upload a document to Tenant B matter: must be rejected.
10. List documents for Tenant B matter: must be rejected or return no data.
11. Soft-delete Tenant B document: must not modify it.

## Relationship integrity

1. Tenant A matter referencing Tenant B client must be rejected.
2. Tenant A document referencing Tenant B matter must be rejected.
3. Tenant A status-history entry referencing Tenant B matter must be rejected.
4. Tenant A document-storage row referencing Tenant B document must be rejected by the composite foreign key.
5. Tenant A may assign a matter only to an active non-SUPER_ADMIN user belonging to Tenant A.
6. Attempting to assign a Tenant B user must be rejected.
7. Attempting to assign a SUPER_ADMIN must be rejected.
8. Attempting to assign an inactive user must be rejected.

## Input validation

- Invalid numeric IDs are rejected.
- Empty matter title is rejected.
- Oversized text fields are rejected.
- Unsupported matter status is rejected.
- Invalid date strings are rejected.
- Document uploads over 15 MB are rejected.
- Unsupported document MIME types are rejected.
- Uploaded document filenames cannot control the physical storage path.
- Storage keys are generated server-side and cannot be supplied by the browser.

## Document storage checks

- `STORAGE_ROOT` must be outside the public web root.
- Tenant A storage path must never resolve to Tenant B storage.
- Stored filenames must be random server-generated keys.
- Physical files must not be world-readable.
- A future download endpoint must authorize by both document ID and authenticated tenant before reading the file.
- A future download endpoint must resolve the final path safely under `STORAGE_ROOT` and reject traversal outside it.

## Audit checks

Verify that successful business mutations create audit records with:

- session tenant ID;
- authenticated user ID;
- action;
- module;
- record ID;
- IP address where available;
- user agent where available.

Also verify that failed cross-tenant attempts do not create misleading successful mutation records.

## Deployment gate

Do not merge to `main` and do not run migration `006_canonical_saas_schema.sql` in production until the staging tests above pass.

Record the test date, database version, PHP version, result, and any remediation commit before approving the merge.
