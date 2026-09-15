<?php
declare(strict_types=1);

require_once __DIR__ . '/config/api_guard.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

security_headers();
$action = $_GET['action'] ?? 'list';
$user = api_guard(in_array($action, ['create', 'update', 'delete'], true));
$tenantId = tenant_id_from_user($user);
$conn = $koneksi;

function invoice_money(mixed $value): float
{
    if (is_int($value) || is_float($value)) return max(0, (float) $value);
    $raw = preg_replace('/[^0-9]/', '', (string) $value);
    return $raw === '' ? 0.0 : (float) $raw;
}

function invoice_date_valid(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function invoice_items_valid(mixed $items): bool
{
    if (!is_array($items) || count($items) > 100) return false;
    foreach ($items as $item) {
        if (!is_array($item)) return false;
        $description = trim((string) ($item['description'] ?? ''));
        $amount = invoice_money($item['amount'] ?? 0);
        $distribution = (string) ($item['distribution'] ?? 'kosong');
        if ($description === '' || mb_strlen($description) > 500 || $amount < 0 ||
            !in_array($distribution, ['kosong', 'bagi2', 'pembeli', 'penjual'], true)) return false;
    }
    return true;
}

function generated_invoice_number(mysqli $conn, int $tenantId): string
{
    $prefix = 'INV-' . date('Ymd') . '-';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = $prefix . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $conn->prepare('SELECT id FROM invoices WHERE tenant_id = ? AND invoice_number = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('invoice_number_check');
        $stmt->bind_param('is', $tenantId, $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) return $candidate;
    }
    throw new RuntimeException('invoice_number_generation');
}

if ($action === 'list') {
    $stmt = $conn->prepare(
        'SELECT id, invoice_number, service_mode, invoice_date, property_reference, seller_name, buyer_name,
                real_transaction_amount, tax_base_amount, npoptkp_amount, burden_mode, created_at, updated_at
         FROM invoices
         WHERE tenant_id = ? AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 500'
    );
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['burden_mode'] = (bool) $row['burden_mode'];
        $rows[] = $row;
    }
    $stmt->close();
    json_response(['status' => 'success', 'data' => $rows]);
}

