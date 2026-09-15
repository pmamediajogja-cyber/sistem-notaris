-- Tenant isolation foundation for legacy business tables.
-- Columns are intentionally nullable first so existing data can be audited/backfilled
-- before production enforcement is made NOT NULL.

ALTER TABLE tabel_invoice
    ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id,
    ADD INDEX idx_invoice_tenant_id (tenant_id);

ALTER TABLE tabel_pelacakan
    ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id,
    ADD INDEX idx_pelacakan_tenant_id (tenant_id);

ALTER TABLE tabel_agenda
    ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER tanggal,
    ADD INDEX idx_agenda_tenant_id (tenant_id);

-- IMPORTANT:
-- Do not add a foreign key or make tenant_id NOT NULL until legacy records
-- have been mapped to the correct tenant. Never guess the tenant mapping.
