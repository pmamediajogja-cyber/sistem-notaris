<?php
declare(strict_types=1);

require_once __DIR__ . '/config/api_guard.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

security_headers();
$action = $_GET['action'] ?? 'list';
$user = api_guard($action !== 'list');
$tenantId = tenant_id_from_user($user);
$conn = $koneksi;

function receipt_date_valid(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function receipt_items_valid(mixed $items): bool
{
    if (!is_array($items) || count($items) < 1 || count($items) > 100) return false;
    foreach ($items as $item) {
        if (!is_array($item)) return false;
        $description = trim((string)($item['description'] ?? ''));
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($description === '' || mb_strlen($description) > 500 || $quantity === false) return false;
    }
    return true;
}

if ($action === 'list') {
    $stmt = $conn->prepare(
        'SELECT r.id, r.matter_id, r.direction, r.counterparty_name, r.receipt_date, r.staff_name, r.created_at,
                m.matter_code, m.title
         FROM document_receipts r
         INNER JOIN matters m ON m.tenant_id = r.tenant_id AND m.id = r.matter_id
         WHERE r.tenant_id = ?
         ORDER BY r.receipt_date DESC, r.id DESC
         LIMIT 500'
    );
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan tanda terima tidak tersedia'], 500);
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    json_response(['status' => 'success', 'data' => $rows]);
}

if ($action === 'detail') {
    $receiptId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$receiptId || $receiptId < 1) json_response(['status' => 'error', 'pesan' => 'ID tanda terima tidak valid'], 422);

    $stmt = $conn->prepare(
        'SELECT r.id, r.matter_id, r.direction, r.counterparty_name, r.receipt_date, r.staff_name, r.created_at,
                m.matter_code, m.title
         FROM document_receipts r
         INNER JOIN matters m ON m.tenant_id = r.tenant_id AND m.id = r.matter_id
         WHERE r.id = ? AND r.tenant_id = ? LIMIT 1'
    );
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan tanda terima tidak tersedia'], 500);
    $stmt->bind_param('ii', $receiptId, $tenantId);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$receipt) json_response(['status' => 'error', 'pesan' => 'Tanda terima tidak ditemukan'], 404);

    $itemStmt = $conn->prepare('SELECT id, description, quantity FROM document_receipt_items WHERE receipt_id = ? AND tenant_id = ? ORDER BY id ASC');
    if (!$itemStmt) json_response(['status' => 'error', 'pesan' => 'Layanan item tanda terima tidak tersedia'], 500);
    $itemStmt->bind_param('ii', $receiptId, $tenantId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    $items = [];
    while ($item = $result->fetch_assoc()) $items[] = $item;
    $itemStmt->close();

    $receipt['items'] = $items;
    json_response(['status' => 'success', 'data' => $receipt]);
}

if ($action === 'create') {
    $matterId = filter_input(INPUT_POST, 'matter_id', FILTER_VALIDATE_INT);
    $direction = trim((string)($_POST['direction'] ?? ''));
    $counterparty = trim((string)($_POST['counterparty_name'] ?? ''));
    $receiptDate = trim((string)($_POST['receipt_date'] ?? ''));
    $staffName = trim((string)($_POST['staff_name'] ?? ''));
    $itemsRaw = (string)($_POST['items'] ?? '');
    $items = json_decode($itemsRaw, true);

    if (!$matterId || $matterId < 1 || !in_array($direction, ['incoming', 'outgoing'], true) ||
        $counterparty === '' || mb_strlen($counterparty) > 200 || !receipt_date_valid($receiptDate) ||
        mb_strlen($staffName) > 200 || !receipt_items_valid($items)) {
        json_response(['status' => 'error', 'pesan' => 'Data tanda terima tidak valid'], 422);
    }

    $matterCheck = $conn->prepare('SELECT id FROM matters WHERE id = ? AND tenant_id = ? LIMIT 1');
    if (!$matterCheck) json_response(['status' => 'error', 'pesan' => 'Layanan tanda terima tidak tersedia'], 500);
    $matterCheck->bind_param('ii', $matterId, $tenantId);
    $matterCheck->execute();
    $matter = $matterCheck->get_result()->fetch_assoc();
    $matterCheck->close();
    if (!$matter) json_response(['status' => 'error', 'pesan' => 'Perkara tidak ditemukan'], 404);

    $createdBy = (int)$user['user_id'];
    $staffValue = $staffName !== '' ? $staffName : null;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('INSERT INTO document_receipts (tenant_id, matter_id, direction, counterparty_name, receipt_date, staff_name, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('receipt_prepare');
        $stmt->bind_param('iissssi', $tenantId, $matterId, $direction, $counterparty, $receiptDate, $staffValue, $createdBy);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('receipt_insert'); }
        $receiptId = (int)$conn->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare('INSERT INTO document_receipt_items (tenant_id, receipt_id, description, quantity) VALUES (?, ?, ?, ?)');
        if (!$itemStmt) throw new RuntimeException('item_prepare');
        foreach ($items as $item) {
            $description = trim((string)$item['description']);
            $quantity = (int)$item['quantity'];
            $itemStmt->bind_param('iisi', $tenantId, $receiptId, $description, $quantity);
            if (!$itemStmt->execute()) { $itemStmt->close(); throw new RuntimeException('item_insert'); }
        }
        $itemStmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_response(['status' => 'error', 'pesan' => 'Tanda terima gagal disimpan'], 500);
    }

    audit_log($conn, 'document_receipt.create', 'document_receipts', (string)$receiptId);
    json_response(['status' => 'success', 'receipt_id' => $receiptId]);
}

json_response(['status' => 'error', 'pesan' => 'Aksi tidak dikenal'], 400);
