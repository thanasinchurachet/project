<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

if (!isset($_SESSION['userid'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาล็อกอิน']);
    exit();
}

$postId = isset($_POST['postId']) ? intval($_POST['postId']) : 0;
$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';

if (empty($postId) || empty($title) || empty($description)) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit();
}

try {
    // ตรวจสอบว่าโพสต์เป็นของผู้ใช้
    $stmt = $pdo->prepare("SELECT userId FROM post WHERE postID = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    if (!$post || $post['userId'] != $_SESSION['userid']) {
        echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์แก้ไขโพสต์นี้']);
        exit();
    }

    // อัปเดตข้อมูลโพสต์
    $stmt = $pdo->prepare("
        UPDATE post 
        SET title = ?, details = ?, post_status = 'pending', post_date = ?
        WHERE postID = ?
    ");
    $thailandTime = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
    $stmt->execute([$title, $description, $thailandTime, $postId]);

    // จัดการรูปภาพ
    $uploadDir = 'img/post_img/';
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    $imagePaths = [];

    for ($i = 1; $i <= 3; $i++) {
        if (isset($_FILES["image$i"]) && $_FILES["image$i"]['size'] > 0) {
            $file = $_FILES["image$i"];
            if (!in_array($file['type'], $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => "ไฟล์รูปภาพ $i ต้องเป็น JPG หรือ PNG"]);
                exit();
            }
            if ($file['size'] > $maxFileSize) {
                echo json_encode(['success' => false, 'message' => "ไฟล์รูปภาพ $i ใหญ่เกิน 5MB"]);
                exit();
            }
            $filename = uniqid() . '_' . $file['name'];
            $destPath = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $imagePaths[$i-1] = $filename;
            } else {
                echo json_encode(['success' => false, 'message' => "ไม่สามารถอัปโหลดรูปภาพ $i ได้"]);
                exit();
            }
        }
    }

    // อัปเดตรูปภาพในฐานข้อมูล
    if (!empty($imagePaths)) {
        $stmt = $pdo->prepare("SELECT image FROM post_image WHERE postID = ? ORDER BY imageID LIMIT 3");
        $stmt->execute([$postId]);
        $existingImages = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($imagePaths as $index => $newImage) {
            if (isset($existingImages[$index])) {
                // อัปเดตรูปภาพที่มีอยู่
                $stmt = $pdo->prepare("UPDATE post_image SET image = ? WHERE postID = ? AND imageID = ?");
                $stmt->execute([$newImage, $postId, $existingImages[$index]['imageID']]);
                // ลบไฟล์รูปภาพเก่า
                if (file_exists($uploadDir . $existingImages[$index])) {
                    unlink($uploadDir . $existingImages[$index]);
                }
            } else {
                // เพิ่มรูปภาพใหม่
                $stmt = $pdo->prepare("INSERT INTO post_image (postID, image) VALUES (?, ?)");
                $stmt->execute([$postId, $newImage]);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'อัปเดตโพสต์เรียบร้อยแล้ว']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
?>