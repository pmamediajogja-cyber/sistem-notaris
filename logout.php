<?php
declare(strict_types=1);
require_once __DIR__ . '/config/auth.php';

require_post();
logout_user();
json_response(['status' => 'success']);
