<?php
// PDO connection — settings come from .env (see config.php and .env.example)
require_once __DIR__ . '/config.php';

$db_host = env('DB_HOST', 'localhost');
$db_name = env('DB_NAME', 'waste_system');
$db_user = env('DB_USER', 'root');
$db_pass = env('DB_PASSWORD', '');

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo "Database connection failed. Check DB_* in .env";
    exit();
}
