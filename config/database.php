<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function database_connection(): mysqli
{
    $host = env_value('DB_HOST', '127.0.0.1');
    $port = (int) env_value('DB_PORT', '3306');
    $database = env_value('DB_DATABASE', 'notaris');
    $username = env_value('DB_USERNAME', 'root');
    $password = env_value('DB_PASSWORD', '');

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init();
    $connected = $db->real_connect($host, $username, $password, $database, $port);

    if (!$connected) {
        error_log('Database connection failed: ' . $db->connect_error);
        http_response_code(500);
        exit('Layanan database tidak tersedia.');
    }

    $db->set_charset('utf8mb4');
    return $db;
}
