<?php
require_once 'db1.php';
header('Content-Type: application/json');

// Check if user is admin (implement your authentication logic)
// if (!is_admin()) {
//     http_response_code(403);
//     echo json_encode([
//         'success' => false,
//         'message' => 'ไม่มีสิทธิ์ในการดำเนินการนี้'
//     ]);
//     exit;
// }

$contentId = isset($_POST['contentId']) ? intval($_POST['contentId']) : 0;

if ($contentId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่พบรหัสเนื้อหาที่ต้องการลบ'
    ]);
    exit;
}

try {
    // Begin transaction
    $conn->begin_transaction();
    
    // Get image info before deleting
    $imageQuery = "SELECT image FROM trash_content WHERE contentId = ?";
    $imageStmt = $conn->prepare($imageQuery);
    $imageStmt->bind_param("i", $contentId);
    $imageStmt->execute();
    $imageResult = $imageStmt->get_result();
    
    if ($imageResult->num_rows === 0) {
        throw new Exception('ไม่พบเนื้อหาที่ต้องการลบ');
    }
    
    $imageName = $imageResult->fetch_assoc()['image'];
    
    // Delete from database
    $deleteQuery = "DELETE FROM trash_content WHERE contentId = ?";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->bind_param("i", $contentId);
    $deleteStmt->execute();
    
    if ($deleteStmt->affected_rows === 0) {
        throw new Exception('ไม่สามารถลบเนื้อหาได้');
    }
    
    // Log admin action
    $logQuery = "
        INSERT INTO admin_log (action, targetID, target_type, reason) 
        VALUES ('delete_trash_content', ?, 'trash_content', ?)
    ";
    $logStmt = $conn->prepare($logQuery);
    $actionReason = 'ลบเนื้อหาขยะ';
    $logStmt->bind_param("is", $contentId, $actionReason);
    $logStmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    // Delete image file if exists
    if ($imageName && file_exists('../img/trash_content/' . $imageName)) {
        unlink('../img/trash_content/' . $imageName);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'ลบเนื้อหาเรียบร้อยแล้ว'
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}

$conn->close();
?>