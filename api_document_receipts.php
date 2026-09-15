<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/api_guard.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

security_headers();
$user = api_guard(false);
$tenantId = tenant_id_from_user($user);
$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list')));

if ($action === 'list') {
    $stmt = $koneksi->prepare('SELECT id, matter_id, direction, counterparty_name, receipt_date, staff_name, notes, created_at FROM document_receipts WHERE tenant_id = ? ORDER BY id DESC LIMIT 200');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan tanda terima tidak tersedia'], 500);
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['matter_id'] = (int)$row['matter_id'];
        $rows[] = $row;
    }
    $stmt->close();
    json_response(['status' => 'ok', 'data' => $rows]);
}

if ($action === 'create') {
    require_post();
    require_csrf();

    $matterId = filter_input(INPUT_POST, 'matter_id', FILTER_VALIDATE_INT);
    $direction = strtolower(trim((string)($_POST['direction'] ?? 'incoming')));
    $counterparty = trim((string)($_POST['counterparty_name'] ?? ''));
    $receiptDate = trim((string)($_POST['receipt_date'] ?? ''));
    $staffName = trim((string)($_POST['staff_name'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $itemsRaw = (string)($_POST['items'] ?? '[]');
    $items = json_decode($itemsRaw, true);

    if (!$matterId || !in_array($direction, ['incoming', 'outgoing'], true) || $counterparty === '' || strlen($counterparty) > 200 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate) || strlen($staffName) > 200 || strlen($notes) > 5000 || !is_array($items) || count($items) < 1 || count($items) > 100) {
        json_response(['status' => 'error', 'pesan' => 'Data tanda terima tidak valid'], 422);
    }

    $matterStmt = $koneksi->prepare('SELECT id FROM matters WHERE id = ? AND tenant_id = ? LIMIT 1');
    if (!$matterStmt) json_response(['status' => 'error', 'pesan' => 'Perkara tidak dapat diperiksa'], 500);
    $matterStmt->bind_param('ii', $matterId, $tenantId);
    $matterStmt->execute();
    $matter = $matterStmt->get_result()->fetch_assoc();
    $matterStmt->close();
    if (!$matter) json_response(['status' => 'error', 'pesan' => 'Perkara tidak ditemukan'], 404);

    $cleanItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) json_response(['status' => 'error', 'pesan' => 'Item dokumen tidak valid'], 422);
        $description = trim((string)($item['description'] ?? ''));
        $quantity = filter_var($item['quantity'] ?? 1, FILTER_VALIDATE_INT);
        $documentId = isset($item['document_id']) && $item['document_id'] !== '' ? filter_var($item['document_id'], FILTER_VALIDATE_INT) : null;
        if ($description === '' || strlen($description) > 500 || $quantity === false || $quantity < 1 || $quantity > 9999 || ($documentId !== null && (!$documentId || $documentId < 1))) json_response(['status' => 'error', 'pesan' => 'Item dokumen tidak valid'], 422);
        $cleanItems[] = ['description' => $description, 'quantity' => $quantity, 'document_id' => $documentId];
    }

    $verifiedDocs = [];
    $docIds = array_values(array_unique(array_filter(array_column($cleanItems, 'document_id'), fn($id) => $id !== null)));
    if ($docIds) {
        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        $types = str_repeat('i', count($docIds) + 2);
        $sql = 'SELECT id FROM documents WHERE tenant_id = ? AND matter_id = ? AND deleted_at IS NULL AND id IN (' . $placeholders . ')';
        $stmt = $koneksi->prepare($sql);
        if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Dokumen tidak dapat diperiksa'], 500);
        $params = array_merge([$tenantId, $matterId], $docIds);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $verifiedDocs[(int)$row['id']] = true;
        $stmt->close();
        if (count($verifiedDocs) !== count($docIds)) json_response(['status' => 'error', 'pesan' => 'Ada dokumen yang tidak terkait dengan perkara ini'], 422);
    }

    if (!$koneksi->begin_transaction()) json_response(['status' => 'error', 'pesan' => 'Gagal memulai penyimpanan'], 500);
    try {
        $stmt = $koneksi->prepare('INSERT INTO document_receipts (tenant_id, matter_id, direction, counterparty_name, receipt_date, staff_name, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('receipt prepare');
        $createdBy = (int)$user['user_id'];
        $stmt->bind_param('iisssssi', $tenantId, $matterId, $direction, $counterparty, $receiptDate, $staffName, $notes, $createdBy);
        if (!$stmt->execute()) throw new RuntimeException('receipt insert');
        $receiptId = (int)$koneksi->insert_id;
        $stmt->close();

        $itemStmt = $koneksi->prepare('INSERT INTO document_receipt_items (tenant_id, receipt_id, document_id, description, quantity) VALUES (?, ?, ?, ?, ?)');
        if (!$itemStmt) throw new RuntimeException('item prepare');
        foreach ($cleanItems as $item) {
            $docId = $item['document_id'];
            $itemStmt->bind_param('iiisi', $tenantId, $receiptId, $docId, $item['description'], $item['quantity']);
            if (!$itemStmt->execute()) throw new RuntimeException('item insert');
        }
        $itemStmt->close();
        if (!$koneksi->commit()) throw new RuntimeException('commit');
    } catch (Throwable $e) {
        $koneksi->rollback();
        json_response(['status' => 'error', 'pesan' => 'Tanda terima gagal disimpan'], 500);
    }

    audit_log($koneksi, 'document_receipt.create', 'document_receipts', (string)$receiptId);
    json_response(['status' => 'ok', 'pesan' => 'Tanda terima berhasil disimpan', 'receipt_id' => $receiptId], 201);
}

json_response(['status' => 'error', 'pesan' => 'Aksi tanda terima tidak dikenal'], 400);
