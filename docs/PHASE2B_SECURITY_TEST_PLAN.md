# Phase 2D — Security Test Plan

Status: TEST PLAN READY — DO NOT RUN AGAINST PRODUCTION
Branch: `phase-2d-ui-users`

## Goal

Prove tenant isolation, authentication/session invalidation, CSRF protection, role hierarchy, and safe cross-origin behavior before promotion to `main`.

## Test fixtures

Create a dedicated staging database with at least:

- Tenant A and Tenant B.
- One active non-SUPER_ADMIN user in each tenant.
- Two active OWNER/NOTARIS managers in Tenant A for last-manager race tests.
- One client in each tenant.
- One matter in each tenant.
- One document in each matter.
- Valid CSRF token for each authenticated session.

Never use real client documents for these tests.

## Authentication and session

| Test | Expected |
|---|---|
| Unauthenticated request to tenant API | HTTP 401 |
| Invalid login credentials | Generic HTTP 401; no account enumeration |
| Valid login | HTTP 200 and authenticated session established |
| Session ID after successful login | Regenerated; pre-login session is not reusable |
| Deactivated user calls tenant API with an existing session | HTTP 401; session is invalidated |
| Role changed while session remains open | Next API request uses the current database role, not the stale session role |
| Tenant changed while session remains open | Next API request uses the current database tenant, not stale session tenant |
| Logout | Session becomes unusable |

## CSRF

| Test | Expected |
|---|---|
| POST without CSRF | Rejected |
| POST with invalid CSRF | Rejected |
| Valid authenticated POST + CSRF | Accepted when payload is valid |
| CSRF token after session regeneration | Token belongs to the new session |
| Browser-supplied `tenant_id` differs from session tenant | Ignored; session tenant remains authoritative |

## CORS / cross-origin session

Only test this when `ALLOWED_ORIGIN` is configured for the exact frontend origin and HTTPS is used.

| Test | Expected |
|---|---|
| OPTIONS from exact allowed origin | HTTP 204 with credentials and exact allowlisted origin |
| OPTIONS from another origin | HTTP 403 |
| Credentialed request from allowed origin | Allowed; no wildcard `*` origin |
| Request from untrusted origin | No permissive CORS headers |
| Write from allowed origin without CSRF | Rejected |

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
12. Update Tenant B user: must be rejected.
13. Deactivate Tenant B user: must be rejected.
14. Reset Tenant B user password: must be rejected.

## User privilege and last-manager protection

1. OWNER may manage OWNER/NOTARIS/ADMIN/STAFF according to the defined hierarchy.
2. NOTARIS may manage ADMIN/STAFF only.
3. ADMIN may manage STAFF only.
4. A user cannot change their own role through the management API.
5. A user cannot deactivate their own account.
6. A sole active OWNER/NOTARIS cannot be demoted or deactivated.
7. Two concurrent attempts to remove the last active manager must not both succeed.
8. A failed privileged change must not leave the transaction partially committed.
9. Password reset cannot target the actor's own account through the privileged reset endpoint.

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
- Download authorization must require both document ID and authenticated tenant before reading the file.
- Download path resolution must stay safely under `STORAGE_ROOT` and reject traversal outside it.

## Audit checks

Verify that successful business mutations create audit records with session tenant ID, authenticated user ID, action, module, record ID, IP address where available, and user agent where available.

Also verify that failed cross-tenant attempts do not create misleading successful mutation records.

## Deployment gate

Do not merge to `main` and do not run migration `006_canonical_saas_schema.sql` in production until the staging tests above pass.

Record the test date, database version, PHP version, result, and remediation commit before approving the merge.
