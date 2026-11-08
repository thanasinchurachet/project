<?php
// ไฟล์: get_pending_items.php
header('Content-Type: application/json');
require_once 'db1.php'; // ตรวจสอบที่อยู่ของไฟล์เชื่อมต่อฐานข้อมูล

try {
    // นับจำนวนโพสต์รอการอนุมัติ
    $postQuery = "SELECT COUNT(*) as pendingPosts FROM post WHERE status = 'pending'";
    $postResult = $conn->query($postQuery);
    $pendingPosts = $postResult->fetch_assoc()['pendingPosts'];

    // นับจำนวนคอมเมนต์รอการดำเนินการ
    $commentQuery = "SELECT COUNT(*) as pendingComments FROM comment_report WHERE action_status = 'pending'";
    $commentResult = $conn->query($commentQuery);
    $pendingComments = $commentResult->fetch_assoc()['pendingComments'];

    // Query เพิ่มเติมเพื่อดึงรายละเอียดของโพสต์รออนุมัติล่าสุด
    $latestPostsQuery = "SELECT p.postId, p.title, p.date, u.name as author 
                        FROM post p 
                        JOIN users u ON p.userId = u.userID 
                        WHERE p.status = 'pending' 
                        ORDER BY p.date DESC 
                        LIMIT 5";
    $latestPostsResult = $conn->query($latestPostsQuery);
    $latestPosts = [];
    
    if ($latestPostsResult && $latestPostsResult->num_rows > 0) {
        while ($row = $latestPostsResult->fetch_assoc()) {
            $latestPosts[] = $row;
        }
    }

    // Query เพิ่มเติมเพื่อดึงรายละเอียดของคอมเมนต์รอการดำเนินการล่าสุด
    $latestCommentsQuery = "SELECT cr.commentID, c.content, cr.Report_date, u.name as reporter 
                           FROM comment_report cr 
                           JOIN comment c ON cr.commentID = c.commentID 
                           JOIN users u ON cr.userID = u.userID 
                           WHERE cr.action_status = 'pending' 
                           ORDER BY cr.Report_date DESC 
                           LIMIT 5";
    $latestCommentsResult = $conn->query($latestCommentsQuery);
    $latestComments = [];
    
    if ($latestCommentsResult && $latestCommentsResult->num_rows > 0) {
        while ($row = $latestCommentsResult->fetch_assoc()) {
            // ตัดคอมเมนต์ให้สั้นลงถ้ายาวเกินไป
            $row['content'] = strlen($row['content']) > 50 
                ? substr($row['content'], 0, 50) . '...' 
                : $row['content'];
            $latestComments[] = $row;
        }
    }

    // ส่งข้อมูลกลับในรูปแบบ JSON
    echo json_encode([
        'pendingPosts' => (int)$pendingPosts,
        'pendingComments' => (int)$pendingComments,
        'latestPosts' => $latestPosts,
        'latestComments' => $latestComments,
        'success' => true
    ]);
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'success' => false
    ]);
}
$conn->close();
?>

