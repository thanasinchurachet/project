<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['userid'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$userId = $_SESSION['userid'];
$postId = $_POST['postId'];

try {
    $pdo->beginTransaction();

    // ตรวจสอบว่าเคยไลค์หรือยัง
    $stmt = $pdo->prepare("SELECT * FROM likes WHERE postId = ? AND userId = ?");
    $stmt->execute([$postId, $userId]);
    $existing_like = $stmt->fetch();

    if ($existing_like) {
        // ถ้าเคยไลค์แล้ว ให้ลบไลค์
        $stmt = $pdo->prepare("DELETE FROM likes WHERE postId = ? AND userId = ?");
        $stmt->execute([$postId, $userId]);

        // ลดจำนวนไลค์ในตาราง post
        $stmt = $pdo->prepare("UPDATE post SET likes = likes - 1 WHERE postId = ?");
        $stmt->execute([$postId]);
        
        $is_liked = false;
    } else {
        // ถ้ายังไม่เคยไลค์ ให้เพิ่มไลค์
        $stmt = $pdo->prepare("INSERT INTO likes (postId, userId) VALUES (?, ?)");
        $stmt->execute([$postId, $userId]);

        // เพิ่มจำนวนไลค์ในตาราง post
        $stmt = $pdo->prepare("UPDATE post SET likes = likes + 1 WHERE postId = ?");
        $stmt->execute([$postId]);
        
        $is_liked = true;
    }

    $pdo->commit();

    // ดึงจำนวนไลค์ล่าสุด
    $stmt = $pdo->prepare("SELECT likes FROM post WHERE postId = ?");
    $stmt->execute([$postId]);
    $current_likes = $stmt->fetch()['likes'];

    echo json_encode([
        'success' => true,
        'is_liked' => $is_liked,
        'likes' => $current_likes
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
?>