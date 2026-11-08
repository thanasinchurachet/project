<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['userid'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$userId = $_SESSION['userid'];

if (!isset($_GET['postId']) || empty($_GET['postId'])) {
    echo json_encode(['success' => false, 'error' => 'ไม่พบรหัสโพสต์']);
    exit;
}

$postId = intval($_GET['postId']);

try {
    // ตรวจสอบจำนวนไลค์จากตาราง post
    $stmt = $pdo->prepare("SELECT likes FROM post WHERE postId = ?");
    $stmt->execute([$postId]);
    $postData = $stmt->fetch();
    
    if (!$postData) {
        echo json_encode(['success' => false, 'error' => 'โพสต์ไม่พบ']);
        exit;
    }
    
    $likeCount = $postData['likes'] ?: 0;
    
    // ตรวจสอบว่าผู้ใช้ปัจจุบันกดไลค์หรือไม่
    $stmt = $pdo->prepare("SELECT COUNT(*) as is_liked FROM likes WHERE postId = ? AND userId = ?");
    $stmt->execute([$postId, $userId]);
    $isLiked = $stmt->fetch()['is_liked'] > 0;
    
    echo json_encode([
        'success' => true,
        'likes' => $likeCount,
        'is_liked' => $isLiked
    ]);
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>