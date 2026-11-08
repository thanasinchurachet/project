<?php
session_start();
header('Content-Type: application/json');

if (isset($_SESSION['userid'])) {
    echo json_encode([
        'success' => true,
        'userId' => $_SESSION['userid']
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่ได้เข้าสู่ระบบ'
    ]);
}
?>