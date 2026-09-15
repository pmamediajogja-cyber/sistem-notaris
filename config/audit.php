<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function audit_log(mysqli $db, string $action, string $module, ?string $recordId = null): void
{
    $user = current_user();
    $tenantId = $user['tenant_id'] ?? null;
    $userId = $user['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

    $stmt = $db->prepare(
        'INSERT INTO audit_logs (tenant_id, user_id, action, module, record_id, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('iisssss', $tenantId, $userId, $action, $module, $recordId, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}
