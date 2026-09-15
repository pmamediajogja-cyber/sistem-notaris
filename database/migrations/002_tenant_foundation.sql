-- Tenant foundation for SaaS isolation.
-- Business tables will receive tenant_id in the next migration after existing data is audited.

CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'trial',
    plan VARCHAR(50) NOT NULL DEFAULT 'founding',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
