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
    if (!isset($_GET['postId'])) {
        throw new Exception('Missing post ID');
    }

    $postId = $_GET['postId'];
    $current_user_id = $_SESSION['userid'] ?? null;

    // --- START FINAL SQL CORRECTION ---
    // ยืนยันการใช้ t.Waste_item และ t.Waste_type ตามไฟล์ waste_system (5).sql
    $query = "
    SELECT 
        p.postID AS post_id,
        p.userID,
        p.trashId,
        t.name,       -- <<< [CORRECTED] ชื่อขยะ/ชิ้นขยะ
        t.Waste_type,       -- <<< ประเภทขยะ
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
    WHERE p.postID = ?
    GROUP BY 
        p.postID,
        p.userID,
        p.trashId,
        t.name,       -- <<< [CORRECTED]
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
    ";
    // --- END FINAL SQL CORRECTION ---

    $stmt = $pdo->prepare($query);
    $stmt->execute([$current_user_id, $postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        throw new Exception('Post not found');
    }

    $post['images'] = [];
    if (!empty($post['image'])) {
        $images = json_decode(trim($post['image']), true);
        if (is_array($images)) {
            $post['images'] = array_filter($images, function($img) { return !empty(trim($img)); });
        } elseif (!empty(trim($post['image']))) {
            $post['images'] = [trim($post['image'])];
        }
    }
    unset($post['image']);
    $cover = null;
if (!empty($post['images'])) {
    $first = $post['images'][0];
    $cover = $first;
}

// 2) ถ้ายังไม่มี และเป็นวิดีโอไฟล์ → ใช้คู่ .jpg
if (!$cover && ($post['video_source'] === 'file') && !empty($post['video_path'])) {
    $coverPath = preg_replace('/\.[^.]+$/', '.jpg', $post['video_path']);
    $cover = $coverPath;
}

// 3) ถ้ายังไม่มี และเป็นวิดีโอ URL → ขอรูปจากแพลตฟอร์ม
if (!$cover && ($post['video_source'] === 'url') && !empty($post['video_url']) && !empty($post['video_platform'])) {
    $thumb = resolveVideoThumbnail($post['video_platform'], $post['video_url']);
    if ($thumb) $cover = $thumb;
}

$post['cover_image'] = $cover ?: null;
    // Normalize video fields
    $post['video_source'] = $post['video_source'] ?? 'none';
    if ($post['video_source'] !== 'url' && $post['video_source'] !== 'file') {
        $post['video_source'] = 'none';
    }

    echo json_encode(['success' => true, 'data' => $post]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
