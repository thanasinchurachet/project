<?php
session_start(); 
header('Content-Type: application/json');  
require_once 'db.php'; 

try {
    // ตรวจสอบว่าผู้ใช้ล็อกอินหรือไม่
    $current_user_id = $_SESSION['userid'] ?? null;

    $query = "
    SELECT 
        p.postID AS post_id,
        p.userID,
        p.trashId,
        t.Waste_type,
        u.name AS username,
        u.profile_img AS avatar_path,
        p.Management_type,
        p.title,
        p.likes,
        p.details,
        p.image,
        p.video_source,
        p.video_url,
        p.video_platform,
        p.video_path,
        p.video_mime,
        p.video_size,
        p.date,
        p.status AS post_status,  /* เพิ่มการดึงสถานะโพสต์ */
        CASE WHEN l.userId IS NOT NULL THEN 1 ELSE 0 END as is_liked,
        COUNT(DISTINCT c.commentID) AS comment_count
    FROM post p
    JOIN users u ON p.userID = u.userID
    JOIN trash t ON p.trashId = t.trashId
    LEFT JOIN likes l ON p.postID = l.postId AND l.userId = ?
    LEFT JOIN comment c ON p.postID = c.postID AND c.status = 'visible'
    WHERE p.status = 'approved'  /* เพิ่มเงื่อนไขให้แสดงเฉพาะโพสต์ที่ approved */
    GROUP BY 
        p.postID,
        p.userID,
        p.trashId,
        t.Waste_type,
        u.name,
        u.profile_img,
        p.Management_type,
        p.title,
        p.likes,
        p.details,
        p.image,
        p.video_source,
        p.video_url,
        p.video_platform,
        p.video_path,
        p.video_mime,
        p.video_size,
        p.date,
        p.status,
        is_liked
    ORDER BY p.date DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$current_user_id]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // แปลงข้อมูลรูปภาพ
    foreach ($posts as &$post) {
        // เพิ่มการตรวจสอบ
        $post['images'] = [];

        if (!empty($post['image'])) {
            // พยายามแปลง JSON string เป็น array
            $images = json_decode(trim($post['image']), true);
            
            // กรองข้อมูลรูปภาพ
            if (is_array($images)) {
                $post['images'] = array_filter($images, function($img) {
                    return !empty(trim($img));
                });
            } elseif (!empty(trim($post['image']))) {
                $post['images'] = [trim($post['image'])];
            }
        }

        // เคลียร์ฟิลด์เดิม
        unset($post['image']);
    }

    echo json_encode($posts);
} catch (Exception $e) {
    // บันทึกข้อผิดพลาด
    error_log('Error in get_posts.php: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
