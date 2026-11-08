<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

try {
    // ตรวจสอบว่าผู้ใช้เข้าสู่ระบบ
    if (!isset($_SESSION['userid'])) {
        throw new Exception('กรุณาเข้าสู่ระบบ');
    }

    // รับข้อมูล
    $commentId = $_POST['commentId'] ?? null;
    $newContent = trim($_POST['comment'] ?? '');

    // ตรวจสอบความถูกต้องของข้อมูล
    if (!$commentId) {
        throw new Exception('ไม่พบความคิดเห็น');
    }

    if (empty($newContent)) {
        throw new Exception('กรุณากรอกความคิดเห็น');
    }

    // ตรวจสอบว่าผู้ใช้เป็นเจ้าของความคิดเห็นหรือไม่
    $checkOwnerQuery = "SELECT userID FROM comment WHERE commentID = ?";
    $stmt = $pdo->prepare($checkOwnerQuery);
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment || $comment['userID'] != $_SESSION['userid']) {
        throw new Exception('คุณไม่มีสิทธิ์แก้ไขความคิดเห็นนี้');
    }

    // อัปเดตความคิดเห็น
    $updateQuery = "UPDATE comment SET content = ?, date_created = NOW() WHERE commentID = ?";
    $stmt = $pdo->prepare($updateQuery);
    $stmt->execute([$newContent, $commentId]);

    // ส่งการตอบกลับ
    echo json_encode([
        'success' => true,
        'message' => 'แก้ไขความคิดเห็นสำเร็จ'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>