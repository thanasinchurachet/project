<?php
session_start();
require_once 'db.php';

// ตรวจสอบว่ามีการส่ง postId มาหรือไม่
if (isset($_POST['postId'])) {
    $postId = $_POST['postId'];
    
    // ตรวจสอบ session - ใช้ userid ตามที่มีใน nav.php
    if (isset($_SESSION['userid'])) {
        $userId = $_SESSION['userid'];
        
        try {
            // ตรวจสอบสถานะการไลค์ - แก้ชื่อคอลัมน์ให้ตรงกับฐานข้อมูล
            $stmt = $pdo->prepare("SELECT * FROM likes WHERE postId = ? AND userId = ?");
            $stmt->execute([$postId, $userId]);
            $like_status = $stmt->fetch() ? true : false;
            
            // ดึงจำนวนไลค์ทั้งหมดของโพสต์ - แก้ชื่อคอลัมน์ให้ตรงกับฐานข้อมูล
            $stmt = $pdo->prepare("SELECT likes FROM post WHERE postId = ?");
            $stmt->execute([$postId]);
            $total_likes = $stmt->fetch(PDO::FETCH_ASSOC)['likes'];
            
            echo json_encode([
                'is_liked' => $like_status,
                'total_likes' => $total_likes,
                'userId' => $userId // เพิ่มการส่ง userId กลับไปเพื่อตรวจสอบ
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        echo json_encode([
            'is_liked' => false,
            'total_likes' => 0,
            'error' => 'Not logged in'
        ]);
    }
} else {
    echo json_encode(['error' => 'No post ID provided']);
}
?>