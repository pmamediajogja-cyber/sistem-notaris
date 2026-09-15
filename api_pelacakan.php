<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

$conn = $koneksi;
header('Content-Type: application/json; charset=UTF-8');
security_headers();

$action = $_GET['action'] ?? '';

// Lightweight health check remains public and performs no schema mutation.
if ($action === 'ping') {
    json_response(['status' => 'online']);
}

// Never create/ALTER business tables during an API request.
$columnCheck = $conn->query("SHOW COLUMNS FROM tabel_pelacakan LIKE 'tenant_id'");
if (!$columnCheck || $columnCheck->num_rows !== 1) {
    json_response([
        'status' => 'error',
        'pesan' => 'Struktur pelacakan belum siap untuk mode tenant',
    ], 503);
}

$user = require_tenant_user();
$tenantId = tenant_id_from_user($user);
$userId = (string) ((int) $user['id']);

if ($action === 'check_lock') {
    require_post();
    require_csrf();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;

    if ($id < 1) {
        json_response(['status' => 'error', 'pesan' => 'ID berkas tidak valid'], 422);
    }

    $stmt = $conn->prepare(
        'SELECT locked_by, locked_time FROM tabel_pelacakan
         WHERE id = ? AND tenant_id = ? LIMIT 1'
    );
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan pelacakan tidak tersedia'], 500);
    }
    $stmt->bind_param('ii', $id, $tenantId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_response(['status' => 'error', 'pesan' => 'Berkas tidak ditemukan'], 404);
    }

    $lockedBy = (string) ($row['locked_by'] ?? '');
    $lockedTime = (string) ($row['locked_time'] ?? '');
    $active = $lockedBy !== '' && $lockedTime !== '' && strtotime($lockedTime) !== false
        && strtotime($lockedTime) > strtotime('-15 minutes');

    if ($active && $lockedBy !== $userId) {
        json_response([
            'status' => 'locked',
            'pesan' => 'Berkas ini sedang dikerjakan/dibuka oleh staf lain. Silakan tunggu beberapa saat.',
        ]);
    }

    $lock = $conn->prepare(
        'UPDATE tabel_pelacakan
         SET locked_by = ?, locked_time = NOW()
         WHERE id = ? AND tenant_id = ?'
    );
    if (!$lock) {
        json_response(['status' => 'error', 'pesan' => 'Layanan pelacakan tidak tersedia'], 500);
    }
    $lock->bind_param('sii', $userId, $id, $tenantId);
    $lock->execute();
    $lock->close();

    json_response(['status' => 'available']);
}

if ($action === 'release_lock') {
    require_post();
    require_csrf();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;

    if ($id < 1) {
        json_response(['status' => 'error', 'pesan' => 'ID berkas tidak valid'], 422);
    }

    $stmt = $conn->prepare(
        'UPDATE tabel_pelacakan
         SET locked_by = NULL, locked_time = NULL
         WHERE id = ? AND tenant_id = ? AND locked_by = ?'
    );
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan pelacakan tidak tersedia'], 500);
    }
    $stmt->bind_param('iis', $id, $tenantId, $userId);
    $stmt->execute();
    $stmt->close();
    json_response(['status' => 'released']);
}

