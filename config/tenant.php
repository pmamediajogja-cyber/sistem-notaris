<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function require_tenant_user(): array
{
    $user = require_auth();
    if (($user['role'] ?? '') === 'SUPER_ADMIN' || empty($user['tenant_id'])) {
        json_response(['status' => 'error', 'pesan' => 'Akses tenant diperlukan'], 403);
    }
    return $user;
}

function tenant_id_from_user(array $user): int
{
    $tenantId = (int) ($user['tenant_id'] ?? 0);
    if ($tenantId < 1) {
        json_response(['status' => 'error', 'pesan' => 'Tenant tidak valid'], 403);
    }
    return $tenantId;
}
