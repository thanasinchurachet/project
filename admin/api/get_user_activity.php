<?php
// กำหนดค่า header สำหรับ API
header('Content-Type: application/json');

// เชื่อมต่อฐานข้อมูล
require_once '../db1.php';

// ตรวจสอบว่ามีพารามิเตอร์ userId
if (!isset($_GET['userId'])) {
    echo json_encode(['success' => false, 'message' => 'ต้องระบุรหัสผู้ใช้']);
    exit;
}

$userId = (int) $_GET['userId'];
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

/**
 * ดึงข้อมูลกิจกรรมของผู้ใช้
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @param int $limit จำนวนรายการต่อประเภท
 * @return array ข้อมูลกิจกรรมของผู้ใช้
 */
function getUserActivity($conn, $userId, $limit = 10) {
    try {
        // ตรวจสอบว่าผู้ใช้มีอยู่หรือไม่
        $query = "SELECT userID, name, email, status FROM users WHERE userID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return [
                'success' => false,
                'message' => 'ไม่พบผู้ใช้'
            ];
        }
        
        $userData = $result->fetch_assoc();
        
        // ดึงข้อมูลโพสต์ล่าสุด
        $query = "SELECT postId, title, Management_type, details, image, date, status 
                 FROM post 
                 WHERE userId = ? 
                 ORDER BY date DESC 
                 LIMIT ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $posts = [];
        while ($row = $result->fetch_assoc()) {
            $posts[] = $row;
        }
        
        // ดึงข้อมูลคอมเมนต์ล่าสุด
        $query = "SELECT c.commentID, c.postID, c.content, c.date_created, c.status, p.title as post_title 
                 FROM comment c 
                 LEFT JOIN post p ON c.postID = p.postId 
                 WHERE c.userID = ? 
                 ORDER BY c.date_created DESC 
                 LIMIT ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $comments = [];
        while ($row = $result->fetch_assoc()) {
            $comments[] = $row;
        }
        
        // ดึงข้อมูลการรายงานล่าสุด
        $reports = [];
        
        // รายงานโพสต์
        $query = "SELECT pr.postID, p.title, pr.reason, pr.date_OPT, pr.status 
                 FROM post_report pr 
                 LEFT JOIN post p ON pr.postID = p.postId 
                 WHERE pr.userID = ? 
                 ORDER BY pr.date_OPT DESC 
                 LIMIT ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['report_type'] = 'post';
            $reports[] = $row;
        }
        
        // รายงานคอมเมนต์
        $query = "SELECT cr.commentID, cr.postID, c.content, p.title as post_title, cr.reason, cr.Report_date, cr.action_status, cr.status 
                 FROM comment_report cr 
                 LEFT JOIN comment c ON cr.commentID = c.commentID 
                 LEFT JOIN post p ON cr.postID = p.postId 
                 WHERE cr.userID = ? 
                 ORDER BY cr.Report_date DESC 
                 LIMIT ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['report_type'] = 'comment';
            $reports[] = $row;
        }
        
        // เรียงรายงานตามวันที่
        usort($reports, function($a, $b) {
            $dateA = isset($a['date_OPT']) ? $a['date_OPT'] : $a['Report_date'];
            $dateB = isset($b['date_OPT']) ? $b['date_OPT'] : $b['Report_date'];
            return strtotime($dateB) - strtotime($dateA);
        });
        
        // ตัดให้เหลือตามจำนวนที่กำหนด
        $reports = array_slice($reports, 0, $limit);
        
        // ดึงข้อมูลสถิติ
        $stats = [
            'total_posts' => 0,
            'total_comments' => 0,
            'total_reports' => 0,
            'last_activity' => null
        ];
        
        // จำนวนโพสต์ทั้งหมด
        $query = "SELECT COUNT(*) as count FROM post WHERE userId = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['total_posts'] = $result->fetch_assoc()['count'];
        
        // จำนวนคอมเมนต์ทั้งหมด
        $query = "SELECT COUNT(*) as count FROM comment WHERE userID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['total_comments'] = $result->fetch_assoc()['count'];
        
        // จำนวนรายงานทั้งหมด
        $query = "SELECT 
                 (SELECT COUNT(*) FROM post_report WHERE userID = ?) +
                 (SELECT COUNT(*) FROM comment_report WHERE userID = ?) as count";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['total_reports'] = $result->fetch_assoc()['count'];
        
        // กิจกรรมล่าสุด
        $query = "SELECT 
                 GREATEST(
                     COALESCE((SELECT MAX(date) FROM post WHERE userId = ?), '1970-01-01'),
                     COALESCE((SELECT MAX(date_created) FROM comment WHERE userID = ?), '1970-01-01'),
                     COALESCE((SELECT MAX(date_OPT) FROM post_report WHERE userID = ?), '1970-01-01'),
                     COALESCE((SELECT MAX(Report_date) FROM comment_report WHERE userID = ?), '1970-01-01')
                 ) as last_activity";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['last_activity'] = $result->fetch_assoc()['last_activity'];
        
        return [
            'success' => true,
            'data' => [
                'user' => $userData,
                'stats' => $stats,
                'posts' => $posts,
                'comments' => $comments,
                'reports' => $reports
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
        ];
    }
}

// ดึงข้อมูลกิจกรรมของผู้ใช้
$result = getUserActivity($conn, $userId, $limit);

// ส่งผลลัพธ์
echo json_encode($result);
?>