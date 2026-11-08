<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db1.php'; // ตรวจสอบว่าไฟล์นี้สร้างตัวแปร $conn หรือ $pdo

try {
    $postId = isset($_GET['postId']) ? (int)$_GET['postId'] : 0;
    
    if (!$postId) {
        throw new Exception('ไม่พบรหัสโพสต์');
    }

    // แก้ไขให้ใช้ $conn แทน $pdo หากในไฟล์ db1.php ใช้ $conn
    $sql = "
        SELECT p.*, t.name as trash_name, t.Waste_type, u.name as user_name, u.profile_img,
               a.reason as reject_reason, a.date_action as reject_date
        FROM post p 
        JOIN users u ON p.userId = u.userID 
        JOIN trash t ON p.trashId = t.trashId 
        LEFT JOIN admin_log a ON a.targetID = p.postId AND a.action IN ('delete_post', 'suspend_post')
        WHERE p.postId = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();
    
    if (!$post) {
        throw new Exception('ไม่พบข้อมูลโพสต์');
    }

    // ตรวจสอบและแปลงค่า image
    if (isset($post['image']) && !is_null($post['image'])) {
        // ถ้ายังไม่ใช่ array ให้แปลงเป็น array
        if (!is_array($post['image'])) {
            $post['image'] = json_decode($post['image'], true);
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $post
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>