<?php
// เชื่อมต่อกับฐานข้อมูล
require_once 'db1.php';

// ตรวจสอบว่ามีการเรียกไฟล์นี้โดยตรงหรือไม่
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit(json_encode(['success' => false, 'message' => 'Method Not Allowed']));
}

// รับพารามิเตอร์จาก URL
$report_id = isset($_GET['reportId']) ? $_GET['reportId'] : '';

if (empty($report_id)) {
    header('Content-Type: application/json');
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'ไม่ได้ระบุ reportId']));
}

try {
    // แยก reportId เป็น postID, commentID, userID
    $parts = explode('_', $report_id);
    if (count($parts) !== 3) {
        throw new Exception('รูปแบบ reportId ไม่ถูกต้อง');
    }
    
    $postID = $parts[0];
    $commentID = $parts[1];
    $userID = $parts[2];
    
    // ดึงข้อมูลรายละเอียดรายงาน
    $sql = "SELECT cr.postID, cr.commentID, cr.userID, cr.Report_date, cr.status, cr.reason,
                  c.content as comment_content, c.date_created,
                  p.title as post_title, p.image as post_image, p.details as post_details,
                  u_reporter.name as reporter_name, u_reporter.profile_img as reporter_image,
                  u_commenter.name as commenter_name, u_commenter.profile_img as commenter_image
           FROM comment_report cr
           JOIN comment c ON cr.commentID = c.commentID
           JOIN post p ON cr.postID = p.postId
           JOIN users u_reporter ON cr.userID = u_reporter.userID
           JOIN users u_commenter ON c.userID = u_commenter.userID
           WHERE cr.postID = ? AND cr.commentID = ? AND cr.userID = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $postID, $commentID, $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('ไม่พบข้อมูลรายงาน');
    }
    
    $report = $result->fetch_assoc();
    
    // จัดการกับ JSON ของรูปภาพโพสต์
    if ($report['post_image']) {
        try {
            $images = json_decode($report['post_image'], true);
            $report['post_image'] = is_array($images) && !empty($images) ? $images[0] : 'default-post.jpg';
        } catch (Exception $e) {
            $report['post_image'] = 'default-post.jpg';
        }
    } else {
        $report['post_image'] = 'default-post.jpg';
    }
    
    // เพิ่มรหัสรายงานสำหรับการอ้างอิง
    $report['reportId'] = $report_id;
    
    // ส่งข้อมูลกลับเป็น JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $report
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}