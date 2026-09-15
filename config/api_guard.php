<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function api_guard(bool $write = false): array
{
    $user = require_auth();

    if ($write) {
        require_post();
        require_csrf();
    }

    $origin = env_value('ALLOWED_ORIGIN');
    if ($origin) {
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($requestOrigin !== '' && hash_equals($origin, $requestOrigin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
    }

    return $user;
}
