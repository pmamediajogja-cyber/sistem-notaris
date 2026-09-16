-- Phase 2D: optional canonical links from invoices to clients/matters.
-- Existing invoices remain valid because both references are nullable.
-- Composite foreign keys enforce tenant isolation at the database layer.

ALTER TABLE invoices
    ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER tenant_id,
    ADD COLUMN matter_id BIGINT UNSIGNED NULL AFTER client_id,
    ADD KEY idx_invoices_tenant_client (tenant_id, client_id),
    ADD KEY idx_invoices_tenant_matter (tenant_id, matter_id),
    ADD CONSTRAINT fk_invoices_client
        FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_invoices_matter
        FOREIGN KEY (tenant_id, matter_id) REFERENCES matters (tenant_id, id)
        ON DELETE SET NULL;
