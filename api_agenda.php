<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

$conn = $koneksi;
header('Content-Type: application/json; charset=UTF-8');
security_headers();

// Do not create or alter business schema during a request.
$columnCheck = $conn->query("SHOW COLUMNS FROM tabel_agenda LIKE 'tenant_id'");
$agendaIdCheck = $conn->query("SHOW COLUMNS FROM tabel_agenda LIKE 'agenda_id'");
if (!$columnCheck || $columnCheck->num_rows !== 1 || !$agendaIdCheck || $agendaIdCheck->num_rows !== 1) {
    json_response([
        'status' => 'error',
        'pesan' => 'Struktur agenda belum siap untuk mode tenant',
    ], 503);
}

$user = require_tenant_user();
$tenantId = tenant_id_from_user($user);
$action = $_GET['action'] ?? '';

if ($action === 'load') {
    $stmt = $conn->prepare(
        'SELECT tanggal, deskripsi
         FROM tabel_agenda
         WHERE tenant_id = ?
         ORDER BY tanggal ASC'
    );
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan agenda tidak tersedia'], 500);
    }
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();

    $agenda = [];
    while ($row = $result->fetch_assoc()) {
        $agenda[(string) $row['tanggal']] = (string) $row['deskripsi'];
    }
    $stmt->close();
    json_response($agenda);
}

if ($action === 'save') {
    require_post();
    require_csrf();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_response(['status' => 'error', 'pesan' => 'Data agenda tidak valid'], 422);
    }

    if (count($data) > 1000) {
        json_response(['status' => 'error', 'pesan' => 'Jumlah agenda melebihi batas'], 422);
    }

    $conn->begin_transaction();
    try {
        $delete = $conn->prepare('DELETE FROM tabel_agenda WHERE tenant_id = ?');
        if (!$delete) {
            throw new RuntimeException('delete_prepare');
        }
        $delete->bind_param('i', $tenantId);
        $delete->execute();
        $delete->close();

        $insert = $conn->prepare(
            'INSERT INTO tabel_agenda (tenant_id, tanggal, deskripsi) VALUES (?, ?, ?)'
        );
        if (!$insert) {
            throw new RuntimeException('insert_prepare');
        }

        foreach ($data as $tanggal => $deskripsi) {
            $tanggal = trim((string) $tanggal);
            $deskripsi = trim((string) $deskripsi);
            if ($tanggal === '' || mb_strlen($tanggal) > 20 || mb_strlen($deskripsi) > 5000) {
                $insert->close();
                throw new InvalidArgumentException('Data agenda tidak valid');
            }
            $insert->bind_param('iss', $tenantId, $tanggal, $deskripsi);
            $insert->execute();
        }
        $insert->close();
        $conn->commit();

        audit_log($conn, 'agenda.sync', 'agenda', null);
        json_response(['status' => 'success']);
    } catch (Throwable $e) {
        $conn->rollback();
        json_response(['status' => 'error', 'pesan' => 'Agenda gagal disimpan'], 500);
    }
}

json_response(['status' => 'error', 'pesan' => 'Aksi tidak dikenal'], 400);
