# Phase 2A.2 — Legacy Workflow & Data Boundary Audit

Branch: `phase-2-saas-foundation`

Status: **AUDIT COMPLETE / MIGRATION NOT YET APPLIED**

## Objective

Identify which legacy components contain tenant-owned business data, which are client-only utilities, and which must be isolated before SaaS rollout.

## Findings

| Component | Type | Tenant data | SaaS action | Risk |
|---|---|---:|---|---|
| `users` | Identity | Yes | Keep; require tenant for office users | 🟢 |
| `tenants` | SaaS control | Yes | Keep as platform boundary | 🟢 |
| `audit_logs` | Security | Yes | Keep; tenant-scoped where applicable | 🟢 |
| `tabel_invoice` / `api_invoice.php` | Business | Yes | tenant_id + ownership checks | 🟡 |
| `tabel_pelacakan` / `api_pelacakan.php` | Business | Yes | tenant_id + ownership/lock checks | 🟡 |
| `tabel_agenda` / `api_agenda.php` | Business | Yes | tenant_id + surrogate agenda_id | 🟡 |
| `akta.html` | Local document utility | No server persistence found | Keep as isolated client-side module; add auth only when server persistence is introduced | 🟢/🟡 |
| `generator_batch.html` | Client-side generator | No server persistence found | Keep; tenant boundary applies only to future uploads/storage | 🟢/🟡 |
| `generator_kuasa.html` | Client-side generator | No server persistence found | Keep; tenant boundary applies only to future persistence | 🟢/🟡 |
| `cetak_susulan.html` | Client-side print utility | No server persistence found | Keep; no tenant database migration required | 🟢 |
| `data_kbli.json` | Reference data | No tenant ownership | Shared read-only catalog | 🟢 |
| `foto_patok/*` | Static media | Potentially office/client-related | **Do not use as shared SaaS storage**; move to tenant-scoped storage before production | 🔴 |
| `api_pelacakan_dian.php` | Legacy/VIP API | Yes | Keep outside SaaS until separately migrated | 🔴 |
| `koneksi_dian.php` | Legacy DB connector | Yes | Never expose in SaaS request path; replace with controlled configuration during VIP migration | 🔴 |

## Important conclusions

### 1. No safe automatic legacy tenant mapping

The current source does not contain a trustworthy tenant identifier for existing legacy business rows. Therefore Phase 2 must **not** mass-update old rows with a guessed tenant ID.

Migration rule:

```text
legacy row -> verified office mapping -> tenant_id -> validation -> constraint
```

### 2. Agenda requires structural migration

Legacy agenda used `tanggal` as its sole primary key. Multi-tenant SaaS requires a row identity independent of date. `agenda_id` is therefore the canonical key, with `(tenant_id, tanggal)` used as the business uniqueness boundary where appropriate.

### 3. Client/akta/document domain is not yet a database domain in this branch

`akta.html` is a browser-side DOCX proofreader using Mammoth and does not itself establish a server-side client/akta/document table. It must not be treated as proof that a persistent `akta` table exists.

The SaaS domain should therefore be designed explicitly rather than inventing a migration against an unknown legacy table:

```text
tenants
  |
  +-- clients
  |     |
  |     +-- matters/cases
  |             |
  |             +-- documents
  |             +-- invoices
  |             +-- status_history
  |
  +-- users
  +-- audit_logs
```

### 4. Static `foto_patok` is not acceptable as tenant storage

The repository contains many large JPG files. Static repository media cannot safely represent per-office private documents in a SaaS deployment. Future uploads must use tenant-scoped storage and authorization checks.

Recommended logical path:

```text
storage/tenants/{tenant_id}/cases/{case_id}/patok/{uuid}.jpg
```

The browser must never be able to choose another tenant's storage path.

### 5. Dian/VIP is a hard boundary

`api_pelacakan_dian.php` and `koneksi_dian.php` remain legacy-only. They must not be silently converted into the shared SaaS database path. A later VIP migration gets its own design and test cycle.

## Phase 2A.2 exit criteria

- [x] Business APIs already moved to tenant-aware access for agenda, invoice, and pelacakan.
- [x] No request-time business schema creation/ALTER remains in those APIs.
- [x] Existing data is not mass-assigned to an invented tenant.
- [x] Client-side utilities are separated from persistent SaaS data concerns.
- [x] Static media is identified as a future storage migration concern.
- [x] VIP/Dian boundary is explicitly documented.
- [x] Repository ignore rules are restored to a conventional `.gitignore`.

## Not done intentionally

- No production database migration was executed.
- No legacy client/document ownership was guessed.
- No `NOT NULL` or foreign-key constraint was forced onto unknown legacy rows.
- No VIP/Dian database was modified.
- No document content was copied into the SaaS schema.

## Next phase

Phase 2B should create the **canonical SaaS business schema** for clients, matters/cases, documents, status history, and tenant-scoped storage metadata. The legacy tables should then be mapped into that schema through an explicit import plan rather than being exposed directly as the long-term SaaS model.
