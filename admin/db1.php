<?php
// mysqli connection used by the admin API — settings come from .env (see config.php)
require_once __DIR__ . '/../config.php';

$conn = new mysqli(env('DB_HOST', 'localhost'), env('DB_USER', 'root'), env('DB_PASSWORD', ''), env('DB_NAME', 'waste_system'));

if ($conn->connect_error) {
    die("การเชื่อมต่อฐานข้อมูลล้มเหลว — ตรวจสอบ DB_* ใน .env");
}
$conn->set_charset("utf8mb4");
