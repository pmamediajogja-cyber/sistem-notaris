<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/api_guard.php';
require_once __DIR__ . '/koneksi.php';

$conn = $koneksi;
security_headers();
$user = api_guard(false);
$tenantId = tenant_id_from_user($user);

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$documentId || $documentId < 1) {
    json_response(['status' => 'error', 'pesan' => 'ID dokumen tidak valid'], 422);
}

$rootConfig = rtrim((string) env_value('STORAGE_ROOT', ''), DIRECTORY_SEPARATOR);
if ($rootConfig === '' || !is_dir($rootConfig)) {
    json_response(['status' => 'error', 'pesan' => 'Penyimpanan dokumen belum dikonfigurasi'], 503);
}

$root = realpath($rootConfig);
if ($root === false) {
    json_response(['status' => 'error', 'pesan' => 'Penyimpanan dokumen tidak tersedia'], 503);
}
$rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

$stmt = $conn->prepare('SELECT id, original_name, storage_key, mime_type, size_bytes FROM documents WHERE id=? AND tenant_id=? AND deleted_at IS NULL LIMIT 1');
if (!$stmt) {
    json_response(['status' => 'error', 'pesan' => 'Layanan dokumen tidak tersedia'], 500);
}
$stmt->bind_param('ii', $documentId, $tenantId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$document) {
    json_response(['status' => 'error', 'pesan' => 'Dokumen tidak ditemukan'], 404);
}

$storageKey = (string) $document['storage_key'];
if ($storageKey === '' || str_contains($storageKey, "\0") || str_starts_with($storageKey, '/') || str_starts_with($storageKey, '\\')) {
    json_response(['status' => 'error', 'pesan' => 'Lokasi dokumen tidak valid'], 500);
}

$path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storageKey);
$realPath = realpath($path);
if ($realPath === false || !is_file($realPath) || !is_readable($realPath) || !str_starts_with($realPath, $rootPrefix)) {
    json_response(['status' => 'error', 'pesan' => 'File dokumen tidak tersedia'], 404);
}

$size = filesize($realPath);
if ($size === false) {
    json_response(['status' => 'error', 'pesan' => 'File dokumen tidak dapat dibaca'], 500);
}

$downloadName = basename((string) $document['original_name']);
$downloadName = preg_replace('/[\x00-\x1F\x7F"\\\/]+/', '_', $downloadName) ?: 'dokumen';
$downloadName = trim($downloadName, " .\t\n\r\0\x0B");
if ($downloadName === '') $downloadName = 'dokumen';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . (string) $document['mime_type']);
header('Content-Length: ' . (string) $size);
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"") . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($realPath);
audit_log($conn, 'document.download', 'documents', (string) $documentId);
exit;
