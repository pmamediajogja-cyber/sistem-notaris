# Phase 2D — Invoice Audit

## Legacy boundary

The legacy `api_invoice.php` on `main` stores invoice data in `tabel_invoice.data_json` and has no tenant boundary. Its read path returns all rows, its delete path performs a permanent delete, and the API accepts the record ID directly from the request. It also creates its table at request time. This endpoint must not be reused by the SaaS request path.

## Canonical replacement

Phase 2D uses `invoices` + `invoice_items` with:

- `tenant_id` on every record;
- tenant-scoped reads and writes;
- POST + CSRF for mutations;
- soft delete for invoices;
- prepared statements;
- audit events for create/update/delete;
- canonical optional links to `clients` and `matters`;
- composite foreign keys so a client/matter from another tenant cannot be attached to an invoice.

## Migration policy

Legacy invoice rows are **not automatically imported**. Ownership cannot be inferred safely from the old global table. Importing them without an explicit tenant mapping would create a cross-tenant data risk.

## Tax/calculation boundary

The new UI currently implements the legacy product intent (AJB/UMUM, cost distribution, PPh/BPHTB display) as a working calculator. The tax formulas are not yet treated as a legal/tax rules engine and require office/legal verification before production use. No tax rate should be changed silently by the migration layer.

## Next integration step

Connect the invoice editor to canonical client/matter selectors. The browser may submit selected IDs, but the API must remain authoritative and verify tenant ownership and the client↔matter relationship before persistence.
