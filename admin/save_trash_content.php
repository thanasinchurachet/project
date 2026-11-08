<?php
// Clear output buffers and set strict error reporting
ob_start();
ob_end_clean();

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Strict JSON headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once 'db1.php';

// Logging function
function logError($message) {
    error_log('[Trash Content] ' . $message . ' at ' . date('Y-m-d H:i:s'));
}

// Function to save base64 image to folder
function saveBase64Image($base64Data, $imageType, $uploadDir) {
    $imageFileName = uniqid() . '.' . $imageType;
    $imagePath = $uploadDir . $imageFileName;
    
    if (file_put_contents($imagePath, base64_decode($base64Data))) {
        return 'img/trash_content/' . $imageFileName;
    }
    throw new Exception('Failed to save base64 image');
}

try {
    // Validate database connection
    if (!isset($conn) || !$conn->ping()) {
        throw new Exception('Database connection failed');
    }

    // Validate and sanitize input data
    $contentId = isset($_POST['contentId']) ? (int)$_POST['contentId'] : 0;
    $trashId = isset($_POST['trashId']) ? (int)$_POST['trashId'] : 0;
    $managementType = isset($_POST['managementType']) ? trim($_POST['managementType']) : '';
    $content = isset($_POST['content']) ? trim($_POST['content']) : '';

    // Detailed logging of received data
    logError("Request Data: ContentID=$contentId, TrashID=$trashId, Management=$managementType");
    logError("Content Length: " . strlen($content));
    
    // Comprehensive validation
    $errors = [];
    if ($trashId <= 0) $errors[] = 'กรุณาเลือกขยะ';
    if (!in_array($managementType, ['reuse', 'recycle', 'dispose'])) $errors[] = 'วิธีจัดการไม่ถูกต้อง';
    if (empty($content)) $errors[] = 'กรุณากรอกเนื้อหา';

    if (!empty($errors)) {
        http_response_code(400);
        throw new Exception(implode(', ', $errors));
    }

    // Initialize upload directory
    $uploadDir = __DIR__ . '/img/trash_content/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Handle base64 images in content
    $pattern = '/<img[^>]*src=["|\']data:image\/([^;]+);base64,([^"\']+)["|\']/i';
    // ในส่วนที่จัดการ base64 images
if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        // $match[0] คือ tag <img ...> ทั้งหมดที่เจอ
        // $match[1] คือประเภทของรูปภาพ (เช่น png, jpeg)
        // $match[2] คือข้อมูล base64
        
        $originalImgTag = $match[0];
        $imageType = strtolower($match[1]);
        $base64Data = $match[2];

        // ตรวจสอบประเภทไฟล์ที่อนุญาต
        if (!in_array($imageType, ['jpeg', 'jpg', 'png', 'gif'])) {
            continue;
        }
        
        // 1. บันทึกรูปภาพและรับ URL ใหม่กลับมา
        $imageUrl = saveBase64Image($base64Data, $imageType, $uploadDir);
        
        // 2. สร้าง "data:image..." string ต้นฉบับขึ้นมาเพื่อใช้ค้นหา
        $originalSrcValue = 'data:image/' . $imageType . ';base64,' . $base64Data;
        
        // 3. แทนที่แค่ค่า src ใน tag เดิมที่จับมาได้ เพื่อรักษารายละเอียดอื่นๆ (style, alt)
        $newImgTag = str_replace($originalSrcValue, $imageUrl, $originalImgTag);
        
        // 4. แทนที่ tag <img...> เดิมในเนื้อหาทั้งหมดด้วย tag ที่เราเพิ่งแก้ไข
        // วิธีนี้จะปลอดภัยและแม่นยำกว่า
        $content = str_replace($originalImgTag, $newImgTag, $content);
    }
}

    // Handle file upload
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpeg', 'jpg', 'png', 'gif'])) {
            throw new Exception('ประเภทไฟล์รูปภาพไม่รองรับ');
        }
        
        $imageName = uniqid() . '.' . $extension;
        $uploadPath = $uploadDir . $imageName;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadPath)) {
            $imagePath = 'img/trash_content/' . $imageName;
        }
    }

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Before write: prevent duplicate management type per trash
        // A trash can have only one content per Management_type
        $dupSql = $contentId > 0
            ? "SELECT contentId FROM trash_content WHERE trashId = ? AND Management_type = ? AND contentId <> ? LIMIT 1"
            : "SELECT contentId FROM trash_content WHERE trashId = ? AND Management_type = ? LIMIT 1";
        $dupStmt = $conn->prepare($dupSql);
        if ($contentId > 0) {
            $dupStmt->bind_param("isi", $trashId, $managementType, $contentId);
        } else {
            $dupStmt->bind_param("is", $trashId, $managementType);
        }
        $dupStmt->execute();
        $dupRes = $dupStmt->get_result();
        if ($dupRes && $dupRes->num_rows > 0) {
            http_response_code(409);
            throw new Exception('มีเนื้อหาสำหรับวิธีจัดการนี้อยู่แล้ว (ไม่สามารถซ้ำได้)');
        }

        if ($contentId > 0) {
            // Update existing content
            if ($imagePath) {
                $query = "UPDATE trash_content SET 
                          trashId = ?, 
                          Management_type = ?, 
                          content = ?, 
                          image = ? 
                          WHERE contentId = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("isssi", $trashId, $managementType, $content, $imagePath, $contentId);
            } else {
                $query = "UPDATE trash_content SET 
                          trashId = ?, 
                          Management_type = ?, 
                          content = ? 
                          WHERE contentId = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("issi", $trashId, $managementType, $content, $contentId);
            }
            $message = 'อัปเดตเนื้อหาเรียบร้อยแล้ว';
        } else {
            // Insert new content
            $query = "INSERT INTO trash_content 
                      (trashId, Management_type, content, image) 
                      VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("isss", $trashId, $managementType, $content, $imagePath);
            $message = 'เพิ่มเนื้อหาใหม่เรียบร้อยแล้ว';
        }

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }

        $newContentId = $contentId > 0 ? $contentId : $conn->insert_id;

        // Log admin action
        $actionType = $contentId > 0 ? 'edit_trash_content' : 'add_trash_content';
        $logQuery = "INSERT INTO admin_log (action, targetID, target_type, reason) 
                     VALUES (?, ?, 'trash_content', ?)";
        $logStmt = $conn->prepare($logQuery);
        $actionReason = $contentId > 0 ? 'แก้ไขเนื้อหาขยะ' : 'เพิ่มเนื้อหาขยะใหม่';
        $logStmt->bind_param("sis", $actionType, $newContentId, $actionReason);
        $logStmt->execute();

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => $message,
            'contentId' => $newContentId
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    // Preserve response code if previously set (e.g., 409)
    $code = http_response_code();
    if ($code === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
?>
