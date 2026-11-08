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

    // ตรวจสอบความถูกต้องของข้อมูล
    if (!$commentId) {
        throw new Exception('ไม่พบความคิดเห็น');
    }

    // ตรวจสอบว่าผู้ใช้เป็นเจ้าของความคิดเห็นหรือไม่
    $checkOwnerQuery = "SELECT userID, postID FROM comment WHERE commentID = ?";
    $stmt = $pdo->prepare($checkOwnerQuery);
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment || $comment['userID'] != $_SESSION['userid']) {
        throw new Exception('คุณไม่มีสิทธิ์ลบความคิดเห็นนี้');
    }

    // เก็บ postID ไว้ก่อนลบ
    $postId = $comment['postID'];

    // ลบความคิดเห็น
    $deleteQuery = "DELETE FROM comment WHERE commentID = ?";
    $stmt = $pdo->prepare($deleteQuery);
    $stmt->execute([$commentId]);

    // นับจำนวนคอมเมนต์ปัจจุบันของโพสต์
    $countQuery = "SELECT COUNT(*) AS comment_count FROM comment WHERE postID = ? AND status = 'visible'";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute([$postId]);
    $commentCount = $stmt->fetch(PDO::FETCH_ASSOC)['comment_count'];

    // ส่งการตอบกลับพร้อมจำนวนคอมเมนต์
    echo json_encode([
        'success' => true,
        'message' => 'ลบความคิดเห็นสำเร็จ',
        'postId' => $postId,
        'comment_count' => $commentCount
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>