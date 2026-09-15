<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';

function api_guard(bool $write = false): array
{
    global $koneksi;

    $user = require_auth();

    // A session must not remain usable after the account is deactivated.
    $stmt = $koneksi->prepare('SELECT is_active, role, tenant_id, name FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Gagal memeriksa sesi'], 500);
    }
    $userId = (int) ($user['user_id'] ?? 0);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $dbUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dbUser || !(bool) $dbUser['is_active']) {
        logout_user();
        json_response(['status' => 'error', 'pesan' => 'Sesi tidak lagi aktif'], 401);
    }

    // Do not trust role/tenant values kept in an old session after an access change.
    $user['role'] = (string) $dbUser['role'];
    $user['tenant_id'] = isset($dbUser['tenant_id']) ? (int) $dbUser['tenant_id'] : null;
    $user['name'] = (string) $dbUser['name'];
    $_SESSION['auth'] = $user;

    if ($write) {
        require_post();
        require_csrf();
    }

    $origin = env_value('ALLOWED_ORIGIN');
    if ($origin) {
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($requestOrigin !== '' && hash_equals($origin, $requestOrigin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
    }

    return $user;
}
