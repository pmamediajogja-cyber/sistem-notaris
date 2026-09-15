<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

$conn = $koneksi;

header('Content-Type: application/json; charset=UTF-8');
security_headers();

$action = $_GET['action'] ?? '';

// The tenant migration must be applied before this endpoint is enabled.
// We deliberately do not auto-create or ALTER business tables at request time.
$columnCheck = $conn->query("SHOW COLUMNS FROM tabel_invoice LIKE 'tenant_id'");
if (!$columnCheck || $columnCheck->num_rows !== 1) {
    json_response([
        'status' => 'error',
        'pesan' => 'Struktur invoice belum siap untuk mode tenant',
    ], 503);
}

if ($action === 'load') {
    $user = require_tenant_user();
    $tenantId = tenant_id_from_user($user);

    $stmt = $conn->prepare(
        'SELECT id, no_invoice, nama_pihak, data_json
         FROM tabel_invoice
         WHERE tenant_id = ?
         ORDER BY id DESC'
    );
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
    }

    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();

    $arsip = [];
    while ($row = $result->fetch_assoc()) {
        $decoded = json_decode((string) $row['data_json'], true);
        $arsip[] = [
            'id' => (int) $row['id'],
            'no_invoice' => $row['no_invoice'],
            'nama_pihak' => $row['nama_pihak'],
            'data_json' => is_array($decoded) ? $decoded : null,
        ];
    }

    $stmt->close();
    json_response($arsip);
}

if ($action === 'save') {
    $user = require_tenant_user();
    require_post();
    require_csrf();
    $tenantId = tenant_id_from_user($user);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_response(['status' => 'error', 'pesan' => 'Data JSON tidak valid'], 422);
    }

    $noInvoice = trim((string) ($data['no_invoice'] ?? ''));
    $penjual = trim((string) ($data['penjual'] ?? ''));
    $pembeli = trim((string) ($data['pembeli'] ?? ''));

    if ($noInvoice === '' || $penjual === '') {
        json_response(['status' => 'error', 'pesan' => 'Nomor invoice dan penjual wajib diisi'], 422);
    }

    $namaPihak = $pembeli !== '' ? "$penjual & $pembeli" : $penjual;
    $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($dataJson === false) {
        json_response(['status' => 'error', 'pesan' => 'Data invoice tidak dapat diproses'], 422);
    }

    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;

    if ($id > 0) {
        $exists = $conn->prepare('SELECT id FROM tabel_invoice WHERE id = ? AND tenant_id = ? LIMIT 1');
        if (!$exists) {
            json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
        }
        $exists->bind_param('ii', $id, $tenantId);
        $exists->execute();
        $found = $exists->get_result()->num_rows === 1;
        $exists->close();

        if (!$found) {
            json_response(['status' => 'error', 'pesan' => 'Invoice tidak ditemukan atau bukan milik kantor ini'], 404);
        }

        $stmt = $conn->prepare(
            'UPDATE tabel_invoice
             SET no_invoice = ?, nama_pihak = ?, data_json = ?
             WHERE id = ? AND tenant_id = ?'
        );
        if (!$stmt) {
            json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
        }
        $stmt->bind_param('sssii', $noInvoice, $namaPihak, $dataJson, $id, $tenantId);
        $stmt->execute();
        $stmt->close();

        audit_log($conn, 'invoice.update', 'invoice', (string) $id);
        json_response(['status' => 'success', 'id' => $id]);
    }

    $stmt = $conn->prepare(
        'INSERT INTO tabel_invoice (tenant_id, no_invoice, nama_pihak, data_json)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
    }

    $stmt->bind_param('isss', $tenantId, $noInvoice, $namaPihak, $dataJson);
    $stmt->execute();
    $insertId = $conn->insert_id;
    $stmt->close();

    audit_log($conn, 'invoice.create', 'invoice', (string) $insertId);
    json_response(['status' => 'success', 'id' => $insertId]);
}

if ($action === 'delete') {
    $user = require_tenant_user();
    require_post();
    require_csrf();
    $tenantId = tenant_id_from_user($user);
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (!$id || $id < 1) {
        json_response(['status' => 'error', 'pesan' => 'ID invoice tidak valid'], 422);
    }

    $stmt = $conn->prepare('DELETE FROM tabel_invoice WHERE id = ? AND tenant_id = ?');
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
    }

    $stmt->bind_param('ii', $id, $tenantId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted !== 1) {
        json_response(['status' => 'error', 'pesan' => 'Invoice tidak ditemukan atau bukan milik kantor ini'], 404);
    }

    audit_log($conn, 'invoice.delete', 'invoice', (string) $id);
    json_response(['status' => 'success']);
}

json_response(['status' => 'error', 'pesan' => 'Aksi tidak dikenal'], 400);
