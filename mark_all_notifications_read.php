<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['userid'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $userId = (int)$_SESSION['userid'];

    // ทำเครื่องหมายว่าอ่านทั้งหมด ทั้งตั้ง read_at และ is_read เพื่อความสอดคล้องกับจุดอื่นๆ
    $sql = "UPDATE notifications SET read_at = NOW(), is_read = 1 WHERE userID = ? AND (read_at IS NULL OR is_read = 0)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $affected = $stmt->rowCount();

    echo json_encode(['success' => true, 'updated' => $affected]);
} catch (Throwable $e) {
    error_log('mark_all_notifications_read.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>

