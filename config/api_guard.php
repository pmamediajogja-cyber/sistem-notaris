<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';

function apply_cors_headers(): void
{
    $origin = env_value('ALLOWED_ORIGIN');
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin || $requestOrigin === '' || !hash_equals($origin, $requestOrigin)) return;

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Vary: Origin');
}

function handle_cors_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'OPTIONS') return;

    $origin = env_value('ALLOWED_ORIGIN');
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin || $requestOrigin === '' || !hash_equals($origin, $requestOrigin)) {
        json_response(['status' => 'error', 'pesan' => 'Origin tidak diizinkan'], 403);
    }

    apply_cors_headers();
    http_response_code(204);
    exit;
}

function api_guard(bool $write = false): array
{
    global $koneksi;

    handle_cors_preflight();
    apply_cors_headers();
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

    return $user;
}
