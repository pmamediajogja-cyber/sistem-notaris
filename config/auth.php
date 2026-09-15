<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

start_secure_session();
security_headers();

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = [
        'user_id' => (int) $user['id'],
        'tenant_id' => isset($user['tenant_id']) ? (int) $user['tenant_id'] : null,
        'name' => (string) $user['name'],
        'role' => (string) $user['role'],
    ];
}

function current_user(): ?array
{
    return $_SESSION['auth'] ?? null;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['status' => 'error', 'pesan' => 'Autentikasi diperlukan'], 401);
    }
    return $user;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}
