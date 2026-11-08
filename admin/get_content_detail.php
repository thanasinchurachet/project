<?php
require_once 'db1.php';
header('Content-Type: application/json');

$contentId = isset($_GET['contentId']) ? intval($_GET['contentId']) : 0;

if ($contentId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่พบรหัสเนื้อหาที่ต้องการ'
    ]);
    exit;
}

try {
    // เปลี่ยนการดึงข้อมูลรูปภาพจาก trash_content เป็น trash
    $query = "
        SELECT 
            tc.contentId, 
            tc.trashId, 
            tc.Management_type, 
            tc.content,
            t.name AS waste_name,
            t.Waste_type,
            t.image
        FROM 
            trash_content tc
        JOIN 
            trash t ON tc.trashId = t.trashId
        WHERE 
            tc.contentId = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $contentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่พบข้อมูลเนื้อหาที่ต้องการ'
        ]);
        exit;
    }
    
    $content = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'data' => $content
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}

$conn->close();
?>