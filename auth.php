<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_response([
        'status' => 'ok',
        'authenticated' => current_user() !== null,
        'csrf_token' => csrf_token(),
        'user' => current_user(),
    ]);
}

require_post();

$action = $_POST['action'] ?? '';

if ($action === 'logout') {
    logout_user();
    json_response(['status' => 'ok', 'pesan' => 'Logout berhasil']);
}

if ($action !== 'login') {
    json_response(['status' => 'error', 'pesan' => 'Aksi tidak dikenal'], 400);
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    json_response(['status' => 'error', 'pesan' => 'Email dan password wajib diisi'], 422);
}

require_once __DIR__ . '/koneksi.php';

$stmt = $koneksi->prepare(
    'SELECT id, tenant_id, name, email, password_hash, role, is_active
     FROM users
     WHERE email = ?
     LIMIT 1'
);

if (!$stmt) {
    json_response(['status' => 'error', 'pesan' => 'Layanan autentikasi tidak tersedia'], 500);
}

$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
    json_response(['status' => 'error', 'pesan' => 'Email atau password salah'], 401);
}

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $rehash = $koneksi->prepare('UPDATE users SET password_hash = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    if ($rehash) {
        $id = (int) $user['id'];
        $rehash->bind_param('si', $newHash, $id);
        $rehash->execute();
        $rehash->close();
    }
} else {
    $update = $koneksi->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    if ($update) {
        $id = (int) $user['id'];
        $update->bind_param('i', $id);
        $update->execute();
        $update->close();
    }
}

login_user($user);

json_response([
    'status' => 'ok',
    'pesan' => 'Login berhasil',
    'csrf_token' => csrf_token(),
    'user' => current_user(),
]);
