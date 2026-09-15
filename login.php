<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/auth.php';

require_post();

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    json_response(['status' => 'error', 'pesan' => 'Email atau password tidak valid'], 422);
}

$host = env_value('DB_HOST', '127.0.0.1');
$port = env_value('DB_PORT', '3306');
$db = env_value('DB_DATABASE');
$user = env_value('DB_USERNAME');
$pass = env_value('DB_PASSWORD');

if (!$db || !$user) {
    json_response(['status' => 'error', 'pesan' => 'Konfigurasi database belum tersedia'], 500);
}

$mysqli = @new mysqli($host, $user, $pass ?? '', $db, (int)$port);
if ($mysqli->connect_errno) {
    json_response(['status' => 'error', 'pesan' => 'Database tidak dapat dihubungi'], 503);
}
$mysqli->set_charset('utf8mb4');

$stmt = $mysqli->prepare('SELECT id, tenant_id, name, role, password_hash, is_active FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$account = $result->fetch_assoc();
$stmt->close();
$mysqli->close();

if (!$account || !(int)$account['is_active'] || !password_verify($password, $account['password_hash'])) {
    json_response(['status' => 'error', 'pesan' => 'Email atau password salah'], 401);
}

login_user($account);
json_response([
    'status' => 'success',
    'user' => [
        'id' => (int)$account['id'],
        'tenant_id' => $account['tenant_id'] !== null ? (int)$account['tenant_id'] : null,
        'name' => $account['name'],
        'role' => $account['role'],
    ],
    'csrf_token' => csrf_token(),
]);
