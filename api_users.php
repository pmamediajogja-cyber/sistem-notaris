<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/api_guard.php';
require_once __DIR__ . '/config/audit.php';

$user = api_guard(false);
$user = require_tenant_user();
$tenantId = tenant_id_from_user($user);
$role = (string) ($user['role'] ?? '');

function user_management_can_manage(array $actor, string $targetRole): bool
{
    $actorRole = (string) ($actor['role'] ?? '');
    if ($actorRole === 'OWNER') return in_array($targetRole, ['OWNER', 'NOTARIS', 'ADMIN', 'STAFF'], true);
    if ($actorRole === 'NOTARIS') return in_array($targetRole, ['ADMIN', 'STAFF'], true);
    if ($actorRole === 'ADMIN') return $targetRole === 'STAFF';
    return false;
}

function valid_user_role(string $value): bool
{
    return in_array($value, ['OWNER', 'NOTARIS', 'ADMIN', 'STAFF'], true);
}

function management_target(mysqli $db, int $targetId, int $tenantId): array
{
    $stmt = $db->prepare('SELECT id, role, is_active FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Gagal memeriksa user'], 500);
    $stmt->bind_param('ii', $targetId, $tenantId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$target) json_response(['status' => 'error', 'pesan' => 'User tidak ditemukan'], 404);
    return $target;
}

function guard_last_manager(mysqli $db, int $tenantId, string $currentRole, string $newRole, bool $active, bool $forUpdate = false): void
{
    if (!$active || !in_array($currentRole, ['OWNER', 'NOTARIS'], true) || in_array($newRole, ['OWNER', 'NOTARIS'], true)) return;
    $sql = "SELECT id FROM users WHERE tenant_id = ? AND is_active = 1 AND role IN ('OWNER','NOTARIS') ORDER BY id ASC LIMIT 2" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Gagal memeriksa administrator aktif'], 500);
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = $result->num_rows;
    $stmt->close();
    if ($total <= 1) json_response(['status' => 'error', 'pesan' => 'Tidak dapat menurunkan role satu-satunya OWNER/NOTARIS aktif'], 409);
}

function begin_manager_change(mysqli $db): void
{
    if (!$db->begin_transaction()) json_response(['status' => 'error', 'pesan' => 'Gagal memulai perubahan akses'], 500);
}

function rollback_manager_change(mysqli $db): void
{
    $db->rollback();
}

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list')));

if ($action === 'list') {
    $stmt = $koneksi->prepare('SELECT id, name, email, role, is_active, created_at, last_login_at FROM users WHERE tenant_id = ? ORDER BY name ASC, id ASC LIMIT 500');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Gagal menyiapkan daftar user'], 500);
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        $users[] = $row;
    }
    $stmt->close();
    json_response(['status' => 'ok', 'data' => $users]);
}

require_post();
require_csrf();
if (!in_array($role, ['OWNER', 'NOTARIS', 'ADMIN'], true)) json_response(['status' => 'error', 'pesan' => 'Role Anda tidak dapat mengelola user'], 403);

if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $targetRole = strtoupper(trim((string)($_POST['role'] ?? 'STAFF')));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !valid_user_role($targetRole)) json_response(['status' => 'error', 'pesan' => 'Data user tidak valid'], 422);
    if (!user_management_can_manage($user, $targetRole)) json_response(['status' => 'error', 'pesan' => 'Anda tidak berwenang membuat user dengan role tersebut'], 403);
    if (strlen($password) < 12 || strlen($password) > 255) json_response(['status' => 'error', 'pesan' => 'Password harus 12-255 karakter'], 422);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) json_response(['status' => 'error', 'pesan' => 'Gagal membuat password'], 500);
    $stmt = $koneksi->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Gagal menyiapkan pembuatan user'], 500);
    $stmt->bind_param('issss', $tenantId, $name, $email, $hash, $targetRole);
    if (!$stmt->execute()) { $stmt->close(); json_response(['status' => 'error', 'pesan' => 'Email sudah digunakan atau user gagal dibuat'], 409); }
    $newId = (int) $koneksi->insert_id; $stmt->close();
    audit_log($koneksi, 'user.create', 'users', (string) $newId);
    json_response(['status' => 'ok', 'pesan' => 'User berhasil dibuat', 'user_id' => $newId], 201);
}

