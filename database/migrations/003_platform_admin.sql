-- Platform-level administrator has no tenant_id.
-- Office users must belong to a tenant.
-- Enforced at application layer until legacy schema migration is complete.
UPDATE users SET role = 'SUPER_ADMIN', tenant_id = NULL WHERE role = 'SUPER_ADMIN';

CREATE INDEX idx_users_role_active ON users (role, is_active);
CREATE INDEX idx_tenants_status ON tenants (status);
