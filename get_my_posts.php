<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';
// Helper: หา thumbnail ของวิดีโอจาก platform/URL
function resolveVideoThumbnail($platform, $url) {
    $platform = strtolower(trim((string)$platform));
    if ($platform === 'youtube') {
        if (preg_match('~(?:v=|youtu\.be/|embed/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            $id = $m[1];
            // ใช้ hqdefault.jpg ก่อน (เสถียรกว่า)
            return "https://img.youtube.com/vi/$id/hqdefault.jpg";
        }
    } elseif ($platform === 'tiktok') {
        // TikTok ต้องเรียก oEmbed (หากเซิร์ฟเวอร์อนุญาต outbound)
        $oembed = "https://www.tiktok.com/oembed?url=" . urlencode($url);
        $ch = curl_init($oembed);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5]);
        $res = curl_exec($ch);
        if ($res) {
            $json = json_decode($res, true);
            if (!empty($json['thumbnail_url'])) return $json['thumbnail_url'];
        }
    }
    // instagram/facebook ปกติจะต้องใช้ token → ถ้าไม่มีให้คืน null
    return null;
}

try {
    // ตรวจสอบว่าผู้ใช้ล็อกอินหรือไม่
    if (!isset($_SESSION['userid'])) {
        throw new Exception('ไม่ได้ล็อกอิน');
    }

    $userId = $_SESSION['userid'];

    // ดึงโพสต์ทั้งหมดของผู้ใช้ปัจจุบัน (รวมถึงสถานะ pending และ suspended)
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
        p.status AS post_status,
        CASE WHEN l.userId IS NOT NULL THEN 1 ELSE 0 END as is_liked,
        COUNT(DISTINCT c.commentID) AS comment_count
    FROM post p
    JOIN users u ON p.userID = u.userID
    JOIN trash t ON p.trashId = t.trashId
    LEFT JOIN likes l ON p.postID = l.postId AND l.userId = ?
    LEFT JOIN comment c ON p.postID = c.postID AND c.status = 'visible'
    WHERE p.userId = ?
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
    $stmt->execute([$userId, $userId]);
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
        $cover = null;
if (!empty($post['images'])) {
    $first = $post['images'][0];
    // ถ้าเก็บเป็น path ตรง ให้ใช้ตามนั้นเลย (อย่าเติม prefix)
    $cover = $first;
}

// 2) ถ้ายังไม่มี และเป็นวิดีโอแบบไฟล์ → เดาคู่ .jpg ของไฟล์วิดีโอ
if (!$cover && ($post['video_source'] === 'file') && !empty($post['video_path'])) {
    // ตัวอย่าง: uploads/videos/abc123.mp4 → uploads/videos/abc123.jpg
    $coverPath = preg_replace('/\.[^.]+$/', '.jpg', $post['video_path']);
    $cover = $coverPath;
}

// 3) ถ้ายังไม่มี และเป็นวิดีโอแบบ URL → ขอรูปจากแพลตฟอร์ม
if (!$cover && ($post['video_source'] === 'url') && !empty($post['video_url']) && !empty($post['video_platform'])) {
    $thumb = resolveVideoThumbnail($post['video_platform'], $post['video_url']);
    if ($thumb) $cover = $thumb;
}

$post['cover_image'] = $cover ?: null;

        // Normalize video fields
        $post['video_source'] = $post['video_source'] ?? 'none';
    }

    echo json_encode($posts);
} catch (Exception $e) {
    // บันทึกข้อผิดพลาด
    error_log('Error in get_my_posts.php: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
