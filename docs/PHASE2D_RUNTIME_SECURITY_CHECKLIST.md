# Phase 2D — Runtime Security Checklist

Status: READY FOR STAGING EXECUTION
Branch: `phase-2d-ui-users`

## Purpose

Validate the Phase 2D authentication, session, CSRF, CORS, tenant isolation, and user-management controls against a dedicated staging deployment.

Do not run these checks against production or real client data.

## Staging prerequisites

- HTTPS staging URL.
- Dedicated staging database.
- `ALLOWED_ORIGIN` set to the exact frontend origin.
- `STORAGE_ROOT` outside the public web root.
- Tenant A and Tenant B fixtures.
- One active OWNER/NOTARIS plus lower-privilege users in each tenant.
- Tenant B client, matter, document, and user IDs.
- cURL or equivalent HTTP client with cookie-jar support.

## 1. Authentication / session

- [ ] Unauthenticated API request returns 401.
- [ ] Valid login returns authenticated session and CSRF token.
- [ ] Invalid password returns 401 without revealing account existence.
- [ ] Session ID changes after successful login.
- [ ] Authenticated API reads the current database role/tenant instead of trusting stale session values.
- [ ] Deactivated user loses API access on the next request.
- [ ] Logout destroys the authenticated session.
- [ ] A previously issued session cookie cannot restore the logged-out session.

## 2. CSRF

For every write endpoint:

- [ ] Missing CSRF token is rejected.
- [ ] Invalid CSRF token is rejected.
- [ ] Valid CSRF token is accepted for valid authenticated requests.
- [ ] GET cannot perform a state-changing action.
- [ ] CSRF token is not accepted from an unrelated session.

## 3. CORS / cross-origin

From the configured frontend origin:

- [ ] Credentialed request receives exact `Access-Control-Allow-Origin`.
- [ ] `Access-Control-Allow-Credentials: true` is present only for the allowlisted origin.
- [ ] Preflight allows only the required methods/headers.
- [ ] Unknown Origin does not receive credentialed CORS access.
- [ ] Wildcard `Access-Control-Allow-Origin: *` is never emitted for credentialed requests.
- [ ] If frontend and API are deployed same-origin through a reverse proxy, CORS is unnecessary and cookies remain same-site.

## 4. Tenant isolation

Authenticate as Tenant A and use Tenant B identifiers:

- [ ] Tenant B clients are not listed.
- [ ] Tenant B matters are not listed or modified.
- [ ] Tenant B documents are not listed, uploaded to, downloaded, or deleted.
- [ ] Tenant B status history is not readable or writable.
- [ ] Tenant B users cannot be updated, deactivated, or have passwords reset.
- [ ] Browser-supplied `tenant_id` cannot switch the effective tenant.
- [ ] Matter/client relationships reject cross-tenant references.
- [ ] Matter assignment rejects users from another tenant.
- [ ] Matter assignment rejects inactive users.
- [ ] Matter assignment rejects `SUPER_ADMIN`.

## 5. Privilege escalation

- [ ] STAFF cannot manage users.
- [ ] ADMIN can manage STAFF only.
- [ ] NOTARIS can manage ADMIN/STAFF only.
- [ ] OWNER can manage OWNER/NOTARIS/ADMIN/STAFF.
- [ ] A user cannot change their own role through the management endpoint.
- [ ] A user cannot deactivate their own account through the management endpoint.
- [ ] A lower role cannot promote another user above its permitted hierarchy.
- [ ] The final active OWNER/NOTARIS cannot be removed or demoted.
- [ ] Concurrent attempts cannot leave a tenant without an active OWNER/NOTARIS.

## 6. API error behavior

- [ ] Invalid IDs return a controlled 4xx response.
- [ ] Invalid roles/statuses are rejected.
- [ ] Oversized input is rejected.
- [ ] Duplicate email is rejected without exposing SQL details.
- [ ] Database failures do not expose SQL statements, credentials, filesystem paths, or stack traces.
- [ ] Unexpected content types are rejected or handled safely.

## 7. Document security

- [ ] Upload limit is 15 MB.
- [ ] MIME type is detected server-side.
- [ ] Storage key is random/server-generated.
- [ ] Original filename cannot control the filesystem path.
- [ ] Stored file permissions are not world-readable.
- [ ] Download authorizes both document ID and authenticated tenant.
- [ ] Deleted documents cannot be downloaded.
- [ ] `../`, absolute paths, and symlink/path traversal attempts cannot escape `STORAGE_ROOT`.
- [ ] No private document is directly reachable from the public web root.

## 8. Audit

- [ ] Successful user create/update/activate/deactivate/password-reset operations create audit records.
- [ ] Document download creates an audit record.
- [ ] Audit records contain tenant, authenticated user, action, module, and record ID.
- [ ] Failed cross-tenant attempts do not create false successful mutation records.

## Evidence record

Record for each staging run:

- Date/time:
- Git commit:
- PHP version:
- MySQL/MariaDB version:
- Frontend origin:
- API origin:
- Result: PASS / FAIL
- Failed checks:
- Remediation commit(s):

## Promotion gate

Do not merge to `main` until the checklist has been executed on staging, failures have been remediated, and the evidence record is complete.