if ($action === 'detail') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) json_response(['status' => 'error', 'pesan' => 'ID invoice tidak valid'], 422);

    $stmt = $conn->prepare(
        'SELECT id, invoice_number, service_mode, invoice_date, property_reference, area_m2, seller_name, buyer_name,
                real_transaction_amount, tax_base_amount, npoptkp_amount, burden_mode, created_at, updated_at
         FROM invoices WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
    $stmt->bind_param('ii', $id, $tenantId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice) json_response(['status' => 'error', 'pesan' => 'Invoice tidak ditemukan'], 404);

    $itemStmt = $conn->prepare(
        'SELECT id, description, amount, distribution, sort_order
         FROM invoice_items WHERE invoice_id = ? AND tenant_id = ? ORDER BY sort_order ASC, id ASC'
    );
    if (!$itemStmt) json_response(['status' => 'error', 'pesan' => 'Layanan item invoice tidak tersedia'], 500);
    $itemStmt->bind_param('ii', $id, $tenantId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    $items = [];
    while ($item = $result->fetch_assoc()) $items[] = $item;
    $itemStmt->close();

    $invoice['id'] = (int) $invoice['id'];
    $invoice['burden_mode'] = (bool) $invoice['burden_mode'];
    $invoice['items'] = $items;
    json_response(['status' => 'success', 'data' => $invoice]);
}

if (in_array($action, ['create', 'update'], true)) {
    $raw = json_decode(file_get_contents('php://input'), true);
    if (!is_array($raw)) json_response(['status' => 'error', 'pesan' => 'Data JSON tidak valid'], 422);

    $serviceMode = (string) ($raw['service_mode'] ?? 'AJB');
    $invoiceDate = trim((string) ($raw['invoice_date'] ?? ''));
    $propertyReference = trim((string) ($raw['property_reference'] ?? ''));
    $areaM2 = isset($raw['area_m2']) && $raw['area_m2'] !== '' ? max(0, (float) $raw['area_m2']) : null;
    $seller = trim((string) ($raw['seller_name'] ?? ''));
    $buyer = trim((string) ($raw['buyer_name'] ?? ''));
    $realAmount = invoice_money($raw['real_transaction_amount'] ?? 0);
    $taxBase = invoice_money($raw['tax_base_amount'] ?? 0);
    $npoptkp = invoice_money($raw['npoptkp_amount'] ?? 0);
    $burdenMode = !empty($raw['burden_mode']) ? 1 : 0;
    $items = $raw['items'] ?? [];

    if (!in_array($serviceMode, ['AJB', 'UMUM'], true) ||
        ($invoiceDate !== '' && !invoice_date_valid($invoiceDate)) ||
        mb_strlen($propertyReference) > 255 || mb_strlen($seller) > 255 || mb_strlen($buyer) > 255 ||
        ($areaM2 !== null && $areaM2 > 999999999999) ||
        $realAmount > 999999999999999999 || $taxBase > 999999999999999999 || $npoptkp > 999999999999999999 ||
        !invoice_items_valid($items)) {
        json_response(['status' => 'error', 'pesan' => 'Data invoice tidak valid'], 422);
    }

    $id = isset($raw['id']) && is_numeric($raw['id']) ? (int) $raw['id'] : 0;
    if ($action === 'update' && $id < 1) json_response(['status' => 'error', 'pesan' => 'ID invoice tidak valid'], 422);

    if ($id > 0) {
        $exists = $conn->prepare('SELECT id, invoice_number FROM invoices WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1');
        if (!$exists) json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
        $exists->bind_param('ii', $id, $tenantId);
        $exists->execute();
        $existing = $exists->get_result()->fetch_assoc();
        $exists->close();
        if (!$existing) json_response(['status' => 'error', 'pesan' => 'Invoice tidak ditemukan atau bukan milik kantor ini'], 404);
        $invoiceNumber = (string) $existing['invoice_number'];
    } else {
        $requestedNumber = trim((string) ($raw['invoice_number'] ?? ''));
        $invoiceNumber = ($requestedNumber === '' || strtoupper($requestedNumber) === 'AUTO-GENERATE')
            ? generated_invoice_number($conn, $tenantId) : $requestedNumber;
        if (mb_strlen($invoiceNumber) > 100) json_response(['status' => 'error', 'pesan' => 'Nomor invoice terlalu panjang'], 422);
    }

    $invoiceDateValue = $invoiceDate !== '' ? $invoiceDate : null;
    $propertyValue = $propertyReference !== '' ? $propertyReference : null;
    $sellerValue = $seller !== '' ? $seller : null;
    $buyerValue = $buyer !== '' ? $buyer : null;
    $areaValue = $areaM2;
    $createdBy = (int) $user['user_id'];

    $conn->begin_transaction();
    try {
        if ($id > 0) {
            $stmt = $conn->prepare(
                'UPDATE invoices SET service_mode=?, invoice_date=?, property_reference=?, area_m2=?, seller_name=?, buyer_name=?,
                 real_transaction_amount=?, tax_base_amount=?, npoptkp_amount=?, burden_mode=?
                 WHERE id=? AND tenant_id=? AND deleted_at IS NULL'
            );
            if (!$stmt) throw new RuntimeException('invoice_update_prepare');
            $stmt->bind_param('sssdsddddiii', $serviceMode, $invoiceDateValue, $propertyValue, $areaValue, $sellerValue, $buyerValue,
                $realAmount, $taxBase, $npoptkp, $burdenMode, $id, $tenantId);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('invoice_update'); }
            $stmt->close();
            $deleteItems = $conn->prepare('DELETE FROM invoice_items WHERE invoice_id = ? AND tenant_id = ?');
            if (!$deleteItems) throw new RuntimeException('invoice_items_delete_prepare');
            $deleteItems->bind_param('ii', $id, $tenantId);
            if (!$deleteItems->execute()) { $deleteItems->close(); throw new RuntimeException('invoice_items_delete'); }
            $deleteItems->close();
            $auditAction = 'invoice.update';
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO invoices (tenant_id, invoice_number, service_mode, invoice_date, property_reference, area_m2,
                 seller_name, buyer_name, real_transaction_amount, tax_base_amount, npoptkp_amount, burden_mode, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) throw new RuntimeException('invoice_insert_prepare');
            $stmt->bind_param('issssdssdddii', $tenantId, $invoiceNumber, $serviceMode, $invoiceDateValue, $propertyValue, $areaValue,
                $sellerValue, $buyerValue, $realAmount, $taxBase, $npoptkp, $burdenMode, $createdBy);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('invoice_insert'); }
            $id = (int) $conn->insert_id;
            $stmt->close();
            $auditAction = 'invoice.create';
        }

        if (count($items) > 0) {
            $itemStmt = $conn->prepare(
                'INSERT INTO invoice_items (tenant_id, invoice_id, description, amount, distribution, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$itemStmt) throw new RuntimeException('invoice_item_prepare');
            foreach (array_values($items) as $index => $item) {
                $description = trim((string) $item['description']);
                $amount = invoice_money($item['amount'] ?? 0);
                $distribution = (string) ($item['distribution'] ?? 'kosong');
                $sortOrder = $index;
                $itemStmt->bind_param('iisdsi', $tenantId, $id, $description, $amount, $distribution, $sortOrder);
                if (!$itemStmt->execute()) { $itemStmt->close(); throw new RuntimeException('invoice_item_insert'); }
            }
            $itemStmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_response(['status' => 'error', 'pesan' => 'Invoice gagal disimpan'], 500);
    }

    audit_log($conn, $auditAction, 'invoices', (string) $id);
    json_response(['status' => 'success', 'id' => $id, 'invoice_number' => $invoiceNumber]);
}

if ($action === 'delete') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) json_response(['status' => 'error', 'pesan' => 'ID invoice tidak valid'], 422);

    $stmt = $conn->prepare('UPDATE invoices SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL');
    if (!$stmt) json_response(['status' => 'error', 'pesan' => 'Layanan invoice tidak tersedia'], 500);
    $stmt->bind_param('ii', $id, $tenantId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed !== 1) json_response(['status' => 'error', 'pesan' => 'Invoice tidak ditemukan atau bukan milik kantor ini'], 404);

    audit_log($conn, 'invoice.delete', 'invoices', (string) $id);
    json_response(['status' => 'success']);
}

json_response(['status' => 'error', 'pesan' => 'Aksi tidak dikenal'], 400);
