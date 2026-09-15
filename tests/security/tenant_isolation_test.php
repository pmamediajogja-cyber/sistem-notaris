<?php
declare(strict_types=1);

/**
 * Phase 2B staging-only tenant isolation smoke test.
 *
 * Required environment variables:
 * BASE_URL, TENANT_A_EMAIL, TENANT_A_PASSWORD,
 * TENANT_B_CLIENT_ID, TENANT_B_MATTER_ID, TENANT_B_DOCUMENT_ID.
 *
 * Never run against production or with real client credentials/documents.
 */

$required = ['BASE_URL','TENANT_A_EMAIL','TENANT_A_PASSWORD','TENANT_B_CLIENT_ID','TENANT_B_MATTER_ID','TENANT_B_DOCUMENT_ID'];
foreach ($required as $key) {
    if (getenv($key) === false || getenv($key) === '') {
        fwrite(STDERR, "Missing environment variable: {$key}\n");
        exit(2);
    }
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "PHP cURL extension is required.\n");
    exit(2);
}

$base = rtrim((string)getenv('BASE_URL'), '/');
$cookie = tempnam(sys_get_temp_dir(), 'notaris-test-');
if ($cookie === false) exit(2);
register_shutdown_function(static function () use ($cookie): void { @unlink($cookie); });

function request(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array
{
    global $cookie;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

function jsonBody(string $email, string $password): string
{
    return http_build_query(['action'=>'login','email'=>$email,'password'=>$password]);
}

function login(string $base, string $email, string $password): string
{
    [, , $body] = request($base.'/auth.php', 'POST', ['Content-Type: application/x-www-form-urlencoded'], jsonBody($email, $password));
    $json = json_decode($body, true);
    if (!is_array($json) || ($json['status'] ?? '') !== 'ok' || empty($json['csrf_token'])) {
        throw new RuntimeException('Staging login failed');
    }
    return (string)$json['csrf_token'];
}

function assertTrue(bool $condition, string $message): void
{
    echo ($condition ? "PASS" : "FAIL") . "  {$message}\n";
    if (!$condition) $GLOBALS['failed'] = true;
}

$failed = false;
$csrf = login($base, (string)getenv('TENANT_A_EMAIL'), (string)getenv('TENANT_A_PASSWORD'));
$bClient = (int)getenv('TENANT_B_CLIENT_ID');
$bMatter = (int)getenv('TENANT_B_MATTER_ID');
$bDocument = (int)getenv('TENANT_B_DOCUMENT_ID');

[$status, , $body] = request($base.'/api_clients.php?action=list');
$data = json_decode($body, true);
$ids = is_array($data) ? array_map('intval', array_column($data, 'id')) : [];
assertTrue($status === 200 && !in_array($bClient, $ids, true), 'Tenant A cannot list Tenant B client');

$matterPayload = json_encode(['id'=>$bMatter,'client_id'=>$bClient,'title'=>'FORGED CROSS TENANT','status'=>'open']);
[$status] = request($base.'/api_matters.php?action=save', 'POST', ['Content-Type: application/json','X-CSRF-Token: '.$csrf], $matterPayload);
assertTrue($status >= 400 && $status < 500, 'Tenant A cannot update Tenant B matter');

[$status] = request($base.'/api_clients.php?action=delete', 'POST', ['Content-Type: application/x-www-form-urlencoded','X-CSRF-Token: '.$csrf], http_build_query(['id'=>$bClient]));
assertTrue($status >= 400 && $status < 500, 'Tenant A cannot deactivate Tenant B client');

[$status, , $body] = request($base.'/api_documents.php?action=list&matter_id='.$bMatter);
$data = json_decode($body, true);
assertTrue($status === 404 || $status === 200 && $data === [], 'Tenant A cannot list Tenant B documents');

[$status] = request($base.'/api_documents.php?action=delete', 'POST', ['Content-Type: application/x-www-form-urlencoded','X-CSRF-Token: '.$csrf], http_build_query(['id'=>$bDocument]));
assertTrue($status >= 400 && $status < 500, 'Tenant A cannot delete Tenant B document');

[$status] = request($base.'/api_status_history.php?action=list&matter_id='.$bMatter);
assertTrue($status >= 400 && $status < 500, 'Tenant A cannot read Tenant B status history');

if ($failed) {
    fwrite(STDERR, "\nSecurity smoke test FAILED. Do not promote this build.\n");
    exit(1);
}

echo "\nSecurity smoke test PASSED. This is staging evidence only.\n";
exit(0);
