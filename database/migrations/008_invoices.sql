-- Phase 2D: tenant-scoped invoice register.
-- Additive schema. Legacy tabel_invoice is intentionally not imported because
-- legacy ownership cannot be inferred safely.

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(100) NOT NULL,
    service_mode VARCHAR(20) NOT NULL DEFAULT 'AJB',
    invoice_date DATE NULL,
    property_reference VARCHAR(255) NULL,
    area_m2 DECIMAL(15,2) NULL,
    seller_name VARCHAR(255) NULL,
    buyer_name VARCHAR(255) NULL,
    real_transaction_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
    tax_base_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
    npoptkp_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
    burden_mode TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_invoices_tenant_number (tenant_id, invoice_number),
    KEY idx_invoices_tenant_date (tenant_id, invoice_date),
    KEY idx_invoices_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_invoices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_invoices_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_invoices_service_mode CHECK (service_mode IN ('AJB', 'UMUM')),
    CONSTRAINT chk_invoices_area CHECK (area_m2 IS NULL OR area_m2 >= 0),
    CONSTRAINT chk_invoices_amounts CHECK (real_transaction_amount >= 0 AND tax_base_amount >= 0 AND npoptkp_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(20,2) NOT NULL DEFAULT 0,
    distribution VARCHAR(20) NOT NULL DEFAULT 'kosong',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice_items_tenant_invoice (tenant_id, invoice_id),
    CONSTRAINT fk_invoice_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (tenant_id, invoice_id) REFERENCES invoices(tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_invoice_items_distribution CHECK (distribution IN ('kosong', 'bagi2', 'pembeli', 'penjual')),
    CONSTRAINT chk_invoice_items_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
