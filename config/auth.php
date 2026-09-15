<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

start_secure_session();
security_headers();

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    $expected = $_SESSION['_csrf'] ?? '';

    if ($expected === '' || $token === '' || !hash_equals($expected, (string) $token)) {
        json_response(['status' => 'error', 'pesan' => 'Token CSRF tidak valid'], 419);
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    csrf_token();
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

function require_role(string ...$roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        json_response(['status' => 'error', 'pesan' => 'Akses ditolak'], 403);
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
