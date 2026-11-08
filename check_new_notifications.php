<?php
// check_new_notifications.php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['userid'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$userId = (int)$_SESSION['userid'];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE userID = ? 
        AND (read_at IS NULL OR is_read = 0)
        AND created_at >= NOW() - INTERVAL 24 HOUR
    ");
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();
    
    echo json_encode(['count' => $count]);
} catch (Throwable $e) {
    error_log("Error in check_new_notifications.php: " . $e->getMessage());
    echo json_encode(['count' => 0]);
}