-- Phase 2: prepare legacy business tables for tenant-safe access.
-- IMPORTANT: review legacy data and assign each existing record to a tenant BEFORE
-- making tenant_id NOT NULL or adding foreign keys.
-- This migration does not guess tenant ownership.

-- tenant_id is already introduced by migration 004. IF NOT EXISTS keeps this
-- migration usable when 004 has not yet been applied.
ALTER TABLE tabel_invoice
    ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED NULL;

ALTER TABLE tabel_pelacakan
    ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED NULL;

-- Legacy agenda used tanggal as the sole primary key. That prevents two offices
-- from having an entry on the same date. Introduce a surrogate key first.
ALTER TABLE tabel_agenda
    ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS agenda_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST;

ALTER TABLE tabel_agenda
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (agenda_id),
    ADD UNIQUE KEY uq_agenda_tenant_date (tenant_id, tanggal);

-- After every legacy row has been mapped, run a separate controlled migration
-- to make tenant_id NOT NULL and add foreign keys to tenants(id).
-- Never infer or mass-assign tenant ownership.
