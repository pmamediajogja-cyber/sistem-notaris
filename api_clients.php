<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/api_guard.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

$conn = $koneksi;
security_headers();
$action = $_GET['action'] ?? 'list';
$user = api_guard($action !== 'list');
$tenantId = tenant_id_from_user($user);

if ($action === 'list') {
    $stmt = $conn->prepare(
        'SELECT id, client_code, name, client_type, phone, email, address, status, created_at, updated_at
         FROM clients WHERE tenant_id = ? ORDER BY name ASC LIMIT 500'
    );
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan klien tidak tersedia'], 500);
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    json_response($rows);
}

if ($action === 'save') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) json_response(['status' => 'error', 'pesan' => 'Data klien tidak valid'], 422);

    $id = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;
    $code = trim((string)($data['client_code'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $type = trim((string)($data['client_type'] ?? 'individual'));
    $identifier = trim((string)($data['nik_or_identifier'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $address = trim((string)($data['address'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));
    $status = trim((string)($data['status'] ?? 'active'));

    if ($name === '' || mb_strlen($name) > 200 || mb_strlen($code) > 50 || mb_strlen($type) > 30 || mb_strlen($identifier) > 100 || mb_strlen($phone) > 50 || mb_strlen($email) > 190 || mb_strlen($address) > 10000 || mb_strlen($notes) > 10000 || mb_strlen($status) > 30) {
        json_response(['status' => 'error', 'pesan' => 'Data klien tidak valid atau terlalu panjang'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['status' => 'error', 'pesan' => 'Format email tidak valid'], 422);

    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE clients SET client_code = NULLIF(?, \'\'), name = ?, client_type = ?, nik_or_identifier = NULLIF(?, \'\'), phone = NULLIF(?, \'\'), email = NULLIF(?, \'\'), address = NULLIF(?, \'\'), notes = NULLIF(?, \'\'), status = ? WHERE id = ? AND tenant_id = ?');
        if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan klien tidak tersedia'], 500);
        $stmt->bind_param('sssssssssii', $code, $name, $type, $identifier, $phone, $email, $address, $notes, $status, $id, $tenantId);
        $stmt->execute();
        $stmt->close();
        audit_log($conn, 'client.update', 'clients', (string)$id);
        json_response(['status' => 'success', 'id' => $id]);
    }

    $createdBy = (int)$user['user_id'];
    $stmt = $conn->prepare('INSERT INTO clients (tenant_id, client_code, name, client_type, nik_or_identifier, phone, email, address, notes, status, created_by) VALUES (?, NULLIF(?, \'\'), ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?)');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan klien tidak tersedia'], 500);
    $stmt->bind_param('isssssssssi', $tenantId, $code, $name, $type, $identifier, $phone, $email, $address, $notes, $status, $createdBy);
    $stmt->execute();
    $newId = $conn->insert_id;
    $stmt->close();
    audit_log($conn, 'client.create', 'clients', (string)$newId);
    json_response(['status' => 'success', 'id' => $newId]);
}

if ($action === 'delete') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) json_response(['status' => 'error', 'pesan' => 'ID klien tidak valid'], 422);
    $stmt = $conn->prepare('UPDATE clients SET status = \'inactive\' WHERE id = ? AND tenant_id = ?');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan klien tidak tersedia'], 500);
    $stmt->bind_param('ii', $id, $tenantId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected < 0) json_response(['status' => 'error', 'pesan' => 'Klien gagal dinonaktifkan'], 500);
    audit_log($conn, 'client.deactivate', 'clients', (string)$id);
    json_response(['status' => 'success']);
}

json_response(['status' => 'error', 'pesan' => 'Aksi tidak dikenal'], 400);