if ($action === 'update') {
    $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $targetRole = strtoupper(trim((string)($_POST['role'] ?? '')));
    if (!$targetId || $name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !valid_user_role($targetRole)) json_response(['status' => 'error', 'pesan' => 'Data user tidak valid'], 422);
    $target = management_target($koneksi, (int) $targetId, $tenantId);
    if ((int) $targetId === (int) $user['user_id'] && $targetRole !== $role) json_response(['status' => 'error', 'pesan' => 'Anda tidak dapat mengubah role akun sendiri'], 403);
    if (!user_management_can_manage($user, (string) $target['role']) || !user_management_can_manage($user, $targetRole)) json_response(['status' => 'error', 'pesan' => 'Anda tidak berwenang mengubah user/role tersebut'], 403);

    $managerChange = (bool) $target['is_active'] && in_array((string) $target['role'], ['OWNER', 'NOTARIS'], true) && !in_array($targetRole, ['OWNER', 'NOTARIS'], true);
    if ($managerChange) begin_manager_change($koneksi);
    if ($managerChange) guard_last_manager($koneksi, $tenantId, (string) $target['role'], $targetRole, true, true);

    $stmt = $koneksi->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ? AND tenant_id = ?');
    if (!$stmt) { if ($managerChange) rollback_manager_change($koneksi); json_response(['status' => 'error', 'pesan' => 'Gagal menyiapkan perubahan user'], 500); }
    $stmt->bind_param('sssii', $name, $email, $targetRole, $targetId, $tenantId);
    if (!$stmt->execute()) { $stmt->close(); if ($managerChange) rollback_manager_change($koneksi); json_response(['status' => 'error', 'pesan' => 'Email sudah digunakan atau perubahan gagal'], 409); }
    $stmt->close();
    if ($managerChange && !$koneksi->commit()) { $koneksi->rollback(); json_response(['status' => 'error', 'pesan' => 'Perubahan akses gagal disimpan'], 500); }
    audit_log($koneksi, 'user.update', 'users', (string) $targetId);
    json_response(['status' => 'ok', 'pesan' => 'User berhasil diperbarui']);
}

if ($action === 'set_active') {
    $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if (!$targetId || $active === null) json_response(['status' => 'error', 'pesan' => 'Parameter user/status tidak valid'], 422);
    $target = management_target($koneksi, (int) $targetId, $tenantId);
    if ((int) $targetId === (int) $user['user_id'] && $active === false) json_response(['status' => 'error', 'pesan' => 'Anda tidak dapat menonaktifkan akun sendiri'], 403);
    if (!user_management_can_manage($user, (string) $target['role'])) json_response(['status' => 'error', 'pesan' => 'Anda tidak berwenang mengubah status user tersebut'], 403);

    $managerChange = !$active && in_array((string) $target['role'], ['OWNER', 'NOTARIS'], true) && (bool) $target['is_active'];
    if ($managerChange) begin_manager_change($koneksi);
    if ($managerChange) guard_last_manager($koneksi, $tenantId, (string) $target['role'], 'STAFF', true, true);

    $newValue = $active ? 1 : 0;
    $stmt = $koneksi->prepare('UPDATE users SET is_active = ? WHERE id = ? AND tenant_id = ?');
    if (!$stmt) { if ($managerChange) rollback_manager_change($koneksi); json_response(['status' => 'error', 'pesan' => 'Gagal menyiapkan status user'], 500); }
    $stmt->bind_param('iii', $newValue, $targetId, $tenantId);
    if (!$stmt->execute()) { $stmt->close(); if ($managerChange) rollback_manager_change($koneksi); json_response(['status' => 'error', 'pesan' => 'Gagal mengubah status user'], 500); }
    $stmt->close();
    if ($managerChange && !$koneksi->commit()) { $koneksi->rollback(); json_response(['status' => 'error', 'pesan' => 'Perubahan akses gagal disimpan'], 500); }
    audit_log($koneksi, $active ? 'user.activate' : 'user.deactivate', 'users', (string) $targetId);
    json_response(['status' => 'ok', 'pesan' => $active ? 'User diaktifkan' : 'User dinonaktifkan']);
}

if ($action === 'reset_password') {
    $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $password = (string)($_POST['password'] ?? '');
    if (!$targetId || strlen($password) < 12 || strlen($password) > 255) json_response(['status' => 'error', 'pesan' => 'User/password tidak valid. Password harus 12-255 karakter'], 422);
    if ((int) $targetId === (int) $user['user_id']) json_response(['status' => 'error', 'pesan' => 'Gunakan alur ganti password akun sendiri'], 403);
    $target = management_target($koneksi, (int) $targetId, $tenantId);
    if (!user_management_can_manage($user, (string) $target['role'])) json_response(['status' => 'error', 'pesan' => 'Anda tidak berwenang reset password user tersebut'], 403);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) json_response(['status' => 'error', 'pesan' => 'Gagal membuat password'], 500);
    $stmt = $koneksi->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Gagal menyiapkan reset password'], 500);
    $stmt->bind_param('sii', $hash, $targetId, $tenantId);
    if (!$stmt->execute()) { $stmt->close(); json_response(['status' => 'error', 'pesan' => 'Reset password gagal'], 500); }
    $stmt->close();
    audit_log($koneksi, 'user.password_reset', 'users', (string) $targetId);
    json_response(['status' => 'ok', 'pesan' => 'Password berhasil direset']);
}

json_response(['status' => 'error', 'pesan' => 'Aksi user tidak dikenal'], 400);
