<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../koneksi.php';

start_secure_session();
security_headers();

$user = require_auth();
if (($user['role'] ?? '') !== 'SUPER_ADMIN') {
    json_response(['status' => 'error', 'pesan' => 'Akses ditolak'], 403);
}

require_post();
require_csrf();

$name = trim((string)($_POST['name'] ?? ''));
$slug = strtolower(trim((string)($_POST['slug'] ?? '')));
$adminName = trim((string)($_POST['admin_name'] ?? ''));
$adminEmail = strtolower(trim((string)($_POST['admin_email'] ?? '')));
$adminPassword = (string)($_POST['admin_password'] ?? '');
$plan = trim((string)($_POST['plan'] ?? 'founding'));

if ($name === '' || !preg_match('/^[a-z0-9-]{3,100}$/', $slug) || $adminName === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPassword) < 12) {
    json_response(['status' => 'error', 'pesan' => 'Data tenant tidak valid. Password minimal 12 karakter.'], 422);
}

$koneksi->begin_transaction();
try {
    $tenantStmt = $koneksi->prepare('INSERT INTO tenants (name, slug, status, plan) VALUES (?, ?, ?, ?)');
    if (!$tenantStmt) throw new RuntimeException('tenant prepare failed');
    $status = 'trial';
    $tenantStmt->bind_param('ssss', $name, $slug, $status, $plan);
    if (!$tenantStmt->execute()) throw new RuntimeException('tenant insert failed');
    $tenantId = $koneksi->insert_id;
    $tenantStmt->close();

    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $role = 'OWNER';
    $userStmt = $koneksi->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    if (!$userStmt) throw new RuntimeException('user prepare failed');
    $userStmt->bind_param('issss', $tenantId, $adminName, $adminEmail, $hash, $role);
    if (!$userStmt->execute()) throw new RuntimeException('user insert failed');
    $userStmt->close();

    $koneksi->commit();
    json_response(['status' => 'ok', 'pesan' => 'Tenant berhasil dibuat', 'tenant_id' => $tenantId]);
} catch (Throwable $e) {
    $koneksi->rollback();
    json_response(['status' => 'error', 'pesan' => 'Tenant gagal dibuat. Periksa slug/email dan coba lagi.'], 409);
}
