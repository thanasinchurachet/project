<?php
// get_user_profile.php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

try {
    $userId = $_GET['userId'] ?? null;
    
    if (!$userId) {
        throw new Error('ไม่พบ User ID');
    }

    // ดึงข้อมูลผู้ใช้
    $userQuery = "
        SELECT 
            u.userID,
            u.name,
            u.email,
            u.profile_img,
            COUNT(DISTINCT p.postId) as post_count
        FROM users u
        LEFT JOIN post p ON u.userID = p.userId AND p.status = 'approved'
        WHERE u.userID = ?
        GROUP BY u.userID
    ";

    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userData) {
        throw new Error('ไม่พบข้อมูลผู้ใช้');
    }

    // ดึงโพสต์ของผู้ใช้
    $postsQuery = "
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
            CASE WHEN l.userId IS NOT NULL THEN 1 ELSE 0 END as is_liked,
            COUNT(DISTINCT c.commentID) AS comment_count
        FROM post p
        JOIN users u ON p.userID = u.userID
        JOIN trash t ON p.trashId = t.trashId
        LEFT JOIN likes l ON p.postID = l.postId AND l.userId = ?
        LEFT JOIN comment c ON p.postID = c.postID AND c.status = 'visible'
        WHERE p.userId = ? AND p.status = 'approved'
        GROUP BY 
            p.postID, p.userID, p.trashId, t.Waste_type, 
            u.name, u.profile_img, p.Management_type, p.title,
            p.likes, p.details, p.image, p.video_source, p.video_url, p.video_platform, p.video_path, p.video_mime, p.video_size,
            p.date, is_liked
        ORDER BY p.date DESC
    ";

    $stmt = $pdo->prepare($postsQuery);
    $stmt->execute([$_SESSION['userid'] ?? null, $userId]);
    $userPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // แปลงข้อมูลรูปภาพในโพสต์
    foreach ($userPosts as &$post) {
    $post['images'] = [];
    if (!empty($post['image'])) {
        $images = json_decode(trim($post['image']), true);
        if (is_array($images)) {
            $post['images'] = array_filter($images, function($img) {
                return !empty(trim($img));
            });
        } elseif (!empty(trim($post['image']))) {
            $post['images'] = [trim($post['image'])];
        }
    }
    unset($post['image']);

    // เพิ่มส่วนนี้: normalize ค่าฟิลด์วิดีโอ
    $post['video_source'] = $post['video_source'] ?? 'none';
    if ($post['video_source'] !== 'url' && $post['video_source'] !== 'file') {
        $post['video_source'] = 'none';
    }
}

    echo json_encode([
        'user' => $userData,
        'posts' => $userPosts
    ]);

} catch (Exception $e) {
    error_log('Error in get_user_profile.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>