if ($action === 'save') {
    require_post();
    require_csrf();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_response(['status' => 'error', 'pesan' => 'Data berkas tidak valid'], 422);
    }

    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;
    $no = trim((string) ($data['no_berkas'] ?? ''));
    $jenis = trim((string) ($data['jenis_proses'] ?? ''));
    $klien = trim((string) ($data['klien'] ?? ''));
    $objek = trim((string) ($data['objek'] ?? ''));
    $tglMasuk = trim((string) ($data['tgl_masuk'] ?? ''));
    $tglTtd = trim((string) ($data['tgl_ttd'] ?? ''));
    $status = trim((string) ($data['status'] ?? 'Pendaftaran Berkas'));
    $catatan = trim((string) ($data['catatan'] ?? ''));
    $riwayat = trim((string) ($data['riwayat_status'] ?? ''));

    if ($no === '' || mb_strlen($no) > 100 || $jenis === '' || mb_strlen($jenis) > 100) {
        json_response(['status' => 'error', 'pesan' => 'Nomor berkas dan jenis proses wajib diisi'], 422);
    }
    foreach ([$klien, $objek, $status, $catatan, $riwayat] as $value) {
        if (mb_strlen($value) > 20000) {
            json_response(['status' => 'error', 'pesan' => 'Data berkas terlalu panjang'], 422);
        }
    }

    if ($id > 0) {
        $stmt = $conn->prepare(
            'UPDATE tabel_pelacakan
             SET no_berkas = ?, jenis_proses = ?, klien = ?, objek = ?,
                 tgl_masuk = ?, tgl_ttd = ?, status = ?, catatan = ?,
                 riwayat_status = ?, locked_by = NULL, locked_time = NULL
             WHERE id = ? AND tenant_id = ?'
        );
        if (!$stmt) {
            json_response(['status' => 'error', 'pesan' => 'Layanan pelacakan tidak tersedia'], 500);
        }
        $stmt->bind_param(
            'sssssssssii',
            $no, $jenis, $klien, $objek, $tglMasuk, $tglTtd, $status,
            $catatan, $riwayat, $id, $tenantId
        );
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 0) {
            json_response(['status' => 'error', 'pesan' => 'Berkas gagal disimpan'], 500);
        }
        audit_log($conn, 'pelacakan.update', 'pelacakan', (string) $id);
        json_response(['status' => 'success', 'id' => $id]);
    }

    $stmt = $conn->prepare(
        'INSERT INTO tabel_pelacakan
         (tenant_id, no_berkas, jenis_proses, klien, objek, tgl_masuk, tgl_ttd,
          status, catatan, riwayat_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan pelacakan tidak tersedia'], 500);
    }
    $stmt->bind_param(
        'isssssssss',
        $tenantId, $no, $jenis, $klien, $objek, $tglMasuk, $tglTtd,
        $status, $catatan, $riwayat
    );
    $stmt->execute();
    $insertId = $conn->insert_id;
    $stmt->close();

    audit_log($conn, 'pelacakan.create', 'pelacakan', (string) $insertId);
    json_response(['status' => 'success', 'id' => $insertId]);
}

if ($action === 'load') {
    $stmt = $conn->prepare(
        'SELECT id, no_berkas, jenis_proses, klien, objek, tgl_masuk, tgl_ttd,
                status, catatan, riwayat_status, waktu_update
         FROM tabel_pelacakan
         WHERE tenant_id = ?
         ORDER BY waktu_update DESC'
    );
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan pelacakan tidak tersedia'], 500);
    }
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $arsip = [];
    while ($row = $result->fetch_assoc()) {
        $arsip[] = $row;
    }
    $stmt->close();
    json_response($arsip);
}

if ($action === 'delete') {
    require_post();
    require_csrf();
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        json_response(['status' => 'error', 'pesan' => 'ID berkas tidak valid'], 422);
    }

    $stmt = $conn->prepare('DELETE FROM tabel_pelacakan WHERE id = ? AND tenant_id = ?');
    if (!$stmt) {
        json_response(['status' => 'error', 'pesan' => 'Layanan pelacakan tidak tersedia'], 500);
    }
    $stmt->bind_param('ii', $id, $tenantId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted !== 1) {
        json_response(['status' => 'error', 'pesan' => 'Berkas tidak ditemukan atau bukan milik kantor ini'], 404);
    }
    audit_log($conn, 'pelacakan.delete', 'pelacakan', (string) $id);
    json_response(['status' => 'success']);
}

json_response(['status' => 'error', 'pesan' => 'Aksi tidak dikenal'], 400);
