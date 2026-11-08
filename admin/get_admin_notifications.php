<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
require_once 'db1.php'; // ไฟล์เชื่อมต่อฐานข้อมูล

try {
    // ดึงข้อมูลโพสต์ที่รออนุมัติ
    $query_posts = "SELECT COUNT(*) AS post_count FROM post WHERE status = 'pending'";
    $result_posts = $conn->query($query_posts);
    $post_data = $result_posts->fetch_assoc();
    $pending_posts = $post_data['post_count'];
    
    // ดึงข้อมูลคอมเมนต์ที่รอการดำเนินการ
    $query_comments = "SELECT COUNT(*) AS comment_count FROM comment_report WHERE action_status = 'pending'";
    $result_comments = $conn->query($query_comments);
    $comment_data = $result_comments->fetch_assoc();
    $pending_comments = $comment_data['comment_count'];
    
    $notifications = [];
    
    if ($pending_posts > 0) {
        $notifications[] = [
            'type' => 'โพสต์',
            'message' => "มีโพสต์รออนุมัติ {$pending_posts} รายการ",
            'created_at' => date('Y-m-d H:i:s'),
            'count' => $pending_posts
        ];
    }
    
    if ($pending_comments > 0) {
        $notifications[] = [
            'type' => 'คอมเมนต์',
            'message' => "มีคอมเมนต์รอการดำเนินการ {$pending_comments} รายการ",
            'created_at' => date('Y-m-d H:i:s'),
            'count' => $pending_comments
        ];
    }
    
    echo json_encode($notifications);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
$conn->close();
?>