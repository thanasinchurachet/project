<?php
session_start();
require_once 'db.php';

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['userid'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit();
}

// รับข้อมูลจาก client
$data = json_decode(file_get_contents('php://input'), true);
$postId = isset($data['postId']) ? (int)$data['postId'] : 0;

if ($postId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit();
}

try {
    // ตรวจสอบว่าผู้ใช้เคยไลค์โพสต์นี้หรือไม่
    $stmt = $pdo->prepare("SELECT * FROM likes WHERE userId = ? AND postId = ?");
    $stmt->execute([$_SESSION['userid'], $postId]);
    $like = $stmt->fetch();

    if (!$like) {
        echo json_encode(['success' => false, 'message' => 'คุณยังไม่ได้ถูกใจโพสต์นี้']);
        exit();
    }

    // ลบการไลค์ออกจากฐานข้อมูล
    $stmt = $pdo->prepare("DELETE FROM likes WHERE userId = ? AND postId = ?");
    $stmt->execute([$_SESSION['userid'], $postId]);

    // นับจำนวนไลค์ใหม่
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE postId = ?");
    $stmt->execute([$postId]);
    $likesCount = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'ยกเลิกถูกใจเรียบร้อย',
        'likes' => $likesCount
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการประมวลผล: ' . $e->getMessage()]);
}
?>