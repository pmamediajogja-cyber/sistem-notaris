-- Phase 2B: canonical SaaS business schema.
-- This is additive and intentionally independent from legacy business tables.
-- Do not import legacy rows here until an explicit tenant mapping exists.
-- Composite parent/child keys prevent cross-tenant relationships at DB level.

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    client_code VARCHAR(50) NULL,
    name VARCHAR(200) NOT NULL,
    client_type VARCHAR(30) NOT NULL DEFAULT 'individual',
    nik_or_identifier VARCHAR(100) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    address TEXT NULL,
    notes TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clients_tenant_code (tenant_id, client_code),
    UNIQUE KEY uq_clients_tenant_id (tenant_id, id),
    KEY idx_clients_tenant_name (tenant_id, name),
    CONSTRAINT fk_clients_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    matter_code VARCHAR(50) NULL,
    title VARCHAR(255) NOT NULL,
    service_type VARCHAR(100) NULL,
    deed_type VARCHAR(100) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    opened_at DATE NULL,
    closed_at DATE NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matters_tenant_code (tenant_id, matter_code),
    UNIQUE KEY uq_matters_tenant_id (tenant_id, id),
    KEY idx_matters_tenant_status (tenant_id, status),
    KEY idx_matters_client (tenant_id, client_id),
    CONSTRAINT fk_matters_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_matters_client FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id),
    CONSTRAINT fk_matters_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_matters_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    matter_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    uploaded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_documents_tenant_matter (tenant_id, matter_id),
    KEY idx_documents_sha256 (sha256),
    CONSTRAINT fk_documents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_documents_matter FOREIGN KEY (tenant_id, matter_id) REFERENCES matters(tenant_id, id),
    CONSTRAINT fk_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    matter_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NOT NULL,
    note TEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status_history_tenant_matter (tenant_id, matter_id, created_at),
    CONSTRAINT fk_status_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_status_history_matter FOREIGN KEY (tenant_id, matter_id) REFERENCES matters(tenant_id, id),
    CONSTRAINT fk_status_history_changed_by FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_storage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    storage_key VARCHAR(500) NOT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'local',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_storage_document (document_id),
    UNIQUE KEY uq_document_storage_key (storage_key),
    KEY idx_document_storage_tenant (tenant_id),
    CONSTRAINT fk_document_storage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_document_storage_document FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentionally no FK is added to legacy tables here.
-- Legacy-to-canonical import will be a separate, auditable step after tenant mapping.
