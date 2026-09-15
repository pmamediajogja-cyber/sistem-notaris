# Phase 2D — Cross-Origin Session Boundary

Status: DESIGN / HARDENING ONLY — NOT PRODUCTION DEPLOYMENT

## Goal

Allow the `pma.wtf` frontend to call the SaaS API from a different origin without weakening tenant isolation or CSRF protection.

## Current backend behavior

- Session cookie uses `HttpOnly` and `SameSite=Lax`.
- API CORS currently emits `Access-Control-Allow-Origin` and `Access-Control-Allow-Credentials` only when the request Origin exactly equals `ALLOWED_ORIGIN`.
- Write endpoints require POST + CSRF.
- The frontend API adapter uses `credentials: include` and keeps the CSRF token in runtime memory only.

## Important deployment rule

Do NOT change the cookie to `SameSite=None` merely because the frontend is hosted elsewhere. First determine whether the frontend/API relationship is same-site or cross-site and prefer a same-origin reverse proxy where practical.

If a genuinely cross-site deployment is required, use HTTPS everywhere and explicitly review:

1. `SameSite=None; Secure` requirements for the session cookie.
2. Exact allowlisted origin; never `*` with credentials.
3. CORS handling for preflight `OPTIONS` requests if the browser requires them.
4. `Access-Control-Allow-Credentials: true`.
5. CSRF token delivery and validation after session creation.
6. No tenant ID accepted from the browser as an authorization boundary.
7. No wildcard or reflected Origin behavior.
8. No production testing with real client documents or credentials.

## Preferred architecture

Prefer:

`https://pma.wtf/notary/*` → same-origin reverse proxy → private SaaS API

This keeps browser requests same-origin and avoids unnecessary cross-site session complexity. The proxy must not expose database credentials or bypass backend authorization.

## Verification checklist

- [ ] HTTPS on frontend and API
- [ ] Exact production frontend origin in `ALLOWED_ORIGIN`
- [ ] Session cookie attributes reviewed for actual deployment topology
- [ ] Browser login obtains a session
- [ ] Browser receives CSRF token
- [ ] `GET api_users.php?action=list` returns only current tenant users
- [ ] POST create/activate/deactivate succeeds with CSRF
- [ ] Missing/invalid CSRF returns 419
- [ ] Tenant B data cannot be accessed from Tenant A session
- [ ] CORS does not permit arbitrary origins
- [ ] No secrets committed to frontend source
- [ ] Staging test completed before production
