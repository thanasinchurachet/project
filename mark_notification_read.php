<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['userid'])) {
        throw new Exception('User not authenticated');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = $input['notificationId'] ?? null;

    if (!$notificationId) {
        throw new Exception('Notification ID is required');
    }

    $stmt = $pdo->prepare("
    UPDATE notifications 
    SET read_at = NOW(), is_read = 1
    WHERE id = ? AND userID = ?
");
    $stmt->execute([$notificationId, $_SESSION['userid']]);

    echo json_encode([
        'success' => true,
        'message' => 'Notification marked as read'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
