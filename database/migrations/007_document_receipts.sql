-- Phase 2D: tenant-scoped document receipt register.
-- Additive migration. No legacy data is imported here.

CREATE TABLE IF NOT EXISTS document_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    matter_id BIGINT UNSIGNED NOT NULL,
    direction VARCHAR(20) NOT NULL DEFAULT 'incoming',
    counterparty_name VARCHAR(200) NOT NULL,
    receipt_date DATE NOT NULL,
    staff_name VARCHAR(200) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_receipts_tenant_date (tenant_id, receipt_date),
    KEY idx_receipts_tenant_matter (tenant_id, matter_id),
    CONSTRAINT fk_receipts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_receipts_matter FOREIGN KEY (tenant_id, matter_id) REFERENCES matters(tenant_id, id),
    CONSTRAINT fk_receipts_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_receipts_direction CHECK (direction IN ('incoming', 'outgoing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_receipt_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    receipt_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_receipt_items_tenant_receipt (tenant_id, receipt_id),
    CONSTRAINT fk_receipt_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_receipt_items_receipt FOREIGN KEY (tenant_id, receipt_id) REFERENCES document_receipts(tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_receipt_items_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
