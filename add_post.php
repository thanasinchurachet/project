<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';
require_once 'video_utils.php';

try {
    // ตรวจสอบการล็อกอิน
    if (!isset($_SESSION['userid'])) {
        throw new Exception('กรุณาเข้าสู่ระบบก่อนโพสต์');
    }

    // ตรวจสอบการระงับการโพสต์
    $restrictionQuery = "
        SELECT restriction_id, end_date, is_permanent, reason 
        FROM user_restrictions 
        WHERE userID = ? 
        AND restriction_type IN ('post', 'both') 
        AND status = 'active' 
        AND (is_permanent = 1 OR (is_permanent = 0 AND end_date > NOW()))
        LIMIT 1
    ";
    $restrictionStmt = $pdo->prepare($restrictionQuery);
    $restrictionStmt->execute([$_SESSION['userid']]);
    $restriction = $restrictionStmt->fetch(PDO::FETCH_ASSOC);

    if ($restriction) {
        if ($restriction['is_permanent']) {
            throw new Exception("คุณถูกระงับการโพสต์อย่างถาวร เนื่องจาก: {$restriction['reason']}");
        } else {
            $endDate = date('d/m/Y H:i', strtotime($restriction['end_date']));
            throw new Exception("คุณถูกระงับการโพสต์จนถึงวันที่ {$endDate} เนื่องจาก: {$restriction['reason']}");
        }
    }

    // รับข้อมูลจาก POST
    $title = trim($_POST['title'] ?? '');
    $wasteItem = trim($_POST['wasteItem'] ?? '');
    $customWaste = trim($_POST['customWaste'] ?? '');
    $wasteType = trim($_POST['wasteType'] ?? '');
    $managementType = trim($_POST['managementType'] ?? '');
    $details = trim($_POST['description'] ?? '');

    // Log ข้อมูลที่ได้รับ
    error_log("Received POST data: " . print_r($_POST, true));
    error_log("Received FILES data: " . print_r($_FILES, true));

    // ตรวจสอบข้อมูลที่จำเป็น
    if (empty($title)) throw new Exception('กรุณากรอกหัวข้อ');
    if (empty($managementType)) throw new Exception('กรุณาเลือกวิธีจัดการ');
    if (empty($details)) throw new Exception('กรุณากรอกรายละเอียด');
    if (empty($wasteItem) || ($wasteItem === 'other' && empty($customWaste))) {
        throw new Exception('กรุณาเลือกหรือระบุชิ้นขยะ');
    }
    if (empty($wasteType)) throw new Exception('กรุณาระบุประเภทขยะ');

    // จัดการ trashId
    $trashId = null;
    if ($wasteItem === 'other') {
        $insertTrashQuery = "INSERT INTO trash (name, Waste_type) VALUES (:name, :wasteType)";
        $insertStmt = $pdo->prepare($insertTrashQuery);
        $insertStmt->execute([':name' => $customWaste, ':wasteType' => $wasteType]);
        $trashId = $pdo->lastInsertId();
    } else {
        $trashId = $wasteItem;
    }

    // ตรวจสอบว่า trashId มีอยู่ในตาราง trash
    $checkTrashQuery = "SELECT COUNT(*) FROM trash WHERE trashId = ?";
    $checkStmt = $pdo->prepare($checkTrashQuery);
    $checkStmt->execute([$trashId]);
    if ($checkStmt->fetchColumn() == 0) {
        throw new Exception("trashId '$trashId' ไม่พบในตาราง trash");
    }

    // จัดการการอัปโหลดรูปภาพ
    $images = [];
    $uploadDir = 'img/post_img/';
    
    // ตรวจสอบและสร้างโฟลเดอร์
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('ไม่สามารถสร้างโฟลเดอร์สำหรับอัปโหลดได้');
        }
        error_log("Created directory $uploadDir");
    }
    
    // ตรวจสอบสิทธิ์การเขียน
    if (!is_writable($uploadDir)) {
        throw new Exception("โฟลเดอร์ $uploadDir ไม่มีสิทธิ์เขียน กรุณาตั้งค่า chmod 755 หรือ 775");
    }

    // ประมวลผลไฟล์ที่อัปโหลด
    for ($i = 1; $i <= 3; $i++) {
        $fileKey = "image$i";
        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$fileKey];
            error_log("Processing file $fileKey: " . print_r($file, true));

            // ตรวจสอบประเภทไฟล์
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception("ไฟล์ภาพที่ $i รองรับเฉพาะ JPG และ PNG เท่านั้น");
            }

            // ตรวจสอบขนาดไฟล์
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new Exception("ไฟล์ภาพที่ $i ต้องไม่เกิน 5MB");
            }

            // สร้างชื่อไฟล์ใหม่
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $newFileName = uniqid('') . '.' . $extension;
            $uploadPath = $uploadDir . $newFileName;

            // ย้ายไฟล์
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new Exception("ไม่สามารถอัปโหลดไฟล์ภาพที่ $i ได้");
            }

            $images[] = $newFileName;
            error_log("Successfully uploaded $fileKey to $uploadPath");
        } elseif (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new Exception("เกิดข้อผิดพลาดในการอัปโหลดไฟล์ที่ $i: " . uploadErrorMessage($_FILES[$fileKey]['error']));
        }
    }

    // จัดการวิดีโอ
    $video_source = 'none';
    $video_url = null;
    $video_platform = null;
    $video_path = null;
    $video_mime = null;
    $video_size = null;

    $videoMode = $_POST['video_mode'] ?? 'none';
    $videoMode = in_array($videoMode, ['none','url','file'], true) ? $videoMode : 'none';

    if ($videoMode === 'url') {
        $rawUrl = trim($_POST['video_url'] ?? '');
        if ($rawUrl !== '') {
            $platform = detect_video_platform($rawUrl);
            if (!$platform) {
                throw new Exception('ลิงก์วิดีโอไม่รองรับ กรุณาใช้ YouTube, Instagram หรือ Facebook');
            }
            $video_source = 'url';
            $video_url = $rawUrl;
            $video_platform = $platform;
        }
    } elseif ($videoMode === 'file') {
        if (!isset($_FILES['video_file'])) {
            throw new Exception('กรุณาเลือกไฟล์วิดีโอ');
        }
        $vf = $_FILES['video_file'];
        if ($vf['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('อัปโหลดไฟล์วิดีโอผิดพลาด (code ' . $vf['error'] . ')');
        }
        $maxSize = 200 * 1024 * 1024; // 40MB (align with server default); increase via php.ini if needed
        if ($vf['size'] > $maxSize) {
            throw new Exception('ขนาดไฟล์วิดีโอต้องไม่เกิน 200MB');
        }
        $tmp = $vf['tmp_name'];
        $mime = @mime_content_type($tmp) ?: ($vf['type'] ?? '');
        $allowed = ['video/mp4','video/webm','video/ogg'];
        $ext = strtolower(pathinfo($vf['name'] ?? 'mp4', PATHINFO_EXTENSION));
        if (!in_array(strtolower($mime), $allowed, true)) {
            // Fallback: allow by extension if MIME is unknown or octet-stream
            if (!in_array($ext, ['mp4','webm','ogg'], true)) {
                throw new Exception('รองรับเฉพาะไฟล์วิดีโอ MP4, WebM, Ogg');
            }
        }
        $videoDir = __DIR__ . '/uploads/videos/';
        if (!is_dir($videoDir)) {
            if (!@mkdir($videoDir, 0775, true) && !is_dir($videoDir)) {
                throw new Exception('ไม่สามารถสร้างโฟลเดอร์วิดีโอได้');
            }
        }
        // Reuse ext resolved above
        if ($ext === '') {
            $ext = str_contains($mime, 'webm') ? 'webm' : (str_contains($mime, 'ogg') ? 'ogg' : 'mp4');
        }
        $safe = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $videoDir . $safe)) {
            throw new Exception('อัปโหลดวิดีโอไม่สำเร็จ');
        }
        $video_source = 'file';
        $video_path = $safe; // เส้นทางจะอ้างจาก uploads/videos/ ตอนแสดงผล
        $video_mime = $mime;
        $video_size = (int)$vf['size'];
    }

    // แปลง array รูปภาพเป็น JSON
    $imageJson = !empty($images) ? json_encode($images, JSON_UNESCAPED_UNICODE) : null;
    error_log("Final images array: " . print_r($images, true));
    error_log("Generated imageJson: $imageJson");

    // บันทึกโพสต์ลงฐานข้อมูล
    $query = "
        INSERT INTO post (
            userId, trashId, Management_type, details, image,
            video_source, video_url, video_platform, video_path, video_mime, video_size,
            date, title, status, likes
        ) VALUES (
            :userId, :trashId, :managementType, :details, :image,
            :video_source, :video_url, :video_platform, :video_path, :video_mime, :video_size,
            NOW(), :title, 'pending', 0
        )
    ";
    $stmt = $pdo->prepare($query);
    $result = $stmt->execute([
        ':userId' => $_SESSION['userid'],
        ':trashId' => $trashId,
        ':managementType' => $managementType,
        ':details' => $details,
        ':image' => $imageJson,
        ':video_source' => $video_source,
        ':video_url' => $video_url,
        ':video_platform' => $video_platform,
        ':video_path' => $video_path,
        ':video_mime' => $video_mime,
        ':video_size' => $video_size,
        ':title' => $title
    ]);

    if (!$result) {
        throw new Exception('เกิดข้อผิดพลาดในการบันทึกข้อมูลลงฐานข้อมูล');
    }

    // ส่งการตอบกลับเมื่อสำเร็จ
    echo json_encode([
        'success' => true,
        'message' => 'โพสต์ถูกส่งเรียบร้อยแล้ว กรุณารอการอนุมัติจากผู้ดูแลระบบ',
        'postId' => $pdo->lastInsertId()
    ]);

} catch (Exception $e) {
    // ลบไฟล์ที่อัปโหลดไปแล้วหากเกิดข้อผิดพลาด (ป้องกันกรณีตัวแปรยังไม่ถูกตั้งค่า)
    if (isset($images) && is_array($images)) {
        $dir = isset($uploadDir) ? $uploadDir : (__DIR__ . '/img/post_img/');
        foreach ($images as $image) {
            $filePath = rtrim($dir, '/\\') . '/' . $image;
            if (is_file($filePath)) {
                @unlink($filePath);
                error_log("Deleted uploaded image due to error: $filePath");
            }
        }
    }
    if (isset($video_path) && $video_path) {
        $vp = __DIR__ . '/uploads/videos/' . basename($video_path);
        if (is_file($vp)) {
            @unlink($vp);
            error_log("Deleted uploaded video due to error: $vp");
        }
    }

    // ส่งการตอบกลับเมื่อเกิดข้อผิดพลาด
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log("Error in add_post.php: " . $e->getMessage());
}

/**
 * แปลงรหัสข้อผิดพลาดการอัปโหลดเป็นข้อความ
 */
function uploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return 'ไฟล์เกินขนาดที่กำหนดใน php.ini';
        case UPLOAD_ERR_FORM_SIZE:
            return 'ไฟล์เกินขนาดที่กำหนดในฟอร์ม';
        case UPLOAD_ERR_PARTIAL:
            return 'อัปโหลดไฟล์ไม่สมบูรณ์';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'ไม่มีโฟลเดอร์ชั่วคราว';
        case UPLOAD_ERR_CANT_WRITE:
            return 'ไม่สามารถเขียนไฟล์ได้';
        case UPLOAD_ERR_EXTENSION:
            return 'อัปโหลดถูกหยุดโดยส่วนขยาย';
        default:
            return 'เกิดข้อผิดพลาดที่ไม่รู้จัก';
    }
}
?>
