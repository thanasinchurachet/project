<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

try {
    // ตรวจสอบการล็อกอิน
    if (!isset($_SESSION['userid'])) {
        throw new Exception('กรุณาเข้าสู่ระบบก่อนลบโพสต์');
    }

    // รับ postId
    $postId = $_POST['postId'] ?? null;
    if (!$postId) {
        throw new Exception('ไม่ระบุโพสต์ที่ต้องการลบ');
    }

    // ตรวจสอบการเป็นเจ้าของโพสต์ และดึงข้อมูลวิดีโอ
    $stmt = $pdo->prepare("SELECT userId, video_source, video_path FROM post WHERE postId = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post || $post['userId'] != $_SESSION['userid']) {
        throw new Exception('คุณไม่มีสิทธิ์ลบโพสต์นี้');
    }

    // เริ่ม Transaction
    $pdo->beginTransaction();

    try {
// 1) ลบรายงานคอมเมนต์ของโพสต์นี้ทั้งหมด (ตารางจริงคือ comment_report)
$stmt = $pdo->prepare("DELETE FROM comment_report WHERE postID = ?");
$stmt->execute([$postId]);

// 2) ลบรายงานโพสต์ (ตารางจริงคือ post_report)
$stmt = $pdo->prepare("DELETE FROM post_report WHERE postId = ?");
$stmt->execute([$postId]);

// 3) ลบไลก์ของโพสต์
$stmt = $pdo->prepare("DELETE FROM likes WHERE postId = ?");
$stmt->execute([$postId]);

// 4) ลบคอมเมนต์ทั้งหมดของโพสต์
$stmt = $pdo->prepare("DELETE FROM comment WHERE postID = ?");
$stmt->execute([$postId]);

// 5) ลบรูปภาพของโพสต์ (ถ้าคุณมีตารางนี้จริง)
if ($pdo->query("SHOW TABLES LIKE 'post_images'")->rowCount() > 0) {
    $stmt = $pdo->prepare("DELETE FROM post_images WHERE postId = ?");
    $stmt->execute([$postId]);
}

// 6) ลบไฟล์วิดีโอที่อัปโหลด (ถ้ามี)
if (!empty($post['video_source']) && $post['video_source'] === 'file' && !empty($post['video_path'])) {
    $videoFile = __DIR__ . '/uploads/videos/' . basename($post['video_path']);
    if (is_file($videoFile)) { @unlink($videoFile); }
}

// 7) ลบโพสต์หลัก
$stmt = $pdo->prepare("DELETE FROM post WHERE postId = ?");
$result = $stmt->execute([$postId]);
if (!$result) {
    throw new Exception('ไม่สามารถลบโพสต์ได้');
}

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'ลบโพสต์เรียบร้อยแล้ว'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
