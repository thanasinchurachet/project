<?php
// เชื่อมต่อกับฐานข้อมูล
require_once 'db1.php';

// ตรวจสอบว่ามีการเรียกไฟล์นี้โดยตรงหรือไม่
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit(json_encode(['success' => false, 'message' => 'Method Not Allowed']));
}

// รับพารามิเตอร์จาก URL
$status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 8;
$search = isset($_GET['search']) ? $_GET['search'] : '';

// คำนวณ offset สำหรับ LIMIT ใน SQL
$offset = ($page - 1) * $limit;

// กำหนดเงื่อนไขการค้นหาตามสถานะ
$status_condition = '';
if ($status === 'pending') {
    $status_condition = "AND cr.action_status = 'pending'";
} elseif ($status === 'handled') {
    $status_condition = "AND (cr.action_status = 'hidden' OR cr.action_status = 'ignored')";
}

// ถ้าเป็น "all" ไม่ต้องใส่เงื่อนไข

// กำหนดเงื่อนไขการค้นหาข้อความ
$search_condition = '';
if (!empty($search)) {
    $search = '%' . $search . '%';
    $search_condition = "AND (c.content LIKE ? OR u_reporter.name LIKE ? OR u_commenter.name LIKE ? OR p.title LIKE ?)";
}

try {
    // รับจำนวนรายงานทั้งหมดที่ตรงกับเงื่อนไข
    $count_sql = "SELECT COUNT(*) as total 
                  FROM comment_report cr
                  JOIN comment c ON cr.commentID = c.commentID
                  JOIN post p ON cr.postID = p.postId
                  JOIN users u_reporter ON cr.userID = u_reporter.userID
                  JOIN users u_commenter ON c.userID = u_commenter.userID
                  WHERE 1=1 $status_condition $search_condition";
    
    $count_stmt = $conn->prepare($count_sql);
    
    // Bind parameters สำหรับการค้นหา
    if (!empty($search)) {
        $count_stmt->bind_param("ssss", $search, $search, $search, $search);
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    $total_reports = $count_row['total'];
    
    // ดึงข้อมูลรายงานคอมเมนต์
    $sql = "SELECT cr.postID, cr.commentID, cr.userID, cr.Report_date, cr.status, cr.action_status, cr.reason,
              c.content as comment_content, c.date_created,
              p.title as post_title, p.image as post_image,
              u_reporter.name as reporter_name, u_reporter.profile_img as reporter_image,
              u_commenter.name as commenter_name, u_commenter.profile_img as commenter_image
           FROM comment_report cr
           JOIN comment c ON cr.commentID = c.commentID
           JOIN post p ON cr.postID = p.postId
           JOIN users u_reporter ON cr.userID = u_reporter.userID
           JOIN users u_commenter ON c.userID = u_commenter.userID
           WHERE 1=1 $status_condition $search_condition
           ORDER BY cr.Report_date DESC
           LIMIT ?, ?";
    
    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    if (!empty($search)) {
        $stmt->bind_param("ssssii", $search, $search, $search, $search, $offset, $limit);
    } else {
        $stmt->bind_param("ii", $offset, $limit);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reports = [];
    while ($row = $result->fetch_assoc()) {
        // จัดการกับ JSON ของรูปภาพโพสต์
        if ($row['post_image']) {
            try {
                $images = json_decode($row['post_image'], true);
                $row['post_image'] = is_array($images) && !empty($images) ? $images[0] : 'default-post.jpg';
            } catch (Exception $e) {
                $row['post_image'] = 'default-post.jpg';
            }
        } else {
            $row['post_image'] = 'default-post.jpg';
        }
        
        // เพิ่มรหัสรายงานสำหรับการอ้างอิง (ใช้ postID_commentID_userID แทน เนื่องจากไม่มี reportID ในตาราง)
        $row['reportId'] = $row['postID'] . '_' . $row['commentID'] . '_' . $row['userID'];
        
        $reports[] = $row;
    }
    
    // ส่งข้อมูลกลับเป็น JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'reports' => $reports,
            'total' => $total_reports,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total_reports / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}